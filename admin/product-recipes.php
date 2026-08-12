<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$catColors = ['#c8924b', '#b5683c', '#9a4f56', '#a06a2c', '#8a7b4a', '#5d7a6b'];

try {
    $productsStmt = $pdo->query("
        SELECT fp.product_id, fp.product_name, fp.product_code, fp.calculated_cost_usd, fp.selling_price_usd, u.abbreviation
        FROM finished_products fp INNER JOIN units u ON fp.unit_id = u.unit_id
        WHERE fp.status = 'active' ORDER BY fp.product_name ASC
    ");
    $products = $productsStmt->fetchAll();

    $compStmt = $pdo->query("
        SELECT fpc.product_id, fpc.quantity_used, fpc.total_cost_usd, i.item_name, u.abbreviation
        FROM finished_product_components fpc
        INNER JOIN items i ON fpc.item_id = i.item_id
        INNER JOIN units u ON i.unit_id = u.unit_id
        ORDER BY i.item_name ASC
    ");
    $componentsByProduct = [];
    foreach ($compStmt->fetchAll() as $c) {
        $componentsByProduct[$c['product_id']][] = $c;
    }
} catch (PDOException $e) {
    $products = [];
    $componentsByProduct = [];
}

$navCrumb = 'Production';
$navTitle = 'Product Recipes';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta">Read-only recipe viewer · ingredient breakdown per product</span>
            <div class="spacer"></div>
            <div class="search-box" style="width:240px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="recipeSearch" placeholder="Search products…" oninput="filterRecipeCards()">
            </div>
        </div>

        <?php if (!empty($products)): ?>
            <div class="yz-grid-2" id="recipeCards">
                <?php foreach ($products as $i => $product): ?>
                    <?php
                    $color = $catColors[$i % count($catColors)];
                    $components = $componentsByProduct[$product['product_id']] ?? [];
                    ?>
                    <div class="yz-card" style="padding:20px;" data-name="<?= htmlspecialchars(mb_strtolower($product['product_name'] . ' ' . $product['product_code'])); ?>">
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
                            <div style="width:42px; height:42px; flex:none; border-radius:12px; background:<?= $color; ?>22; color:<?= $color; ?>; display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-weight:800; font-size:16px;"><?= htmlspecialchars(mb_substr($product['product_name'], 0, 1)); ?></div>
                            <div style="flex:1;"><div style="font-family:var(--font-display); font-weight:800; font-size:15px;"><?= htmlspecialchars($product['product_name']); ?></div><div style="font-size:12px; color:var(--text-faint);"><?= htmlspecialchars($product['product_code']); ?></div></div>
                        </div>
                        <table style="width:100%; border-collapse:collapse;">
                            <thead><tr style="text-align:left; color:var(--text-faint); font-size:10.5px; text-transform:uppercase; letter-spacing:.04em;"><th style="padding:5px 0; font-weight:600;">Ingredient</th><th style="padding:5px 0; font-weight:600; text-align:right;">Qty</th><th style="padding:5px 0; font-weight:600; text-align:right;">Cost</th></tr></thead>
                            <tbody>
                                <?php if (!empty($components)): ?>
                                    <?php foreach ($components as $c): ?>
                                        <tr style="border-top:1px solid var(--border-soft);">
                                            <td style="padding:7px 0; font-size:13px;"><?= htmlspecialchars($c['item_name']); ?></td>
                                            <td style="padding:7px 0; text-align:right; font-size:12.5px; color:var(--text-muted);"><?= rtrim(rtrim(number_format($c['quantity_used'], 3), '0'), '.'); ?> <?= htmlspecialchars($c['abbreviation']); ?></td>
                                            <td style="padding:7px 0; text-align:right; font-family:var(--font-display); font-weight:600; font-size:12.5px;">$<?= number_format($c['total_cost_usd'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" style="padding:10px 0; color:var(--text-faint); font-size:12.5px;">No components defined.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div style="display:flex; justify-content:space-between; margin-top:14px; padding-top:12px; border-top:1px dashed var(--border-input);">
                            <div><span style="font-size:11px; color:var(--text-faint);">Recipe cost</span> <span style="font-family:var(--font-display); font-weight:800; font-size:15px;">$<?= number_format($product['calculated_cost_usd'], 2); ?></span></div>
                            <div><span style="font-size:11px; color:var(--text-faint);">Sells at</span> <span style="font-family:var(--font-display); font-weight:800; font-size:15px; color:var(--success);">$<?= number_format($product['selling_price_usd'], 2); ?></span></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="yz-card" style="text-align:center; color:var(--text-faint);">No finished products found. <a href="finished-products.php" style="color:var(--caramel);">Add a finished product</a> first.</div>
        <?php endif; ?>

    </main>

    <script>
        function filterRecipeCards() {
            var q = document.getElementById('recipeSearch').value.trim().toLowerCase();
            document.querySelectorAll('#recipeCards [data-name]').forEach(function (card) {
                card.style.display = card.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
