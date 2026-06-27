<?php

require __DIR__ . '/vendor/autoload.php';

use Leaf\Helpers\Password;

// ─── Environment ──────────────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/.env')) {
    $_ENV = array_merge($_ENV, parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW));
    foreach ($_ENV as $key => $value) {
        putenv("{$key}={$value}");
    }
}

// ─── Auth & DB ────────────────────────────────────────────────────────────────
auth()->config([
    'session'        => false,
    'db.table'       => 'users',
    'unique'         => ['email'],
    'token.secret'   => _env('APP_SECRET', '@_leaf$0Secret!'),
    'token.lifetime' => (int) _env('TOKEN_LIFETIME', 60 * 60 * 24 * 7), // 7 days
]);

auth()->autoConnect();
db()->autoConnect();

// ─── Schema Management ────────────────────────────────────────────────────────
$schema = new \SinusPi\Migri\Migri(db()->connection());

$schema->manageTable('users', [
    '1' => "CREATE TABLE users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
]);

$schema->manageTable('password_resets', [
    '1' => "CREATE TABLE password_resets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        INDEX idx_pr_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
]);

// ─── Token Validation Middleware ──────────────────────────────────────────────
// Static storage for decoded token data
class TokenContext {
    public static $decoded = null;
}

app()->registerMiddleware('bearer', function () {
    $header = request()->headers('Authorization') ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        response()->json([
            'success' => false,
            'message' => 'Missing or invalid Authorization header',
            'error'   => 'unauthorized',
        ], 401);
        exit;
    }

    $token = $matches[1];

    try {
        $decoded = \Firebase\JWT\JWT::decode(
            $token,
            new \Firebase\JWT\Key(auth()->config('token.secret'), 'HS256')
        );

        if (!isset($decoded->{'user.id'})) {
            throw new \Exception('Invalid token payload');
        }

        // Store decoded token for route handler access
        TokenContext::$decoded = $decoded;

        // Verify user still exists
        $user = db()->select('users', 'id')->where('id', $decoded->{'user.id'})->first();
        if (!$user) {
            throw new \Exception('User not found');
        }
    } catch (\Throwable $e) {
        response()->json([
            'success' => false,
            'message' => 'Invalid or expired token: ' . $e->getMessage(),
            'error'   => 'invalid_token',
        ], 401);
        exit;
    }
});

// Helper to get authenticated user from token
function getAuthenticatedUser() {
    if (!TokenContext::$decoded || !isset(TokenContext::$decoded->{'user.id'})) {
        return null;
    }
    return db()->select('users')->where('id', TokenContext::$decoded->{'user.id'})->first();
}


// ─── Mail ─────────────────────────────────────────────────────────────────────
mailer()->connect([
    'host'     => _env('MAIL_HOST', 'localhost'),
    'port'     => (int) _env('MAIL_PORT', 587),
    'security' => _env('MAIL_ENCRYPTION', 'STARTTLS'),
    'auth'     => [
        'username' => _env('MAIL_USERNAME', ''),
        'password' => _env('MAIL_PASSWORD', ''),
    ],
    'defaults' => [
        'senderName'  => _env('MAIL_FROM_NAME', 'Remindy'),
        'senderEmail' => _env('MAIL_FROM_ADDRESS', ''),
    ],
]);

// ─── Routes ───────────────────────────────────────────────────────────────────
app()->get('/', fn() => response()->page('main.php',200));

// ── Dashboard (protected) ────────────────────────────────────────────────────
app()->get('/me', [
    'middleware' => 'bearer',
    function () {
        $user = getAuthenticatedUser();
        
        if (!$user) {
            response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
            return;
        }

        response()->json([
            'success' => true,
            'user'    => [
                'id'         => $user['id'],
                'username'   => $user['username'],
                'email'      => $user['email'],
                'created_at' => $user['created_at'],
            ],
        ], 200);
    },
]);

// ── Login ─────────────────────────────────────────────────────────────────────
app()->post('/login', function () {
    $data = request()->get(['email', 'password']);

    if (!$data['email'] || !$data['password']) {
        response()->json([
            'success' => false,
            'message' => 'Email and password are required',
            'error'   => 'invalid_input',
        ], 400);
        return;
    }

    if (auth()->login(['email' => strtolower(trim($data['email'])), 'password' => $data['password']])) {
        $user   = auth()->user();
        $tokens = auth()->tokens();

        response()->json([
            'success'       => true,
            'message'       => 'Login successful',
            'access_token'  => $tokens['access'],
            'refresh_token' => $tokens['refresh'],
            'user'          => [
                'id'       => $user->id(),
                'username' => $user->username,
                'email'    => $user->email,
            ],
        ], 200);
    } else {
        $errors = auth()->errors();
        response()->json([
            'success' => false,
            'message' => $errors['auth'] ?? $errors['password'] ?? 'Invalid credentials',
            'error'   => 'invalid_credentials',
        ], 401);
    }
});

