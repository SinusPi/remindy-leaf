<?php
function durationUnitOptions($selectedUnit = 'day') {
    $units = [
        'minute' => 'minutes',
        'hour' => 'hours',
        'day' => 'days',
        'week' => 'weeks',
        'second' => 'seconds (advanced)',
    ];

    $html = '';
    foreach ($units as $value => $label) {
        $selected = $value === $selectedUnit ? ' selected' : '';
        $html .= '<option value="' . $value . '"' . $selected . '>' . $label . '</option>';
    }

    return $html;
}

function renderDurationField($idPrefix, $label, $defaultValue = '', $defaultUnit = 'day', $allowEmpty = false) {
    $required = $allowEmpty ? '' : ' required';
    $placeholder = $allowEmpty ? 'placeholder="optional"' : '';

    return '
    <div>
        <label for="' . $idPrefix . 'Value">' . $label . '</label>
        <div class="period-field">
            <input id="' . $idPrefix . 'Value" type="number" min="0" step="1" value="' . htmlspecialchars((string) $defaultValue, ENT_QUOTES, 'UTF-8') . '" ' . $placeholder . $required . '>
            <select id="' . $idPrefix . 'Unit">' . durationUnitOptions($defaultUnit) . '</select>
        </div>
    </div>';
}
?>
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
        input, button, textarea {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #bdd2e2;
            font-size: 14px;
            transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.12s ease;
        }
        input, select, textarea {
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
            color: #1c2a38;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #2b7aa0;
            box-shadow: 0 0 0 3px rgba(43, 122, 160, 0.18);
        }
        button {
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #0e7490 0%, #115e7d 100%);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 6px 16px rgba(14, 116, 144, 0.25);
        }
        button:hover {
            transform: translateY(-1px);
            filter: brightness(1.02);
        }
        button:active {
            transform: translateY(0);
        }
        .btn-muted { background: linear-gradient(135deg, #64748b 0%, #475569 100%); }
        .btn-danger { background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%); }
        .btn-ok { background: linear-gradient(135deg, #15803d 0%, #166534 100%); }
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
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #bdd2e2;
            font-size: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
        }
        .period-field {
            display: grid;
            grid-template-columns: minmax(110px, 1fr) minmax(140px, 1fr);
            gap: 8px;
        }
        .threshold-list {
            display: grid;
            gap: 8px;
        }
        .threshold-item {
            border: 1px solid #d6e3ec;
            border-radius: 10px;
            background: #f8fbff;
            padding: 8px;
            display: grid;
            grid-template-columns: minmax(150px, 1.2fr) minmax(100px, 1fr) minmax(100px, 1fr) minmax(160px, 1fr) auto;
            gap: 8px;
            align-items: end;
        }
        .threshold-item button {
            width: auto;
            min-width: 72px;
            padding: 8px 10px;
        }
        .threshold-summary-list {
            margin: 0;
            padding-left: 18px;
        }
        .threshold-summary-list li {
            margin-bottom: 2px;
        }
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(6, 21, 34, 0.52);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px;
            z-index: 50;
        }
        .modal.hidden {
            display: none;
        }
        .modal-card {
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #d6e6f1;
            box-shadow: 0 22px 44px rgba(10, 30, 42, 0.24);
            padding: 16px;
        }
        .modal-title {
            margin: 0 0 12px;
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            justify-content: flex-end;
        }
        .modal-actions button {
            width: auto;
            min-width: 110px;
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        @media (max-width: 720px) {
            .page { padding: 12px; }
            .row > div { flex: 1 1 100%; }
            .toolbar { grid-template-columns: 1fr; }
            .threshold-item {
                grid-template-columns: 1fr;
            }
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
                <label for="authUsernameLogin">Username</label>
                <input id="authUsernameLogin" type="text" required>
            </div>
            <div>
                <label for="authPassword">Password</label>
                <input id="authPassword" type="password" required>
            </div>
            <div id="passwordConfirmField" class="hidden">
                <label for="authPasswordConfirm">Confirm Password</label>
                <input id="authPasswordConfirm" type="password">
            </div>
            <div id="usernameField" class="hidden">
                <label for="authEmail">Email</label>
                <input id="authEmail" type="email">
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
                <?php echo renderDurationField('rExpected', 'Expected period', 3, 'day', true); ?>
                <div>
                    <label for="rDesiredDate">Desired date</label>
                    <input id="rDesiredDate" type="date">
                </div>
            </div>
            <div class="row">
                <div style="flex: 1 1 100%;">
                    <label>Thresholds</label>
                    <div id="rThresholds" class="threshold-list"></div>
                    <div class="actions" style="margin-top: 8px;">
                        <button id="addCreateThresholdBtn" type="button" class="btn-muted">Add Threshold</button>
                    </div>
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
                        <option value="seconds_elapsed_desc">Most overdue</option>
                        <option value="seconds_elapsed_asc">Least overdue</option>
                        <option value="avg_interval_desc">Highest average interval</option>
                        <option value="title_asc">Title A-Z</option>
                        <option value="title_desc">Title Z-A</option>
                    </select>
                </div>
            </div>
            <div id="remindersList"></div>
        </div>

        <template id="reminderItemTemplate">
            <div class="reminder-item">
                <h3 class="r-title"></h3>
                <div class="small">Current severity: <strong class="r-current-severity"></strong></div>
                <div class="small">Average severity: <strong class="r-average-severity"></strong></div>
                <div class="small">Last completed: <span class="r-last-completed"></span></div>
                <div class="small">Time since last completion: <span class="r-seconds-since"></span></div>
                <div class="small">Desired date: <span class="r-desired-date"></span></div>
                <div class="small">Expected period: <span class="r-expected-period"></span></div>
                <div class="small">Average between completions: <span class="r-average-between"></span></div>
                <div class="small">Thresholds:</div>
                <div class="small r-thresholds"></div>
                <div class="actions">
                    <button class="btn-ok complete-btn" type="button">Complete</button>
                    <button class="btn-muted history-btn" type="button">History</button>
                    <button class="btn-muted edit-btn" type="button">Edit</button>
                    <button class="btn-danger delete-btn" type="button">Delete</button>
                </div>
                <div class="history hidden r-history"></div>
            </div>
        </template>
    </section>

    <section id="completeModal" class="modal hidden" aria-hidden="true">
        <div class="modal-card">
            <h3 class="modal-title">Complete Reminder</h3>
            <p id="completeReminderTitle" class="small"></p>
            <label for="completeCommentInput">Comment (optional)</label>
            <textarea id="completeCommentInput" placeholder="Example: watered all plants and added fertilizer"></textarea>
            <div class="modal-actions">
                <button id="completeCancelBtn" class="btn-muted">Cancel</button>
                <button id="completeSaveBtn" class="btn-ok">Save Completion</button>
            </div>
        </div>
    </section>

    <section id="editModal" class="modal hidden" aria-hidden="true">
        <div class="modal-card">
            <h3 class="modal-title">Edit Reminder</h3>
            <div class="row">
                <div>
                    <label for="editTitle">Title</label>
                    <input id="editTitle" type="text">
                </div>
                <?php echo renderDurationField('editExpected', 'Expected period', '', 'day', true); ?>
                <div>
                    <label for="editDesiredDate">Desired date</label>
                    <input id="editDesiredDate" type="date">
                </div>
            </div>
            <div class="row">
                <div style="flex: 1 1 100%;">
                    <label>Thresholds</label>
                    <div id="editThresholds" class="threshold-list"></div>
                    <div class="actions" style="margin-top: 8px;">
                        <button id="addEditThresholdBtn" type="button" class="btn-muted">Add Threshold</button>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button id="editCancelBtn" class="btn-muted">Cancel</button>
                <button id="editSaveBtn">Save Changes</button>
            </div>
        </div>
    </section>

    <section id="deleteModal" class="modal hidden" aria-hidden="true">
        <div class="modal-card">
            <h3 class="modal-title">Delete Reminder</h3>
            <p id="deleteReminderText" class="small"></p>
            <div class="modal-actions">
                <button id="deleteCancelBtn" class="btn-muted">Cancel</button>
                <button id="deleteConfirmBtn" class="btn-danger">Delete</button>
            </div>
        </div>
    </section>
</div>
<script src="app.js"></script>
</body>
</html>
