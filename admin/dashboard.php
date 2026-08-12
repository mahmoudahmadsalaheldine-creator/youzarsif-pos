<?php
session_start();
require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

try {
    $monthStmt = $pdo->query("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(total_usd), 0) AS revenue
        FROM sales WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
    ");
    $monthStats = $monthStmt->fetch();

    $lastMonthStmt = $pdo->query("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(total_usd), 0) AS revenue
        FROM sales WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)
    ");
    $lastMonthStats = $lastMonthStmt->fetch();

    $cogsStmt = $pdo->query("
        SELECT COALESCE(SUM(si.quantity * fp.calculated_cost_usd), 0) AS cogs
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.sale_id
        INNER JOIN finished_products fp ON si.product_id = fp.product_id
        WHERE s.status = 'completed' AND MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE())
    ");
    $cogs = (float) $cogsStmt->fetch()['cogs'];
} catch (PDOException $e) {
    $monthStats = ['cnt' => 0, 'revenue' => 0];
    $lastMonthStats = ['cnt' => 0, 'revenue' => 0];
    $cogs = 0;
}

$revenue = (float) $monthStats['revenue'];
$grossProfit = $revenue - $cogs;
$revDelta = $lastMonthStats['revenue'] > 0 ? round(($revenue - $lastMonthStats['revenue']) / $lastMonthStats['revenue'] * 100, 1) : 0;
$salesDelta = $lastMonthStats['cnt'] > 0 ? round(($monthStats['cnt'] - $lastMonthStats['cnt']) / $lastMonthStats['cnt'] * 100, 1) : 0;

try {
    $lowStockStmt = $pdo->query("
        SELECT i.item_id, i.item_name, i.min_stock_qty, u.abbreviation,
            COALESCE(SUM(CASE WHEN sm.direction = 'in' AND sm.status = 'active' THEN sm.quantity WHEN sm.direction = 'out' AND sm.status = 'active' THEN -sm.quantity ELSE 0 END), 0) AS current_stock
        FROM items i
        INNER JOIN units u ON i.unit_id = u.unit_id
        LEFT JOIN stock_movements sm ON sm.item_id = i.item_id
        WHERE i.status = 'active'
        GROUP BY i.item_id, i.item_name, i.min_stock_qty, u.abbreviation
        HAVING current_stock < i.min_stock_qty
        ORDER BY current_stock ASC LIMIT 3
    ");
    $lowStock = $lowStockStmt->fetchAll();

    $lowStockCountStmt = $pdo->query("
        SELECT COUNT(*) AS cnt FROM (
            SELECT i.item_id, COALESCE(SUM(CASE WHEN sm.direction = 'in' AND sm.status = 'active' THEN sm.quantity WHEN sm.direction = 'out' AND sm.status = 'active' THEN -sm.quantity ELSE 0 END), 0) AS current_stock, i.min_stock_qty
            FROM items i LEFT JOIN stock_movements sm ON sm.item_id = i.item_id WHERE i.status = 'active' GROUP BY i.item_id, i.min_stock_qty
        ) t WHERE current_stock < min_stock_qty
    ");
    $lowStockCount = (int) $lowStockCountStmt->fetch()['cnt'];
} catch (PDOException $e) {
    $lowStock = [];
    $lowStockCount = 0;
}

try {
    $revBarsStmt = $pdo->query("
        SELECT DATE(created_at) AS d, COALESCE(SUM(total_usd), 0) AS rev
        FROM sales WHERE status = 'completed' AND created_at >= CURDATE() - INTERVAL 6 DAY
        GROUP BY DATE(created_at)
    ");
    $revByDate = [];
    foreach ($revBarsStmt->fetchAll() as $r) {
        $revByDate[$r['d']] = (float) $r['rev'];
    }
} catch (PDOException $e) {
    $revByDate = [];
}
$revBars = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $revBars[] = ['label' => date('D', strtotime($d)), 'val' => $revByDate[$d] ?? 0];
}
$maxRev = max(1, max(array_column($revBars, 'val')));

try {
    $topProductsStmt = $pdo->query("
        SELECT fp.product_name, SUM(si.quantity) AS units, SUM(si.total_price_usd) AS revenue
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.sale_id
        INNER JOIN finished_products fp ON si.product_id = fp.product_id
        WHERE s.status = 'completed'
        GROUP BY fp.product_id, fp.product_name
        ORDER BY units DESC LIMIT 5
    ");
    $topProducts = $topProductsStmt->fetchAll();
} catch (PDOException $e) {
    $topProducts = [];
}

try {
    $recentSalesStmt = $pdo->query("
        SELECT s.sale_ref, s.total_usd, s.created_at, l.location_name,
            (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.sale_id) AS item_count
        FROM sales s INNER JOIN locations l ON s.location_id = l.location_id
        WHERE s.status = 'completed' ORDER BY s.created_at DESC LIMIT 5
    ");
    $recentSales = $recentSalesStmt->fetchAll();
} catch (PDOException $e) {
    $recentSales = [];
}

$catColors = ['#c8924b', '#b5683c', '#9a4f56', '#a06a2c', '#8a7b4a', '#5d7a6b'];

$navCrumb = 'Overview';
$navTitle = 'Dashboard';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="yz-grid-4" style="margin-bottom:18px;">
            <div class="yz-card">
                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <span style="font-size:12.5px; color:var(--text-muted); font-weight:600;">Revenue (This Month)</span>
                    <span style="width:34px; height:34px; border-radius:11px; background:var(--success-soft); color:var(--success); display:flex; align-items:center; justify-content:center; font-size:16px;">＄</span>
                </div>
                <div style="font-family:var(--font-display); font-weight:800; font-size:28px; margin-top:10px; line-height:1;">$<?= number_format($revenue, 0); ?></div>
                <div style="font-size:12px; margin-top:6px; color:<?= $revDelta >= 0 ? 'var(--success)' : 'var(--danger)'; ?>; font-weight:600;"><?= $revDelta >= 0 ? '▲' : '▼'; ?> <?= abs($revDelta); ?>% vs last month</div>
            </div>
            <div class="yz-card">
                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <span style="font-size:12.5px; color:var(--text-muted); font-weight:600;">Sales Count</span>
                    <span style="width:34px; height:34px; border-radius:11px; background:#f3e6cf; color:var(--caramel); display:flex; align-items:center; justify-content:center; font-size:16px;">▤</span>
                </div>
                <div style="font-family:var(--font-display); font-weight:800; font-size:28px; margin-top:10px; line-height:1;"><?= (int) $monthStats['cnt']; ?></div>
                <div style="font-size:12px; margin-top:6px; color:<?= $salesDelta >= 0 ? 'var(--success)' : 'var(--danger)'; ?>; font-weight:600;"><?= $salesDelta >= 0 ? '▲' : '▼'; ?> <?= abs($salesDelta); ?>% vs last month</div>
            </div>
            <div class="yz-card">
                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <span style="font-size:12.5px; color:var(--text-muted); font-weight:600;">Gross Profit</span>
                    <span style="width:34px; height:34px; border-radius:11px; background:#f1e2e4; color:#9a4f56; display:flex; align-items:center; justify-content:center; font-size:16px;">◰</span>
                </div>
                <div style="font-family:var(--font-display); font-weight:800; font-size:28px; margin-top:10px; line-height:1;">$<?= number_format($grossProfit, 0); ?></div>
                <div style="font-size:12px; margin-top:6px; color:var(--text-muted); font-weight:600;"><?= $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : 0; ?>% margin</div>
            </div>
            <div class="yz-card">
                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <span style="font-size:12.5px; color:var(--text-muted); font-weight:600;">Low Stock Items</span>
                    <span style="width:34px; height:34px; border-radius:11px; background:var(--danger-soft); color:var(--danger); display:flex; align-items:center; justify-content:center; font-size:16px;">⚠</span>
                </div>
                <div style="font-family:var(--font-display); font-weight:800; font-size:28px; margin-top:10px; line-height:1;"><?= $lowStockCount; ?></div>
                <div style="font-size:12px; margin-top:6px; color:var(--danger); font-weight:600;">Needs restock</div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1.6fr 1fr; gap:16px; margin-bottom:18px;">
            <div class="yz-card">
                <div style="display:flex; align-items:baseline; justify-content:space-between; margin-bottom:18px;">
                    <div style="font-family:var(--font-display); font-weight:800; font-size:16px;">Daily Revenue</div>
                    <div style="font-size:12px; color:var(--text-faint);">Last 7 days · USD</div>
                </div>
                <div style="overflow-x:auto; -webkit-overflow-scrolling:touch; margin:0 -4px; padding:0 4px 4px;">
                <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; height:180px; min-width:320px;">
                    <?php foreach ($revBars as $i => $b): ?>
                        <?php $h = max(4, round($b['val'] / $maxRev * 160)); $isToday = $i === count($revBars) - 1; ?>
                        <div style="flex:1; min-width:40px; display:flex; flex-direction:column; align-items:center; gap:8px; height:100%; justify-content:flex-end;">
                            <div style="font-family:var(--font-display); font-weight:700; font-size:11px; color:#7a5d40;">$<?= number_format($b['val'], 0); ?></div>
                            <div style="width:100%; max-width:42px; height:<?= $h; ?>px; border-radius:10px 10px 4px 4px; background:<?= $isToday ? 'var(--caramel)' : '#e3cda6'; ?>;"></div>
                            <div style="font-size:11px; color:var(--text-faint);"><?= $b['label']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                </div>
            </div>
            <div class="yz-card">
                <div style="font-family:var(--font-display); font-weight:800; font-size:16px; margin-bottom:14px;">Top Products</div>
                <?php if (!empty($topProducts)): ?>
                    <?php foreach ($topProducts as $i => $p): ?>
                        <?php $color = $catColors[$i % count($catColors)]; ?>
                        <div style="display:flex; align-items:center; gap:12px; padding:9px 0; border-bottom:1px solid var(--border-soft);">
                            <div style="width:34px; height:34px; flex:none; border-radius:10px; background:<?= $color; ?>22; color:<?= $color; ?>; display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-weight:800; font-size:14px;"><?= htmlspecialchars(mb_substr($p['product_name'], 0, 1)); ?></div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-family:var(--font-display); font-weight:700; font-size:13.5px;"><?= htmlspecialchars($p['product_name']); ?></div>
                                <div style="font-size:11.5px; color:var(--text-faint);"><?= (int) $p['units']; ?> sold</div>
                            </div>
                            <div style="font-family:var(--font-display); font-weight:800; font-size:14px;">$<?= number_format($p['revenue'], 0); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="color:var(--text-faint); font-size:13px; padding:10px 0;">No sales yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1.6fr 1fr; gap:16px;">
            <div class="yz-card yz-card-flush">
                <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 16px 12px;">
                    <div style="font-family:var(--font-display); font-weight:800; font-size:16px;">Recent Sales</div>
                    <a href="pos.php" style="font-size:12px; color:var(--caramel); font-weight:600; text-decoration:none;">View all</a>
                </div>
                <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; min-width:520px;">
                    <thead><tr style="text-align:left; color:var(--text-faint); font-size:11px; text-transform:uppercase; letter-spacing:.05em;">
                        <th style="padding:8px 16px; font-weight:600;">Ref</th><th style="padding:8px 16px; font-weight:600;">Store</th><th style="padding:8px 16px; font-weight:600;">Items</th><th style="padding:8px 16px; font-weight:600; text-align:right;">Total</th><th style="padding:8px 16px; font-weight:600; text-align:right;">Time</th>
                    </tr></thead>
                    <tbody>
                        <?php if (!empty($recentSales)): ?>
                            <?php foreach ($recentSales as $r): ?>
                                <tr style="border-top:1px solid var(--border-soft);">
                                    <td style="padding:11px 16px; font-family:var(--font-display); font-weight:700; font-size:12.5px;"><?= htmlspecialchars($r['sale_ref']); ?></td>
                                    <td style="padding:11px 16px; font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($r['location_name']); ?></td>
                                    <td style="padding:11px 16px; font-size:13px; color:var(--text-muted);"><?= (int) $r['item_count']; ?> items</td>
                                    <td style="padding:11px 16px; font-family:var(--font-display); font-weight:700; font-size:13px; text-align:right;">$<?= number_format($r['total_usd'], 2); ?></td>
                                    <td style="padding:11px 16px; font-size:12px; color:var(--text-faint); text-align:right;"><?= htmlspecialchars(date('H:i', strtotime($r['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="padding:24px; text-align:center; color:var(--text-faint);">No sales recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="yz-card">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:14px;">
                    <span style="color:var(--danger); font-size:17px;">⚠</span>
                    <div style="font-family:var(--font-display); font-weight:800; font-size:16px;">Low Stock Alerts</div>
                </div>
                <?php if (!empty($lowStock)): ?>
                    <?php foreach ($lowStock as $l): ?>
                        <div style="display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid var(--border-soft);">
                            <div style="flex:1; min-width:0;">
                                <div style="font-family:var(--font-display); font-weight:700; font-size:13.5px;"><?= htmlspecialchars($l['item_name']); ?></div>
                                <div style="font-size:11.5px; color:var(--text-faint);">Min <?= rtrim(rtrim(number_format($l['min_stock_qty'], 3), '0'), '.'); ?></div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-family:var(--font-display); font-weight:800; font-size:14px; color:var(--danger);"><?= rtrim(rtrim(number_format($l['current_stock'], 3), '0'), '.'); ?></div>
                                <div style="font-size:11px; color:var(--text-faint);"><?= htmlspecialchars($l['abbreviation']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="color:var(--success); font-size:13px; font-weight:600;">✓ All items well stocked.</div>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <?php require_once "../includes/admin_footer.php"; ?>
