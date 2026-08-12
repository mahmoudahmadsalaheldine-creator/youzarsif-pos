<?php
session_start();

require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$allowedStatuses = [
    "active" => "Active",
    "inactive" => "Inactive"
];

$categoryTypeLabels = [
    "ingredient" => "Ingredient",
    "supporting_item" => "Supporting Item",
    "other" => "Other"
];

try {
    $categoriesStmt = $pdo->query("
        SELECT category_id, category_name, category_type
        FROM categories
        WHERE status = 'active'
        AND category_type IN ('ingredient', 'supporting_item', 'other')
        ORDER BY category_name ASC
    ");
    $categories = $categoriesStmt->fetchAll();

    $unitsStmt = $pdo->query("
        SELECT unit_id, unit_name, abbreviation
        FROM units
        WHERE status = 'active'
        ORDER BY unit_name ASC
    ");
    $units = $unitsStmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
    $units = [];
}

try {
    $sql = "
        SELECT
            i.*,
            c.category_name,
            c.category_type,
            u.unit_name,
            u.abbreviation,
            COALESCE(sm_stock.current_stock, 0) AS current_stock,
            COALESCE(sm_cost.avg_unit_cost, 0) AS avg_unit_cost
        FROM items i
        INNER JOIN categories c ON i.category_id = c.category_id
        INNER JOIN units u ON i.unit_id = u.unit_id
        LEFT JOIN (
            SELECT item_id, SUM(CASE WHEN direction = 'in' THEN quantity WHEN direction = 'out' THEN -quantity ELSE 0 END) AS current_stock
            FROM stock_movements WHERE status = 'active' GROUP BY item_id
        ) sm_stock ON sm_stock.item_id = i.item_id
        LEFT JOIN (
            SELECT item_id, SUM(total_cost_usd) / SUM(quantity) AS avg_unit_cost
            FROM stock_movements WHERE status = 'active' AND movement_type = 'purchase_in' GROUP BY item_id
        ) sm_cost ON sm_cost.item_id = i.item_id
        ORDER BY i.item_id DESC
    ";
    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll();
} catch (PDOException $e) {
    $items = [];
}

$navCrumb = 'Inventory';
$navTitle = 'Ingredients & Items';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta"><?= count($items); ?> items · auto codes</span>
            <div class="spacer"></div>
            <select id="itemTypeFilter" onchange="filterItemRows()" style="border:1px solid var(--border-input); border-radius:9999px; padding:9px 14px; font-family:var(--font-body); font-size:13px; color:var(--text); background:#fff;">
                <option value="">All Types</option>
                <?php foreach ($categoryTypeLabels as $value => $label): ?>
                    <option value="<?= $value; ?>"><?= $label; ?></option>
                <?php endforeach; ?>
            </select>
            <select id="itemCategoryFilter" onchange="filterItemRows()" style="border:1px solid var(--border-input); border-radius:9999px; padding:9px 14px; font-family:var(--font-body); font-size:13px; color:var(--text); background:#fff;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['category_id']; ?>"><?= htmlspecialchars($category['category_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="search-box" style="width:240px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="itemSearch" placeholder="Search items…" oninput="filterItemRows()">
            </div>
            <button type="button" class="btn-yz btn-yz-outline" onclick="resetItemFilters()">Reset</button>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openItemModal()">＋ New Item</button>
        </div>

        <div class="yz-table-wrap">
            <table class="yz-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Item</th>
                        <th>Category</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Stock</th>
                        <th class="text-end">Value</th>
                        <th>Status</th>
                        <th style="width:78px;"></th>
                    </tr>
                </thead>
                <tbody id="itemRows">
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $stock = (float) $item['current_stock'];
                            $min = (float) $item['min_stock_qty'];
                            $cost = (float) $item['avg_unit_cost'];
                            $low = $stock < $min;
                            $typeLabel = $categoryTypeLabels[$item['category_type']] ?? $item['category_type'];
                            $payload = htmlspecialchars(json_encode([
                                'id' => $item['item_id'],
                                'name' => $item['item_name'],
                                'code' => $item['item_code'],
                                'category_id' => $item['category_id'],
                                'family_code' => $item['family_code'],
                                'unit_id' => $item['unit_id'],
                                'min_stock_qty' => $item['min_stock_qty'],
                                'status' => $item['status'],
                            ]), ENT_QUOTES);
                            ?>
                            <tr data-name="<?= htmlspecialchars(mb_strtolower($item['item_name'] . ' ' . $item['item_code'] . ' ' . $item['category_name'])); ?>" data-category="<?= (int) $item['category_id']; ?>" data-type="<?= htmlspecialchars($item['category_type']); ?>">
                                <td style="font-family:var(--font-display); font-weight:700; font-size:12px; color:var(--caramel);"><?= htmlspecialchars($item['item_code']); ?></td>
                                <td>
                                    <div style="font-family:var(--font-display); font-weight:700; font-size:13.5px;"><?= htmlspecialchars($item['item_name']); ?></div>
                                    <div style="font-size:11px; color:var(--text-faint);"><?= htmlspecialchars($typeLabel); ?></div>
                                </td>
                                <td style="font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($item['category_name']); ?></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:600;">$<?= number_format($cost, 2); ?></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:700;"><?= rtrim(rtrim(number_format($stock, 3), '0'), '.'); ?> <span style="color:var(--text-faint); font-weight:400; font-size:11px;"><?= htmlspecialchars($item['abbreviation']); ?></span></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:700;">$<?= number_format($stock * $cost, 2); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $low ? 'danger' : 'ok'; ?>"><?= $low ? 'Low' : 'In Stock'; ?></span></td>
                                <td class="text-end" style="white-space:nowrap;">
                                    <button type="button" class="icon-btn icon-btn-edit" title="Edit" onclick='openItemModal(<?= $payload; ?>)'>✎</button>
                                    <form method="POST" action="<?= BASE_URL; ?>actions/item_action.php" style="display:inline;" onsubmit="return confirmAction(this, { title: 'Deactivate this item?', message: 'This item will no longer be available for new records.', confirmLabel: 'Deactivate' });">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="item_id" value="<?= (int) $item['item_id']; ?>">
                                        <button type="submit" class="icon-btn icon-btn-delete" title="Deactivate">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center" style="color:var(--text-faint); padding:32px 0;">No ingredients or items found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <div class="yz-modal-overlay" id="itemModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/item_action.php" id="itemForm">
                <div class="yz-modal-header">
                    <div>
                        <h3 id="itemModalTitle">New Item</h3>
                        <p>Raw material — code is auto-generated</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('itemModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" id="itemAction" value="add">
                    <input type="hidden" name="item_id" id="itemId" value="">

                    <div class="yz-hint" id="itemCodeHint">Item code is generated automatically · e.g. <strong>ING-SUGAR-001</strong></div>

                    <div class="yz-field">
                        <label>Item name</label>
                        <input type="text" name="item_name" id="itemName" placeholder="e.g. White Sugar" required>
                    </div>
                    <div class="yz-row yz-row-2" style="margin-bottom:14px;">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Category</label>
                            <select name="category_id" id="itemCategory" class="select2-enable" data-placeholder="Search category…" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['category_id']; ?>">
                                        <?= htmlspecialchars($category['category_name']); ?> — <?= htmlspecialchars($categoryTypeLabels[$category['category_type']] ?? $category['category_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Family Code</label>
                            <input type="text" name="family_code" id="itemFamilyCode" placeholder="e.g. SUGAR" required>
                        </div>
                    </div>
                    <div class="yz-row yz-row-2">
                        <div class="yz-field">
                            <label>Unit</label>
                            <select name="unit_id" id="itemUnit" required>
                                <option value="">Select Unit</option>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?= (int) $unit['unit_id']; ?>"><?= htmlspecialchars($unit['unit_name']); ?> (<?= htmlspecialchars($unit['abbreviation']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="yz-field">
                            <label>Min stock qty</label>
                            <input type="number" step="0.001" min="0" name="min_stock_qty" id="itemMinStock" placeholder="0">
                        </div>
                    </div>
                    <div class="yz-field">
                        <label>Status</label>
                        <select name="status" id="itemStatus" required>
                            <?php foreach ($allowedStatuses as $value => $label): ?>
                                <option value="<?= $value; ?>"><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('itemModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openItemModal(it) {
            document.getElementById('itemForm').reset();
            if (it) {
                document.getElementById('itemModalTitle').textContent = 'Edit Item';
                document.getElementById('itemAction').value = 'update';
                document.getElementById('itemId').value = it.id;
                document.getElementById('itemName').value = it.name;
                jQuery('#itemCategory').val(it.category_id).trigger('change');
                document.getElementById('itemFamilyCode').value = it.family_code;
                document.getElementById('itemUnit').value = it.unit_id;
                document.getElementById('itemMinStock').value = it.min_stock_qty;
                document.getElementById('itemStatus').value = it.status;
                document.getElementById('itemCodeHint').innerHTML = 'System code · <strong>' + it.code + '</strong> (fixed, not editable)';
            } else {
                document.getElementById('itemModalTitle').textContent = 'New Item';
                document.getElementById('itemAction').value = 'add';
                document.getElementById('itemId').value = '';
                document.getElementById('itemCodeHint').innerHTML = 'Item code is generated automatically · e.g. <strong>ING-SUGAR-001</strong>';
                jQuery('#itemCategory').val('').trigger('change');
            }
            openYzModal('itemModal');
        }
        function filterItemRows() {
            var q = document.getElementById('itemSearch').value.trim().toLowerCase();
            var type = document.getElementById('itemTypeFilter').value;
            var category = document.getElementById('itemCategoryFilter').value;
            document.querySelectorAll('#itemRows tr[data-name]').forEach(function (row) {
                var matchesSearch = row.getAttribute('data-name').indexOf(q) !== -1;
                var matchesType = !type || row.getAttribute('data-type') === type;
                var matchesCategory = !category || row.getAttribute('data-category') === category;
                row.style.display = (matchesSearch && matchesType && matchesCategory) ? '' : 'none';
            });
        }
        function resetItemFilters() {
            document.getElementById('itemSearch').value = '';
            document.getElementById('itemTypeFilter').value = '';
            document.getElementById('itemCategoryFilter').value = '';
            filterItemRows();
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
