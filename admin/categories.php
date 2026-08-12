<?php
session_start();

require_once "../config/database.php";
require_once "../includes/auth.php";
$currentStaff = requireStaffLogin($pdo);
require_once "../includes/admin_header.php";
require_once "../includes/admin_sidebar.php";

$allowedTypes = [
    "ingredient" => "Ingredient",
    "supporting_item" => "Supporting Item",
    "finished_product" => "Finished Product",
    "expense" => "Expense",
    "other" => "Other"
];

$typeBadgeKind = [
    "ingredient" => "info",
    "supporting_item" => "purple",
    "finished_product" => "ok",
    "expense" => "warn",
    "other" => "off"
];

$typeBadgeLabel = [
    "ingredient" => "Ingredient",
    "supporting_item" => "Supporting",
    "finished_product" => "Finished Product",
    "expense" => "Expense",
    "other" => "Other"
];

$allowedStatuses = [
    "active" => "Active",
    "inactive" => "Inactive"
];

try {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY category_id DESC");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}

$navCrumb = 'Setup';
$navTitle = 'Categories';
?>

<div class="admin-main">

    <?php require_once "../includes/admin_navbar.php"; ?>

    <main class="admin-content">


    <?php require_once "../includes/back_link.php"; ?>
        <div class="toolbar">
            <span class="toolbar-meta"><?= count($categories); ?> categories</span>
            <div class="spacer"></div>
            <div class="search-box" style="width:240px;">
                <span style="color:var(--caramel);">⌕</span>
                <input type="text" id="categorySearch" placeholder="Search categories…" oninput="filterCategoryRows()">
            </div>
            <button type="button" class="btn-yz btn-yz-dark" onclick="openYzModal('categoryModal')">
                ＋ New Category
            </button>
        </div>

        <div class="yz-table-wrap">
            <table class="yz-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Type</th>
                        <th class="text-center">Items</th>
                        <th>Status</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody id="categoryRows">
                    <?php if (!empty($categories)): ?>
                        <?php foreach ($categories as $category): ?>
                            <?php
                            $type = $category["category_type"];
                            $kind = $typeBadgeKind[$type] ?? 'off';
                            $statusKind = $category["status"] === "active" ? "ok" : "off";
                            $editPayload = htmlspecialchars(json_encode([
                                'id' => $category['category_id'],
                                'name' => $category['category_name'],
                                'type' => $type,
                                'status' => $category['status'],
                            ]), ENT_QUOTES);

                            $itemCount = 0;
                            try {
                                $cntStmt = $pdo->prepare("
                                    SELECT
                                        (SELECT COUNT(*) FROM items WHERE category_id = :cid1) +
                                        (SELECT COUNT(*) FROM finished_products WHERE category_id = :cid2) AS cnt
                                ");
                                $cntStmt->execute([':cid1' => $category['category_id'], ':cid2' => $category['category_id']]);
                                $itemCount = (int) $cntStmt->fetch()['cnt'];
                            } catch (PDOException $e) {
                                $itemCount = 0;
                            }
                            ?>
                            <tr data-name="<?= htmlspecialchars(mb_strtolower($category['category_name'])); ?>">
                                <td style="font-family:var(--font-display); font-weight:700; font-size:14px;"><?= htmlspecialchars($category["category_name"]); ?></td>
                                <td><span class="yz-badge yz-badge-<?= $kind; ?>"><?= htmlspecialchars($typeBadgeLabel[$type] ?? $type); ?></span></td>
                                <td class="text-center" style="font-family:var(--font-display); font-weight:700; color:var(--text-muted);"><?= $itemCount; ?></td>
                                <td><span class="yz-badge yz-badge-<?= $statusKind; ?>"><?= $allowedStatuses[$category["status"]] ?? $category["status"]; ?></span></td>
                                <td class="text-end" style="white-space:nowrap;">
                                    <button type="button" class="icon-btn icon-btn-edit" title="Edit"
                                        onclick='openEditCategory(<?= $editPayload; ?>)'>✎</button>
                                    <button type="button" class="icon-btn icon-btn-delete" title="Deactivate"
                                        onclick="openDeleteCategory(<?= (int) $category['category_id']; ?>, '<?= htmlspecialchars(addslashes($category['category_name'])); ?>')">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center" style="color:var(--text-faint); padding:32px 0;">No categories added yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <!-- ===== Category Form Modal ===== -->
    <div class="yz-modal-overlay" id="categoryModal">
        <div class="yz-modal">
            <form method="POST" action="<?= BASE_URL; ?>actions/category_action.php" id="categoryForm">
                <div class="yz-modal-header">
                    <div>
                        <h3 id="categoryModalTitle">New Category</h3>
                        <p>Classify ingredients, items, products &amp; expenses</p>
                    </div>
                    <button type="button" class="yz-modal-close" onclick="closeYzModal('categoryModal')">✕</button>
                </div>
                <div class="yz-modal-body">
                    <input type="hidden" name="action" id="categoryAction" value="add">
                    <input type="hidden" name="category_id" id="categoryId" value="">

                    <div class="yz-field">
                        <label>Category name</label>
                        <input type="text" name="category_name" id="categoryName" placeholder="e.g. Nuts" required>
                    </div>
                    <div class="yz-row yz-row-2">
                        <div class="yz-field">
                            <label>Type</label>
                            <select name="category_type" id="categoryType" required>
                                <option value="">Select Category Type</option>
                                <?php foreach ($allowedTypes as $value => $label): ?>
                                    <option value="<?= $value; ?>"><?= $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="yz-field">
                            <label>Status</label>
                            <select name="status" id="categoryStatus" required>
                                <?php foreach ($allowedStatuses as $value => $label): ?>
                                    <option value="<?= $value; ?>"><?= $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="yz-modal-footer">
                    <button type="button" class="btn-yz btn-yz-outline" onclick="closeYzModal('categoryModal')">Cancel</button>
                    <button type="submit" class="btn-yz btn-yz-dark">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Delete Confirm Modal ===== -->
    <div class="yz-modal-overlay" id="deleteCategoryModal">
        <div class="yz-modal yz-modal-sm" style="padding:28px; text-align:center;">
            <div style="width:60px; height:60px; margin:0 auto 16px; border-radius:50%; background:var(--danger-soft); color:var(--danger); display:flex; align-items:center; justify-content:center; font-size:28px;">
                <i class="bi bi-trash"></i>
            </div>
            <h3 style="font-size:19px;">Deactivate this category?</h3>
            <p style="font-size:13.5px; color:var(--text-muted); margin-top:8px; line-height:1.5;">
                You're about to deactivate <strong id="deleteCategoryName" style="color:var(--text);"></strong>. It will no longer be available for new records.
            </p>
            <form method="POST" action="<?= BASE_URL; ?>actions/category_action.php" style="display:flex; gap:10px; margin-top:24px;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="category_id" id="deleteCategoryId" value="">
                <button type="button" class="btn-yz btn-yz-outline" style="flex:1; justify-content:center;" onclick="closeYzModal('deleteCategoryModal')">Cancel</button>
                <button type="submit" class="btn-yz btn-yz-danger" style="flex:1; justify-content:center;">Deactivate</button>
            </form>
        </div>
    </div>

    <script>
        function openEditCategory(c) {
            document.getElementById('categoryModalTitle').textContent = 'Edit Category';
            document.getElementById('categoryAction').value = 'update';
            document.getElementById('categoryId').value = c.id;
            document.getElementById('categoryName').value = c.name;
            document.getElementById('categoryType').value = c.type;
            document.getElementById('categoryStatus').value = c.status;
            openYzModal('categoryModal');
        }
        function openDeleteCategory(id, name) {
            document.getElementById('deleteCategoryId').value = id;
            document.getElementById('deleteCategoryName').textContent = name;
            openYzModal('deleteCategoryModal');
        }
        document.querySelector('[onclick="openYzModal(\'categoryModal\')"]').addEventListener('click', function () {
            document.getElementById('categoryModalTitle').textContent = 'New Category';
            document.getElementById('categoryAction').value = 'add';
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryForm').reset();
        });
        function filterCategoryRows() {
            var q = document.getElementById('categorySearch').value.trim().toLowerCase();
            document.querySelectorAll('#categoryRows tr[data-name]').forEach(function (row) {
                row.style.display = row.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
            });
        }
    </script>

    <?php require_once "../includes/admin_footer.php"; ?>
