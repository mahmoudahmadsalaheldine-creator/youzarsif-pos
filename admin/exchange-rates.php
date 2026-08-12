<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

try {
    $rates = $pdo->query("SELECT * FROM exchange_rates ORDER BY created_at DESC")->fetchAll();
} catch (PDOException $e) {
    $rates = [];
}

$navCrumb = 'Finance';
$navTitle = 'Exchange Rates';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="yz-row yz-row-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px;">
            <div style="background:var(--chocolate); color:var(--cream); border-radius:18px; padding:24px;">
                <div style="font-size:12px; opacity:.6; text-transform:uppercase; letter-spacing:.06em;">Current Rate</div>
                <div style="display:flex; align-items:baseline; gap:10px; margin-top:8px;">
                    <span style="font-family:var(--font-display); font-weight:800; font-size:38px; line-height:1;"><?= !empty($rates) ? number_format($rates[0]['usd_to_lbp'], 0) : '—'; ?></span>
                    <span style="font-size:15px; opacity:.7;">LL / USD</span>
                </div>
                <div style="font-size:12.5px; opacity:.6; margin-top:10px;">Used across POS, expenses &amp; reports</div>
            </div>
            <div class="yz-card" style="padding:24px; display:flex; flex-direction:column; justify-content:center; gap:12px;">
                <div style="font-family:var(--font-display); font-weight:800; font-size:16px;">Update Rate</div>
                <div style="font-size:13px; color:var(--text-muted);">Fetch the live USD→LBP rate, or enter it manually.</div>
                <div style="display:flex; gap:10px; margin-top:4px;">
                    <button type="button" class="btn-yz btn-yz-success" style="flex:1; justify-content:center;" onclick="fetchLiveRate()" id="fetchRateBtn">⟳ Fetch Live Rate</button>
                    <button type="button" class="btn-yz" style="flex:1; justify-content:center; background:var(--bg); color:var(--text);" onclick="openYzModal('rateModal')">＋ Manual Entry</button>
                </div>
                <small id="fetchStatus"></small>
            </div>
        </div>

        <div class="yz-table-wrap">
            <div style="padding:16px 18px; font-family:var(--font-display); font-weight:800; font-size:15px;">Rate History</div>
            <table class="yz-table">
                <thead>
                    <tr><th>Rate</th><th>Date</th><th>Source</th><th></th><th style="width:40px;"></th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($rates)): ?>
                        <?php foreach ($rates as $i => $rate): ?>
                            <?php $isApi = stripos($rate['notes'] ?? '', 'api') !== false || stripos($rate['notes'] ?? '', 'fetch') !== false; ?>
                            <tr>
                                <td style="font-family:var(--font-display); font-weight:700; font-size:14px;"><?= number_format($rate['usd_to_lbp'], 0); ?> LL</td>
                                <td style="font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($rate['rate_date']); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $isApi ? 'info' : 'off'; ?>"><?= $isApi ? 'API' : 'Manual'; ?></span></td>
                                <td><?php if ($i === 0): ?><span class="yz-badge yz-badge-ok">● Active</span><?php endif; ?></td>
                                <td class="text-end">
                                    <form method="POST" action="<?= BASE_URL; ?>actions/exchange_rate_action.php" onsubmit="return confirmAction(this, { title: 'Delete this rate?', message: 'This exchange rate record will be permanently removed.', confirmLabel: 'Delete' });">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="rate_id" value="<?= (int) $rate['rate_id']; ?>">
                                        <button type="submit" class="icon-btn icon-btn-delete">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center" style="color:var(--text-faint); padding:32px 0;">No exchange rates added yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <div class="yz-modal-overlay" id="rateModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/exchange_rate_action.php">
                <div class="yz-modal-header">
                    <div><h3>Manual Exchange Rate</h3><p>Set the USD → LBP rate</p></div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('rateModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="yz-field">
                        <label>USD → LBP rate</label>
                        <input type="number" name="usd_to_lbp" id="rateInput" min="1" step="1" placeholder="89500" style="font-family:var(--font-display); font-weight:700; font-size:16px;" required>
                    </div>
                    <div class="yz-row yz-row-2">
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Date</label>
                            <input type="date" name="rate_date" value="<?= date('Y-m-d'); ?>" required>
                        </div>
                        <div class="yz-field" style="margin-bottom:0;">
                            <label>Notes</label>
                            <input type="text" name="notes" placeholder="Optional">
                        </div>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('rateModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function fetchLiveRate() {
            const btn = document.getElementById('fetchRateBtn');
            const status = document.getElementById('fetchStatus');
            btn.disabled = true;
            btn.textContent = 'Fetching…';
            status.textContent = '';
            fetch('https://api.exchangerate-api.com/v4/latest/USD')
                .then(function (r) { if (!r.ok) throw new Error('Network error'); return r.json(); })
                .then(function (data) {
                    const lbp = data.rates['LBP'];
                    if (!lbp) throw new Error('LBP not found');
                    openYzModal('rateModal');
                    document.getElementById('rateInput').value = Math.round(lbp);
                    status.textContent = '✓ Live rate fetched: ' + Math.round(lbp).toLocaleString() + ' LL — adjust if needed, then save.';
                    status.style.color = 'var(--success)';
                })
                .catch(function () {
                    status.textContent = '✕ Could not fetch live rate. Please enter manually.';
                    status.style.color = 'var(--danger)';
                    openYzModal('rateModal');
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = '⟳ Fetch Live Rate';
                });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
