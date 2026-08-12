<?php
session_start();

require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$allowedStatuses = [
    "active" => "Active",
    "inactive" => "Inactive"
];

try {
    $stmt = $pdo->query("SELECT * FROM units ORDER BY unit_id DESC");
    $units = $stmt->fetchAll();
} catch (PDOException $e) {
    $units = [];
}

$navCrumb = 'Setup';
$navTitle = 'Units';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta"><?= count($units); ?> units</span>
            <div class="spacer"></div>
            <div class="search-box" style="width:240px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="unitSearch" placeholder="Search units…" oninput="filterUnitCards()">
            </div>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openUnitModal()">＋ New Unit</button>
        </div>

        <div class="yz-grid-3" id="unitCards">
            <?php if (!empty($units)): ?>
                <?php foreach ($units as $unit): ?>
                    <?php
                    $statusKind = $unit["status"] === "active" ? "ok" : "off";
                    $payload = htmlspecialchars(json_encode([
                        'id' => $unit['unit_id'],
                        'name' => $unit['unit_name'],
                        'abbr' => $unit['abbreviation'],
                        'status' => $unit['status'],
                    ]), ENT_QUOTES);
                    ?>
                    <div class="yz-card unit-card" data-name="<?= htmlspecialchars(mb_strtolower($unit['unit_name'] . ' ' . $unit['abbreviation'])); ?>">
                        <div class="unit-card-main" onclick='openUnitModal(<?= $payload; ?>)'>
                            <div class="unit-card-avatar"><?= htmlspecialchars($unit['abbreviation']); ?></div>
                            <div class="unit-card-info">
                                <div class="unit-card-name"><?= htmlspecialchars($unit['unit_name']); ?></div>
                                <div class="unit-card-abbr">Abbr · <?= htmlspecialchars($unit['abbreviation']); ?></div>
                            </div>
                            <span class="yz-badge yz-badge-<?= $statusKind; ?>"><?= $allowedStatuses[$unit['status']] ?? $unit['status']; ?></span>
                        </div>
                        <div class="unit-card-actions">
                            <button type="button" class="icon-btn icon-btn-edit" title="Edit" onclick='openUnitModal(<?= $payload; ?>)'>✎</button>
                            <form method="POST" action="<?= BASE_URL; ?>actions/unit_action.php" onsubmit="return confirmAction(this, { title: 'Deactivate this unit?', message: 'This unit will no longer be available for new records.', confirmLabel: 'Deactivate' });">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="unit_id" value="<?= (int) $unit['unit_id']; ?>">
                                <button type="submit" class="icon-btn icon-btn-delete" title="Deactivate" style="margin:0;">✕</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:var(--text-faint); padding:20px;">No units added yet.</div>
            <?php endif; ?>
        </div>

    </main>

    <div class="yz-modal-overlay" id="unitModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/unit_action.php" id="unitForm">
                <div class="yz-modal-header">
                    <div>
                        <h3 id="unitModalTitle">New Unit</h3>
                        <p>A measurement unit for items</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('unitModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" id="unitAction" value="add">
                    <input type="hidden" name="unit_id" id="unitId" value="">
                    <div class="yz-row yz-row-2-1">
                        <div class="yz-field">
                            <label>Unit name</label>
                            <input type="text" name="unit_name" id="unitName" placeholder="e.g. Kilogram" required>
                        </div>
                        <div class="yz-field">
                            <label>Abbreviation</label>
                            <input type="text" name="abbreviation" id="unitAbbr" placeholder="kg" required>
                        </div>
                    </div>
                    <div class="yz-field">
                        <label>Status</label>
                        <select name="status" id="unitStatus" required>
                            <?php foreach ($allowedStatuses as $value => $label): ?>
                                <option value="<?= $value; ?>"><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('unitModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openUnitModal(u) {
            document.getElementById('unitForm').reset();
            if (u) {
                document.getElementById('unitModalTitle').textContent = 'Edit Unit';
                document.getElementById('unitAction').value = 'update';
                document.getElementById('unitId').value = u.id;
                document.getElementById('unitName').value = u.name;
                document.getElementById('unitAbbr').value = u.abbr;
                document.getElementById('unitStatus').value = u.status;
            } else {
                document.getElementById('unitModalTitle').textContent = 'New Unit';
                document.getElementById('unitAction').value = 'add';
                document.getElementById('unitId').value = '';
            }
            openYzModal('unitModal');
        }
        function filterUnitCards() {
            var q = document.getElementById('unitSearch').value.trim().toLowerCase();
            document.querySelectorAll('#unitCards [data-name]').forEach(function (card) {
                card.style.display = card.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
