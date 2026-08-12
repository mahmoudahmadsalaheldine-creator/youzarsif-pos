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

try {
    $expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_usd), 0) AS total FROM expenses WHERE DATE(created_at) BETWEEN :f AND :t");
    $expStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $totalExpenses = (float) $expStmt->fetch()['total'];

    $revStmt = $pdo->prepare("SELECT COALESCE(SUM(total_usd), 0) AS total FROM sales WHERE status = 'completed' AND DATE(created_at) BETWEEN :f AND :t");
    $revStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $totalRevenue = (float) $revStmt->fetch()['total'];

    $productsStmt = $pdo->query("
        SELECT product_id, product_name, product_code, calculated_cost_usd, selling_price_usd, profit_percentage
        FROM finished_products WHERE status = 'active' ORDER BY product_name ASC
    ");
    $products = $productsStmt->fetchAll();

    $soldStmt = $pdo->prepare("
        SELECT si.product_id, COALESCE(SUM(si.quantity), 0) AS total_sold
        FROM sale_items si INNER JOIN sales s ON si.sale_id = s.sale_id
        WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN :f AND :t GROUP BY si.product_id
    ");
    $soldStmt->execute([":f" => $dateFrom, ":t" => $dateTo]);
    $unitsSoldPerProduct = [];
    foreach ($soldStmt->fetchAll() as $r) {
        $unitsSoldPerProduct[$r['product_id']] = (float) $r['total_sold'];
    }
} catch (PDOException $e) {
    $totalExpenses = 0;
    $totalRevenue = 0;
    $products = [];
    $unitsSoldPerProduct = [];
}

$avgMarginPct = 0;
if (!empty($products)) {
    $sum = 0;
    foreach ($products as $p) {
        $price = (float) $p['selling_price_usd'];
        if ($price > 0) {
            $sum += ($price - (float) $p['calculated_cost_usd']) / $price;
        }
    }
    $avgMarginPct = $sum / count($products);
}

$breakEvenRevenue = $avgMarginPct > 0 ? $totalExpenses / $avgMarginPct : 0;
$aboveBelow = $totalRevenue - $breakEvenRevenue;
$safetyPct = $totalRevenue > 0 ? round($aboveBelow / $totalRevenue * 100) : 0;
$beMax = max($breakEvenRevenue, $totalRevenue, $totalExpenses, 1);

$navCrumb = 'Reports';
$navTitle = 'Break-even Analysis';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="yz-grid-4" style="margin-bottom:18px;">
            <div class="yz-card"><div style="font-size:12px; color:var(--text-muted); font-weight:600;">Total Expenses</div><div style="font-family:var(--font-display); font-weight:800; font-size:25px; margin-top:8px;">$<?= number_format($totalExpenses, 0); ?></div></div>
            <div class="yz-card"><div style="font-size:12px; color:var(--text-muted); font-weight:600;">Avg. Contribution Margin</div><div style="font-family:var(--font-display); font-weight:800; font-size:25px; margin-top:8px;"><?= round($avgMarginPct * 100, 1); ?>%</div></div>
            <div style="background:var(--chocolate); color:var(--cream); border-radius:18px; padding:20px;"><div style="font-size:12px; opacity:.6;">Break-even Revenue</div><div style="font-family:var(--font-display); font-weight:800; font-size:25px; margin-top:8px;">$<?= number_format($breakEvenRevenue, 0); ?></div></div>
            <div style="background:var(--success-soft); border-radius:18px; padding:20px;"><div style="font-size:12px; color:var(--success); font-weight:600;">Margin of Safety</div><div style="font-family:var(--font-display); font-weight:800; font-size:25px; margin-top:8px; color:var(--success);"><?= $safetyPct; ?>%</div></div>
        </div>

        <div style="display:grid; grid-template-columns:1.3fr 1fr; gap:16px; margin-bottom:18px;">
            <div class="yz-card" style="padding:24px;">
                <div style="font-family:var(--font-display); font-weight:800; font-size:16px; margin-bottom:20px;">Revenue vs Break-even</div>
                <div style="display:flex; align-items:flex-end; justify-content:space-around; gap:24px; height:190px;">
                    <?php
                    $bars = [
                        ['Break-even', $breakEvenRevenue, '#d9b87a'],
                        ['Current Revenue', $totalRevenue, 'var(--success)'],
                        ['Total Expenses', $totalExpenses, '#b5683c'],
                    ];
                    foreach ($bars as $b): $h = max(4, round($b[1] / $beMax * 150)); ?>
                        <div style="flex:1; max-width:90px; display:flex; flex-direction:column; align-items:center; gap:8px; height:100%; justify-content:flex-end;">
                            <div style="font-family:var(--font-display); font-weight:700; font-size:12px; color:#7a5d40;">$<?= number_format($b[1], 0); ?></div>
                            <div style="width:100%; height:<?= $h; ?>px; border-radius:10px 10px 4px 4px; background:<?= $b[2]; ?>;"></div>
                            <div style="font-size:12px; color:var(--text-faint); text-align:center;"><?= $b[0]; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="yz-card" style="padding:24px; display:flex; flex-direction:column; justify-content:center; gap:14px;">
                <div style="font-family:var(--font-display); font-weight:800; font-size:16px;">Where you stand</div>
                <div style="font-size:14px; color:var(--text); line-height:1.6;">
                    At current revenue of <strong>$<?= number_format($totalRevenue, 0); ?></strong>, the business is
                    <strong style="color:<?= $aboveBelow >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">$<?= number_format(abs($aboveBelow), 0); ?> <?= $aboveBelow >= 0 ? 'above' : 'below'; ?></strong>
                    break-even. <?= $avgMarginPct > 0 ? 'Every dollar of sales beyond $' . number_format($breakEvenRevenue, 0) . ' contributes ' . round($avgMarginPct * 100, 1) . '% to net profit.' : 'Add expenses and sales to calculate margin.'; ?>
                </div>
                <div style="background:<?= $aboveBelow >= 0 ? 'var(--success-soft)' : 'var(--danger-soft)'; ?>; color:<?= $aboveBelow >= 0 ? 'var(--success)' : 'var(--danger)'; ?>; border-radius:12px; padding:12px 16px; font-size:13px; font-weight:600;">
                    <?= $aboveBelow >= 0 ? '✓ Operating safely above break-even point' : '⚠ Below break-even — revenue needs to increase'; ?>
                </div>
            </div>
        </div>

        <div class="yz-table-wrap">
            <div style="padding:16px 18px; font-family:var(--font-display); font-weight:800; font-size:15px;">Break-even Per Product</div>
            <table class="yz-table">
                <thead><tr><th>Product</th><th class="text-end">Price</th><th class="text-end">Cost</th><th class="text-end">Profit/Unit</th><th class="text-end">Break-even Units</th><th class="text-end">Units Sold</th></tr></thead>
                <tbody>
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $p): ?>
                            <?php
                            $price = (float) $p['selling_price_usd'];
                            $cost = (float) $p['calculated_cost_usd'];
                            $profitPerUnit = $price - $cost;
                            $beUnits = $profitPerUnit > 0 && $totalExpenses > 0 ? ceil($totalExpenses / $profitPerUnit) : 0;
                            $sold = $unitsSoldPerProduct[$p['product_id']] ?? 0;
                            ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($p['product_name']); ?></td>
                                <td class="text-end">$<?= number_format($price, 2); ?></td>
                                <td class="text-end" style="color:var(--text-muted);">$<?= number_format($cost, 2); ?></td>
                                <td class="text-end" style="color:var(--success); font-weight:600;">$<?= number_format($profitPerUnit, 2); ?></td>
                                <td class="text-end"><?= $beUnits > 0 ? number_format($beUnits, 0) : '—'; ?></td>
                                <td class="text-end" style="font-weight:600;"><?= number_format($sold, 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center" style="color:var(--text-faint); padding:32px 0;">No finished products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <?php require_once "../includes/admin_footer.php"; ?>
