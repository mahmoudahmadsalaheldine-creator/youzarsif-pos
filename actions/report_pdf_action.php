<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

require_once "../dompdf/autoload.inc.php";

use Dompdf\Dompdf;
use Dompdf\Options;

$dateFrom = $_GET["date_from"] ?? date("Y-m-01");
$dateTo   = $_GET["date_to"]   ?? date("Y-m-d");

try {
    $rateRow = $pdo->query("SELECT usd_to_lbp FROM exchange_rates ORDER BY created_at DESC LIMIT 1")->fetch();
    $exchangeRate = $rateRow ? (float) $rateRow["usd_to_lbp"] : 89000;
} catch (PDOException $e) {
    $exchangeRate = 89000;
}

try {
    $salesStmt = $pdo->prepare("
        SELECT COUNT(*) AS total_sales, COALESCE(SUM(subtotal_usd), 0) AS total_subtotal,
            COALESCE(SUM(discount_amount_usd), 0) AS total_discount,
            COALESCE(SUM(total_usd), 0) AS total_revenue_usd, COALESCE(SUM(total_lbp), 0) AS total_revenue_lbp
        FROM sales WHERE status = 'completed' AND DATE(created_at) BETWEEN :f AND :t
    ");
    $salesStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $salesSummary = $salesStmt->fetch();

    $bestSellingStmt = $pdo->prepare("
        SELECT fp.product_name, fp.product_code, SUM(si.quantity) AS total_qty_sold,
            SUM(si.total_price_usd) AS total_revenue_usd, COUNT(DISTINCT si.sale_id) AS total_orders
        FROM sale_items si
        INNER JOIN finished_products fp ON si.product_id = fp.product_id
        INNER JOIN sales s ON si.sale_id = s.sale_id
        WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN :f AND :t
        GROUP BY fp.product_id, fp.product_name, fp.product_code
        ORDER BY total_qty_sold DESC LIMIT 10
    ");
    $bestSellingStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $bestSelling = $bestSellingStmt->fetchAll();

    // Cost of Goods Sold — required to compute Gross Profit correctly (previous report
    // version skipped this and showed Gross Profit == Revenue, which was wrong).
    $cogsStmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity * fp.calculated_cost_usd), 0) AS cogs
        FROM sale_items si INNER JOIN sales s ON si.sale_id = s.sale_id
        INNER JOIN finished_products fp ON si.product_id = fp.product_id
        WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN :f AND :t
    ");
    $cogsStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $cogs = (float) $cogsStmt->fetch()["cogs"];

    $expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_usd), 0) AS total_expenses_usd FROM expenses WHERE DATE(created_at) BETWEEN :f AND :t");
    $expStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $expensesSummary = $expStmt->fetch();

    $expByCatStmt = $pdo->prepare("
        SELECT ec.category_name, COUNT(*) AS total_entries, COALESCE(SUM(e.amount_usd), 0) AS total_usd
        FROM expenses e INNER JOIN expense_categories ec ON e.expense_category_id = ec.expense_category_id
        WHERE DATE(e.created_at) BETWEEN :f AND :t
        GROUP BY ec.expense_category_id, ec.category_name ORDER BY total_usd DESC
    ");
    $expByCatStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $expensesByCategory = $expByCatStmt->fetchAll();

    $dailyStmt = $pdo->prepare("
        SELECT DATE(created_at) AS sale_date, COUNT(*) AS total_sales, COALESCE(SUM(total_usd), 0) AS total_revenue_usd
        FROM sales WHERE status = 'completed' AND DATE(created_at) BETWEEN :f AND :t
        GROUP BY DATE(created_at) ORDER BY sale_date DESC
    ");
    $dailyStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $dailySales = $dailyStmt->fetchAll();

    $ingredientStockStmt = $pdo->query("
        SELECT i.item_name, i.item_code, i.min_stock_qty, c.category_name, u.abbreviation,
            COALESCE(stk.current_stock, 0) AS current_stock, COALESCE(cost.avg_cost, 0) AS avg_cost
        FROM items i
        INNER JOIN categories c ON i.category_id = c.category_id
        INNER JOIN units u ON i.unit_id = u.unit_id
        LEFT JOIN (SELECT item_id, SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END) AS current_stock FROM stock_movements GROUP BY item_id) stk ON stk.item_id = i.item_id
        LEFT JOIN (SELECT item_id, SUM(total_cost_usd) / SUM(quantity) AS avg_cost FROM stock_movements WHERE movement_type = 'purchase_in' AND status = 'active' GROUP BY item_id) cost ON cost.item_id = i.item_id
        WHERE i.status = 'active'
        ORDER BY i.item_name ASC
    ");
    $ingredientStock = $ingredientStockStmt->fetchAll();

    $productStockStmt = $pdo->query("
        SELECT fp.product_name, fp.product_code, fp.min_stock_qty, fp.calculated_cost_usd, c.category_name, u.abbreviation,
            COALESCE(stk.current_stock, 0) AS current_stock
        FROM finished_products fp
        INNER JOIN categories c ON fp.category_id = c.category_id
        INNER JOIN units u ON fp.unit_id = u.unit_id
        LEFT JOIN (SELECT product_id, SUM(CASE WHEN direction = 'in' AND status = 'active' THEN quantity WHEN direction = 'out' AND status = 'active' THEN -quantity ELSE 0 END) AS current_stock FROM product_stock_movements GROUP BY product_id) stk ON stk.product_id = fp.product_id
        WHERE fp.status = 'active'
        ORDER BY fp.product_name ASC
    ");
    $productStock = $productStockStmt->fetchAll();
} catch (PDOException $e) {
    $salesSummary = ["total_sales" => 0, "total_subtotal" => 0, "total_discount" => 0, "total_revenue_usd" => 0, "total_revenue_lbp" => 0];
    $bestSelling = [];
    $cogs = 0;
    $expensesSummary = ["total_expenses_usd" => 0];
    $expensesByCategory = [];
    $dailySales = [];
    $ingredientStock = [];
    $productStock = [];
}

$revenue = (float) $salesSummary["total_revenue_usd"];
$gross = $revenue - $cogs;
$totalExpensesUsd = (float) $expensesSummary["total_expenses_usd"];
$netProfit = $gross - $totalExpensesUsd;
$netMargin = $revenue > 0 ? round($netProfit / $revenue * 100, 1) : 0;

$ingredientStockValue = 0;
foreach ($ingredientStock as $r) {
    $ingredientStockValue += (float) $r["current_stock"] * (float) $r["avg_cost"];
}
$productStockValue = 0;
foreach ($productStock as $r) {
    $productStockValue += (float) $r["current_stock"] * (float) $r["calculated_cost_usd"];
}

function statusBadge($stock, $min)
{
    if ($stock <= 0) return '<span class="badge badge-out">Out</span>';
    if ($stock < $min) return '<span class="badge badge-low">Low</span>';
    return '<span class="badge badge-ok">OK</span>';
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 26px 28px; }
    * { box-sizing: border-box; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #3b2416; margin: 0; }

    .brand-header { background-color: #3b2416; border-radius: 16px; padding: 18px 22px; margin-bottom: 18px; }
    .brand-row { display: table; width: 100%; }
    .brand-cell { display: table-cell; vertical-align: middle; }
    .brand-mark { width: 38px; height: 38px; background-color: #c8924b; border-radius: 10px; color: #3b2416; font-weight: bold; font-size: 18px; text-align: center; line-height: 38px; }
    .brand-title { color: #f8f1e8; font-size: 18px; font-weight: bold; padding-left: 12px; }
    .brand-sub { color: #d9c7b3; font-size: 10px; padding-left: 12px; margin-top: 2px; }
    .brand-meta { color: #f8f1e8; font-size: 10px; text-align: right; }

    .section-title {
        font-size: 12px; font-weight: bold; color: #3b2416;
        background-color: #f3e6cf; border-left: 4px solid #c8924b;
        padding: 7px 12px; border-radius: 6px; margin: 18px 0 10px;
    }

    .kpi-row { display: table; width: 100%; border-spacing: 8px 0; margin-bottom: 14px; }
    .kpi { display: table-cell; width: 25%; background-color: #faf5ee; border-radius: 10px; padding: 10px 12px; text-align: center; }
    .kpi .v { font-size: 16px; font-weight: bold; color: #3b2416; }
    .kpi .l { font-size: 9px; color: #9a7a56; text-transform: uppercase; letter-spacing: .03em; margin-top: 2px; }

    .flow-row { display: table; width: 100%; margin-bottom: 14px; border-spacing: 4px 0; }
    .flow-cell { display: table-cell; background-color: #faf5ee; border-radius: 10px; padding: 9px 6px; text-align: center; }
    .flow-cell .v { font-size: 13px; font-weight: bold; }
    .flow-cell .l { font-size: 8.5px; color: #9a7a56; text-transform: uppercase; }
    .flow-op { display: table-cell; width: 16px; text-align: center; vertical-align: middle; color: #a98a66; font-size: 13px; font-weight: bold; }

    table.report-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.report-table th {
        background-color: #faf5ee; color: #9a7a56; text-transform: uppercase; letter-spacing: .03em;
        padding: 6px 9px; text-align: left; font-size: 9px; border-bottom: 2px solid #efe6d8;
    }
    table.report-table td { padding: 6px 9px; border-bottom: 1px solid #efe6d8; font-size: 10px; }
    table.report-table tr:nth-child(even) td { background-color: #faf5ee; }
    .text-right { text-align: right; }
    .text-success { color: #2f6a3f; font-weight: bold; }
    .text-danger { color: #ba1a1a; font-weight: bold; }
    .muted { color: #9a7a56; }

    .badge { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 8.5px; font-weight: bold; }
    .badge-ok { background-color: #e7f0e9; color: #2f6a3f; }
    .badge-low { background-color: #f7ecd6; color: #9a6a18; }
    .badge-out { background-color: #f6e0e0; color: #ba1a1a; }

    .pnl-table td:first-child { font-size: 11px; }
    .pnl-table tr.total td { font-weight: bold; font-size: 12px; border-top: 2px solid #3b2416; background-color: #faf5ee; }

    .footer-note { margin-top: 18px; text-align: center; color: #b49a78; font-size: 9px; border-top: 1px solid #efe6d8; padding-top: 8px; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>

<div class="brand-header">
    <div class="brand-row">
        <div class="brand-cell" style="width:46px;"><div class="brand-mark">Y</div></div>
        <div class="brand-cell">
            <div class="brand-title">Youzarsif Sweets — Business Report</div>
            <div class="brand-sub">Period <?= htmlspecialchars($dateFrom); ?> → <?= htmlspecialchars($dateTo); ?></div>
        </div>
        <div class="brand-cell brand-meta">
            Generated <?= date("d M Y, H:i"); ?><br>
            1$ = <?= number_format($exchangeRate, 0); ?> LL
        </div>
    </div>
</div>

<!-- ===== SALES ===== -->
<div class="section-title">Sales</div>
<div class="kpi-row">
    <div class="kpi"><div class="v"><?= (int) $salesSummary["total_sales"]; ?></div><div class="l">Total Sales</div></div>
    <div class="kpi"><div class="v">$<?= number_format($salesSummary["total_revenue_usd"], 2); ?></div><div class="l">Revenue USD</div></div>
    <div class="kpi"><div class="v"><?= number_format($salesSummary["total_revenue_lbp"], 0); ?></div><div class="l">Revenue LBP</div></div>
    <div class="kpi"><div class="v">$<?= number_format($salesSummary["total_discount"], 2); ?></div><div class="l">Discounts Given</div></div>
</div>

<table class="report-table">
    <thead><tr><th>#</th><th>Product</th><th>Code</th><th class="text-right">Qty Sold</th><th class="text-right">Orders</th><th class="text-right">Revenue</th></tr></thead>
    <tbody>
        <?php if (!empty($bestSelling)): foreach ($bestSelling as $i => $p): ?>
            <tr>
                <td><?= $i + 1; ?></td>
                <td><?= htmlspecialchars($p["product_name"]); ?></td>
                <td class="muted"><?= htmlspecialchars($p["product_code"]); ?></td>
                <td class="text-right"><?= number_format($p["total_qty_sold"], 2); ?></td>
                <td class="text-right"><?= (int) $p["total_orders"]; ?></td>
                <td class="text-right text-success">$<?= number_format($p["total_revenue_usd"], 2); ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="6" class="text-right" style="text-align:center;color:#b49a78;">No sales in this period.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- ===== PROFIT & LOSS ===== -->
<div class="section-title">Profit &amp; Loss</div>

<div class="flow-row">
    <div class="flow-cell"><div class="v">$<?= number_format($revenue, 0); ?></div><div class="l">Revenue</div></div>
    <div class="flow-op">&minus;</div>
    <div class="flow-cell"><div class="v text-danger">$<?= number_format($cogs, 0); ?></div><div class="l">COGS</div></div>
    <div class="flow-op">=</div>
    <div class="flow-cell"><div class="v text-success">$<?= number_format($gross, 0); ?></div><div class="l">Gross Profit</div></div>
    <div class="flow-op">&minus;</div>
    <div class="flow-cell"><div class="v text-danger">$<?= number_format($totalExpensesUsd, 0); ?></div><div class="l">Expenses</div></div>
    <div class="flow-op">=</div>
    <div class="flow-cell"><div class="v" style="color:<?= $netProfit >= 0 ? '#2f6a3f' : '#ba1a1a'; ?>;">$<?= number_format(abs($netProfit), 0); ?><?= $netProfit < 0 ? ' (Loss)' : ''; ?></div><div class="l">Net Profit</div></div>
</div>

<table class="report-table pnl-table" style="width:60%;">
    <tbody>
        <tr><td>Revenue</td><td class="text-right text-success">$<?= number_format($revenue, 2); ?></td></tr>
        <tr><td>Cost of Goods Sold</td><td class="text-right text-danger">−$<?= number_format($cogs, 2); ?></td></tr>
        <tr class="total"><td>Gross Profit</td><td class="text-right text-success">$<?= number_format($gross, 2); ?></td></tr>
        <tr><td>Operating Expenses</td><td class="text-right text-danger">−$<?= number_format($totalExpensesUsd, 2); ?></td></tr>
        <tr class="total"><td>Net Profit (<?= $netMargin; ?>% margin)</td><td class="text-right" style="color:<?= $netProfit >= 0 ? '#2f6a3f' : '#ba1a1a'; ?>;">$<?= number_format(abs($netProfit), 2); ?><?= $netProfit < 0 ? ' (Loss)' : ''; ?></td></tr>
    </tbody>
</table>

<div style="margin-top:14px; font-size:11px; font-weight:bold; color:#3b2416;">Expenses by Category</div>
<table class="report-table">
    <thead><tr><th>Category</th><th class="text-right">Entries</th><th class="text-right">Total USD</th></tr></thead>
    <tbody>
        <?php if (!empty($expensesByCategory)): foreach ($expensesByCategory as $exp): ?>
            <tr>
                <td><?= htmlspecialchars($exp["category_name"]); ?></td>
                <td class="text-right"><?= (int) $exp["total_entries"]; ?></td>
                <td class="text-right text-danger">$<?= number_format($exp["total_usd"], 2); ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="3" style="text-align:center;color:#b49a78;">No expenses in this period.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="page-break"></div>

<!-- ===== STOCK ===== -->
<div class="section-title">Stock Value</div>
<div class="kpi-row">
    <div class="kpi" style="width:50%;"><div class="v" style="color:#c8924b;">$<?= number_format($ingredientStockValue, 2); ?></div><div class="l">Ingredient Stock Value</div></div>
    <div class="kpi" style="width:50%;"><div class="v" style="color:#9a4f56;">$<?= number_format($productStockValue, 2); ?></div><div class="l">Finished Product Value</div></div>
</div>

<div style="font-size:11px; font-weight:bold; color:#3b2416; margin-bottom:6px;">Ingredient Stock Value</div>
<table class="report-table">
    <thead><tr><th>Item</th><th>Category</th><th class="text-right">Stock</th><th class="text-right">Avg Cost</th><th class="text-right">Value</th><th>Status</th></tr></thead>
    <tbody>
        <?php foreach ($ingredientStock as $item):
            $stock = (float) $item["current_stock"];
            $min = (float) $item["min_stock_qty"];
            $value = $stock * (float) $item["avg_cost"];
        ?>
            <tr>
                <td><?= htmlspecialchars($item["item_name"]); ?></td>
                <td class="muted"><?= htmlspecialchars($item["category_name"]); ?></td>
                <td class="text-right"><?= number_format($stock, 2); ?> <?= htmlspecialchars($item["abbreviation"]); ?></td>
                <td class="text-right">$<?= number_format($item["avg_cost"], 2); ?></td>
                <td class="text-right" style="font-weight:bold;">$<?= number_format($value, 2); ?></td>
                <td><?= statusBadge($stock, $min); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($ingredientStock)): ?><tr><td colspan="6" style="text-align:center;color:#b49a78;">No active ingredients found.</td></tr><?php endif; ?>
    </tbody>
</table>

<div style="font-size:11px; font-weight:bold; color:#3b2416; margin:14px 0 6px;">Finished Product Value</div>
<table class="report-table">
    <thead><tr><th>Product</th><th>Category</th><th class="text-right">Stock</th><th class="text-right">Unit Cost</th><th class="text-right">Value</th><th>Status</th></tr></thead>
    <tbody>
        <?php foreach ($productStock as $product):
            $stock = (float) $product["current_stock"];
            $min = (float) $product["min_stock_qty"];
            $value = $stock * (float) $product["calculated_cost_usd"];
        ?>
            <tr>
                <td><?= htmlspecialchars($product["product_name"]); ?></td>
                <td class="muted"><?= htmlspecialchars($product["category_name"]); ?></td>
                <td class="text-right"><?= number_format($stock, 2); ?> <?= htmlspecialchars($product["abbreviation"]); ?></td>
                <td class="text-right">$<?= number_format($product["calculated_cost_usd"], 2); ?></td>
                <td class="text-right" style="font-weight:bold;">$<?= number_format($value, 2); ?></td>
                <td><?= statusBadge($stock, $min); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($productStock)): ?><tr><td colspan="6" style="text-align:center;color:#b49a78;">No active finished products found.</td></tr><?php endif; ?>
    </tbody>
</table>

<!-- ===== DAILY ===== -->
<div class="section-title">Daily Sales Breakdown</div>
<table class="report-table">
    <thead><tr><th>Date</th><th class="text-right">Sales</th><th class="text-right">Revenue USD</th></tr></thead>
    <tbody>
        <?php if (!empty($dailySales)): foreach ($dailySales as $day): ?>
            <tr>
                <td><?= htmlspecialchars($day["sale_date"]); ?></td>
                <td class="text-right"><?= (int) $day["total_sales"]; ?></td>
                <td class="text-right text-success">$<?= number_format($day["total_revenue_usd"], 2); ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="3" style="text-align:center;color:#b49a78;">No sales in this period.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="footer-note">Youzarsif Sweets Management System &middot; Generated <?= date("Y-m-d H:i:s"); ?></div>

</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set("isHtml5ParserEnabled", true);
$options->set("isPhpEnabled", false);
$options->set("defaultFont", "Helvetica");

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

$filename = "Youzarsif-Report-" . $dateFrom . "-to-" . $dateTo . ".pdf";
$dompdf->stream($filename, ["Attachment" => true]);
exit;
