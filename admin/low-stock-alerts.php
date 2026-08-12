<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

try {
    $stmt = $pdo->query("
        SELECT
            i.item_id, i.item_name, i.item_code, i.min_stock_qty,
            c.category_name, u.abbreviation,
            COALESCE(SUM(
                CASE WHEN sm.direction = 'in' AND sm.status = 'active' THEN sm.quantity
                     WHEN sm.direction = 'out' AND sm.status = 'active' THEN -sm.quantity
                     ELSE 0 END
            ), 0) AS current_stock
        FROM items i
        INNER JOIN categories c ON i.category_id = c.category_id
        INNER JOIN units u ON i.unit_id = u.unit_id
        LEFT JOIN stock_movements sm ON sm.item_id = i.item_id
        WHERE i.status = 'active'
        GROUP BY i.item_id, i.item_name, i.item_code, i.min_stock_qty, c.category_name, u.abbreviation
        HAVING current_stock < i.min_stock_qty
        ORDER BY current_stock ASC, i.item_name ASC
    ");
    $lowStockItems = $stmt->fetchAll();
} catch (PDOException $e) {
    $lowStockItems = [];
}

$navCrumb = 'Inventory';
$navTitle = 'Low Stock Alerts';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div style="display:flex; align-items:center; gap:10px; background:var(--danger-soft); border-radius:14px; padding:14px 18px; margin-bottom:18px;">
            <span style="font-size:20px; color:var(--danger);">⚠</span>
            <div style="flex:1;">
                <div style="font-family:var(--font-display); font-weight:700; font-size:14px; color:var(--danger);"><?= count($lowStockItems); ?> items below minimum stock</div>
                <div style="font-size:12.5px; color:#9a4a4a;">Reorder these ingredients before the next production run.</div>
            </div>
        </div>

        <?php if (!empty($lowStockItems)): ?>

            <div class="toolbar">
                <div class="spacer"></div>
                <div class="search-box" style="width:240px;">
                    <span style="color:var(--caramel);">⌕</span>
                    <input type="text" id="lowStockSearch" placeholder="Search low stock items…" oninput="filterLowStock()">
                </div>
            </div>

            <div class="yz-grid-2" id="lowStockCards" style="margin-bottom:24px;">
                <?php foreach ($lowStockItems as $item): ?>
                    <?php
                    $stock = (float) $item['current_stock'];
                    $min = (float) $item['min_stock_qty'];
                    $pct = $min > 0 ? round($stock / $min * 100) : 0;
                    $barW = min(100, max(0, $pct));
                    $short = $min - $stock;
                    ?>
                    <div class="yz-card" style="padding:20px; border-left:4px solid var(--danger);" data-name="<?= htmlspecialchars(mb_strtolower($item['item_name'] . ' ' . $item['item_code'] . ' ' . $item['category_name'])); ?>">
                        <div style="display:flex; align-items:flex-start; justify-content:space-between;">
                            <div>
                                <div style="font-family:var(--font-display); font-weight:800; font-size:16px;"><?= htmlspecialchars($item['item_name']); ?></div>
                                <div style="font-size:12px; color:var(--caramel); font-family:var(--font-display); font-weight:600;"><?= htmlspecialchars($item['item_code']); ?></div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-family:var(--font-display); font-weight:800; font-size:22px; color:var(--danger);"><?= rtrim(rtrim(number_format($stock, 3), '0'), '.'); ?></div>
                                <div style="font-size:11px; color:var(--text-faint);">/ <?= rtrim(rtrim(number_format($min, 3), '0'), '.'); ?> <?= htmlspecialchars($item['abbreviation']); ?> min</div>
                            </div>
                        </div>
                        <div style="height:8px; background:#f1e2e2; border-radius:9999px; margin-top:14px; overflow:hidden;">
                            <div style="height:100%; width:<?= $barW; ?>%; background:var(--danger); border-radius:9999px;"></div>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-top:8px; font-size:12px; color:var(--text-faint);">
                            <span><?= $pct; ?>% of minimum</span>
                            <span style="color:var(--danger); font-weight:600;">Short <?= rtrim(rtrim(number_format($short, 3), '0'), '.'); ?> <?= htmlspecialchars($item['abbreviation']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="font-family:var(--font-display); font-weight:800; font-size:15px; margin-bottom:12px;">Low Stock Items</div>
            <div class="yz-table-wrap">
                <table class="yz-table">
                    <thead>
                        <tr>
                            <th>Status</th><th>Item</th><th>Code</th><th>Category</th>
                            <th class="text-end">Current Stock</th><th class="text-end">Min Stock</th><th class="text-end">Shortage</th><th style="width:120px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="lowStockRows">
                        <?php foreach ($lowStockItems as $item): ?>
                            <?php
                            $stock = (float) $item['current_stock'];
                            $min = (float) $item['min_stock_qty'];
                            $short = $min - $stock;
                            if ($stock <= 0) {
                                $statusKind = 'danger'; $statusLabel = 'Out of Stock';
                            } elseif ($min > 0 && $stock <= $min * 0.5) {
                                $statusKind = 'warn'; $statusLabel = 'Critical';
                            } else {
                                $statusKind = 'warn'; $statusLabel = 'Low';
                            }
                            ?>
                            <tr data-name="<?= htmlspecialchars(mb_strtolower($item['item_name'] . ' ' . $item['item_code'] . ' ' . $item['category_name'])); ?>">
                                <td><span class="yz-badge yz-badge-<?= $statusKind; ?>"><?= $statusLabel; ?></span></td>
                                <td style="font-weight:600; font-size:13.5px;"><?= htmlspecialchars($item['item_name']); ?></td>
                                <td style="font-family:var(--font-display); font-weight:700; font-size:12px; color:var(--caramel);"><?= htmlspecialchars($item['item_code']); ?></td>
                                <td style="font-size:13px; color:var(--text-muted);"><?= htmlspecialchars($item['category_name']); ?></td>
                                <td class="text-end" style="font-family:var(--font-display); font-weight:700; color:<?= $stock <= 0 ? 'var(--danger)' : 'var(--warn)'; ?>;"><?= rtrim(rtrim(number_format($stock, 3), '0'), '.'); ?> <?= htmlspecialchars($item['abbreviation']); ?></td>
                                <td class="text-end" style="color:var(--text-muted);"><?= rtrim(rtrim(number_format($min, 3), '0'), '.'); ?> <?= htmlspecialchars($item['abbreviation']); ?></td>
                                <td class="text-end" style="font-weight:700; color:var(--danger);"><?= rtrim(rtrim(number_format($short, 3), '0'), '.'); ?> <?= htmlspecialchars($item['abbreviation']); ?></td>
                                <td style="white-space:nowrap;"><a href="stock-movements.php" class="btn-yz btn-yz-caramel" style="padding:7px 14px; font-size:12px; white-space:nowrap;">↓&nbsp;Restock</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div class="yz-card" style="text-align:center; color:var(--success); font-weight:600;">✓ All items are well stocked. No alerts at this time.</div>
        <?php endif; ?>

    </main>

    <script>
        function filterLowStock() {
            var q = document.getElementById('lowStockSearch').value.trim().toLowerCase();
            document.querySelectorAll('#lowStockCards [data-name]').forEach(function (card) {
                card.style.display = card.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
            document.querySelectorAll('#lowStockRows tr[data-name]').forEach(function (row) {
                row.style.display = row.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
