<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$movementTypes = [
    "purchase_in" => "Purchase In",
    "adjustment_in" => "Adjustment +",
    "adjustment_out" => "Adjustment −",
    "waste_out" => "Waste Out",
    "return_in" => "Return In"
];

$movementBadgeKind = [
    "purchase_in" => "ok",
    "production_consumption" => "info",
    "waste_out" => "danger",
    "adjustment_in" => "warn",
    "adjustment_out" => "warn",
    "return_in" => "purple"
];
$movementBadgeLabel = [
    "purchase_in" => "Purchase In",
    "production_consumption" => "Production",
    "waste_out" => "Waste",
    "adjustment_in" => "Adjustment +",
    "adjustment_out" => "Adjustment −",
    "return_in" => "Return"
];

try {
    $itemsStmt = $pdo->query("
        SELECT i.item_id, i.item_name, i.item_code, u.abbreviation
        FROM items i INNER JOIN units u ON i.unit_id = u.unit_id
        WHERE i.status = 'active' ORDER BY i.item_name ASC
    ");
    $items = $itemsStmt->fetchAll();

    $locationsStmt = $pdo->query("SELECT location_id, location_name FROM locations WHERE status = 'active' ORDER BY location_name ASC");
    $locations = $locationsStmt->fetchAll();
} catch (PDOException $e) {
    $items = [];
    $locations = [];
}

try {
    $movementsStmt = $pdo->query("
        SELECT sm.*, i.item_name, u.abbreviation, l.location_name
        FROM stock_movements sm
        INNER JOIN items i ON sm.item_id = i.item_id
        INNER JOIN units u ON i.unit_id = u.unit_id
        INNER JOIN locations l ON sm.location_id = l.location_id
        ORDER BY sm.movement_id DESC
        LIMIT 100
    ");
    $movements = $movementsStmt->fetchAll();
} catch (PDOException $e) {
    $movements = [];
}

$navCrumb = 'Inventory';
$navTitle = 'Stock Movements';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta">Stock ledger · calculated, never stored</span>
            <div class="spacer"></div>
            <select id="movementTypeFilter" onchange="filterMovementRows()" style="border:1px solid var(--border-input); border-radius:9999px; padding:9px 14px; font-family:var(--font-body); font-size:13px; color:var(--text); background:#fff;">
                <option value="">All Types</option>
                <option value="production_consumption">Production</option>
                <?php foreach ($movementTypes as $value => $label): ?>
                    <option value="<?= $value; ?>"><?= $label; ?></option>
                <?php endforeach; ?>
            </select>
            <select id="movementLocationFilter" onchange="filterMovementRows()" style="border:1px solid var(--border-input); border-radius:9999px; padding:9px 14px; font-family:var(--font-body); font-size:13px; color:var(--text); background:#fff;">
                <option value="">All Locations</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= htmlspecialchars($location['location_name']); ?>"><?= htmlspecialchars($location['location_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="search-box" style="width:220px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="movementSearch" placeholder="Search movements…" oninput="filterMovementRows()">
            </div>
            <button type="button" class="btn-yz btn-yz-outline" onclick="resetMovementFilters()">Reset</button>
            <button type="button" class="btn-yz btn-yz-caramel" onclick="openMovementModal(true)">↓ Purchase In</button>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openMovementModal(false)">＋ Movement</button>
        </div>

        <div class="yz-table-wrap">
            <table class="yz-table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Total</th>
                        <th>Ref No</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody id="movementRows">
                    <?php if (!empty($movements)): ?>
                        <?php foreach ($movements as $m): ?>
                            <?php
                            $isVoided = $m['status'] === 'voided';
                            $kind = $movementBadgeKind[$m['movement_type']] ?? 'info';
                            $label = $movementBadgeLabel[$m['movement_type']] ?? $m['movement_type'];
                            $dirColor = $m['direction'] === 'in' ? 'var(--success)' : 'var(--danger)';
                            $sign = $m['direction'] === 'in' ? '+' : '−';
                            ?>
                            <tr class="<?= $isVoided ? 'row-voided' : ''; ?>" data-name="<?= htmlspecialchars(mb_strtolower($m['item_name'] . ' ' . $label . ' ' . $m['location_name'] . ' ' . $m['reference_no'])); ?>" data-type="<?= htmlspecialchars($m['movement_type']); ?>" data-location="<?= htmlspecialchars($m['location_name']); ?>">
                                <td style="font-family:var(--font-display); font-weight:700; font-size:12px;">MV-<?= (int) $m['movement_id']; ?></td>
                                <td style="font-size:13px; font-weight:600;"><?= htmlspecialchars($m['item_name']); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $kind; ?>"><?= htmlspecialchars($label); ?></span></td>
                                <td style="font-size:12.5px; color:var(--text-muted);"><?= htmlspecialchars($m['location_name']); ?></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:700; color:<?= $dirColor; ?>;"><?= $sign; ?><?= rtrim(rtrim(number_format($m['quantity'], 3), '0'), '.'); ?></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:600;">$<?= number_format($m['total_cost_usd'], 2); ?></td>
                                <td style="font-size:12px; color:var(--text-faint);"><?= htmlspecialchars($m['reference_no'] ?: '—'); ?></td>
                                <td style="font-size:12.5px; color:var(--text-muted);"><?= htmlspecialchars(date('d M', strtotime($m['created_at']))); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $isVoided ? 'danger' : 'ok'; ?>"><?= $isVoided ? 'Voided' : 'Active'; ?></span></td>
                                <td class="text-end">
                                    <?php if (!$isVoided): ?>
                                        <button type="button" class="icon-btn icon-btn-void" title="Void" onclick="openVoidModal(<?= (int) $m['movement_id']; ?>, 'MV-<?= (int) $m['movement_id']; ?>')">⊘</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10" class="text-center" style="color:var(--text-faint); padding:32px 0;">No movements found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <!-- ===== Movement Modal ===== -->
    <div class="yz-modal-overlay" id="movementModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/stock_movement_action.php" id="movementForm">
                <div class="yz-modal-header">
                    <div>
                        <h3>Stock Movement</h3>
                        <p>Record a stock in / out movement</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('movementModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="yz-row yz-row-2" style="margin-bottom:14px;">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Item</label>
                            <select name="item_id" class="select2-enable" data-placeholder="Search item…" required>
                                <option value="">Select Item</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?= (int) $item['item_id']; ?>"><?= htmlspecialchars($item['item_name']); ?> — <?= htmlspecialchars($item['item_code']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Movement type</label>
                            <select name="movement_type" id="movementType" required>
                                <option value="">Select Type</option>
                                <?php foreach ($movementTypes as $value => $label): ?>
                                    <option value="<?= $value; ?>"><?= $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="yz-row yz-row-2" style="margin-bottom:14px;">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Location</label>
                            <select name="location_id" required>
                                <option value="">Select Location</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?= (int) $location['location_id']; ?>"><?= htmlspecialchars($location['location_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Quantity</label>
                            <input type="number" step="0.001" min="0.001" name="quantity" placeholder="0" required>
                        </div>
                    </div>
                    <div class="yz-field">
                        <label>Total purchase price (USD)</label>
                        <input type="number" step="0.01" min="0" name="total_cost_usd" id="movementTotalCost" placeholder="0.00">
                        <div style="font-size:11.5px; color:#9a7a56; margin-top:6px;">Required for Purchase In. Unit cost is calculated automatically (total ÷ quantity). For other OUT movements, average purchase cost is used.</div>
                    </div>
                    <div class="yz-row yz-row-2" style="margin-top:14px;">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Reference No</label>
                            <input type="text" name="reference_no" placeholder="e.g. PO-2261">
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Notes</label>
                            <input type="text" name="notes" placeholder="Optional">
                        </div>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('movementModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Void Modal ===== -->
    <div class="yz-modal-overlay" id="voidModal">
        <div class="yz-modal yz-modal-md" style="padding:28px;">
            <form method="POST" action="<?= BASE_URL; ?>actions/stock_movement_action.php">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                    <div style="width:46px; height:46px; flex:none; border-radius:13px; background:var(--warn-soft); color:var(--warn); display:flex; align-items:center; justify-content:center; font-size:22px;">⊘</div>
                    <div>
                        <div style="font-family:var(--font-display); font-weight:800; font-size:18px;">Void record</div>
                        <div style="font-size:13px; color:var(--text-faint);" id="voidModalName"></div>
                    </div>
                </div>
                <div class="yz-hint">The record stays in history for audit, but no longer affects stock or financials.</div>
                <label style="display:block; font-size:11px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.04em; font-weight:600; margin-bottom:6px;">Reason for voiding</label>
                <textarea name="void_reason" placeholder="e.g. Entered wrong quantity" required minlength="5" style="width:100%; height:80px; resize:none; border:1px solid var(--border-input); border-radius:11px; padding:11px 13px; font-family:var(--font-body); font-size:14px; outline:none;"></textarea>
                <input type="hidden" name="action" value="void">
                <input type="hidden" name="movement_id" id="voidMovementId" value="">
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" class="btn-yz btn-yz-outline" style="flex:1; justify-content:center;" onclick="closeYzModal('voidModal')">Cancel</button>
                    <button type="submit" class="btn-yz" style="flex:1; justify-content:center; background:var(--warn); color:#fff;">Confirm Void</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openMovementModal(purchase) {
            document.getElementById('movementForm').reset();
            document.getElementById('movementType').value = purchase ? 'purchase_in' : '';
            openYzModal('movementModal');
        }
        function openVoidModal(id, ref) {
            document.getElementById('voidMovementId').value = id;
            document.getElementById('voidModalName').textContent = ref + ' · stock will be reversed';
            openYzModal('voidModal');
        }
        function filterMovementRows() {
            var q = document.getElementById('movementSearch').value.trim().toLowerCase();
            var type = document.getElementById('movementTypeFilter').value;
            var location = document.getElementById('movementLocationFilter').value;
            document.querySelectorAll('#movementRows tr[data-name]').forEach(function (row) {
                var matchesSearch = row.getAttribute('data-name').indexOf(q) !== -1;
                var matchesType = !type || row.getAttribute('data-type') === type;
                var matchesLocation = !location || row.getAttribute('data-location') === location;
                row.style.display = (matchesSearch && matchesType && matchesLocation) ? '' : 'none';
            });
        }
        function resetMovementFilters() {
            document.getElementById('movementSearch').value = '';
            document.getElementById('movementTypeFilter').value = '';
            document.getElementById('movementLocationFilter').value = '';
            filterMovementRows();
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
