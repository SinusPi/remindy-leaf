<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remindy</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(120deg, #e9f5ff 0%, #f9f6ef 100%);
            color: #1c2a38;
        }
        .page {
            max-width: 960px;
            margin: 0 auto;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #dce6ee;
            box-shadow: 0 12px 30px rgba(30, 70, 100, 0.08);
            padding: 18px;
            margin-bottom: 16px;
        }
        h1, h2, h3 { margin-top: 0; }
        .row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .row > div { flex: 1 1 180px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        input, button {
            width: 100%;
            padding: 9px 10px;
            border-radius: 8px;
            border: 1px solid #c7d6e2;
            font-size: 14px;
        }
        button {
            border: none;
            cursor: pointer;
            background: #0e7490;
            color: #fff;
            font-weight: 600;
        }
        .btn-muted { background: #64748b; }
        .btn-danger { background: #b91c1c; }
        .btn-ok { background: #15803d; }
        .small { font-size: 12px; color: #526473; }
        .hidden { display: none; }
        .msg {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .msg-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .msg-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .reminder-item {
            border: 1px solid #d6e3ec;
            border-left: 8px solid #94a3b8;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
            background: #fff;
        }
        .sev-green { border-left-color: #16a34a; }
        .sev-yellow { border-left-color: #ca8a04; background: #fffbeb; }
        .sev-red { border-left-color: #dc2626; background: #fef2f2; }
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .actions button { width: auto; padding: 8px 12px; }
        .history {
            margin-top: 10px;
            background: #f8fbff;
            border: 1px solid #d9e7f2;
            border-radius: 8px;
            padding: 10px;
        }
        .history-item {
            border-bottom: 1px dashed #d3e0ea;
            padding: 8px 0;
        }
        .history-item:last-child {
            border-bottom: none;
        }
        .toolbar {
            display: grid;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }
        select {
            width: 100%;
            padding: 9px 10px;
            border-radius: 8px;
            border: 1px solid #c7d6e2;
            font-size: 14px;
            background: #fff;
        }
        @media (max-width: 720px) {
            .page { padding: 12px; }
            .row > div { flex: 1 1 100%; }
            .toolbar { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="page">
    <div id="globalMessage"></div>

    <section id="authPanel" class="card">
        <h1>Remindy</h1>
        <p class="small">Log in or register to manage your personal reminders.</p>

        <div class="row">
            <div>
                <label for="authEmail">Email</label>
                <input id="authEmail" type="email" required>
            </div>
            <div>
                <label for="authPassword">Password</label>
                <input id="authPassword" type="password" required>
            </div>
            <div id="usernameField" class="hidden">
                <label for="authUsername">Username</label>
                <input id="authUsername" type="text">
            </div>
        </div>

        <div class="actions">
            <button id="loginBtn">Login</button>
            <button id="registerBtn" class="btn-muted">Register Mode</button>
            <button id="submitRegisterBtn" class="hidden">Create Account</button>
        </div>
    </section>

    <section id="appPanel" class="hidden">
        <div class="card">
            <h2>Account</h2>
            <div id="userInfo" class="small"></div>
            <div class="actions">
                <button id="refreshBtn" class="btn-muted">Refresh</button>
                <button id="logoutBtn" class="btn-danger">Logout</button>
            </div>
        </div>

        <div class="card">
            <h2>Create Reminder</h2>
            <div class="row">
                <div>
                    <label for="rTitle">Title</label>
                    <input id="rTitle" type="text" placeholder="water plants">
                </div>
                <div>
                    <label for="rExpected">Expected period (days)</label>
                    <input id="rExpected" type="number" min="1" placeholder="3">
                </div>
                <div>
                    <label for="rDesiredDate">Desired date</label>
                    <input id="rDesiredDate" type="date">
                </div>
            </div>
            <div class="row">
                <div>
                    <label for="rYellow">Yellow after days</label>
                    <input id="rYellow" type="number" min="0" value="2">
                </div>
                <div>
                    <label for="rRed">Red after days</label>
                    <input id="rRed" type="number" min="0" value="5">
                </div>
                <div>
                    <label>&nbsp;</label>
                    <button id="createReminderBtn">Add Reminder</button>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Your Reminders</h2>
            <div class="toolbar">
                <div>
                    <label for="filterSeverity">Filter severity</label>
                    <select id="filterSeverity">
                        <option value="all">All</option>
                        <option value="green">Green</option>
                        <option value="yellow">Yellow</option>
                        <option value="red">Red</option>
                    </select>
                </div>
                <div>
                    <label for="filterType">Filter type</label>
                    <select id="filterType">
                        <option value="all">All</option>
                        <option value="date">Date-based</option>
                        <option value="period">Period-based</option>
                    </select>
                </div>
                <div>
                    <label for="sortBy">Sort</label>
                    <select id="sortBy">
                        <option value="severity_desc">Severity (red first)</option>
                        <option value="days_elapsed_desc">Most overdue</option>
                        <option value="days_elapsed_asc">Least overdue</option>
                        <option value="avg_interval_desc">Highest average interval</option>
                        <option value="title_asc">Title A-Z</option>
                        <option value="title_desc">Title Z-A</option>
                    </select>
                </div>
            </div>
            <div id="remindersList"></div>
        </div>
    </section>
</div>

<script>
$(function () {
    let registerMode = false;
    let remindersCache = [];
    const completionsCache = {};

    const token = localStorage.getItem('access_token');
    if (token) {
        bootApp();
    }

    $('#registerBtn').on('click', function () {
        registerMode = !registerMode;
        $('#usernameField').toggleClass('hidden', !registerMode);
        $('#submitRegisterBtn').toggleClass('hidden', !registerMode);
        $('#loginBtn').toggleClass('hidden', registerMode);
        $('#registerBtn').text(registerMode ? 'Login Mode' : 'Register Mode');
        clearMessage();
    });

    $('#loginBtn').on('click', async function () {
        try {
            const payload = {
                email: $('#authEmail').val().trim(),
                password: $('#authPassword').val()
            };
            const res = await api('/login', 'POST', payload, false);
            onAuthSuccess(res);
        } catch (e) {
            showError(e.message || 'Login failed');
        }
    });

    $('#submitRegisterBtn').on('click', async function () {
        try {
            const password = $('#authPassword').val();
            const payload = {
                username: $('#authUsername').val().trim(),
                email: $('#authEmail').val().trim(),
                password: password,
                password_confirm: password
            };
            const res = await api('/register', 'POST', payload, false);
            onAuthSuccess(res);
        } catch (e) {
            showError(e.message || 'Registration failed');
        }
    });

    $('#logoutBtn').on('click', function () {
        localStorage.removeItem('access_token');
        localStorage.removeItem('refresh_token');
        $('#appPanel').addClass('hidden');
        $('#authPanel').removeClass('hidden');
        showSuccess('Logged out');
    });

    $('#refreshBtn').on('click', function () {
        bootApp();
    });

    $('#filterSeverity, #filterType, #sortBy').on('change', function () {
        renderReminders(remindersCache);
    });

    $('#createReminderBtn').on('click', async function () {
        try {
            const payload = {
                title: $('#rTitle').val().trim(),
                expected_period_days: $('#rExpected').val() || null,
                desired_date: $('#rDesiredDate').val() || null,
                yellow_after_days: $('#rYellow').val() || 2,
                red_after_days: $('#rRed').val() || 5
            };

            await api('/reminders', 'POST', payload, true);
            $('#rTitle').val('');
            $('#rExpected').val('');
            $('#rDesiredDate').val('');
            showSuccess('Reminder created');
            await loadReminders();
        } catch (e) {
            showError(e.message || 'Failed to create reminder');
        }
    });

    $('#remindersList').on('click', '.complete-btn', async function () {
        const id = $(this).data('id');
        const completionComment = prompt('Optional completion comment:', '');
        if (completionComment === null) {
            return;
        }
        try {
            await api('/reminders/' + id + '/complete', 'POST', {
                completion_comment: completionComment.trim()
            }, true);
            showSuccess('Reminder completed');
            await loadReminders();
        } catch (e) {
            showError(e.message || 'Failed to complete reminder');
        }
    });

    $('#remindersList').on('click', '.delete-btn', async function () {
        const id = $(this).data('id');
        if (!confirm('Delete this reminder?')) {
            return;
        }
        try {
            await api('/reminders/' + id, 'DELETE', null, true);
            showSuccess('Reminder deleted');
            await loadReminders();
        } catch (e) {
            showError(e.message || 'Failed to delete reminder');
        }
    });

    $('#remindersList').on('click', '.edit-btn', async function () {
        const id = $(this).data('id');
        const title = prompt('New title (leave empty to keep current):');
        const expected = prompt('Expected period in days (empty = none):');
        const desired = prompt('Desired date YYYY-MM-DD (empty = none):');
        const yellow = prompt('Yellow threshold in days:');
        const red = prompt('Red threshold in days:');

        const payload = {};
        if (title !== null && title.trim() !== '') payload.title = title.trim();
        if (expected !== null) payload.expected_period_days = expected.trim() === '' ? '' : Number(expected);
        if (desired !== null) payload.desired_date = desired.trim();
        if (yellow !== null && yellow.trim() !== '') payload.yellow_after_days = Number(yellow);
        if (red !== null && red.trim() !== '') payload.red_after_days = Number(red);

        try {
            await api('/reminders/' + id, 'PUT', payload, true);
            showSuccess('Reminder updated');
            await loadReminders();
        } catch (e) {
            showError(e.message || 'Failed to update reminder');
        }
    });

    $('#remindersList').on('click', '.history-btn', async function () {
        const id = $(this).data('id');
        const target = $('#history-' + id);

        if (!target.hasClass('hidden')) {
            target.addClass('hidden').html('');
            return;
        }

        try {
            const history = await getCompletionsForReminder(id, true);
            target.html(renderHistoryHtml(history)).removeClass('hidden');
        } catch (e) {
            showError(e.message || 'Failed to load completion history');
        }
    });

    async function bootApp() {
        try {
            const me = await api('/me', 'GET', null, true);
            $('#userInfo').text(
                'Logged in as ' + me.user.username + ' (' + me.user.email + ')'
            );
            $('#authPanel').addClass('hidden');
            $('#appPanel').removeClass('hidden');
            await loadReminders();
            clearMessage();
        } catch (e) {
            localStorage.removeItem('access_token');
            $('#appPanel').addClass('hidden');
            $('#authPanel').removeClass('hidden');
            showError('Session expired. Please login again.');
        }
    }

    async function loadReminders() {
        const res = await api('/reminders', 'GET', null, true);
        remindersCache = res.reminders || [];
        renderReminders(remindersCache);
    }

    function renderReminders(inputList) {
        let list = inputList.slice();

        const sevFilter = $('#filterSeverity').val();
        if (sevFilter && sevFilter !== 'all') {
            list = list.filter(function (r) {
                return r.current_severity === sevFilter;
            });
        }

        const typeFilter = $('#filterType').val();
        if (typeFilter === 'date') {
            list = list.filter(function (r) {
                return !!r.desired_date;
            });
        }
        if (typeFilter === 'period') {
            list = list.filter(function (r) {
                return r.expected_period_days !== null;
            });
        }

        const sortBy = $('#sortBy').val();
        list.sort(function (a, b) {
            if (sortBy === 'title_asc') return compareString(a.title, b.title);
            if (sortBy === 'title_desc') return compareString(b.title, a.title);
            if (sortBy === 'days_elapsed_asc') return numberOrNegOne(a.days_elapsed_for_severity) - numberOrNegOne(b.days_elapsed_for_severity);
            if (sortBy === 'days_elapsed_desc') return numberOrNegOne(b.days_elapsed_for_severity) - numberOrNegOne(a.days_elapsed_for_severity);
            if (sortBy === 'avg_interval_desc') return numberOrNegOne(b.average_days_between_completions) - numberOrNegOne(a.average_days_between_completions);
            return severityRank(b.current_severity) - severityRank(a.current_severity);
        });

        if (!list.length) {
            $('#remindersList').html('<p class="small">No reminders match the current filters.</p>');
            return;
        }

        const html = list.map(function (r) {
            const sevClass = r.current_severity === 'red'
                ? 'sev-red'
                : (r.current_severity === 'yellow' ? 'sev-yellow' : 'sev-green');

            return `
                <div class="reminder-item ${sevClass}">
                    <h3>${escapeHtml(r.title)}</h3>
                    <div class="small">Current severity: <strong>${escapeHtml(r.current_severity)}</strong></div>
                    <div class="small">Average severity: <strong>${escapeHtml(r.average_severity || 'n/a')}</strong></div>
                    <div class="small">Last completed: ${escapeHtml(formatDateTime(r.last_completed_at))}</div>
                    <div class="small">Days since last completion: ${escapeHtml(formatNumber(r.days_since_last_completion))}</div>
                    <div class="small">Desired date: ${escapeHtml(r.desired_date || 'none')}</div>
                    <div class="small">Expected period: ${escapeHtml(formatNumber(r.expected_period_days))} day(s)</div>
                    <div class="small">Average between completions: ${escapeHtml(formatNumber(r.average_days_between_completions))} day(s)</div>
                    <div class="small">Thresholds: yellow ${escapeHtml(String(r.yellow_after_days))} / red ${escapeHtml(String(r.red_after_days))}</div>
                    <div class="actions">
                        <button class="btn-ok complete-btn" data-id="${r.id}">Complete</button>
                        <button class="btn-muted history-btn" data-id="${r.id}">History</button>
                        <button class="btn-muted edit-btn" data-id="${r.id}">Edit</button>
                        <button class="btn-danger delete-btn" data-id="${r.id}">Delete</button>
                    </div>
                    <div id="history-${r.id}" class="history hidden"></div>
                </div>
            `;
        }).join('');

        $('#remindersList').html(html);
    }

    function onAuthSuccess(res) {
        localStorage.setItem('access_token', res.access_token);
        if (res.refresh_token) {
            localStorage.setItem('refresh_token', res.refresh_token);
        }
        bootApp();
    }

    function api(url, method, payload, withAuth) {
        return new Promise(function (resolve, reject) {
            $.ajax({
                url: url,
                type: method,
                data: payload ? JSON.stringify(payload) : undefined,
                contentType: payload ? 'application/json' : undefined,
                dataType: 'json',
                headers: withAuth ? { Authorization: 'Bearer ' + localStorage.getItem('access_token') } : {},
                success: function (response) {
                    if (response && response.success) {
                        resolve(response);
                        return;
                    }
                    reject(new Error(response && response.message ? response.message : 'Request failed'));
                },
                error: function (xhr) {
                    const message = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : 'Request failed';
                    reject(new Error(message));
                }
            });
        });
    }

    async function getCompletionsForReminder(id, forceRefresh) {
        if (!forceRefresh && completionsCache[id]) {
            return completionsCache[id];
        }

        const res = await api('/reminders/' + id + '/completions', 'GET', null, true);
        completionsCache[id] = res.completions || [];
        return completionsCache[id];
    }

    function renderHistoryHtml(items) {
        if (!items.length) {
            return '<div class="small">No completion events yet.</div>';
        }

        return items.map(function (entry) {
            const comment = entry.completion_comment ? escapeHtml(entry.completion_comment) : '<em>No comment</em>';
            return `
                <div class="history-item">
                    <div class="small"><strong>${escapeHtml(formatDateTime(entry.completed_at))}</strong></div>
                    <div class="small">Comment: ${comment}</div>
                </div>
            `;
        }).join('');
    }

    function severityRank(color) {
        if (color === 'red') return 3;
        if (color === 'yellow') return 2;
        return 1;
    }

    function numberOrNegOne(value) {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return -1;
        }
        return Number(value);
    }

    function compareString(a, b) {
        return String(a || '').localeCompare(String(b || ''));
    }

    function showError(message) {
        $('#globalMessage').html('<div class="msg msg-error">' + escapeHtml(message) + '</div>');
    }

    function showSuccess(message) {
        $('#globalMessage').html('<div class="msg msg-success">' + escapeHtml(message) + '</div>');
    }

    function clearMessage() {
        $('#globalMessage').html('');
    }

    function formatDateTime(value) {
        if (!value) return 'never';
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString();
    }

    function formatNumber(value) {
        return value === null || value === undefined ? 'n/a' : String(value);
    }

    function escapeHtml(text) {
        return String(text)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
});
</script>
</body>
</html>
