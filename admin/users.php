<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$allowedRoles = ["admin" => "Admin", "factory_user" => "Factory User", "cashier" => "Cashier", "accountant" => "Accountant"];
$allowedStatuses = ["active" => "Active", "inactive" => "Inactive"];

try {
    $stores = $pdo->query("SELECT location_id, location_name FROM locations WHERE status = 'active' AND location_type = 'store' ORDER BY location_name ASC")->fetchAll();
} catch (PDOException $e) {
    $stores = [];
}

try {
    $users = $pdo->query("
        SELECT u.*, l.location_name
        FROM users u LEFT JOIN locations l ON u.location_id = l.location_id
        ORDER BY u.user_id DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $users = [];
}

$roleBadgeKind = ["admin" => "purple", "factory_user" => "info", "cashier" => "ok", "accountant" => "warn"];

$navCrumb = 'Setup';
$navTitle = 'Staff Accounts';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">

        <?php require_once "../includes/back_link.php"; ?>

        <div class="toolbar">
            <span class="toolbar-meta"><?= count($users); ?> accounts</span>
            <div class="spacer"></div>
            <div class="search-box" style="width:240px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="userSearch" placeholder="Search staff…" oninput="filterUserRows()">
            </div>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openUserModal()">＋ New Account</button>
        </div>

        <div class="yz-table-wrap">
            <table class="yz-table">
                <thead>
                    <tr>
                        <th>Name</th><th>Login (Email / Username)</th><th>Role</th><th>Store</th><th>Status</th><th style="width:78px;"></th>
                    </tr>
                </thead>
                <tbody id="userRows">
                    <?php if (!empty($users)): ?>
                        <?php foreach ($users as $u): ?>
                            <?php
                            $statusKind = $u['status'] === 'active' ? 'ok' : 'off';
                            $identifier = $u['role'] === 'admin' ? $u['email'] : $u['username'];
                            $payload = htmlspecialchars(json_encode([
                                'id' => $u['user_id'],
                                'name' => $u['full_name'],
                                'email' => $u['email'],
                                'username' => $u['username'],
                                'role' => $u['role'],
                                'location_id' => $u['location_id'],
                                'status' => $u['status'],
                            ]), ENT_QUOTES);
                            ?>
                            <tr data-name="<?= htmlspecialchars(mb_strtolower($u['full_name'] . ' ' . $identifier . ' ' . ($u['location_name'] ?? ''))); ?>">
                                <td style="font-weight:600; font-size:13.5px;"><?= htmlspecialchars($u['full_name']); ?></td>
                                <td style="font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($identifier ?: '—'); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $roleBadgeKind[$u['role']] ?? 'info'; ?>"><?= $allowedRoles[$u['role']] ?? $u['role']; ?></span></td>
                                <td style="font-size:13px; color:var(--text-muted);"><?= $u['location_name'] ? htmlspecialchars($u['location_name']) : '—'; ?></td>
                                <td><span class="yz-badge yz-badge-<?= $statusKind; ?>"><?= $allowedStatuses[$u['status']] ?? $u['status']; ?></span></td>
                                <td class="text-end" style="white-space:nowrap;">
                                    <button type="button" class="icon-btn icon-btn-edit" title="Edit" onclick='openUserModal(<?= $payload; ?>)'>✎</button>
                                    <?php if ((int) $u['user_id'] !== 1): ?>
                                        <form method="POST" action="<?= BASE_URL; ?>actions/user_action.php" style="display:inline;" onsubmit="return confirmAction(this, { title: 'Deactivate this account?', message: 'This person will no longer be able to sign in.', confirmLabel: 'Deactivate' });">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int) $u['user_id']; ?>">
                                            <button type="submit" class="icon-btn icon-btn-delete" title="Deactivate">✕</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center" style="color:var(--text-faint); padding:32px 0;">No staff accounts yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <div class="yz-modal-overlay" id="userModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/user_action.php" id="userForm">
                <div class="yz-modal-header">
                    <div>
                        <h3 id="userModalTitle">New Account</h3>
                        <p>Cashiers are scoped to one store and only see that store's Touch POS</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('userModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" id="userAction" value="add">
                    <input type="hidden" name="user_id" id="userId" value="">

                    <div class="yz-field">
                        <label>Full name</label>
                        <input type="text" name="full_name" id="userName" placeholder="e.g. Rania Hage" required>
                    </div>
                    <div class="yz-row yz-row-2">
                        <div class="yz-field" style="margin-bottom:0;" id="emailField">
                            <label>Email</label>
                            <input type="email" name="email" id="userEmail" placeholder="name@youzarsifsweets.com">
                        </div>
                        <div class="yz-field" style="margin-bottom:0; display:none;" id="usernameField">
                            <label>Username</label>
                            <input type="text" name="username" id="userUsername" placeholder="e.g. rania.hage" pattern="[A-Za-z0-9_.]{3,50}">
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Password <span id="passwordHint" style="text-transform:none; font-weight:400;"></span></label>
                            <div class="yz-password-wrap">
                                <input type="password" name="password" id="userPassword" placeholder="••••••••">
                                <button type="button" class="yz-password-toggle" onclick="toggleYzPassword(this, 'userPassword')"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                            </div>
                        </div>
                    </div>
                    <div class="yz-row yz-row-2" style="margin-top:14px;">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Role</label>
                            <select name="role" id="userRole" onchange="toggleStoreField(); toggleIdentifierField();" required>
                                <?php foreach ($allowedRoles as $value => $label): ?>
                                    <option value="<?= $value; ?>"><?= $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Status</label>
                            <select name="status" id="userStatus" required>
                                <?php foreach ($allowedStatuses as $value => $label): ?>
                                    <option value="<?= $value; ?>"><?= $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="yz-field" id="storeField" style="margin-top:14px; display:none;">
                        <label>Assigned store</label>
                        <select name="location_id" id="userLocation">
                            <option value="">Select store</option>
                            <?php foreach ($stores as $s): ?>
                                <option value="<?= (int) $s['location_id']; ?>"><?= htmlspecialchars($s['location_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:11.5px; color:var(--text-faint); margin-top:6px;">Required for cashiers — determines which transferred products they see in Touch POS.</div>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('userModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleIdentifierField() {
            const role = document.getElementById('userRole').value;
            const emailField = document.getElementById('emailField');
            const usernameField = document.getElementById('usernameField');
            const emailInput = document.getElementById('userEmail');
            const usernameInput = document.getElementById('userUsername');
            if (role === 'admin') {
                emailField.style.display = '';
                usernameField.style.display = 'none';
                emailInput.setAttribute('required', 'required');
                usernameInput.removeAttribute('required');
                usernameInput.value = '';
            } else {
                emailField.style.display = 'none';
                usernameField.style.display = '';
                usernameInput.setAttribute('required', 'required');
                emailInput.removeAttribute('required');
                emailInput.value = '';
            }
        }

        function toggleStoreField() {
            const role = document.getElementById('userRole').value;
            const field = document.getElementById('storeField');
            const sel = document.getElementById('userLocation');
            if (role === 'cashier') {
                field.style.display = '';
                sel.setAttribute('required', 'required');
            } else {
                field.style.display = 'none';
                sel.removeAttribute('required');
                sel.value = '';
            }
        }

        function openUserModal(u) {
            document.getElementById('userForm').reset();
            const pwd = document.getElementById('userPassword');
            if (u) {
                document.getElementById('userModalTitle').textContent = 'Edit Account';
                document.getElementById('userAction').value = 'update';
                document.getElementById('userId').value = u.id;
                document.getElementById('userName').value = u.name;
                document.getElementById('userEmail').value = u.email || '';
                document.getElementById('userUsername').value = u.username || '';
                document.getElementById('userRole').value = u.role;
                document.getElementById('userStatus').value = u.status;
                document.getElementById('userLocation').value = u.location_id || '';
                pwd.removeAttribute('required');
                document.getElementById('passwordHint').textContent = '(leave blank to keep current)';
            } else {
                document.getElementById('userModalTitle').textContent = 'New Account';
                document.getElementById('userAction').value = 'add';
                document.getElementById('userId').value = '';
                pwd.setAttribute('required', 'required');
                document.getElementById('passwordHint').textContent = '';
            }
            toggleStoreField();
            toggleIdentifierField();
            openYzModal('userModal');
        }

        function filterUserRows() {
            var q = document.getElementById('userSearch').value.trim().toLowerCase();
            document.querySelectorAll('#userRows tr[data-name]').forEach(function (row) {
                row.style.display = row.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
