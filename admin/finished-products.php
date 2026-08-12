<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$allowedStatuses = ["active" => "Active", "inactive" => "Inactive"];

try {
    $categoriesStmt = $pdo->prepare("SELECT category_id, category_name FROM categories WHERE status = :status AND category_type = :type ORDER BY category_name ASC");
    $categoriesStmt->execute([":status" => "active", ":type" => "finished_product"]);
    $productCategories = $categoriesStmt->fetchAll();
} catch (PDOException $e) {
    $productCategories = [];
}

try {
    $unitsStmt = $pdo->query("SELECT unit_id, unit_name, abbreviation FROM units WHERE status = 'active' ORDER BY unit_name ASC");
    $units = $unitsStmt->fetchAll();
} catch (PDOException $e) {
    $units = [];
}

try {
    $itemsStmt = $pdo->query("
        SELECT i.item_id, i.item_name, i.item_code, u.abbreviation,
            CASE WHEN COALESCE(ps.total_qty, 0) > 0 THEN ps.total_cost / ps.total_qty ELSE 0 END AS average_unit_cost,
            COALESCE(stk.current_stock, 0) AS current_stock
        FROM items i
        INNER JOIN categories c ON i.category_id = c.category_id
        INNER JOIN units u ON i.unit_id = u.unit_id
        LEFT JOIN (SELECT item_id, SUM(quantity) AS total_qty, SUM(total_cost_usd) AS total_cost FROM stock_movements WHERE movement_type = 'purchase_in' AND status = 'active' GROUP BY item_id) ps ON ps.item_id = i.item_id
        LEFT JOIN (SELECT item_id, SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END) AS current_stock FROM stock_movements GROUP BY item_id) stk ON stk.item_id = i.item_id
        WHERE i.status = 'active' AND c.category_type IN ('ingredient', 'supporting_item', 'other')
        ORDER BY i.item_name ASC
    ");
    $componentItems = $itemsStmt->fetchAll();
} catch (PDOException $e) {
    $componentItems = [];
}

try {
    $productsStmt = $pdo->query("
        SELECT fp.*, c.category_name, u.abbreviation,
            COALESCE(stk.current_stock, 0) AS current_stock
        FROM finished_products fp
        INNER JOIN categories c ON fp.category_id = c.category_id
        INNER JOIN units u ON fp.unit_id = u.unit_id
        LEFT JOIN (SELECT product_id, SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END) AS current_stock FROM product_stock_movements GROUP BY product_id) stk ON stk.product_id = fp.product_id
        ORDER BY fp.product_id DESC
    ");
    $products = $productsStmt->fetchAll();

    $allComponentsStmt = $pdo->query("SELECT * FROM finished_product_components ORDER BY component_id ASC");
    $allComponents = [];
    foreach ($allComponentsStmt->fetchAll() as $c) {
        $allComponents[$c['product_id']][] = $c;
    }
} catch (PDOException $e) {
    $products = [];
    $allComponents = [];
}

$catColors = ['#c8924b', '#b5683c', '#9a4f56', '#a06a2c', '#8a7b4a', '#5d7a6b'];

$componentItemsJs = array_map(function ($i) {
    return [
        'item_id' => (int) $i['item_id'],
        'name' => $i['item_name'],
        'code' => $i['item_code'],
        'abbr' => $i['abbreviation'],
        'cost' => (float) $i['average_unit_cost'],
        'out_of_stock' => (float) $i['current_stock'] <= 0,
    ];
}, $componentItems);

$productsJs = [];
foreach ($products as $p) {
    $comps = $allComponents[$p['product_id']] ?? [];
    $productsJs[$p['product_id']] = [
        'id' => (int) $p['product_id'],
        'name' => $p['product_name'],
        'code' => $p['product_code'],
        'category_id' => (int) $p['category_id'],
        'family_code' => $p['family_code'],
        'unit_id' => (int) $p['unit_id'],
        'profit' => (float) $p['profit_percentage'],
        'min_stock_qty' => $p['min_stock_qty'],
        'status' => $p['status'],
        'recipe' => array_map(function ($c) {
            return ['item_id' => (int) $c['item_id'], 'qty' => (float) $c['quantity_used']];
        }, $comps),
    ];
}

