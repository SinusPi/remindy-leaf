<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remindy - Login</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .container {
            max-width: 400px;
            width: 100%;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 2rem;
        }
        h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            color: #1f2937;
            transition: all 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-group.register-group {
            display: none;
        }
        .btn {
            width: 100%;
            padding: 0.875rem 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #1f2937;
            margin-top: 0.5rem;
        }
        .btn-secondary:hover:not(:disabled) {
            background: #d1d5db;
        }
        .link-toggle {
            text-align: center;
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 1rem;
        }
        .link-toggle a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }
        .link-toggle a:hover {
            text-decoration: underline;
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .alert-success {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .user-panel {
            display: none;
        }
        .user-info {
            background: #f9fafb;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .user-info h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
        }
        .user-field {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.875rem;
        }
        .user-field:last-child {
            border-bottom: none;
        }
        .user-field strong {
            color: #6b7280;
            font-weight: 600;
        }
        .user-field span {
            color: #1f2937;
            word-break: break-all;
        }
        .loading {
            opacity: 0.5;
            pointer-events: none;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div id="loginPanel">
            <h1>Remindy</h1>
            <div id="message"></div>

            <form id="loginForm">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn" id="loginBtn">Sign in</button>
            </form>

            <form id="registerForm" style="display: none;">
                <div class="form-group">
                    <label for="regUsername">Username</label>
                    <input type="text" id="regUsername" name="username" required>
                </div>
                <div class="form-group">
                    <label for="regEmail">Email</label>
                    <input type="email" id="regEmail" name="email" required>
                </div>
                <div class="form-group">
                    <label for="regPassword">Password</label>
                    <input type="password" id="regPassword" name="password" required minlength="8">
                </div>
                <div class="form-group">
                    <label for="regPasswordConfirm">Confirm Password</label>
                    <input type="password" id="regPasswordConfirm" name="password_confirm" required minlength="8">
                </div>
                <button type="submit" class="btn" id="registerBtn">Create account</button>
            </form>

            <div class="link-toggle">
                <span id="toggleText">Don't have an account? <a id="toggleLink" href="#">Sign up</a></span>
            </div>
        </div>

        <div id="userPanel" class="user-panel">
            <h1>Welcome!</h1>
            <div id="message2"></div>
            <div class="user-info" id="userInfo">
                <!-- User data will be populated here -->
            </div>
            <button id="logoutBtn" class="btn btn-secondary">Sign out</button>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Get stored token
    const token = localStorage.getItem('access_token');
    
    // If token exists, try to load user data
    if (token) {
        loadUser();
    }

    // Toggle between login and register
    $('#toggleLink').click(function(e) {
        e.preventDefault();
        $('#loginForm').toggle();
        $('#registerForm').toggle();
        $('#toggleText').html($('#loginForm').is(':visible') 
            ? "Don't have an account? <a id=\"toggleLink\" href=\"#\">Sign up</a>" 
            : "Already have an account? <a id=\"toggleLink\" href=\"#\">Sign in</a>");
        $('#toggleLink').click(arguments.callee);
        clearMessages();
    });

    // Login form submission
    $('#loginForm').on('submit', function(e) {
        e.preventDefault();
        const email = $('#email').val();
        const password = $('#password').val();

        $.ajax({
            url: '/login',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ email, password }),
            dataType: 'json',
            beforeSend: function() {
                $('#loginBtn').prop('disabled', true).text('Signing in...');
                clearMessages();
            },
            success: function(response) {
                if (response.success) {
                    // Store token
                    localStorage.setItem('access_token', response.access_token);
                    if (response.refresh_token) {
                        localStorage.setItem('refresh_token', response.refresh_token);
                    }
                    // Load user data
                    loadUser();
                } else {
                    showError(response.message || 'Login failed');
                }
            },
            error: function(xhr) {
                const response = xhr.responseJSON;
                showError(response?.message || 'An error occurred');
            },
            complete: function() {
                $('#loginBtn').prop('disabled', false).text('Sign in');
            }
        });
    });

    // Register form submission
    $('#registerForm').on('submit', function(e) {
        e.preventDefault();
        const username = $('#regUsername').val();
        const email = $('#regEmail').val();
        const password = $('#regPassword').val();
        const password_confirm = $('#regPasswordConfirm').val();

        if (password !== password_confirm) {
            showError('Passwords do not match');
            return;
        }

        $.ajax({
            url: '/register',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ username, email, password, password_confirm }),
            dataType: 'json',
            beforeSend: function() {
                $('#registerBtn').prop('disabled', true).text('Creating account...');
                clearMessages();
            },
            success: function(response) {
                if (response.success) {
                    localStorage.setItem('access_token', response.access_token);
                    if (response.refresh_token) {
                        localStorage.setItem('refresh_token', response.refresh_token);
                    }
                    loadUser();
                } else {
                    showError(response.message || 'Registration failed');
                }
            },
            error: function(xhr) {
                const response = xhr.responseJSON;
                showError(response?.message || 'An error occurred');
            },
            complete: function() {
                $('#registerBtn').prop('disabled', false).text('Create account');
            }
        });
    });

    // Logout
    $('#logoutBtn').click(function() {
        localStorage.removeItem('access_token');
        localStorage.removeItem('refresh_token');
        $('#loginPanel').show();
        $('#userPanel').hide();
        $('#loginForm')[0].reset();
        $('#registerForm')[0].reset();
        $('#loginForm').show();
        $('#registerForm').hide();
        clearMessages();
    });

    function loadUser() {
        const token = localStorage.getItem('access_token');
        if (!token) {
            return;
        }

        $.ajax({
            url: '/me',
            type: 'GET',
            headers: {
                'Authorization': 'Bearer ' + token
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.user) {
                    displayUser(response.user);
                    $('#loginPanel').hide();
                    $('#userPanel').show();
                } else {
                    handleAuthError();
                }
            },
            error: function() {
                handleAuthError();
            }
        });
    }

    function displayUser(user) {
        const html = `
            <div class="user-field">
                <strong>ID:</strong>
                <span>${escapeHtml(user.id)}</span>
            </div>
            <div class="user-field">
                <strong>Username:</strong>
                <span>${escapeHtml(user.username)}</span>
            </div>
            <div class="user-field">
                <strong>Email:</strong>
                <span>${escapeHtml(user.email)}</span>
            </div>
            <div class="user-field">
                <strong>Member Since:</strong>
                <span>${user.created_at ? new Date(user.created_at).toLocaleDateString() : 'N/A'}</span>
            </div>
        `;
        $('#userInfo').html(html);
        showSuccess('Successfully logged in!', '#message2');
    }

    function handleAuthError() {
        localStorage.removeItem('access_token');
        localStorage.removeItem('refresh_token');
        $('#loginPanel').show();
        $('#userPanel').hide();
        showError('Session expired. Please log in again.');
    }

    function showError(message) {
        $('#message').html(`<div class="alert alert-error">${escapeHtml(message)}</div>`);
    }

    function showSuccess(message, target = '#message') {
        $(target).html(`<div class="alert alert-success">${escapeHtml(message)}</div>`);
    }

    function clearMessages() {
        $('#message').html('');
        $('#message2').html('');
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
});
</script>

</body>
</html>
