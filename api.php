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

const SECONDS_PER_DAY = 86400;

auth()->autoConnect();
db()->autoConnect();

migrateSchema(db()->connection());

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

function secondsDiffFromNow($dateTimeString) {
    if (!$dateTimeString) {
        return null;
    }

    $timestamp = strtotime($dateTimeString);
    if ($timestamp === false) {
        return null;
    }

    return time() - $timestamp;
}

function reminderTimingSeconds(array $reminder, $secondsKey, $legacyDaysKey = null) {
    if (array_key_exists($secondsKey, $reminder) && $reminder[$secondsKey] !== null) {
        return (int) $reminder[$secondsKey];
    }

    if ($legacyDaysKey !== null && array_key_exists($legacyDaysKey, $reminder) && $reminder[$legacyDaysKey] !== null) {
        return (int) $reminder[$legacyDaysKey] * SECONDS_PER_DAY;
    }

    return null;
}

function requestTimingSeconds(array $data, $secondsKey, $legacyDaysKey = null, $default = null) {
    if (array_key_exists($secondsKey, $data) && $data[$secondsKey] !== null && $data[$secondsKey] !== '') {
        return (int) $data[$secondsKey];
    }

    if ($legacyDaysKey !== null && array_key_exists($legacyDaysKey, $data) && $data[$legacyDaysKey] !== null && $data[$legacyDaysKey] !== '') {
        return (int) $data[$legacyDaysKey] * SECONDS_PER_DAY;
    }

    return $default;
}

function reminderSeverityColor($secondsElapsed, $yellowAfterSeconds, $redAfterSeconds) {
    if ($secondsElapsed === null) {
        return 'green';
    }

    if ($secondsElapsed >= $redAfterSeconds) {
        return 'red';
    }

    if ($secondsElapsed >= $yellowAfterSeconds) {
        return 'yellow';
    }

    return 'green';
}