$navCrumb = 'Production';
$navTitle = 'Finished Products';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta"><?= count($products); ?> products · cost auto-calculated from recipe</span>
            <div class="spacer"></div>
            <select id="productCategoryFilter" onchange="filterProductCards()" style="border:1px solid var(--border-input); border-radius:9999px; padding:9px 14px; font-family:var(--font-body); font-size:13px; color:var(--text); background:#fff;">
                <option value="">All Categories</option>
                <?php foreach ($productCategories as $c): ?>
                    <option value="<?= (int) $c['category_id']; ?>"><?= htmlspecialchars($c['category_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="search-box" style="width:220px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="productSearch" placeholder="Search products…" oninput="filterProductCards()">
            </div>
            <button type="button" class="btn-yz btn-yz-outline" onclick="resetProductFilters()">Reset</button>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openProductModal()">＋ New Product</button>
        </div>

        <div class="yz-grid-3" id="productCards">
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $i => $product): ?>
                    <?php
                    $color = $catColors[$i % count($catColors)];
                    $stock = (float) $product['current_stock'];
                    $min = (float) $product['min_stock_qty'];
                    $low = $stock < $min;
                    ?>
                    <div class="yz-card" style="padding:18px;" data-name="<?= htmlspecialchars(mb_strtolower($product['product_name'] . ' ' . $product['product_code'])); ?>" data-category="<?= (int) $product['category_id']; ?>">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width:46px; height:46px; flex:none; border-radius:13px; background:<?= $color; ?>22; color:<?= $color; ?>; display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-weight:800; font-size:18px;"><?= htmlspecialchars(mb_substr($product['product_name'], 0, 1)); ?></div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-family:var(--font-display); font-weight:800; font-size:15px; line-height:1.2;"><?= htmlspecialchars($product['product_name']); ?></div>
                                <div style="font-size:12px; color:var(--caramel); font-family:var(--font-display); font-weight:600;"><?= htmlspecialchars($product['product_code']); ?></div>
                            </div>
                            <span class="yz-badge yz-badge-<?= $low ? 'warn' : 'ok'; ?>"><?= $low ? 'Low' : 'In Stock'; ?></span>
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:16px; text-align:center;">
                            <div style="background:var(--soft-bg); border-radius:11px; padding:9px 4px;"><div style="font-size:10px; color:var(--text-faint); text-transform:uppercase;">Cost</div><div style="font-family:var(--font-display); font-weight:800; font-size:14px;">$<?= number_format($product['calculated_cost_usd'], 2); ?></div></div>
                            <div style="background:#f3e6cf; border-radius:11px; padding:9px 4px;"><div style="font-size:10px; color:var(--text-faint); text-transform:uppercase;">+Profit</div><div style="font-family:var(--font-display); font-weight:800; font-size:14px; color:var(--caramel);"><?= number_format($product['profit_percentage'], 0); ?>%</div></div>
                            <div style="background:var(--success-soft); border-radius:11px; padding:9px 4px;"><div style="font-size:10px; color:var(--text-faint); text-transform:uppercase;">Price</div><div style="font-family:var(--font-display); font-weight:800; font-size:14px; color:var(--success);">$<?= number_format($product['selling_price_usd'], 2); ?></div></div>
                        </div>
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:14px; padding-top:12px; border-top:1px solid var(--border-soft);">
                            <span style="font-size:11px; color:var(--text-faint);"><?= rtrim(rtrim(number_format($stock, 3), '0'), '.'); ?> <?= htmlspecialchars($product['abbreviation']); ?> in stock</span>
                            <div style="display:flex; gap:6px;">
                                <button type="button" class="icon-btn icon-btn-edit" title="Barcode" style="width:auto; padding:0 10px; gap:5px;" onclick="openBarcodeModal('<?= htmlspecialchars(addslashes($product['product_name'])); ?>', '<?= htmlspecialchars($product['product_code']); ?>')">▥</button>
                                <button type="button" class="icon-btn icon-btn-edit" title="Edit" onclick="openProductModal(<?= (int) $product['product_id']; ?>)">✎</button>
                                <form method="POST" action="<?= BASE_URL; ?>actions/finished_product_action.php" onsubmit="return confirmAction(this, { title: 'Deactivate this product?', message: 'This product will no longer be available for new sales or batches.', confirmLabel: 'Deactivate' });">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['product_id']; ?>">
                                    <button type="submit" class="icon-btn icon-btn-delete" title="Deactivate">✕</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:var(--text-faint); padding:20px;">No finished products added yet.</div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ===== Product Modal ===== -->
    <div class="yz-modal-overlay" id="productModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/finished_product_action.php" id="productForm">
                <div class="yz-modal-header">
                    <div>
                        <h3 id="productModalTitle">New Finished Product</h3>
                        <p>Build the recipe — cost &amp; price auto-calculate</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('productModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" id="productAction" value="add">
                    <input type="hidden" name="product_id" id="productId" value="">

                    <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:12px; margin-bottom:18px;">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Product name</label>
                            <input type="text" name="product_name" id="productName" placeholder="e.g. Assorted Baklava" required>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Category</label>
                            <select name="category_id" id="productCategory" class="select2-enable" data-placeholder="Search category…" required>
                                <option value="">Select</option>
                                <?php foreach ($productCategories as $c): ?>
                                    <option value="<?= (int) $c['category_id']; ?>"><?= htmlspecialchars($c['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Unit</label>
                            <select name="unit_id" id="productUnit" required>
                                <option value="">Select</option>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= (int) $u['unit_id']; ?>"><?= htmlspecialchars($u['unit_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="yz-row yz-row-2" style="margin-bottom:18px;">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Family code</label>
                            <input type="text" name="family_code" id="productFamilyCode" placeholder="e.g. BAK" required>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Min stock qty</label>
                            <input type="number" step="0.001" min="0" name="min_stock_qty" id="productMinStock" placeholder="0">
                        </div>
                    </div>

                    <div style="font-family:var(--font-display); font-weight:800; font-size:13px; margin-bottom:8px;">Recipe Components</div>
                    <div style="background:var(--soft-bg); border-radius:12px; padding:6px 10px 10px; overflow-x:auto;">
                        <div class="recipe-grid-cols" style="padding:8px 4px; font-size:10px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.04em; font-weight:600;">
                            <span>Ingredient</span><span style="text-align:right;">Qty</span><span style="text-align:right;">Unit $</span><span style="text-align:right;">Total</span><span></span>
                        </div>
                        <div id="recipeRows"></div>
                        <button type="button" onclick="addRecipeRow()" style="margin:6px 4px 2px; border:1px dashed var(--border-input); background:#fff; color:var(--text-muted); border-radius:9px; padding:8px 14px; font-family:var(--font-body); font-weight:600; font-size:12.5px; cursor:pointer;">＋ Add ingredient</button>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-top:16px;">
                        <div style="background:var(--soft-bg); border-radius:12px; padding:12px 14px;"><div style="font-size:10px; color:var(--text-faint); text-transform:uppercase;">Recipe Cost</div><div id="recipeTotalDisplay" style="font-family:var(--font-display); font-weight:800; font-size:18px; margin-top:3px;">$0.00</div></div>
                        <div style="background:#f3e6cf; border-radius:12px; padding:12px 14px;"><div style="font-size:10px; color:var(--text-faint); text-transform:uppercase;">Profit %</div><input type="number" step="0.01" min="0" name="profit_percentage" id="productProfit" value="60" oninput="recalcRecipe()" style="width:100%; border:none; background:transparent; font-family:var(--font-display); font-weight:800; font-size:18px; color:var(--caramel); outline:none; margin-top:3px;"></div>
                        <div style="background:var(--success-soft); border-radius:12px; padding:12px 14px;"><div style="font-size:10px; color:var(--success); text-transform:uppercase;">Selling Price</div><div id="sellingPriceDisplay" style="font-family:var(--font-display); font-weight:800; font-size:18px; color:var(--success); margin-top:3px;">$0.00</div></div>
                    </div>

                    <div class="yz-field" style="margin-top:16px;">
                        <label>Status</label>
                        <select name="status" id="productStatus" required>
                            <?php foreach ($allowedStatuses as $value => $label): ?>
                                <option value="<?= $value; ?>"><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('productModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Barcode Modal ===== -->
    <div class="yz-modal-overlay" id="barcodeModal">
        <div class="yz-modal yz-modal-sm" style="padding:28px; text-align:center;">
            <div style="font-family:var(--font-display); font-weight:800; font-size:18px;" id="barcodeName"></div>
            <div style="font-size:12.5px; color:var(--caramel); font-family:var(--font-display); font-weight:600; margin-top:2px;" id="barcodeCodeLabel"></div>
            <div style="background:#fff; border:1px solid var(--border-soft); border-radius:12px; padding:20px; margin:18px 0;">
                <svg id="barcodeSvg"></svg>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn-yz btn-yz-outline" style="flex:1; justify-content:center;" onclick="closeYzModal('barcodeModal')">Close</button>
                <button type="button" class="btn-yz btn-yz-dark" style="flex:1; justify-content:center;" onclick="window.print()">⎙ Print</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        const componentItems = <?= json_encode($componentItemsJs); ?>;
        const productsData = <?= json_encode($productsJs); ?>;
        let recipeRowCount = 0;

        function itemOptionsHtml(selectedId) {
            let html = '';
            componentItems.forEach(function (it) {
                const sel = it.item_id === selectedId ? 'selected' : '';
                const dis = (it.out_of_stock && it.item_id !== selectedId) ? 'disabled' : '';
                html += `<option value="${it.item_id}" ${sel} ${dis}>${it.name} — ${it.code} — ${it.abbr}${it.out_of_stock ? ' — Out of Stock' : ''}</option>`;
            });
            return html;
        }

        function addRecipeRow(itemId, qty) {
            const wrap = document.getElementById('recipeRows');
            const idx = recipeRowCount++;
            const row = document.createElement('div');
            row.className = 'recipe-row recipe-grid-cols';
            row.style.alignItems = 'center';
            row.style.padding = '4px';
            row.innerHTML = `
                <select name="component_item_id[]" class="select2-enable" data-placeholder="Search ingredient…" onchange="recalcRecipe()" style="width:100%;">${itemOptionsHtml(itemId)}</select>
                <input type="number" step="0.001" min="0.001" name="quantity_used[]" value="${qty || ''}" oninput="recalcRecipe()" style="width:100%; border:1px solid var(--border-input); border-radius:9px; padding:8px; font-family:var(--font-display); font-weight:600; font-size:12.5px; text-align:right;">
                <div class="row-unit-cost" style="text-align:right; font-size:12.5px; color:var(--text-muted);">$0.00</div>
                <div class="row-total" style="text-align:right; font-family:var(--font-display); font-weight:700; font-size:12.5px;">$0.00</div>
                <button type="button" onclick="removeRecipeRow(this)" style="border:none; background:transparent; color:var(--danger); font-size:16px; cursor:pointer;">✕</button>
            `;
            wrap.appendChild(row);
            yzSelect2(row.querySelector('select'));
            recalcRecipe();
        }

        function removeRecipeRow(btn) {
            const row = btn.closest('.recipe-row');
            const sel = row.querySelector('select');
            if (window.jQuery && jQuery(sel).data('select2')) jQuery(sel).select2('destroy');
            row.remove();
            recalcRecipe();
        }

        function recalcRecipe() {
            let total = 0;
            document.querySelectorAll('#recipeRows .recipe-row').forEach(function (row) {
                const itemId = parseInt(row.querySelector('select').value);
                const qty = parseFloat(row.querySelector('input[type=number]').value) || 0;
                const item = componentItems.find(function (it) { return it.item_id === itemId; });
                const cost = item ? item.cost : 0;
                const rowTotal = cost * qty;
                row.querySelector('.row-unit-cost').textContent = '$' + cost.toFixed(2);
                row.querySelector('.row-total').textContent = '$' + rowTotal.toFixed(2);
                total += rowTotal;
            });
            document.getElementById('recipeTotalDisplay').textContent = '$' + total.toFixed(2);
            const profit = parseFloat(document.getElementById('productProfit').value) || 0;
            document.getElementById('sellingPriceDisplay').textContent = '$' + (total * (1 + profit / 100)).toFixed(2);
        }

        function openProductModal(productId) {
            document.getElementById('productForm').reset();
            document.getElementById('recipeRows').innerHTML = '';
            recipeRowCount = 0;

            if (productId && productsData[productId]) {
                const p = productsData[productId];
                document.getElementById('productModalTitle').textContent = 'Edit Finished Product';
                document.getElementById('productAction').value = 'update';
                document.getElementById('productId').value = p.id;
                document.getElementById('productName').value = p.name;
                jQuery('#productCategory').val(p.category_id).trigger('change');
                document.getElementById('productUnit').value = p.unit_id;
                document.getElementById('productFamilyCode').value = p.family_code;
                document.getElementById('productMinStock').value = p.min_stock_qty;
                document.getElementById('productProfit').value = p.profit;
                document.getElementById('productStatus').value = p.status;
                p.recipe.forEach(function (r) { addRecipeRow(r.item_id, r.qty); });
            } else {
                document.getElementById('productModalTitle').textContent = 'New Finished Product';
                document.getElementById('productAction').value = 'add';
                document.getElementById('productId').value = '';
                document.getElementById('productProfit').value = 60;
                jQuery('#productCategory').val('').trigger('change');
                addRecipeRow();
            }
            recalcRecipe();
            openYzModal('productModal');
        }

        function filterProductCards() {
            var q = document.getElementById('productSearch').value.trim().toLowerCase();
            var category = document.getElementById('productCategoryFilter').value;
            document.querySelectorAll('#productCards [data-name]').forEach(function (card) {
                var matchesSearch = card.getAttribute('data-name').indexOf(q) !== -1;
                var matchesCategory = !category || card.getAttribute('data-category') === category;
                card.style.display = (matchesSearch && matchesCategory) ? '' : 'none';
            });
        }
        function resetProductFilters() {
            document.getElementById('productSearch').value = '';
            document.getElementById('productCategoryFilter').value = '';
            filterProductCards();
        }

        function openBarcodeModal(name, code) {
            document.getElementById('barcodeName').textContent = name;
            document.getElementById('barcodeCodeLabel').textContent = code;
            openYzModal('barcodeModal');
            setTimeout(function () {
                JsBarcode('#barcodeSvg', code, { format: 'CODE128', width: 2, height: 60, displayValue: true, font: 'Plus Jakarta Sans' });
            }, 50);
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
