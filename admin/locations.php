<?php
session_start();

require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$allowedTypes = [
    "factory" => "Factory",
    "store" => "Store"
];

$allowedStatuses = [
    "active" => "Active",
    "inactive" => "Inactive"
];

try {
    $stmt = $pdo->query("SELECT * FROM locations ORDER BY location_id DESC");
    $locations = $stmt->fetchAll();
} catch (PDOException $e) {
    $locations = [];
}

$navCrumb = 'Setup';
$navTitle = 'Locations';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta"><?= count($locations); ?> locations</span>
            <div class="spacer"></div>
            <div class="search-box" style="width:240px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="locationSearch" placeholder="Search locations…" oninput="filterLocationCards()">
            </div>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openLocationModal()">＋ New Location</button>
        </div>

        <div class="yz-grid-3" id="locationCards">
            <?php if (!empty($locations)): ?>
                <?php foreach ($locations as $location): ?>
                    <?php
                    $type = $location['location_type'];
                    $typeKind = $type === 'factory' ? 'info' : 'purple';
                    $statusKind = $location['status'] === 'active' ? 'ok' : 'off';
                    $payload = htmlspecialchars(json_encode([
                        'id' => $location['location_id'],
                        'name' => $location['location_name'],
                        'type' => $type,
                        'status' => $location['status'],
                    ]), ENT_QUOTES);
                    ?>
                    <div class="yz-card" style="padding:22px; position:relative;" data-name="<?= htmlspecialchars(mb_strtolower($location['location_name'])); ?>">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                            <div style="width:46px; height:46px; border-radius:13px; background:var(--bg); display:flex; align-items:center; justify-content:center; font-size:20px; cursor:pointer;" onclick='openLocationModal(<?= $payload; ?>)'>⌂</div>
                            <span class="yz-badge yz-badge-<?= $typeKind; ?>"><?= $allowedTypes[$type] ?? $type; ?></span>
                        </div>
                        <div style="font-family:var(--font-display); font-weight:800; font-size:17px; cursor:pointer;" onclick='openLocationModal(<?= $payload; ?>)'><?= htmlspecialchars($location['location_name']); ?></div>
                        <div style="margin-top:8px; display:flex; align-items:center; justify-content:space-between;">
                            <span class="yz-badge yz-badge-<?= $statusKind; ?>"><?= $allowedStatuses[$location['status']] ?? $location['status']; ?></span>
                            <form method="POST" action="<?= BASE_URL; ?>actions/location_action.php" onsubmit="return confirmAction(this, { title: 'Deactivate this location?', message: 'This location will no longer be available for new records.', confirmLabel: 'Deactivate' });">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="location_id" value="<?= (int) $location['location_id']; ?>">
                                <button type="submit" class="icon-btn icon-btn-delete" title="Deactivate" style="margin:0;">✕</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:var(--text-faint); padding:20px;">No locations added yet.</div>
            <?php endif; ?>
        </div>

    </main>

    <div class="yz-modal-overlay" id="locationModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/location_action.php" id="locationForm">
                <div class="yz-modal-header">
                    <div>
                        <h3 id="locationModalTitle">New Location</h3>
                        <p>A factory or store location</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('locationModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" id="locationAction" value="add">
                    <input type="hidden" name="location_id" id="locationId" value="">
                    <div class="yz-field">
                        <label>Location name</label>
                        <input type="text" name="location_name" id="locationName" placeholder="e.g. Saida Store" required>
                    </div>
                    <div class="yz-field">
                        <label>Type</label>
                        <select name="location_type" id="locationType" required>
                            <option value="">Select Location Type</option>
                            <?php foreach ($allowedTypes as $value => $label): ?>
                                <option value="<?= $value; ?>"><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="yz-field">
                        <label>Status</label>
                        <select name="status" id="locationStatus" required>
                            <?php foreach ($allowedStatuses as $value => $label): ?>
                                <option value="<?= $value; ?>"><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('locationModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openLocationModal(l) {
            document.getElementById('locationForm').reset();
            if (l) {
                document.getElementById('locationModalTitle').textContent = 'Edit Location';
                document.getElementById('locationAction').value = 'update';
                document.getElementById('locationId').value = l.id;
                document.getElementById('locationName').value = l.name;
                document.getElementById('locationType').value = l.type;
                document.getElementById('locationStatus').value = l.status;
            } else {
                document.getElementById('locationModalTitle').textContent = 'New Location';
                document.getElementById('locationAction').value = 'add';
                document.getElementById('locationId').value = '';
            }
            openYzModal('locationModal');
        }
        function filterLocationCards() {
            var q = document.getElementById('locationSearch').value.trim().toLowerCase();
            document.querySelectorAll('#locationCards [data-name]').forEach(function (card) {
                card.style.display = card.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
