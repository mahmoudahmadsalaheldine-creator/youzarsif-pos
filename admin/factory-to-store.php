<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

function getProductStock($pdo, $productId, $locationId)
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END), 0) AS current_stock
        FROM product_stock_movements WHERE product_id = :product_id AND location_id = :location_id
    ");
    $stmt->execute([":product_id" => $productId, ":location_id" => $locationId]);
    $row = $stmt->fetch();
    return $row ? (float) $row["current_stock"] : 0;
}

try {
    $productsStmt = $pdo->query("
        SELECT fp.product_id, fp.product_name, fp.product_code, fp.calculated_cost_usd, u.abbreviation,
            COALESCE(SUM(CASE WHEN psm.direction = 'in' AND psm.status = 'active' THEN psm.quantity WHEN psm.direction = 'out' AND psm.status = 'active' THEN -psm.quantity ELSE 0 END), 0) AS factory_stock
        FROM finished_products fp
        INNER JOIN units u ON fp.unit_id = u.unit_id
        LEFT JOIN product_stock_movements psm ON psm.product_id = fp.product_id
            AND psm.location_id IN (SELECT location_id FROM locations WHERE location_type = 'factory' AND status = 'active')
        WHERE fp.status = 'active'
        GROUP BY fp.product_id, fp.product_name, fp.product_code, fp.calculated_cost_usd, u.abbreviation
        HAVING factory_stock > 0
        ORDER BY fp.product_name ASC
    ");
    $products = $productsStmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

try {
    $factories = $pdo->query("SELECT location_id, location_name FROM locations WHERE status = 'active' AND location_type = 'factory' ORDER BY location_name ASC")->fetchAll();
    $stores = $pdo->query("SELECT location_id, location_name FROM locations WHERE status = 'active' AND location_type = 'store' ORDER BY location_name ASC")->fetchAll();
} catch (PDOException $e) {
    $factories = [];
    $stores = [];
}

try {
    $transfersStmt = $pdo->query("
        SELECT psm_out.reference_no, psm_out.product_id, psm_out.location_id AS from_location_id, psm_out.quantity, psm_out.status, psm_out.created_at,
            fp.product_name, fp.product_code, u.abbreviation, l_from.location_name AS from_location, l_to.location_name AS to_location
        FROM product_stock_movements psm_out
        INNER JOIN product_stock_movements psm_in ON psm_in.reference_no = psm_out.reference_no AND psm_in.movement_type = 'transfer_in'
        INNER JOIN finished_products fp ON psm_out.product_id = fp.product_id
        INNER JOIN units u ON fp.unit_id = u.unit_id
        INNER JOIN locations l_from ON psm_out.location_id = l_from.location_id
        INNER JOIN locations l_to ON psm_in.location_id = l_to.location_id
        WHERE psm_out.movement_type = 'transfer_out'
        ORDER BY psm_out.created_at DESC
    ");
    $transfers = $transfersStmt->fetchAll();
} catch (PDOException $e) {
    $transfers = [];
}

$productStocksJs = [];
foreach ($products as $p) {
    $productStocksJs[$p['product_id']] = [];
    foreach ($factories as $f) {
        $productStocksJs[$p['product_id']][$f['location_id']] = getProductStock($pdo, $p['product_id'], $f['location_id']);
    }
}

$navCrumb = 'Production';
$navTitle = 'Factory to Store';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta"><?= count($transfers); ?> transfers</span>
            <div class="spacer"></div>
            <div class="search-box" style="width:240px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="transferSearch" placeholder="Search transfers…" oninput="filterTransferRows()">
            </div>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openYzModal('transferModal')">＋ New Transfer</button>
        </div>

        <div class="yz-table-wrap">
            <div style="padding:16px 18px; font-family:var(--font-display); font-weight:800; font-size:15px;">Recent Transfers</div>
            <table class="yz-table">
                <thead>
                    <tr>
                        <th>Ref</th><th>Product</th><th class="text-end">Qty</th><th>Route</th><th>Date</th><th>Status</th><th style="width:54px;"></th>
                    </tr>
                </thead>
                <tbody id="transferRows">
                    <?php if (!empty($transfers)): ?>
                        <?php foreach ($transfers as $t): ?>
                            <?php $isVoided = $t['status'] === 'voided'; ?>
                            <tr class="<?= $isVoided ? 'row-voided' : ''; ?>" data-name="<?= htmlspecialchars(mb_strtolower($t['reference_no'] . ' ' . $t['product_name'] . ' ' . $t['from_location'] . ' ' . $t['to_location'])); ?>">
                                <td style="font-family:var(--font-display); font-weight:700; font-size:12px; color:var(--caramel);"><?= htmlspecialchars($t['reference_no']); ?></td>
                                <td style="font-size:13px; font-weight:600;"><?= htmlspecialchars($t['product_name']); ?></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:700;"><?= rtrim(rtrim(number_format($t['quantity'], 3), '0'), '.'); ?></td>
                                <td style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($t['from_location']); ?> → <?= htmlspecialchars($t['to_location']); ?></td>
                                <td style="font-size:12.5px; color:var(--text-muted);"><?= htmlspecialchars(date('d M', strtotime($t['created_at']))); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $isVoided ? 'danger' : 'ok'; ?>"><?= $isVoided ? 'Voided' : 'Completed'; ?></span></td>
                                <td class="text-end">
                                    <?php if (!$isVoided): ?>
                                        <button type="button" class="icon-btn icon-btn-void" title="Void" onclick="openVoidModal('<?= htmlspecialchars($t['reference_no']); ?>')">⊘</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center" style="color:var(--text-faint); padding:32px 0;">No transfers recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <!-- ===== New Transfer Modal ===== -->
    <div class="yz-modal-overlay" id="transferModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/factory_to_store_action.php" id="transferForm">
                <div class="yz-modal-header">
                    <div>
                        <h3>New Transfer</h3>
                        <p>Move finished product stock from factory to store</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('transferModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" value="add">

                    <div class="yz-field">
                        <label>Product (in stock)</label>
                        <select name="product_id" id="productSelect" class="select2-enable" data-placeholder="Search product…" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['product_id']; ?>" data-unit="<?= htmlspecialchars($p['abbreviation']); ?>"><?= htmlspecialchars($p['product_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:flex; gap:10px; margin-bottom:14px;">
                        <div style="flex:1;">
                            <div style="font-size:11px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px;">From</div>
                            <select name="from_location_id" id="fromLocationSelect" required style="width:100%; border:1px solid var(--border-input); border-radius:11px; padding:11px 13px; font-size:13px; background:#fff;">
                                <option value="">Select</option>
                                <?php foreach ($factories as $f): ?>
                                    <option value="<?= (int) $f['location_id']; ?>"><?= htmlspecialchars($f['location_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:none; align-self:flex-end; padding-bottom:11px; color:var(--caramel); font-size:18px;">⇄</div>
                        <div style="flex:1;">
                            <div style="font-size:11px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px;">To</div>
                            <select name="to_location_id" required style="width:100%; border:1px solid var(--border-input); border-radius:11px; padding:11px 13px; font-size:13px; background:#fff;">
                                <option value="">Select</option>
                                <?php foreach ($stores as $s): ?>
                                    <option value="<?= (int) $s['location_id']; ?>"><?= htmlspecialchars($s['location_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="yz-field">
                        <label>Quantity</label>
                        <input type="number" step="0.001" min="0.001" name="quantity" placeholder="0" required>
                    </div>
                    <div id="availableStockText" style="font-size:11.5px; color:var(--success); margin-bottom:16px;"></div>

                    <div class="yz-field">
                        <label>Notes (optional)</label>
                        <input type="text" name="notes" placeholder="e.g. Weekly store delivery">
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('transferModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Confirm Transfer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Void Modal ===== -->
    <div class="yz-modal-overlay" id="voidModal">
        <div class="yz-modal yz-modal-md" style="padding:28px;">
            <form method="POST" action="<?= BASE_URL; ?>actions/factory_to_store_action.php">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                    <div style="width:46px; height:46px; flex:none; border-radius:13px; background:var(--warn-soft); color:var(--warn); display:flex; align-items:center; justify-content:center; font-size:22px;">⊘</div>
                    <div><div style="font-family:var(--font-display); font-weight:800; font-size:18px;">Void transfer</div><div style="font-size:13px; color:var(--text-faint);">Stock will be reversed at both locations</div></div>
                </div>
                <label style="display:block; font-size:11px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.04em; font-weight:600; margin-bottom:6px;">Reason for voiding</label>
                <textarea name="void_reason" placeholder="e.g. Entered wrong quantity" required minlength="5" style="width:100%; height:80px; resize:none; border:1px solid var(--border-input); border-radius:11px; padding:11px 13px; font-family:var(--font-body); font-size:14px; outline:none;"></textarea>
                <input type="hidden" name="action" value="void">
                <input type="hidden" name="reference_no" id="voidReferenceNo" value="">
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" class="btn-yz btn-yz-outline" style="flex:1; justify-content:center;" onclick="closeYzModal('voidModal')">Cancel</button>
                    <button type="submit" class="btn-yz" style="flex:1; justify-content:center; background:var(--warn); color:#fff;">Confirm Void</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const productStocks = <?= json_encode($productStocksJs); ?>;
        function updateAvailableStock() {
            const productId = document.getElementById('productSelect').value;
            const locationId = document.getElementById('fromLocationSelect').value;
            const el = document.getElementById('availableStockText');
            const sel = document.getElementById('productSelect');
            const unit = sel.options[sel.selectedIndex].getAttribute('data-unit') || '';
            if (productId && locationId && productStocks[productId] && productStocks[productId][locationId] !== undefined) {
                el.textContent = parseFloat(productStocks[productId][locationId]).toFixed(2) + ' ' + unit + ' available';
            } else {
                el.textContent = '';
            }
        }
        document.getElementById('productSelect').addEventListener('change', updateAvailableStock);
        document.getElementById('fromLocationSelect').addEventListener('change', updateAvailableStock);
        function openVoidModal(ref) {
            document.getElementById('voidReferenceNo').value = ref;
            openYzModal('voidModal');
        }
        function filterTransferRows() {
            var q = document.getElementById('transferSearch').value.trim().toLowerCase();
            document.querySelectorAll('#transferRows tr[data-name]').forEach(function (row) {
                row.style.display = row.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
