<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

try {
    $expenseCategories = $pdo->query("SELECT expense_category_id, category_name FROM expense_categories WHERE status = 'active' ORDER BY category_name ASC")->fetchAll();
} catch (PDOException $e) {
    $expenseCategories = [];
}

try {
    $rateRow = $pdo->query("SELECT usd_to_lbp FROM exchange_rates ORDER BY created_at DESC LIMIT 1")->fetch();
    $exchangeRate = $rateRow ? (float) $rateRow['usd_to_lbp'] : 89000;
} catch (PDOException $e) {
    $exchangeRate = 89000;
}

try {
    $expenses = $pdo->query("
        SELECT e.*, ec.category_name
        FROM expenses e INNER JOIN expense_categories ec ON e.expense_category_id = ec.expense_category_id
        ORDER BY e.expense_date DESC, e.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $expenses = [];
}

$totalUsd = 0;
$byCat = [];
foreach ($expenses as $e) {
    $totalUsd += (float) $e['amount_usd'];
    $byCat[$e['category_name']] = ($byCat[$e['category_name']] ?? 0) + (float) $e['amount_usd'];
}
arsort($byCat);

$paymentMethodLabels = ["cash_usd" => "Cash USD", "cash_lbp" => "Cash LBP", "card" => "Card", "mixed" => "Mixed"];
$paymentBadgeKind = ["cash_usd" => "ok", "cash_lbp" => "warn", "card" => "info", "mixed" => "purple"];

$navCrumb = 'Finance';
$navTitle = 'Expenses';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div style="display:grid; grid-template-columns:1fr 1.4fr; gap:16px; margin-bottom:18px;">
            <div class="yz-card" style="padding:22px;">
                <div style="font-size:12px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.05em;">Total Recorded</div>
                <div style="font-family:var(--font-display); font-weight:800; font-size:34px; margin-top:6px;">$<?= number_format($totalUsd, 0); ?></div>
                <div style="font-size:12.5px; color:var(--text-faint); margin-top:4px;">Across <?= count($byCat); ?> categories</div>
            </div>
            <div class="yz-card" style="padding:22px;">
                <div style="font-family:var(--font-display); font-weight:800; font-size:15px; margin-bottom:14px;">By Category</div>
                <?php foreach ($byCat as $cat => $amount): ?>
                    <?php $pct = $totalUsd > 0 ? round($amount / $totalUsd * 100) : 0; ?>
                    <div style="margin-bottom:11px;">
                        <div style="display:flex; justify-content:space-between; font-size:12.5px; margin-bottom:4px;"><span style="font-weight:600;"><?= htmlspecialchars($cat); ?></span><span style="color:var(--text-muted);">$<?= number_format($amount, 0); ?> · <?= $pct; ?>%</span></div>
                        <div style="height:7px; background:var(--bg); border-radius:9999px; overflow:hidden;"><div style="height:100%; width:<?= $pct; ?>%; background:var(--caramel); border-radius:9999px;"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="toolbar">
            <div class="spacer"></div>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openYzModal('expenseModal')">＋ New Expense</button>
        </div>

        <div class="yz-table-wrap">
            <table class="yz-table">
                <thead>
                    <tr><th>Title</th><th>Category</th><th>Payment</th><th class="text-end">USD</th><th class="text-end">LBP</th><th>Date</th><th style="width:54px;"></th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($expenses)): ?>
                        <?php foreach ($expenses as $e): ?>
                            <?php $kind = $paymentBadgeKind[$e['payment_method']] ?? 'info'; ?>
                            <tr>
                                <td style="font-weight:600; font-size:13.5px;"><?= htmlspecialchars($e['expense_title']); ?></td>
                                <td style="font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($e['category_name']); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $kind; ?>"><?= $paymentMethodLabels[$e['payment_method']] ?? $e['payment_method']; ?></span></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:700;"><?= $e['amount_usd'] > 0 ? '$' . number_format($e['amount_usd'], 2) : '—'; ?></td>
                                <td class="text-end" style="font-size:12px; color:var(--text-faint);"><?= $e['amount_lbp'] > 0 ? number_format($e['amount_lbp'], 0) . ' LL' : '—'; ?></td>
                                <td style="font-size:12.5px; color:var(--text-muted);"><?= htmlspecialchars($e['expense_date']); ?></td>
                                <td class="text-end">
                                    <form method="POST" action="<?= BASE_URL; ?>actions/expense_action.php" onsubmit="return confirmAction(this, { title: 'Delete this expense?', message: 'This expense record will be permanently removed.', confirmLabel: 'Delete' });">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="expense_id" value="<?= (int) $e['expense_id']; ?>">
                                        <button type="submit" class="icon-btn icon-btn-delete">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center" style="color:var(--text-faint); padding:32px 0;">No expenses recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <div class="yz-modal-overlay" id="expenseModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/expense_action.php">
                <div class="yz-modal-header">
                    <div><h3>New Expense</h3><p>Record a business expense</p></div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('expenseModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="yz-field">
                        <label>Expense title</label>
                        <input type="text" name="expense_title" placeholder="e.g. Factory Rent" required>
                    </div>
                    <div class="yz-row yz-row-2" style="margin-bottom:14px;">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Category</label>
                            <select name="expense_category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach ($expenseCategories as $c): ?>
                                    <option value="<?= (int) $c['expense_category_id']; ?>"><?= htmlspecialchars($c['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Payment method</label>
                            <select name="payment_method" id="paymentMethodSelect" required>
                                <option value="">Select Method</option>
                                <option value="cash_usd">Cash USD</option>
                                <option value="cash_lbp">Cash LBP</option>
                                <option value="card">Card</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                    </div>
                    <div class="yz-row yz-row-2" style="margin-bottom:14px;">
                        <div class="yz-field" id="amountUsdField" style="margin-bottom:0;">
                            <label>Amount (USD)</label>
                            <input type="number" name="amount_usd" id="amountUsdInput" step="0.01" min="0" placeholder="0.00" value="0">
                        </div>
                        <div class="yz-field" id="amountLbpField" style="margin-bottom:0;">
                            <label>Amount (LBP)</label>
                            <input type="number" name="amount_lbp" id="amountLbpInput" step="1000" min="0" placeholder="0" value="0">
                        </div>
                    </div>
                    <div class="yz-row yz-row-2">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Exchange rate</label>
                            <input type="number" name="exchange_rate" value="<?= $exchangeRate; ?>" step="100">
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Date</label>
                            <input type="date" name="expense_date" value="<?= date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('expenseModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('paymentMethodSelect').addEventListener('change', function () {
            const method = this.value;
            const usdField = document.getElementById('amountUsdField');
            const lbpField = document.getElementById('amountLbpField');
            if (method === 'cash_usd' || method === 'card') {
                usdField.style.display = ''; lbpField.style.display = 'none';
                document.getElementById('amountLbpInput').value = '0';
            } else if (method === 'cash_lbp') {
                usdField.style.display = 'none'; lbpField.style.display = '';
                document.getElementById('amountUsdInput').value = '0';
            } else {
                usdField.style.display = ''; lbpField.style.display = '';
            }
        });
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