function reminderAverageSeverityColor($averageSecondsBetweenCompletions, $lowerYellowBelowSeconds, $lowerRedBelowSeconds, $yellowAfterSeconds, $redAfterSeconds) {
    if ($averageSecondsBetweenCompletions === null) {
        return null;
    }

    if ($averageSecondsBetweenCompletions <= $lowerRedBelowSeconds) {
        return 'red';
    }

    if ($averageSecondsBetweenCompletions <= $lowerYellowBelowSeconds) {
        return 'yellow';
    }

    return reminderSeverityColor((int) round($averageSecondsBetweenCompletions), $yellowAfterSeconds, $redAfterSeconds);
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

    $expectedPeriodSeconds = reminderTimingSeconds($reminder, 'expected_period_seconds', 'expected_period_days');
    $yellowAfterSeconds = reminderTimingSeconds($reminder, 'yellow_after_seconds', 'yellow_after_days');
    $redAfterSeconds = reminderTimingSeconds($reminder, 'red_after_seconds', 'red_after_days');
    $lowerYellowBelowSeconds = reminderTimingSeconds($reminder, 'lower_yellow_below_seconds', 'lower_yellow_below_days');
    $lowerRedBelowSeconds = reminderTimingSeconds($reminder, 'lower_red_below_seconds', 'lower_red_below_days');

    $secondsSinceLastCompletion = secondsDiffFromNow($lastCompletedAt);
    $secondsSinceDesiredDate = secondsDiffFromNow($reminder['desired_date'] ? $reminder['desired_date'] . ' 00:00:00' : null);

    $secondsElapsedForSeverity = $secondsSinceLastCompletion;
    if ($secondsElapsedForSeverity === null && $reminder['desired_date']) {
        $secondsElapsedForSeverity = $secondsSinceDesiredDate;
    }

    $averageSecondsBetweenCompletions = null;
    if (count($completions) >= 2) {
        $sum = 0;
        $count = 0;
        for ($i = 1; $i < count($completions); $i++) {
            $prev = strtotime($completions[$i - 1]['completed_at']);
            $curr = strtotime($completions[$i]['completed_at']);
            if ($prev !== false && $curr !== false && $curr >= $prev) {
                $sum += $curr - $prev;
                $count++;
            }
        }
        if ($count > 0) {
            $averageSecondsBetweenCompletions = (int) round($sum / $count);
        }
    }

    $currentSeverity = reminderSeverityColor(
        $secondsElapsedForSeverity,
        $yellowAfterSeconds,
        $redAfterSeconds
    );

    $averageSeverity = reminderAverageSeverityColor(
        $averageSecondsBetweenCompletions,
        $lowerYellowBelowSeconds,
        $lowerRedBelowSeconds,
        $yellowAfterSeconds,
        $redAfterSeconds
    );

    return [
        'id' => (int) $reminder['id'],
        'title' => $reminder['title'],
        'desired_date' => $reminder['desired_date'],
        'expected_period_seconds' => $expectedPeriodSeconds,
        'yellow_after_seconds' => $yellowAfterSeconds,
        'red_after_seconds' => $redAfterSeconds,
        'lower_yellow_below_seconds' => $lowerYellowBelowSeconds,
        'lower_red_below_seconds' => $lowerRedBelowSeconds,
        'created_at' => $reminder['created_at'],
        'updated_at' => $reminder['updated_at'],
        'last_completed_at' => $lastCompletedAt,
        'seconds_since_last_completion' => $secondsSinceLastCompletion,
        'seconds_since_desired_date' => $secondsSinceDesiredDate,
        'seconds_elapsed_for_severity' => $secondsElapsedForSeverity,
        'average_seconds_between_completions' => $averageSecondsBetweenCompletions,
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
app()->get('/', fn() => response()->json([
    'success' => true,
    'message' => 'Remindy API is running',
], 200));

app()->get('/endpoints', fn() => response()->json([
    'success' => true,
    'endpoints' => [
        'GET /me' => 'Get authenticated user info (requires Bearer token)',
        'POST /login' => 'Authenticate user with username and password and return access/refresh tokens',
        'POST /register' => 'Register a new user and return access/refresh tokens',
        'POST /logout' => 'Logout user (client should discard token)',
        'POST /forgot-password' => 'Request password reset link via email',
        'POST /reset-password' => 'Reset password using token from email',
        'GET /reminders' => 'List all reminders for authenticated user',
        'POST /reminders' => 'Create a new reminder for authenticated user',
        'GET /reminders/{id}' => 'Get details of a specific reminder',
        'PUT /reminders/{id}' => 'Update a specific reminder',
        'DELETE /reminders/{id}' => 'Delete a specific reminder',
        'POST /reminders/{id}/complete' => 'Mark a reminder as completed [args: completion_comment?]',
        'GET /reminders/{id}/completions' => 'List completions for a specific reminder',
    ]
], 200));


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
    $data = request()->get(['username', 'password']);

    $username = trim((string) ($data['username'] ?? ''));

    if ($username === '' || !$data['password']) {
        response()->json([
            'success' => false,
            'message' => 'Username and password are required',
            'error'   => 'invalid_input',
        ], 400);
        return;
    }

    $userRecord = db()->select('users', 'email')->where('username', $username)->first();

    if (!$userRecord) {
        response()->json([
            'success' => false,
            'message' => 'Invalid credentials',
            'error'   => 'invalid_credentials',
        ], 401);
        return;
    }

    if (auth()->login(['email' => strtolower(trim($userRecord['email'])), 'password' => $data['password']])) {
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
            'expected_period_seconds',
            'expected_period_days',
            'yellow_after_seconds',
            'yellow_after_days',
            'red_after_seconds',
            'red_after_days',
            'lower_yellow_below_seconds',
            'lower_yellow_below_days',
            'lower_red_below_seconds',
            'lower_red_below_days',
        ]);

        $title = trim((string) ($data['title'] ?? ''));
        $desiredDate = trim((string) ($data['desired_date'] ?? ''));
        $expectedPeriodSeconds = requestTimingSeconds($data, 'expected_period_seconds', 'expected_period_days');
        $yellowAfterSeconds = requestTimingSeconds($data, 'yellow_after_seconds', 'yellow_after_days', 2 * SECONDS_PER_DAY);
        $redAfterSeconds = requestTimingSeconds($data, 'red_after_seconds', 'red_after_days', 5 * SECONDS_PER_DAY);
        $lowerYellowBelowSeconds = requestTimingSeconds($data, 'lower_yellow_below_seconds', 'lower_yellow_below_days', 2 * SECONDS_PER_DAY);
        $lowerRedBelowSeconds = requestTimingSeconds($data, 'lower_red_below_seconds', 'lower_red_below_days', 1 * SECONDS_PER_DAY);

        if ($title === '') {
            response()->json([
                'success' => false,
                'message' => 'Title is required',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($expectedPeriodSeconds !== null && $expectedPeriodSeconds <= 0) {
            response()->json([
                'success' => false,
                'message' => 'Expected period must be a positive number of seconds',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($yellowAfterSeconds < 0 || $redAfterSeconds < 0 || $redAfterSeconds < $yellowAfterSeconds) {
            response()->json([
                'success' => false,
                'message' => 'Severity thresholds are invalid',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($lowerYellowBelowSeconds < 0 || $lowerRedBelowSeconds < 0 || $lowerRedBelowSeconds > $lowerYellowBelowSeconds) {
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
            'expected_period_seconds' => $expectedPeriodSeconds,
            'yellow_after_seconds' => $yellowAfterSeconds,
            'red_after_seconds' => $redAfterSeconds,
            'lower_yellow_below_seconds' => $lowerYellowBelowSeconds,
            'lower_red_below_seconds' => $lowerRedBelowSeconds,
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
            'expected_period_seconds',
            'expected_period_days',
            'yellow_after_seconds',
            'yellow_after_days',
            'red_after_seconds',
            'red_after_days',
            'lower_yellow_below_seconds',
            'lower_yellow_below_days',
            'lower_red_below_seconds',
            'lower_red_below_days',
        ]);

        $title = trim((string) ($data['title'] ?? $reminder['title']));
        $desiredDateRaw = array_key_exists('desired_date', $data) ? (string) $data['desired_date'] : $reminder['desired_date'];
        $desiredDate = trim((string) $desiredDateRaw);

        $expectedPeriodSeconds = array_key_exists('expected_period_seconds', $data) || array_key_exists('expected_period_days', $data)
            ? requestTimingSeconds($data, 'expected_period_seconds', 'expected_period_days')
            : reminderTimingSeconds($reminder, 'expected_period_seconds');

        $yellowAfterSeconds = array_key_exists('yellow_after_seconds', $data) || array_key_exists('yellow_after_days', $data)
            ? requestTimingSeconds($data, 'yellow_after_seconds', 'yellow_after_days', 2 * SECONDS_PER_DAY)
            : reminderTimingSeconds($reminder, 'yellow_after_seconds');

        $redAfterSeconds = array_key_exists('red_after_seconds', $data) || array_key_exists('red_after_days', $data)
            ? requestTimingSeconds($data, 'red_after_seconds', 'red_after_days', 5 * SECONDS_PER_DAY)
            : reminderTimingSeconds($reminder, 'red_after_seconds');
        $lowerYellowBelowSeconds = array_key_exists('lower_yellow_below_seconds', $data) || array_key_exists('lower_yellow_below_days', $data)
            ? requestTimingSeconds($data, 'lower_yellow_below_seconds', 'lower_yellow_below_days', 2 * SECONDS_PER_DAY)
            : reminderTimingSeconds($reminder, 'lower_yellow_below_seconds');
        $lowerRedBelowSeconds = array_key_exists('lower_red_below_seconds', $data) || array_key_exists('lower_red_below_days', $data)
            ? requestTimingSeconds($data, 'lower_red_below_seconds', 'lower_red_below_days', 1 * SECONDS_PER_DAY)
            : reminderTimingSeconds($reminder, 'lower_red_below_seconds');

        if ($title === '') {
            response()->json([
                'success' => false,
                'message' => 'Title is required',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($expectedPeriodSeconds !== null && $expectedPeriodSeconds <= 0) {
            response()->json([
                'success' => false,
                'message' => 'Expected period must be a positive number of seconds',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($yellowAfterSeconds < 0 || $redAfterSeconds < 0 || $redAfterSeconds < $yellowAfterSeconds) {
            response()->json([
                'success' => false,
                'message' => 'Severity thresholds are invalid',
                'error' => 'invalid_input',
            ], 400);
            return;
        }

        if ($lowerYellowBelowSeconds < 0 || $lowerRedBelowSeconds < 0 || $lowerRedBelowSeconds > $lowerYellowBelowSeconds) {
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
            'expected_period_seconds' => $expectedPeriodSeconds,
            'yellow_after_seconds' => $yellowAfterSeconds,
            'red_after_seconds' => $redAfterSeconds,
            'lower_yellow_below_seconds' => $lowerYellowBelowSeconds,
            'lower_red_below_seconds' => $lowerRedBelowSeconds,
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

function migrateSchema($dbConnection) {
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
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_reminders_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        '1>2' => "ALTER TABLE reminders
            ADD COLUMN lower_yellow_below_days INT UNSIGNED NOT NULL DEFAULT 2 AFTER red_after_days,
            ADD COLUMN lower_red_below_days INT UNSIGNED NOT NULL DEFAULT 1 AFTER lower_yellow_below_days",
        '2>3' => "ALTER TABLE reminders
            CHANGE COLUMN expected_period_days expected_period_seconds INT UNSIGNED NULL,
            CHANGE COLUMN yellow_after_days yellow_after_seconds INT UNSIGNED NOT NULL DEFAULT 172800,
            CHANGE COLUMN red_after_days red_after_seconds INT UNSIGNED NOT NULL DEFAULT 432000,
            CHANGE COLUMN lower_yellow_below_days lower_yellow_below_seconds INT UNSIGNED NOT NULL DEFAULT 172800,
            CHANGE COLUMN lower_red_below_days lower_red_below_seconds INT UNSIGNED NOT NULL DEFAULT 86400;
            UPDATE reminders
            SET expected_period_seconds = CASE
                    WHEN expected_period_seconds IS NULL THEN NULL
                    ELSE expected_period_seconds * 86400
                END,
                yellow_after_seconds = yellow_after_seconds * 86400,
                red_after_seconds = red_after_seconds * 86400,
                lower_yellow_below_seconds = lower_yellow_below_seconds * 86400,
                lower_red_below_seconds = lower_red_below_seconds * 86400",
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
        '1>2' => "ALTER TABLE reminder_completions ADD COLUMN completion_comment TEXT NULL AFTER completed_at",
    ]);

}