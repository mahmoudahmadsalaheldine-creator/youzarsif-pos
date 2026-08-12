<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

try {
    $productsStmt = $pdo->query("
        SELECT fp.product_id, fp.product_name, fp.product_code, fp.calculated_cost_usd, fp.selling_price_usd, u.abbreviation
        FROM finished_products fp INNER JOIN units u ON fp.unit_id = u.unit_id
        WHERE fp.status = 'active' ORDER BY fp.product_name ASC
    ");
    $products = $productsStmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

try {
    $locStmt = $pdo->query("SELECT location_id, location_name, location_type FROM locations WHERE status = 'active' ORDER BY location_type ASC, location_name ASC");
    $allLocations = $locStmt->fetchAll();
} catch (PDOException $e) {
    $allLocations = [];
}

$catColors = ['#c8924b', '#b5683c', '#9a4f56', '#a06a2c', '#8a7b4a', '#5d7a6b'];

try {
    $stockRows = $pdo->query("
        SELECT product_id, location_id, SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END) AS qty
        FROM product_stock_movements GROUP BY product_id, location_id
    ")->fetchAll();
    $stockByProductLocation = [];
    foreach ($stockRows as $r) {
        $stockByProductLocation[$r['product_id']][$r['location_id']] = (float) $r['qty'];
    }
} catch (PDOException $e) {
    $stockByProductLocation = [];
}

try {
    $batchesStmt = $pdo->query("
        SELECT pb.*, fp.product_name, fp.product_code, l.location_name
        FROM production_batches pb
        INNER JOIN finished_products fp ON pb.product_id = fp.product_id
        INNER JOIN locations l ON pb.location_id = l.location_id
        ORDER BY pb.batch_id DESC
    ");
    $batches = $batchesStmt->fetchAll();
} catch (PDOException $e) {
    $batches = [];
}

$navCrumb = 'Production';
$navTitle = 'Production Batches';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta">Auto-deducts ingredients · auto-adds finished stock</span>
            <div class="spacer"></div>
            <div class="search-box" style="width:240px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="batchSearch" placeholder="Search batches…" oninput="filterBatchRows()">
            </div>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openBatchModal()">＋ New Batch</button>
        </div>

        <div class="yz-table-wrap" style="margin-bottom:18px;">
            <table class="yz-table">
                <thead>
                    <tr>
                        <th>Batch Ref</th><th>Product</th><th>Location</th>
                        <th class="text-end">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Total Cost</th>
                        <th>Date</th><th>Status</th><th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody id="batchRows">
                    <?php if (!empty($batches)): ?>
                        <?php foreach ($batches as $b): ?>
                            <?php $isVoided = $b['status'] === 'voided'; ?>
                            <tr class="<?= $isVoided ? 'row-voided' : ''; ?>" data-name="<?= htmlspecialchars(mb_strtolower($b['batch_ref'] . ' ' . $b['product_name'] . ' ' . $b['location_name'])); ?>">
                                <td style="font-family:var(--font-display); font-weight:700; font-size:12px; color:var(--caramel);"><?= htmlspecialchars($b['batch_ref']); ?></td>
                                <td style="font-size:13.5px; font-weight:600;"><?= htmlspecialchars($b['product_name']); ?></td>
                                <td style="font-size:12.5px; color:var(--text-muted);"><?= htmlspecialchars($b['location_name']); ?></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:700;"><?= rtrim(rtrim(number_format($b['quantity_produced'], 3), '0'), '.'); ?></td>
                                <td class="text-end">$<?= number_format($b['unit_cost_usd'], 2); ?></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:700;">$<?= number_format($b['total_cost_usd'], 2); ?></td>
                                <td style="font-size:12.5px; color:var(--text-muted);"><?= htmlspecialchars(date('d M', strtotime($b['created_at']))); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $isVoided ? 'danger' : 'ok'; ?>"><?= $isVoided ? 'Voided' : 'Completed'; ?></span></td>
                                <td class="text-end">
                                    <?php if (!$isVoided): ?>
                                        <button type="button" class="icon-btn icon-btn-void" title="Void" onclick="openVoidModal(<?= (int) $b['batch_id']; ?>, '<?= htmlspecialchars($b['batch_ref']); ?>')">⊘</button>
                                    <?php endif; ?>
                                    <button type="button" class="icon-btn icon-btn-edit" title="Barcode" onclick="openBarcodeModal('<?= htmlspecialchars(addslashes($b['product_name'])); ?>', '<?= htmlspecialchars($b['product_code']); ?>')">▥</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center" style="color:var(--text-faint); padding:32px 0;">No production batches recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="font-family:var(--font-display); font-weight:800; font-size:15px; margin-bottom:12px;">Product Stock Summary</div>
        <div class="yz-table-wrap">
            <table class="yz-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <?php foreach ($allLocations as $loc): ?>
                            <th class="text-end"><?= htmlspecialchars($loc['location_name']); ?></th>
                        <?php endforeach; ?>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $i => $p): ?>
                            <?php
                            $color = $catColors[$i % count($catColors)];
                            $total = 0;
                            ?>
                            <tr>
                                <td><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= $color; ?>; margin-right:9px;"></span><span style="font-weight:600; font-size:13.5px;"><?= htmlspecialchars($p['product_name']); ?></span></td>
                                <?php foreach ($allLocations as $loc): ?>
                                    <?php $qty = $stockByProductLocation[$p['product_id']][$loc['location_id']] ?? 0; $total += $qty; ?>
                                    <td class="text-end" style="font-family:var(--font-display); font-weight:600;"><?= rtrim(rtrim(number_format($qty, 3), '0'), '.'); ?> <?= htmlspecialchars($p['abbreviation']); ?></td>
                                <?php endforeach; ?>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:800;"><?= rtrim(rtrim(number_format($total, 3), '0'), '.'); ?> <?= htmlspecialchars($p['abbreviation']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="<?= 2 + count($allLocations); ?>" class="text-center" style="color:var(--text-faint); padding:32px 0;">No finished products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <!-- ===== Batch Modal ===== -->
    <div class="yz-modal-overlay" id="batchModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/production_batch_action.php">
                <div class="yz-modal-header">
                    <div>
                        <h3>New Production Batch</h3>
                        <p>Auto-deducts ingredients, adds finished stock</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('batchModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="yz-hint">Batch ref is auto-generated · ingredient stock is checked before saving.</div>
                    <div class="yz-field">
                        <label>Product</label>
                        <select name="product_id" class="select2-enable" data-placeholder="Search product…" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['product_id']; ?>"><?= htmlspecialchars($p['product_name']); ?> (<?= htmlspecialchars($p['product_code']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="yz-field">
                        <label>Quantity to produce</label>
                        <input type="number" step="0.001" min="0.001" name="quantity_produced" placeholder="0" required>
                    </div>
                    <div class="yz-field">
                        <label>Notes (optional)</label>
                        <input type="text" name="notes" placeholder="e.g. Morning shift production">
                    </div>
                    <div style="font-size:11.5px; color:var(--text-faint);">Factory location is selected automatically.</div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('batchModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Void Modal ===== -->
    <div class="yz-modal-overlay" id="voidModal">
        <div class="yz-modal yz-modal-md" style="padding:28px;">
            <form method="POST" action="<?= BASE_URL; ?>actions/production_batch_action.php">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                    <div style="width:46px; height:46px; flex:none; border-radius:13px; background:var(--warn-soft); color:var(--warn); display:flex; align-items:center; justify-content:center; font-size:22px;">⊘</div>
                    <div><div style="font-family:var(--font-display); font-weight:800; font-size:18px;">Void batch</div><div style="font-size:13px; color:var(--text-faint);" id="voidModalName"></div></div>
                </div>
                <div class="yz-hint">All stock deductions and additions from this batch will be reversed.</div>
                <label style="display:block; font-size:11px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.04em; font-weight:600; margin-bottom:6px;">Reason for voiding</label>
                <textarea name="void_reason" placeholder="e.g. Entered wrong quantity" required minlength="5" style="width:100%; height:80px; resize:none; border:1px solid var(--border-input); border-radius:11px; padding:11px 13px; font-family:var(--font-body); font-size:14px; outline:none;"></textarea>
                <input type="hidden" name="action" value="void">
                <input type="hidden" name="batch_id" id="voidBatchId" value="">
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="button" class="btn-yz btn-yz-outline" style="flex:1; justify-content:center;" onclick="closeYzModal('voidModal')">Cancel</button>
                    <button type="submit" class="btn-yz" style="flex:1; justify-content:center; background:var(--warn); color:#fff;">Confirm Void</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Barcode Modal ===== -->
    <div class="yz-modal-overlay" id="barcodeModal">
        <div class="yz-modal yz-modal-sm" style="padding:28px; text-align:center;">
            <div style="font-family:var(--font-display); font-weight:800; font-size:18px;" id="barcodeName"></div>
            <div style="font-size:12.5px; color:var(--caramel); font-family:var(--font-display); font-weight:600; margin-top:2px;" id="barcodeCodeLabel"></div>
            <div style="background:#fff; border:1px solid var(--border-soft); border-radius:12px; padding:20px; margin:18px 0;"><svg id="barcodeSvg"></svg></div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn-yz btn-yz-outline" style="flex:1; justify-content:center;" onclick="closeYzModal('barcodeModal')">Close</button>
                <button type="button" class="btn-yz btn-yz-dark" style="flex:1; justify-content:center;" onclick="window.print()">⎙ Print</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        function openBatchModal() { openYzModal('batchModal'); }
        function openVoidModal(id, ref) {
            document.getElementById('voidBatchId').value = id;
            document.getElementById('voidModalName').textContent = ref + ' · stock will be reversed';
            openYzModal('voidModal');
        }
        function openBarcodeModal(name, code) {
            document.getElementById('barcodeName').textContent = name;
            document.getElementById('barcodeCodeLabel').textContent = code;
            openYzModal('barcodeModal');
            setTimeout(function () { JsBarcode('#barcodeSvg', code, { format: 'CODE128', width: 2, height: 60, displayValue: true }); }, 50);
        }
        function filterBatchRows() {
            var q = document.getElementById('batchSearch').value.trim().toLowerCase();
            document.querySelectorAll('#batchRows tr[data-name]').forEach(function (row) {
                row.style.display = row.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