// ── Register ──────────────────────────────────────────────────────────────────
app()->post('/register', function () {
    $data = request()->get(['username', 'email', 'password', 'password_confirm']);

    if (!$data['username'] || !$data['email'] || !$data['password'] || !$data['password_confirm']) {
        response()->json([
            'success' => false,
            'message' => 'All fields are required',
            'error'   => 'invalid_input',
        ], 400);
        return;
    }

    if ($data['password'] !== $data['password_confirm']) {
        response()->json([
            'success' => false,
            'message' => 'Passwords do not match',
            'error'   => 'password_mismatch',
        ], 400);
        return;
    }

    if (strlen($data['password']) < 8) {
        response()->json([
            'success' => false,
            'message' => 'Password must be at least 8 characters',
            'error'   => 'password_weak',
        ], 400);
        return;
    }

    if (auth()->register([
        'username' => $data['username'],
        'email'    => strtolower(trim($data['email'])),
        'password' => $data['password'],
    ])) {
        $user   = auth()->user();
        $tokens = auth()->tokens();

        response()->json([
            'success'       => true,
            'message'       => 'Registration successful',
            'access_token'  => $tokens['access'],
            'refresh_token' => $tokens['refresh'],
            'user'          => [
                'id'       => $user->id(),
                'username' => $user->username,
                'email'    => $user->email,
            ],
        ], 201);
    } else {
        $errors = auth()->errors();
        response()->json([
            'success' => false,
            'message' => reset($errors) ?: 'Registration failed',
            'error'   => 'registration_failed',
            'errors'  => $errors,
        ], 400);
    }
});

// ── Logout ────────────────────────────────────────────────────────────────────
app()->post('/logout', [
    'middleware' => 'bearer',
    function () {
        // In a stateless JWT system, logout is handled by the client deleting the token
        // The server doesn't need to do anything except confirm the request
        response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
    },
]);

// ── Forgot Password ───────────────────────────────────────────────────────────
app()->post('/forgot-password', function () {
    $email = strtolower(trim(request()->get('email') ?? ''));

    if (!$email) {
        response()->json([
            'success' => false,
            'message' => 'Email is required',
            'error'   => 'invalid_input',
        ], 400);
        return;
    }

    $user = db()->select('users', 'id, username')->where('email', $email)->first();

    if ($user) {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires   = date('Y-m-d H:i:s', strtotime('+1 hour'));

        db()->delete('password_resets')->where('email', $email)->execute();

        db()->insert('password_resets')->params([
            'email'      => $email,
            'token'      => $tokenHash,
            'expires_at' => $expires,
        ])->execute();

        $resetLink = rtrim(_env('APP_URL', 'http://localhost'), '/')
            . '?page=reset-password&token=' . urlencode($token)
            . '&email=' . urlencode($email);

        try {
            mailer([
                'subject'        => 'Password Reset Request',
                'body'           => "Hi {$user['username']},<br><br>"
                    . "You requested a password reset. Click the link below to set a new password:<br><br>"
                    . "<a href=\"{$resetLink}\">{$resetLink}</a><br><br>"
                    . "This link expires in <strong>1 hour</strong>.<br><br>"
                    . "If you did not request this, please ignore this email.",
                'recipientEmail' => $email,
                'recipientName'  => $user['username'],
            ])->send();
        } catch (\Throwable $e) {
            error_log('Password reset email failed: ' . $e->getMessage());
        }
    }

    // Always show success to prevent email enumeration
    response()->json([
        'success' => true,
        'message' => 'If an account with that email exists, a reset link has been sent',
    ], 200);
});

// ── Reset Password ────────────────────────────────────────────────────────────
app()->post('/reset-password', function () {
    $data  = request()->get(['token', 'email', 'password', 'password_confirm']);
    $token = $data['token'] ?? '';
    $email = strtolower(trim($data['email'] ?? ''));

    if (!$token || !$email || !$data['password'] || !$data['password_confirm']) {
        response()->json([
            'success' => false,
            'message' => 'All fields are required',
            'error'   => 'invalid_input',
        ], 400);
        return;
    }

    if ($data['password'] !== $data['password_confirm']) {
        response()->json([
            'success' => false,
            'message' => 'Passwords do not match',
            'error'   => 'password_mismatch',
        ], 400);
        return;
    }

    if (strlen($data['password']) < 8) {
        response()->json([
            'success' => false,
            'message' => 'Password must be at least 8 characters',
            'error'   => 'password_weak',
        ], 400);
        return;
    }

    $tokenHash = hash('sha256', $token);
    $reset = db()->select('password_resets')
        ->where('email', $email)
        ->where('token', $tokenHash)
        ->first();

    if (!$reset || strtotime($reset['expires_at']) < time()) {
        response()->json([
            'success' => false,
            'message' => 'This reset link is invalid or has expired',
            'error'   => 'invalid_reset_token',
        ], 400);
        return;
    }

    db()->update('users')
        ->params(['password' => Password::hash($data['password'])])
        ->where('email', $email)
        ->execute();

    db()->delete('password_resets')->where('email', $email)->execute();

    response()->json([
        'success' => true,
        'message' => 'Your password has been reset. You may now log in.',
    ], 200);
});

app()->run();

