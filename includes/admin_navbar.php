<?php
$navCrumb = $navCrumb ?? 'Overview';
$navTitle = $navTitle ?? 'Dashboard';

$currentRate = null;
try {
    $rateStmt = $pdo->query("SELECT usd_to_lbp FROM exchange_rates ORDER BY rate_date DESC, rate_id DESC LIMIT 1");
    $row = $rateStmt->fetch();
    $currentRate = $row ? (float) $row['usd_to_lbp'] : null;
} catch (PDOException $e) {
    $currentRate = null;
}
?>
<header class="admin-navbar">
    <div style="flex:1; min-width:0;">
        <small><?= htmlspecialchars($navCrumb); ?></small>
        <h4><?= htmlspecialchars($navTitle); ?></h4>
    </div>

    <div class="navbar-actions">
        <?php if ($currentRate !== null): ?>
            <div class="rate-chip">
                <span class="dot"></span>
                <span>USD→LBP</span>
                <strong><?= number_format($currentRate, 0); ?></strong>
            </div>
        <?php endif; ?>

        <a href="pos.php" class="pos-link">▤ Open POS</a>

        <?php
        $staffName = $currentStaff['full_name'] ?? 'Admin';
        $staffRole = ucwords(str_replace('_', ' ', $currentStaff['role'] ?? 'admin'));
        $staffInitial = mb_substr($staffName, 0, 1);
        ?>
        <div class="admin-profile">
            <div class="profile-avatar"><?= htmlspecialchars($staffInitial); ?></div>
            <div>
                <strong><?= htmlspecialchars($staffName); ?></strong>
                <small><?= htmlspecialchars($staffRole); ?></small>
            </div>
        </div>

        <a href="<?= BASE_URL; ?>auth/logout.php" title="Logout" style="width:38px; height:38px; flex:none; border-radius:50%; background:var(--bg); color:var(--text-muted); display:flex; align-items:center; justify-content:center; text-decoration:none;">
            <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:17px; height:17px; stroke:currentColor; fill:none;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
    </div>
</header>
