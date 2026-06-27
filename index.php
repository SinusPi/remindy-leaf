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

$schema->manageTable('reminders', [
    '1' => "CREATE TABLE reminders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        desired_date DATE NULL,
        expected_period_days INT UNSIGNED NULL,
        yellow_after_days INT UNSIGNED NOT NULL DEFAULT 2,
        red_after_days INT UNSIGNED NOT NULL DEFAULT 5,
        lower_yellow_below_days INT UNSIGNED NOT NULL DEFAULT 2,
        lower_red_below_days INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        INDEX idx_reminders_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    '2' => "ALTER TABLE reminders
        ADD COLUMN lower_yellow_below_days INT UNSIGNED NOT NULL DEFAULT 2 AFTER red_after_days,
        ADD COLUMN lower_red_below_days INT UNSIGNED NOT NULL DEFAULT 1 AFTER lower_yellow_below_days",
]);

$schema->manageTable('reminder_completions', [
    '1' => "CREATE TABLE reminder_completions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reminder_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        completed_at DATETIME NOT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        INDEX idx_completions_reminder (reminder_id),
        INDEX idx_completions_user (user_id),
        INDEX idx_completions_date (completed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    '2' => "ALTER TABLE reminder_completions ADD COLUMN completion_comment TEXT NULL AFTER completed_at",
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

function authenticatedUserId() {
    $user = getAuthenticatedUser();
    return $user ? (int) $user['id'] : null;
}

function daysDiffFromNow($dateTimeString) {
    if (!$dateTimeString) {
        return null;
    }

    $timestamp = strtotime($dateTimeString);
    if ($timestamp === false) {
        return null;
    }

    return (int) floor((time() - $timestamp) / 86400);
}

function reminderSeverityColor($daysElapsed, $yellowAfterDays, $redAfterDays) {
    if ($daysElapsed === null) {
        return 'green';
    }

    if ($daysElapsed >= $redAfterDays) {
        return 'red';
    }

    if ($daysElapsed >= $yellowAfterDays) {
        return 'yellow';
    }

    return 'green';
}

function reminderAverageSeverityColor($averageDaysBetweenCompletions, $lowerYellowBelowDays, $lowerRedBelowDays, $yellowAfterDays, $redAfterDays) {
    if ($averageDaysBetweenCompletions === null) {
        return null;
    }

    if ($averageDaysBetweenCompletions <= $lowerRedBelowDays) {
        return 'red';
    }

    if ($averageDaysBetweenCompletions <= $lowerYellowBelowDays) {
        return 'yellow';
    }

    return reminderSeverityColor((int) floor($averageDaysBetweenCompletions), $yellowAfterDays, $redAfterDays);
}

function reminderWithStats(array $reminder) {
    $completions = db()
        ->select('reminder_completions')
        ->where('reminder_id', (int) $reminder['id'])
        ->orderBy('completed_at', 'ASC')
        ->get();

    $lastCompletedAt = null;
    if (!empty($completions)) {
        $last = end($completions);
        $lastCompletedAt = $last['completed_at'];
    }

    $daysSinceLastCompletion = daysDiffFromNow($lastCompletedAt);
    $daysSinceDesiredDate = daysDiffFromNow($reminder['desired_date'] ? $reminder['desired_date'] . ' 00:00:00' : null);

    $daysElapsedForSeverity = $daysSinceLastCompletion;
    if ($daysElapsedForSeverity === null && $reminder['desired_date']) {
        $daysElapsedForSeverity = $daysSinceDesiredDate;
    }

    $averageDaysBetweenCompletions = null;
    if (count($completions) >= 2) {
        $sum = 0;
        $count = 0;
        for ($i = 1; $i < count($completions); $i++) {
            $prev = strtotime($completions[$i - 1]['completed_at']);
            $curr = strtotime($completions[$i]['completed_at']);
            if ($prev !== false && $curr !== false && $curr >= $prev) {
                $sum += ($curr - $prev) / 86400;
                $count++;
            }
        }
        if ($count > 0) {
            $averageDaysBetweenCompletions = round($sum / $count, 2);
        }
    }

    $currentSeverity = reminderSeverityColor(
        $daysElapsedForSeverity,
        (int) $reminder['yellow_after_days'],
        (int) $reminder['red_after_days']
    );

    $averageSeverity = reminderAverageSeverityColor(
        $averageDaysBetweenCompletions,
        (int) $reminder['lower_yellow_below_days'],
        (int) $reminder['lower_red_below_days'],
        (int) $reminder['yellow_after_days'],
        (int) $reminder['red_after_days']
    );

    return [
        'id' => (int) $reminder['id'],
        'title' => $reminder['title'],
        'desired_date' => $reminder['desired_date'],
        'expected_period_days' => $reminder['expected_period_days'] !== null ? (int) $reminder['expected_period_days'] : null,
        'yellow_after_days' => (int) $reminder['yellow_after_days'],
        'red_after_days' => (int) $reminder['red_after_days'],
        'lower_yellow_below_days' => (int) $reminder['lower_yellow_below_days'],
        'lower_red_below_days' => (int) $reminder['lower_red_below_days'],
        'created_at' => $reminder['created_at'],
        'updated_at' => $reminder['updated_at'],
        'last_completed_at' => $lastCompletedAt,
        'days_since_last_completion' => $daysSinceLastCompletion,
        'days_since_desired_date' => $daysSinceDesiredDate,
        'days_elapsed_for_severity' => $daysElapsedForSeverity,
        'average_days_between_completions' => $averageDaysBetweenCompletions,
        'current_severity' => $currentSeverity,
        'average_severity' => $averageSeverity,
        'completion_count' => count($completions),
    ];
}

function findReminderForUser($reminderId, $userId) {
    return db()
        ->select('reminders')
        ->where('id', (int) $reminderId)
        ->where('user_id', (int) $userId)
        ->first();
}

function secureRandomHex($bytes = 32) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $buffer = openssl_random_pseudo_bytes($bytes, $strong);
        if ($buffer !== false) {
            return bin2hex($buffer);
        }
    }

    return sha1(uniqid('', true) . microtime(true));
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
        $token     = secureRandomHex(32);
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

// ── Reminders API (protected) ────────────────────────────────────────────────
app()->get('/reminders', [
    'middleware' => 'bearer',
    function () {
        $userId = authenticatedUserId();
        $rows = db()
            ->select('reminders')
            ->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->get();

        $reminders = [];
        foreach ($rows as $row) {
            $reminders[] = reminderWithStats($row);
        }

        response()->json([
            'success' => true,
            'reminders' => $reminders,
        ], 200);
    },
]);

app()->post('/reminders', [
    'middleware' => 'bearer',
    function () {
        $data = request()->get([
            'title',
            'desired_date',
            'expected_period_days',
            'yellow_after_days',
            'red_after_days',
            'lower_yellow_below_days',
            'lower_red_below_days',
        ]);

        $title = trim((string) ($data['title'] ?? ''));
        $desiredDate = trim((string) ($data['desired_date'] ?? ''));
        $expectedPeriodDays = $data['expected_period_days'] !== null && $data['expected_period_days'] !== ''
            ? (int) $data['expected_period_days']
            : null;
        $yellowAfterDays = $data['yellow_after_days'] !== null && $data['yellow_after_days'] !== ''
            ? (int) $data['yellow_after_days']
            : 2;
        $redAfterDays = $data['red_after_days'] !== null && $data['red_after_days'] !== ''
            ? (int) $data['red_after_days']
            : 5;
        $lowerYellowBelowDays = $data['lower_yellow_below_days'] !== null && $data['lower_yellow_below_days'] !== ''
            ? (int) $data['lower_yellow_below_days']
            : 2;
        $lowerRedBelowDays = $data['lower_red_below_days'] !== null && $data['lower_red_below_days'] !== ''
            ? (int) $data['lower_red_below_days']
            : 1;

        if ($title === '') {
            response()->json([
                'success' => false,
                'message' => 'Title is required',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($expectedPeriodDays !== null && $expectedPeriodDays <= 0) {
            response()->json([
                'success' => false,
                'message' => 'Expected period must be a positive number of days',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($yellowAfterDays < 0 || $redAfterDays < 0 || $redAfterDays < $yellowAfterDays) {
            response()->json([
                'success' => false,
                'message' => 'Severity thresholds are invalid',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($lowerYellowBelowDays < 0 || $lowerRedBelowDays < 0 || $lowerRedBelowDays > $lowerYellowBelowDays) {
            response()->json([
                'success' => false,
                'message' => 'Lower average severity thresholds are invalid',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($desiredDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desiredDate)) {
            response()->json([
                'success' => false,
                'message' => 'Desired date must use YYYY-MM-DD format',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        $userId = authenticatedUserId();

        db()->insert('reminders')->params([
            'user_id' => $userId,
            'title' => $title,
            'desired_date' => $desiredDate !== '' ? $desiredDate : null,
            'expected_period_days' => $expectedPeriodDays,
            'yellow_after_days' => $yellowAfterDays,
            'red_after_days' => $redAfterDays,
            'lower_yellow_below_days' => $lowerYellowBelowDays,
            'lower_red_below_days' => $lowerRedBelowDays,
        ])->execute();

        $created = db()
            ->select('reminders')
            ->where('id', db()->connection()->lastInsertId())
            ->first();

        response()->json([
            'success' => true,
            'message' => 'Reminder created',
            'reminder' => reminderWithStats($created),
        ], 201);
    },
]);

app()->get('/reminders/{id}', [
    'middleware' => 'bearer',
    function ($id) {
        $userId = authenticatedUserId();
        $reminder = findReminderForUser($id, $userId);

        if (!$reminder) {
            response()->json([
                'success' => false,
                'message' => 'Reminder not found',
                'error' => 'not_found',
            ], 404);
            return;
        }

        response()->json([
            'success' => true,
            'reminder' => reminderWithStats($reminder),
        ], 200);
    },
]);

app()->put('/reminders/{id}', [
    'middleware' => 'bearer',
    function ($id) {
        $userId = authenticatedUserId();
        $reminder = findReminderForUser($id, $userId);

        if (!$reminder) {
            response()->json([
                'success' => false,
                'message' => 'Reminder not found',
                'error' => 'not_found',
            ], 404);
            return;
        }

        $data = request()->get([
            'title',
            'desired_date',
            'expected_period_days',
            'yellow_after_days',
            'red_after_days',
            'lower_yellow_below_days',
            'lower_red_below_days',
        ]);

        $title = trim((string) ($data['title'] ?? $reminder['title']));
        $desiredDateRaw = array_key_exists('desired_date', $data) ? (string) $data['desired_date'] : $reminder['desired_date'];
        $desiredDate = trim((string) $desiredDateRaw);

        $expectedPeriodDays = array_key_exists('expected_period_days', $data)
            ? (($data['expected_period_days'] === '' || $data['expected_period_days'] === null) ? null : (int) $data['expected_period_days'])
            : ($reminder['expected_period_days'] !== null ? (int) $reminder['expected_period_days'] : null);

        $yellowAfterDays = array_key_exists('yellow_after_days', $data)
            ? (int) $data['yellow_after_days']
            : (int) $reminder['yellow_after_days'];

        $redAfterDays = array_key_exists('red_after_days', $data)
            ? (int) $data['red_after_days']
            : (int) $reminder['red_after_days'];
        $lowerYellowBelowDays = array_key_exists('lower_yellow_below_days', $data)
            ? (int) $data['lower_yellow_below_days']
            : (int) $reminder['lower_yellow_below_days'];
        $lowerRedBelowDays = array_key_exists('lower_red_below_days', $data)
            ? (int) $data['lower_red_below_days']
            : (int) $reminder['lower_red_below_days'];

        if ($title === '') {
            response()->json([
                'success' => false,
                'message' => 'Title is required',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($expectedPeriodDays !== null && $expectedPeriodDays <= 0) {
            response()->json([
                'success' => false,
                'message' => 'Expected period must be a positive number of days',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($yellowAfterDays < 0 || $redAfterDays < 0 || $redAfterDays < $yellowAfterDays) {
            response()->json([
                'success' => false,
                'message' => 'Severity thresholds are invalid',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($lowerYellowBelowDays < 0 || $lowerRedBelowDays < 0 || $lowerRedBelowDays > $lowerYellowBelowDays) {
            response()->json([
                'success' => false,
                'message' => 'Lower average severity thresholds are invalid',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($desiredDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desiredDate)) {
            response()->json([
                'success' => false,
                'message' => 'Desired date must use YYYY-MM-DD format',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        db()->update('reminders')->params([
            'title' => $title,
            'desired_date' => $desiredDate !== '' ? $desiredDate : null,
            'expected_period_days' => $expectedPeriodDays,
            'yellow_after_days' => $yellowAfterDays,
            'red_after_days' => $redAfterDays,
            'lower_yellow_below_days' => $lowerYellowBelowDays,
            'lower_red_below_days' => $lowerRedBelowDays,
        ])->where('id', (int) $id)->where('user_id', $userId)->execute();

        $updated = findReminderForUser($id, $userId);

        response()->json([
            'success' => true,
            'message' => 'Reminder updated',
            'reminder' => reminderWithStats($updated),
        ], 200);
    },
]);

app()->delete('/reminders/{id}', [
    'middleware' => 'bearer',
    function ($id) {
        $userId = authenticatedUserId();
        $reminder = findReminderForUser($id, $userId);

        if (!$reminder) {
            response()->json([
                'success' => false,
                'message' => 'Reminder not found',
                'error' => 'not_found',
            ], 404);
            return;
        }

        db()->delete('reminder_completions')->where('reminder_id', (int) $id)->where('user_id', $userId)->execute();
        db()->delete('reminders')->where('id', (int) $id)->where('user_id', $userId)->execute();

        response()->json([
            'success' => true,
            'message' => 'Reminder deleted',
        ], 200);
    },
]);

app()->post('/reminders/{id}/complete', [
    'middleware' => 'bearer',
    function ($id) {
        $userId = authenticatedUserId();
        $payload = request()->get(['completion_comment']);
        $completionComment = trim((string) ($payload['completion_comment'] ?? ''));
        $reminder = findReminderForUser($id, $userId);

        if (!$reminder) {
            response()->json([
                'success' => false,
                'message' => 'Reminder not found',
                'error' => 'not_found',
            ], 404);
            return;
        }

        $completedAt = date('Y-m-d H:i:s');

        db()->insert('reminder_completions')->params([
            'reminder_id' => (int) $id,
            'user_id' => $userId,
            'completed_at' => $completedAt,
            'completion_comment' => $completionComment !== '' ? $completionComment : null,
        ])->execute();

        $latest = findReminderForUser($id, $userId);

        response()->json([
            'success' => true,
            'message' => 'Reminder marked as completed',
            'reminder' => reminderWithStats($latest),
        ], 201);
    },
]);

app()->get('/reminders/{id}/completions', [
    'middleware' => 'bearer',
    function ($id) {
        $userId = authenticatedUserId();
        $reminder = findReminderForUser($id, $userId);

        if (!$reminder) {
            response()->json([
                'success' => false,
                'message' => 'Reminder not found',
                'error' => 'not_found',
            ], 404);
            return;
        }

        $completions = db()
            ->select('reminder_completions')
            ->where('reminder_id', (int) $id)
            ->where('user_id', $userId)
            ->orderBy('completed_at', 'DESC')
            ->get();

        $mapped = [];
        foreach ($completions as $completion) {
            $mapped[] = [
                'id' => (int) $completion['id'],
                'reminder_id' => (int) $completion['reminder_id'],
                'user_id' => (int) $completion['user_id'],
                'completed_at' => $completion['completed_at'],
                'completion_comment' => $completion['completion_comment'],
            ];
        }

        response()->json([
            'success' => true,
            'completions' => $mapped,
        ], 200);
    },
]);

app()->run();
