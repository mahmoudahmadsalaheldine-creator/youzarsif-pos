<?php
$currentPage = basename($_SERVER['PHP_SELF']);

$lowStockCount = 0;
try {
    $lowStockStmt = $pdo->query("
        SELECT COUNT(*) AS cnt FROM (
            SELECT i.item_id, i.min_stock_qty,
                COALESCE(SUM(CASE WHEN sm.direction = 'in' AND sm.status = 'active' THEN sm.quantity
                                  WHEN sm.direction = 'out' AND sm.status = 'active' THEN -sm.quantity
                                  ELSE 0 END), 0) AS current_stock
            FROM items i
            LEFT JOIN stock_movements sm ON sm.item_id = i.item_id
            WHERE i.status = 'active'
            GROUP BY i.item_id, i.min_stock_qty
        ) t
        WHERE t.current_stock < t.min_stock_qty
    ");
    $lowStockCount = (int) $lowStockStmt->fetch()['cnt'];
} catch (PDOException $e) {
    $lowStockCount = 0;
}

function navLink($page, $href, $icon, $label, $currentPage, $badge = null)
{
    $active = $currentPage === $page ? 'active' : '';
    $badgeHtml = $badge ? '<span class="sidebar-badge">' . htmlspecialchars($badge) . '</span>' : '';
    $plainLabel = htmlspecialchars(strip_tags($label));
    echo '<li><a href="' . $href . '" class="' . $active . '" title="' . $plainLabel . '"><span class="nav-glyph">' . $icon . '</span><span class="nav-label">' . $label . '</span>' . $badgeHtml . '</a></li>';
}
?>

<aside class="admin-sidebar nav-scroll" id="adminSidebar">
    <div class="sidebar-brand">
        <img src="../assets/img/mainlogo.png" alt="Youzarsif" class="sidebar-brand-logo">
        <img src="../assets/img/mainminilogo.png" alt="Youzarsif" class="sidebar-brand-logo-mini">
        <button type="button" class="sidebar-fold-btn" onclick="toggleSidebarFold()" title="Collapse sidebar">
            <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
    </div>

    <ul class="sidebar-menu">
        <li class="menu-title">Main</li>
        <?php navLink('dashboard.php', 'dashboard.php', '◫', 'Dashboard', $currentPage); ?>

        <li class="menu-title">Setup</li>
        <?php navLink('categories.php', 'categories.php', '⊞', 'Categories', $currentPage); ?>
        <?php navLink('units.php', 'units.php', '⚖', 'Units', $currentPage); ?>
        <?php navLink('locations.php', 'locations.php', '⌂', 'Locations', $currentPage); ?>
        <?php navLink('users.php', 'users.php', '◉', 'Staff Accounts', $currentPage); ?>

        <li class="menu-title">Factory &amp; Inventory</li>
        <?php navLink('items.php', 'items.php', '▦', 'Ingredients &amp; Items', $currentPage); ?>
        <?php navLink('stock-movements.php', 'stock-movements.php', '⇅', 'Stock Movements', $currentPage); ?>
        <?php navLink('low-stock-alerts.php', 'low-stock-alerts.php', '⚠', 'Low Stock Alerts', $currentPage, $lowStockCount ?: null); ?>

        <li class="menu-title">Production</li>
        <?php navLink('finished-products.php', 'finished-products.php', '✦', 'Finished Products', $currentPage); ?>
        <?php navLink('product-recipes.php', 'product-recipes.php', '☰', 'Product Recipes', $currentPage); ?>
        <?php navLink('production-batches.php', 'production-batches.php', '⚙', 'Production Batches', $currentPage); ?>
        <?php navLink('factory-to-store.php', 'factory-to-store.php', '⇄', 'Factory to Store', $currentPage); ?>

        <li class="menu-title">Finance</li>
        <?php navLink('exchange-rates.php', 'exchange-rates.php', '＄', 'Exchange Rates', $currentPage); ?>
        <?php navLink('expenses.php', 'expenses.php', '⊟', 'Expenses', $currentPage); ?>

        <li class="menu-title">Point of Sale</li>
        <?php navLink('pos.php', 'pos.php', '▤', 'Touch POS (Factory)', $currentPage); ?>

        <li class="menu-title">Reports</li>
        <?php navLink('reports.php', 'reports.php', '◰', 'Reports', $currentPage); ?>
        <?php navLink('break-even.php', 'break-even.php', '⊿', 'Break-even', $currentPage); ?>
    </ul>
</aside>
