<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$dateFrom = $_GET["date_from"] ?? date("Y-m-01");
$dateTo = $_GET["date_to"] ?? date("Y-m-d");
$tab = $_GET["tab"] ?? "sales";

try {
    $salesStmt = $pdo->prepare("
        SELECT COUNT(*) AS total_sales, COALESCE(SUM(discount_amount_usd), 0) AS total_discount,
            COALESCE(SUM(total_usd), 0) AS total_revenue_usd, COALESCE(SUM(quantity_sum), 0) AS items_sold
        FROM sales s LEFT JOIN (SELECT sale_id, SUM(quantity) AS quantity_sum FROM sale_items GROUP BY sale_id) qs ON qs.sale_id = s.sale_id
        WHERE status = 'completed' AND DATE(s.created_at) BETWEEN :f AND :t
    ");
    $salesStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $salesSummary = $salesStmt->fetch();

    $bestSellingStmt = $pdo->prepare("
        SELECT fp.product_name, SUM(si.quantity) AS qty, SUM(si.total_price_usd) AS revenue
        FROM sale_items si INNER JOIN finished_products fp ON si.product_id = fp.product_id INNER JOIN sales s ON si.sale_id = s.sale_id
        WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN :f AND :t
        GROUP BY fp.product_id, fp.product_name ORDER BY qty DESC LIMIT 5
    ");
    $bestSellingStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $bestSelling = $bestSellingStmt->fetchAll();

    $perStoreStmt = $pdo->prepare("
        SELECT l.location_name, COUNT(*) AS tx, COALESCE(SUM(s.total_usd), 0) AS revenue
        FROM sales s INNER JOIN locations l ON s.location_id = l.location_id
        WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN :f AND :t
        GROUP BY l.location_id, l.location_name ORDER BY revenue DESC
    ");
    $perStoreStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $perStore = $perStoreStmt->fetchAll();

    $expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_usd), 0) AS total FROM expenses WHERE DATE(created_at) BETWEEN :f AND :t");
    $expStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $totalExpenses = (float) $expStmt->fetch()['total'];

    $cogsStmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity * fp.calculated_cost_usd), 0) AS cogs
        FROM sale_items si INNER JOIN sales s ON si.sale_id = s.sale_id INNER JOIN finished_products fp ON si.product_id = fp.product_id
        WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN :f AND :t
    ");
    $cogsStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $cogs = (float) $cogsStmt->fetch()['cogs'];

    $ingredientStockRowsStmt = $pdo->query("
        SELECT i.item_id, i.item_name, i.item_code, i.min_stock_qty, c.category_name, u.abbreviation,
            COALESCE(stk.current_stock, 0) AS current_stock,
            COALESCE(cost.avg_cost, 0) AS avg_cost
        FROM items i
        INNER JOIN categories c ON i.category_id = c.category_id
        INNER JOIN units u ON i.unit_id = u.unit_id
        LEFT JOIN (
            SELECT item_id, SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END) AS current_stock
            FROM stock_movements GROUP BY item_id
        ) stk ON stk.item_id = i.item_id
        LEFT JOIN (
            SELECT item_id, SUM(total_cost_usd) / SUM(quantity) AS avg_cost
            FROM stock_movements WHERE movement_type = 'purchase_in' AND status = 'active' GROUP BY item_id
        ) cost ON cost.item_id = i.item_id
        WHERE i.status = 'active'
        ORDER BY i.item_name ASC
    ");
    $ingredientStockRows = $ingredientStockRowsStmt->fetchAll();
    $ingredientStockValue = 0;
    foreach ($ingredientStockRows as $r) {
        $ingredientStockValue += (float) $r['current_stock'] * (float) $r['avg_cost'];
    }

    $productStockRowsStmt = $pdo->query("
        SELECT fp.product_id, fp.product_name, fp.product_code, fp.min_stock_qty, fp.calculated_cost_usd, c.category_name, u.abbreviation,
            COALESCE(stk.current_stock, 0) AS current_stock
        FROM finished_products fp
        INNER JOIN categories c ON fp.category_id = c.category_id
        INNER JOIN units u ON fp.unit_id = u.unit_id
        LEFT JOIN (
            SELECT product_id, SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END) AS current_stock
            FROM product_stock_movements GROUP BY product_id
        ) stk ON stk.product_id = fp.product_id
        WHERE fp.status = 'active'
        ORDER BY fp.product_name ASC
    ");
    $productStockRows = $productStockRowsStmt->fetchAll();
    $productStockValue = 0;
    foreach ($productStockRows as $r) {
        $productStockValue += (float) $r['current_stock'] * (float) $r['calculated_cost_usd'];
    }

    $expByCatStmt = $pdo->prepare("
        SELECT ec.category_name, COUNT(*) AS total_entries, COALESCE(SUM(e.amount_usd), 0) AS total_usd
        FROM expenses e INNER JOIN expense_categories ec ON e.expense_category_id = ec.expense_category_id
        WHERE DATE(e.created_at) BETWEEN :f AND :t
        GROUP BY ec.expense_category_id, ec.category_name ORDER BY total_usd DESC
    ");
    $expByCatStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $expensesByCategory = $expByCatStmt->fetchAll();

    $dailyStmt = $pdo->prepare("
        SELECT DATE(created_at) AS d, COUNT(*) AS tx, COALESCE(SUM(total_usd), 0) AS rev
        FROM sales WHERE status = 'completed' AND DATE(created_at) BETWEEN :f AND :t GROUP BY DATE(created_at) ORDER BY d DESC
    ");
    $dailyStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $daily = $dailyStmt->fetchAll();
} catch (PDOException $e) {
    $salesSummary = ['total_sales' => 0, 'total_discount' => 0, 'total_revenue_usd' => 0, 'items_sold' => 0];
    $bestSelling = [];
    $perStore = [];
    $totalExpenses = 0;
    $cogs = 0;
    $ingredientStockRows = [];
    $ingredientStockValue = 0;
    $productStockRows = [];
    $productStockValue = 0;
    $expensesByCategory = [];
    $daily = [];
}

$revenue = (float) $salesSummary['total_revenue_usd'];
$gross = $revenue - $cogs;
$net = $gross - $totalExpenses;
$pnlMargin = $revenue > 0 ? round($net / $revenue * 100) : 0;
$maxStoreRev = max(1, ...array_map(fn($s) => (float) $s['revenue'], $perStore ?: [['revenue' => 1]]));

$navCrumb = 'Reports';
$navTitle = 'Reports & Analytics';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <form method="GET" class="toolbar">
            <div style="display:inline-flex; gap:4px; background:var(--bg); padding:4px; border-radius:9999px;">
                <?php foreach (['sales' => 'Sales', 'pnl' => 'Profit & Loss', 'stock' => 'Stock', 'daily' => 'Daily'] as $key => $label): ?>
                    <button type="submit" name="tab" value="<?= $key; ?>" class="btn-yz" style="<?= $tab === $key ? 'background:var(--chocolate); color:var(--cream);' : 'background:transparent; color:var(--text-muted);'; ?>"><?= $label; ?></button>
                <?php endforeach; ?>
            </div>
            <div class="spacer"></div>
            <div class="search-box" style="width:auto;">
                📅 <input type="date" name="date_from" value="<?= $dateFrom; ?>" style="border:none; width:100px;"> →
                <input type="date" name="date_to" value="<?= $dateTo; ?>" style="border:none; width:100px;">
            </div>
            <a href="<?= BASE_URL; ?>actions/report_pdf_action.php?date_from=<?= $dateFrom; ?>&date_to=<?= $dateTo; ?>" target="_blank" class="btn-yz btn-yz-danger">⎙ Export PDF</a>
        </form>

        <?php if ($tab === 'sales'): ?>
            <div class="yz-grid-4" style="margin-bottom:16px;">
                <div class="yz-card"><div style="font-size:12px; color:var(--text-muted); font-weight:600;">Total Sales</div><div style="font-family:var(--font-display); font-weight:800; font-size:26px; margin-top:8px;"><?= (int) $salesSummary['total_sales']; ?></div></div>
                <div class="yz-card"><div style="font-size:12px; color:var(--text-muted); font-weight:600;">Revenue</div><div style="font-family:var(--font-display); font-weight:800; font-size:26px; margin-top:8px;">$<?= number_format($revenue, 0); ?></div></div>
                <div class="yz-card"><div style="font-size:12px; color:var(--text-muted); font-weight:600;">Avg. Sale</div><div style="font-family:var(--font-display); font-weight:800; font-size:26px; margin-top:8px;">$<?= number_format($salesSummary['total_sales'] > 0 ? $revenue / $salesSummary['total_sales'] : 0, 2); ?></div></div>
                <div class="yz-card"><div style="font-size:12px; color:var(--text-muted); font-weight:600;">Items Sold</div><div style="font-family:var(--font-display); font-weight:800; font-size:26px; margin-top:8px;"><?= number_format($salesSummary['items_sold'], 0); ?></div></div>
            </div>

            <!-- Ties Sales → COGS → Expenses → Profit together in one strip, instead of profit only living in the P&L tab -->
            <div class="yz-card" style="margin-bottom:16px; padding:18px 22px;">
                <div style="font-family:var(--font-display); font-weight:800; font-size:14px; margin-bottom:14px;">How Sales Become Profit</div>
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <?php
                    $flow = [
                        ['Revenue', $revenue, 'var(--text)', null],
                        ['Cost of Goods', -$cogs, 'var(--danger)', '−'],
                        ['Gross Profit', $gross, 'var(--success)', '='],
                        ['Expenses', -$totalExpenses, 'var(--danger)', '−'],
                        ['Net Profit', $net, $net >= 0 ? 'var(--success)' : 'var(--danger)', '='],
                    ];
                    foreach ($flow as $i => $f): ?>
                        <?php if ($f[3]): ?><span style="font-size:18px; color:var(--text-faint); font-weight:700;"><?= $f[3]; ?></span><?php endif; ?>
                        <div style="background:var(--soft-bg); border-radius:12px; padding:10px 16px; text-align:center; min-width:110px;">
                            <div style="font-size:10.5px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.04em;"><?= $f[0]; ?></div>
                            <div style="font-family:var(--font-display); font-weight:800; font-size:16px; color:<?= $f[2]; ?>; margin-top:2px;"><?= $f[1] < 0 ? '−$' . number_format(abs($f[1]), 0) : '$' . number_format($f[1], 0); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1.4fr 1fr; gap:16px;">
                <div class="yz-card">
                    <div style="font-family:var(--font-display); font-weight:800; font-size:15px; margin-bottom:16px;">Best Selling Products</div>
                    <?php if (!empty($bestSelling)): ?>
                        <?php foreach ($bestSelling as $p): ?>
                            <div style="display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid var(--border-soft);"><span style="font-weight:600; font-size:13.5px;"><?= htmlspecialchars($p['product_name']); ?></span><span style="font-family:var(--font-display); font-weight:700; font-size:13px;">$<?= number_format($p['revenue'], 0); ?> · <?= (int) $p['qty']; ?> sold</span></div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="color:var(--text-faint); font-size:13px;">No sales in this period.</div>
                    <?php endif; ?>
                </div>
                <div class="yz-card">
                    <div style="font-family:var(--font-display); font-weight:800; font-size:15px; margin-bottom:16px;">Sales per Store</div>
                    <?php foreach ($perStore as $s): ?>
                        <?php $barW = round($s['revenue'] / $maxStoreRev * 100); ?>
                        <div style="margin-bottom:14px;"><div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:5px;"><span style="font-weight:600;"><?= htmlspecialchars($s['location_name']); ?></span><span style="color:var(--text-muted);">$<?= number_format($s['revenue'], 0); ?> · <?= (int) $s['tx']; ?> sales</span></div><div style="height:9px; background:var(--bg); border-radius:9999px; overflow:hidden;"><div style="height:100%; width:<?= $barW; ?>%; background:var(--caramel); border-radius:9999px;"></div></div></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ($tab === 'pnl'): ?>
            <div style="display:grid; grid-template-columns:1.3fr 1fr; gap:16px;">
                <div class="yz-card" style="padding:24px;">
                    <div style="font-family:var(--font-display); font-weight:800; font-size:16px; margin-bottom:16px;">Profit &amp; Loss Statement</div>
                    <?php
                    $rows = [
                        ['Revenue', $revenue, 'var(--text)'],
                        ['Cost of Goods Sold', -$cogs, 'var(--danger)'],
                        ['Gross Profit', $gross, 'var(--success)'],
                        ['Operating Expenses', -$totalExpenses, 'var(--danger)'],
                        ['Net Profit', $net, 'var(--success)'],
                    ];
                    foreach ($rows as $r): ?>
                        <div style="display:flex; justify-content:space-between; padding:12px 0; border-bottom:1px solid var(--border-soft);"><span style="font-size:14px;"><?= $r[0]; ?></span><span style="font-family:var(--font-display); font-weight:800; font-size:15px; color:<?= $r[2]; ?>;"><?= $r[1] < 0 ? '−$' . number_format(abs($r[1]), 0) : '$' . number_format($r[1], 0); ?></span></div>
                    <?php endforeach; ?>
                </div>
                <div style="background:var(--success); color:#fff; border-radius:18px; padding:24px; display:flex; flex-direction:column; justify-content:center;">
                    <div style="font-size:13px; opacity:.75;">Net Profit Margin</div>
                    <div style="font-family:var(--font-display); font-weight:800; font-size:48px; line-height:1; margin:8px 0;"><?= $pnlMargin; ?>%</div>
                    <div style="font-size:13px; opacity:.75;">Healthy margin for a retail sweets operation.</div>
                </div>
            </div>

            <div class="yz-card" style="margin-top:16px;">
                <div style="font-family:var(--font-display); font-weight:800; font-size:15px; margin-bottom:16px;">Expenses by Category</div>
                <?php if (!empty($expensesByCategory)): ?>
                    <?php $expMax = max(1, max(array_column($expensesByCategory, 'total_usd'))); ?>
                    <?php foreach ($expensesByCategory as $e): ?>
                        <?php $barW = round($e['total_usd'] / $expMax * 100); ?>
                        <div style="margin-bottom:13px;">
                            <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:5px;">
                                <span style="font-weight:600;"><?= htmlspecialchars($e['category_name']); ?></span>
                                <span style="color:var(--text-muted);">$<?= number_format($e['total_usd'], 2); ?> · <?= (int) $e['total_entries']; ?> entries</span>
                            </div>
                            <div style="height:9px; background:var(--bg); border-radius:9999px; overflow:hidden;"><div style="height:100%; width:<?= $barW; ?>%; background:var(--danger); border-radius:9999px;"></div></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="color:var(--text-faint); font-size:13px;">No expenses in this period.</div>
                <?php endif; ?>
            </div>
        <?php elseif ($tab === 'stock'): ?>
            <div class="yz-grid-2" style="margin-bottom:18px;">
                <div class="yz-card" style="text-align:center; padding:24px;"><div style="font-size:13px; color:var(--text-muted); font-weight:600;">Ingredient Stock Value</div><div style="font-family:var(--font-display); font-weight:800; font-size:34px; margin-top:10px; color:var(--caramel);">$<?= number_format($ingredientStockValue, 0); ?></div></div>
                <div class="yz-card" style="text-align:center; padding:24px;"><div style="font-size:13px; color:var(--text-muted); font-weight:600;">Finished Product Value</div><div style="font-family:var(--font-display); font-weight:800; font-size:34px; margin-top:10px; color:#9a4f56;">$<?= number_format($productStockValue, 0); ?></div></div>
            </div>

            <!-- Ingredient Stock Value table -->
            <div class="toolbar">
                <span class="toolbar-meta">Ingredient Stock Value</span>
                <div class="spacer"></div>
                <div style="display:inline-flex; gap:4px; background:var(--bg); padding:4px; border-radius:9999px;" id="ingFilterPills">
                    <button type="button" class="ing-filter-pill active" data-filter="all" onclick="setIngFilter('all')" style="border:none; background:var(--chocolate); color:var(--cream); font-family:var(--font-body); font-weight:600; font-size:12px; padding:7px 14px; border-radius:9999px; cursor:pointer;">All</button>
                    <button type="button" class="ing-filter-pill" data-filter="low" onclick="setIngFilter('low')" style="border:none; background:transparent; color:var(--text-muted); font-family:var(--font-body); font-weight:600; font-size:12px; padding:7px 14px; border-radius:9999px; cursor:pointer;">Low</button>
                    <button type="button" class="ing-filter-pill" data-filter="out" onclick="setIngFilter('out')" style="border:none; background:transparent; color:var(--text-muted); font-family:var(--font-body); font-weight:600; font-size:12px; padding:7px 14px; border-radius:9999px; cursor:pointer;">Out</button>
                </div>
                <div class="search-box" style="width:220px;">
                    <span style="color:var(--caramel);">⌕</span>
                    <input type="text" id="ingSearch" placeholder="Search ingredients…" oninput="filterIngredientRows()">
                </div>
            </div>
            <div class="yz-table-wrap" style="margin-bottom:24px;">
                <table class="yz-table">
                    <thead><tr><th>Item</th><th>Code</th><th>Category</th><th class="text-end">Stock</th><th class="text-end">Avg Cost</th><th class="text-end">Value</th><th>Status</th></tr></thead>
                    <tbody id="ingredientRows">
                        <?php if (!empty($ingredientStockRows)): ?>
                            <?php foreach ($ingredientStockRows as $r): ?>
                                <?php
                                $stock = (float) $r['current_stock'];
                                $min = (float) $r['min_stock_qty'];
                                $value = $stock * (float) $r['avg_cost'];
                                if ($stock <= 0) { $statusKind = 'danger'; $statusLabel = 'Out'; $statusFilter = 'out'; }
                                elseif ($stock < $min) { $statusKind = 'warn'; $statusLabel = 'Low'; $statusFilter = 'low'; }
                                else { $statusKind = 'ok'; $statusLabel = 'OK'; $statusFilter = 'ok'; }
                                ?>
                                <tr data-name="<?= htmlspecialchars(mb_strtolower($r['item_name'] . ' ' . $r['item_code'] . ' ' . $r['category_name'])); ?>" data-status="<?= $statusFilter; ?>">
                                    <td style="font-weight:600; font-size:13.5px;"><?= htmlspecialchars($r['item_name']); ?></td>
                                    <td style="font-family:var(--font-display); font-weight:700; font-size:12px; color:var(--caramel);"><?= htmlspecialchars($r['item_code']); ?></td>
                                    <td style="font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($r['category_name']); ?></td>
                                    <td class="text-end"><?= rtrim(rtrim(number_format($stock, 3), '0'), '.'); ?> <?= htmlspecialchars($r['abbreviation']); ?></td>
                                    <td class="text-end" style="color:var(--text-muted);">$<?= number_format($r['avg_cost'], 2); ?></td>
                                    <td class="text-end" style="font-family:var(--font-display); font-weight:700;">$<?= number_format($value, 2); ?></td>
                                    <td><span class="yz-badge yz-badge-<?= $statusKind; ?>"><?= $statusLabel; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center" style="color:var(--text-faint); padding:32px 0;">No active ingredients found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Finished Product Value table -->
            <div class="toolbar">
                <span class="toolbar-meta">Finished Product Value</span>
                <div class="spacer"></div>
                <div style="display:inline-flex; gap:4px; background:var(--bg); padding:4px; border-radius:9999px;" id="prodFilterPills">
                    <button type="button" class="prod-filter-pill active" data-filter="all" onclick="setProdFilter('all')" style="border:none; background:var(--chocolate); color:var(--cream); font-family:var(--font-body); font-weight:600; font-size:12px; padding:7px 14px; border-radius:9999px; cursor:pointer;">All</button>
                    <button type="button" class="prod-filter-pill" data-filter="low" onclick="setProdFilter('low')" style="border:none; background:transparent; color:var(--text-muted); font-family:var(--font-body); font-weight:600; font-size:12px; padding:7px 14px; border-radius:9999px; cursor:pointer;">Low</button>
                    <button type="button" class="prod-filter-pill" data-filter="out" onclick="setProdFilter('out')" style="border:none; background:transparent; color:var(--text-muted); font-family:var(--font-body); font-weight:600; font-size:12px; padding:7px 14px; border-radius:9999px; cursor:pointer;">Out</button>
                </div>
                <div class="search-box" style="width:220px;">
                    <span style="color:var(--caramel);">⌕</span>
                    <input type="text" id="prodSearch" placeholder="Search products…" oninput="filterProductRows()">
                </div>
            </div>
            <div class="yz-table-wrap">
                <table class="yz-table">
                    <thead><tr><th>Product</th><th>Code</th><th>Category</th><th class="text-end">Stock</th><th class="text-end">Unit Cost</th><th class="text-end">Value</th><th>Status</th></tr></thead>
                    <tbody id="productValueRows">
                        <?php if (!empty($productStockRows)): ?>
                            <?php foreach ($productStockRows as $r): ?>
                                <?php
                                $stock = (float) $r['current_stock'];
                                $min = (float) $r['min_stock_qty'];
                                $value = $stock * (float) $r['calculated_cost_usd'];
                                if ($stock <= 0) { $statusKind = 'danger'; $statusLabel = 'Out'; $statusFilter = 'out'; }
                                elseif ($stock < $min) { $statusKind = 'warn'; $statusLabel = 'Low'; $statusFilter = 'low'; }
                                else { $statusKind = 'ok'; $statusLabel = 'OK'; $statusFilter = 'ok'; }
                                ?>
                                <tr data-name="<?= htmlspecialchars(mb_strtolower($r['product_name'] . ' ' . $r['product_code'] . ' ' . $r['category_name'])); ?>" data-status="<?= $statusFilter; ?>">
                                    <td style="font-weight:600; font-size:13.5px;"><?= htmlspecialchars($r['product_name']); ?></td>
                                    <td style="font-family:var(--font-display); font-weight:700; font-size:12px; color:var(--caramel);"><?= htmlspecialchars($r['product_code']); ?></td>
                                    <td style="font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($r['category_name']); ?></td>
                                    <td class="text-end"><?= rtrim(rtrim(number_format($stock, 3), '0'), '.'); ?> <?= htmlspecialchars($r['abbreviation']); ?></td>
                                    <td class="text-end" style="color:var(--text-muted);">$<?= number_format($r['calculated_cost_usd'], 2); ?></td>
                                    <td class="text-end" style="font-family:var(--font-display); font-weight:700;">$<?= number_format($value, 2); ?></td>
                                    <td><span class="yz-badge yz-badge-<?= $statusKind; ?>"><?= $statusLabel; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center" style="color:var(--text-faint); padding:32px 0;">No active finished products found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($tab === 'daily'): ?>
            <div class="yz-table-wrap">
                <table class="yz-table">
                    <thead><tr><th>Day</th><th class="text-end">Revenue</th><th class="text-end">Transactions</th><th class="text-end">Avg. Sale</th></tr></thead>
                    <tbody>
                        <?php if (!empty($daily)): ?>
                            <?php foreach ($daily as $d): ?>
                                <tr>
                                    <td style="font-weight:600;"><?= htmlspecialchars(date('D d M', strtotime($d['d']))); ?></td>
                                    <td class="text-end" style="font-family:var(--font-display); font-weight:700;">$<?= number_format($d['rev'], 0); ?></td>
                                    <td class="text-end" style="color:var(--text-muted);"><?= (int) $d['tx']; ?></td>
                                    <td class="text-end" style="color:var(--text-muted);">$<?= number_format($d['tx'] > 0 ? $d['rev'] / $d['tx'] : 0, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center" style="color:var(--text-faint); padding:32px 0;">No sales data in this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </main>

    <script>
        let ingFilter = 'all';
        function setIngFilter(f) {
            ingFilter = f;
            document.querySelectorAll('.ing-filter-pill').forEach(function (b) {
                const active = b.getAttribute('data-filter') === f;
                b.style.background = active ? 'var(--chocolate)' : 'transparent';
                b.style.color = active ? 'var(--cream)' : 'var(--text-muted)';
            });
            filterIngredientRows();
        }
        function filterIngredientRows() {
            const q = document.getElementById('ingSearch').value.trim().toLowerCase();
            document.querySelectorAll('#ingredientRows tr[data-name]').forEach(function (row) {
                const matchesSearch = row.getAttribute('data-name').indexOf(q) !== -1;
                const matchesFilter = ingFilter === 'all' || row.getAttribute('data-status') === ingFilter;
                row.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
            });
        }

        let prodFilter = 'all';
        function setProdFilter(f) {
            prodFilter = f;
            document.querySelectorAll('.prod-filter-pill').forEach(function (b) {
                const active = b.getAttribute('data-filter') === f;
                b.style.background = active ? 'var(--chocolate)' : 'transparent';
                b.style.color = active ? 'var(--cream)' : 'var(--text-muted)';
            });
            filterProductRows();
        }
        function filterProductRows() {
            const q = document.getElementById('prodSearch').value.trim().toLowerCase();
            document.querySelectorAll('#productValueRows tr[data-name]').forEach(function (row) {
                const matchesSearch = row.getAttribute('data-name').indexOf(q) !== -1;
                const matchesFilter = prodFilter === 'all' || row.getAttribute('data-status') === prodFilter;
                row.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
