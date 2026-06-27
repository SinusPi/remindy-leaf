$(function () {
    let registerMode = false;
    let remindersCache = [];
    const completionsCache = {};

    let activeCompleteReminderId = null;
    let activeEditReminderId = null;
    let activeDeleteReminderId = null;

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

    $('#remindersList').on('click', '.complete-btn', function () {
        const id = Number($(this).data('id'));
        const reminder = findReminderById(id);
        if (!reminder) {
            showError('Reminder not found in current view');
            return;
        }

        activeCompleteReminderId = id;
        $('#completeReminderTitle').text('Completing: ' + reminder.title);
        $('#completeCommentInput').val('');
        openModal('#completeModal');
        $('#completeCommentInput').trigger('focus');
    });

    $('#completeCancelBtn').on('click', function () {
        closeModal('#completeModal');
        activeCompleteReminderId = null;
    });

    $('#completeSaveBtn').on('click', async function () {
        if (!activeCompleteReminderId) {
            closeModal('#completeModal');
            return;
        }

        try {
            await api('/reminders/' + activeCompleteReminderId + '/complete', 'POST', {
                completion_comment: $('#completeCommentInput').val().trim()
            }, true);
            closeModal('#completeModal');
            showSuccess('Reminder completed');
            activeCompleteReminderId = null;
            await loadReminders();
        } catch (e) {
            showError(e.message || 'Failed to complete reminder');
        }
    });

    $('#remindersList').on('click', '.delete-btn', function () {
        const id = Number($(this).data('id'));
        const reminder = findReminderById(id);
        if (!reminder) {
            showError('Reminder not found in current view');
            return;
        }

        activeDeleteReminderId = id;
        $('#deleteReminderText').text('Delete "' + reminder.title + '"? This cannot be undone.');
        openModal('#deleteModal');
    });

    $('#deleteCancelBtn').on('click', function () {
        closeModal('#deleteModal');
        activeDeleteReminderId = null;
    });

    $('#deleteConfirmBtn').on('click', async function () {
        if (!activeDeleteReminderId) {
            closeModal('#deleteModal');
            return;
        }

        try {
            await api('/reminders/' + activeDeleteReminderId, 'DELETE', null, true);
            closeModal('#deleteModal');
            activeDeleteReminderId = null;
            showSuccess('Reminder deleted');
            await loadReminders();
        } catch (e) {
            showError(e.message || 'Failed to delete reminder');
        }
    });

    $('#remindersList').on('click', '.edit-btn', function () {
        const id = Number($(this).data('id'));
        const reminder = findReminderById(id);
        if (!reminder) {
            showError('Reminder not found in current view');
            return;
        }

        activeEditReminderId = id;
        $('#editTitle').val(reminder.title || '');
        $('#editExpected').val(reminder.expected_period_days === null ? '' : reminder.expected_period_days);
        $('#editDesiredDate').val(reminder.desired_date || '');
        $('#editYellow').val(reminder.yellow_after_days);
        $('#editRed').val(reminder.red_after_days);
        openModal('#editModal');
        $('#editTitle').trigger('focus');
    });

    $('#editCancelBtn').on('click', function () {
        closeModal('#editModal');
        activeEditReminderId = null;
    });

    $('#editSaveBtn').on('click', async function () {
        if (!activeEditReminderId) {
            closeModal('#editModal');
            return;
        }

        const payload = {
            title: $('#editTitle').val().trim(),
            expected_period_days: $('#editExpected').val().trim() === '' ? '' : Number($('#editExpected').val()),
            desired_date: $('#editDesiredDate').val().trim(),
            yellow_after_days: Number($('#editYellow').val()),
            red_after_days: Number($('#editRed').val())
        };

        try {
            await api('/reminders/' + activeEditReminderId, 'PUT', payload, true);
            closeModal('#editModal');
            activeEditReminderId = null;
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

    $('.modal').on('click', function (event) {
        if (event.target === this) {
            closeModal('#' + $(this).attr('id'));
        }
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal('#completeModal');
            closeModal('#editModal');
            closeModal('#deleteModal');
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

    function findReminderById(id) {
        return remindersCache.find(function (r) {
            return Number(r.id) === Number(id);
        }) || null;
    }

    function openModal(selector) {
        $(selector).removeClass('hidden').attr('aria-hidden', 'false');
    }

    function closeModal(selector) {
        $(selector).addClass('hidden').attr('aria-hidden', 'true');
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
