<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/finished-products.php";

$allowedStatuses = [
    "active",
    "inactive"
];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . $redirectUrl);
    exit;
}

$action = $_POST["action"] ?? "";

function redirectWithMessage($type, $message, $redirectUrl)
{
    $_SESSION[$type] = $message;
    header("Location: " . $redirectUrl);
    exit;
}

function validateDecimal($value, $default = 0)
{
    if ($value === "" || $value === null) {
        return $default;
    }

    if (!is_numeric($value)) {
        return false;
    }

    if ((float)$value < 0) {
        return false;
    }

    return (float)$value;
}

function normalizeFamilyCode($familyCode)
{
    $familyCode = strtoupper(trim($familyCode));
    $familyCode = preg_replace('/[^A-Z0-9]/', '', $familyCode);

    return $familyCode;
}

function getProductCategoryData($pdo, $categoryId)
{
    $stmt = $pdo->prepare("
        SELECT category_id, category_type
        FROM categories
        WHERE category_id = :category_id
        AND status = 'active'
        AND category_type = 'finished_product'
        LIMIT 1
    ");

    $stmt->execute([
        ":category_id" => $categoryId
    ]);

    return $stmt->fetch();
}

function activeUnitExists($pdo, $unitId)
{
    $stmt = $pdo->prepare("
        SELECT unit_id
        FROM units
        WHERE unit_id = :unit_id
        AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        ":unit_id" => $unitId
    ]);

    return (bool)$stmt->fetch();
}

function activeComponentItemExists($pdo, $itemId)
{
    $stmt = $pdo->prepare("
        SELECT 
            i.item_id,
            i.item_name,
            c.category_type
        FROM items i
        INNER JOIN categories c ON i.category_id = c.category_id
        WHERE i.item_id = :item_id
        AND i.status = 'active'
        AND c.category_type IN ('ingredient', 'supporting_item', 'other')
        LIMIT 1
    ");

    $stmt->execute([
        ":item_id" => $itemId
    ]);

    return $stmt->fetch();
}

function getAverageUnitCost($pdo, $itemId)
{
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(quantity), 0) AS total_qty,
            COALESCE(SUM(total_cost_usd), 0) AS total_cost
        FROM stock_movements
        WHERE item_id = :item_id
        AND movement_type = 'purchase_in'
        AND status = 'active'
    ");

    $stmt->execute([
        ":item_id" => $itemId
    ]);

    $row = $stmt->fetch();

    $totalQty = $row ? (float)$row["total_qty"] : 0;
    $totalCost = $row ? (float)$row["total_cost"] : 0;

    if ($totalQty <= 0) {
        return 0;
    }

    return $totalCost / $totalQty;
}

function generateProductCode($pdo, $familyCode)
{
    $familyCode = normalizeFamilyCode($familyCode);

    if ($familyCode === "") {
        return false;
    }

    $codePrefix = "PRD-" . $familyCode . "-";

    $stmt = $pdo->prepare("
        SELECT product_code
        FROM finished_products
        WHERE product_code LIKE :code_prefix
        ORDER BY CAST(SUBSTRING_INDEX(product_code, '-', -1) AS UNSIGNED) DESC
        LIMIT 1
    ");

    $stmt->execute([
        ":code_prefix" => $codePrefix . "%"
    ]);

    $lastProduct = $stmt->fetch();

    if ($lastProduct) {
        $lastCode = $lastProduct["product_code"];
        $lastNumber = (int) substr($lastCode, strrpos($lastCode, "-") + 1);
        $nextNumber = $lastNumber + 1;
    } else {
        $nextNumber = 1;
    }

    return $codePrefix . str_pad($nextNumber, 3, "0", STR_PAD_LEFT);
}

function prepareComponents($pdo, $itemIds, $quantities)
{
    $preparedComponents = [];
    $merged = [];

    if (!is_array($itemIds) || !is_array($quantities)) {
        return [
            "success" => false,
            "message" => "At least one ingredient/item is required.",
            "components" => []
        ];
    }

    foreach ($itemIds as $index => $rawItemId) {
        $itemId = filter_var($rawItemId, FILTER_VALIDATE_INT);
        $quantity = $quantities[$index] ?? null;

        if (!$itemId || $quantity === null || !is_numeric($quantity) || (float)$quantity <= 0) {
            return [
                "success" => false,
                "message" => "Each component must have a valid item and quantity.",
                "components" => []
            ];
        }

        if (!isset($merged[$itemId])) {
            $merged[$itemId] = 0;
        }

        $merged[$itemId] += (float)$quantity;
    }

    if (empty($merged)) {
        return [
            "success" => false,
            "message" => "At least one ingredient/item is required.",
            "components" => []
        ];
    }

    foreach ($merged as $itemId => $quantityUsed) {
        $itemData = activeComponentItemExists($pdo, $itemId);

        if (!$itemData) {
            return [
                "success" => false,
                "message" => "Invalid or inactive component item selected.",
                "components" => []
            ];
        }

        $unitCost = getAverageUnitCost($pdo, $itemId);

        if ($unitCost <= 0) {
            return [
                "success" => false,
                "message" => "Item '" . $itemData["item_name"] . "' has no purchase cost yet. Add a Purchase In movement first.",
                "components" => []
            ];
        }

        $totalCost = $quantityUsed * $unitCost;

        $preparedComponents[] = [
            "item_id" => $itemId,
            "quantity_used" => $quantityUsed,
            "unit_cost_usd" => $unitCost,
            "total_cost_usd" => $totalCost
        ];
    }

    return [
        "success" => true,
        "message" => "Components prepared successfully.",
        "components" => $preparedComponents
    ];
}

function calculateTotals($components, $profitPercentage)
{
    $calculatedCost = 0;

    foreach ($components as $component) {
        $calculatedCost += (float)$component["total_cost_usd"];
    }

    $sellingPrice = $calculatedCost + ($calculatedCost * ($profitPercentage / 100));

    return [
        "calculated_cost_usd" => $calculatedCost,
        "selling_price_usd" => $sellingPrice
    ];
}

if ($action === "add") {
    $productName = trim($_POST["product_name"] ?? "");
    $categoryId = filter_var($_POST["category_id"] ?? null, FILTER_VALIDATE_INT);
    $familyCode = normalizeFamilyCode($_POST["family_code"] ?? "");
    $unitId = filter_var($_POST["unit_id"] ?? null, FILTER_VALIDATE_INT);
    $profitPercentage = validateDecimal($_POST["profit_percentage"] ?? 60, 60);
    $minStockQty = validateDecimal($_POST["min_stock_qty"] ?? 0);
    $status = $_POST["status"] ?? "active";

    $componentItemIds = $_POST["component_item_id"] ?? [];
    $quantitiesUsed = $_POST["quantity_used"] ?? [];

    if ($productName === "") {
        redirectWithMessage("error", "Product name is required.", $redirectUrl);
    }

    // Check for duplicate product name
    $dupStmt = $pdo->prepare("
    SELECT product_id FROM finished_products
    WHERE LOWER(product_name) = LOWER(:product_name)
    LIMIT 1
");
    $dupStmt->execute([":product_name" => $productName]);
    if ($dupStmt->fetch()) {
        redirectWithMessage("error", "A product with the name \"" . htmlspecialchars($productName) . "\" already exists. Please use a different name.", $redirectUrl);
    }

    if (!$categoryId || !getProductCategoryData($pdo, $categoryId)) {
        redirectWithMessage("error", "Invalid or inactive finished product category selected.", $redirectUrl);
    }

    if ($familyCode === "") {
        redirectWithMessage("error", "Family code is required.", $redirectUrl);
    }

    if (!$unitId || !activeUnitExists($pdo, $unitId)) {
        redirectWithMessage("error", "Invalid or inactive unit selected.", $redirectUrl);
    }

    if ($profitPercentage === false) {
        redirectWithMessage("error", "Profit percentage must be a valid number.", $redirectUrl);
    }

    if ($minStockQty === false) {
        redirectWithMessage("error", "Minimum stock must be a valid number.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    $componentResult = prepareComponents($pdo, $componentItemIds, $quantitiesUsed);

    if (!$componentResult["success"]) {
        redirectWithMessage("error", $componentResult["message"], $redirectUrl);
    }

    $components = $componentResult["components"];
    $totals = calculateTotals($components, $profitPercentage);

    $productCode = generateProductCode($pdo, $familyCode);

    if (!$productCode) {
        redirectWithMessage("error", "Failed to generate product code.", $redirectUrl);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO finished_products
                (
                    product_name,
                    product_code,
                    family_code,
                    category_id,
                    unit_id,
                    calculated_cost_usd,
                    profit_percentage,
                    selling_price_usd,
                    min_stock_qty,
                    status
                )
            VALUES
                (
                    :product_name,
                    :product_code,
                    :family_code,
                    :category_id,
                    :unit_id,
                    :calculated_cost_usd,
                    :profit_percentage,
                    :selling_price_usd,
                    :min_stock_qty,
                    :status
                )
        ");

        $stmt->execute([
            ":product_name" => $productName,
            ":product_code" => $productCode,
            ":family_code" => $familyCode,
            ":category_id" => $categoryId,
            ":unit_id" => $unitId,
            ":calculated_cost_usd" => $totals["calculated_cost_usd"],
            ":profit_percentage" => $profitPercentage,
            ":selling_price_usd" => $totals["selling_price_usd"],
            ":min_stock_qty" => $minStockQty,
            ":status" => $status
        ]);

        $productId = $pdo->lastInsertId();

        $componentStmt = $pdo->prepare("
            INSERT INTO finished_product_components
                (
                    product_id,
                    item_id,
                    quantity_used,
                    unit_cost_usd,
                    total_cost_usd
                )
            VALUES
                (
                    :product_id,
                    :item_id,
                    :quantity_used,
                    :unit_cost_usd,
                    :total_cost_usd
                )
        ");

        foreach ($components as $component) {
            $componentStmt->execute([
                ":product_id" => $productId,
                ":item_id" => $component["item_id"],
                ":quantity_used" => $component["quantity_used"],
                ":unit_cost_usd" => $component["unit_cost_usd"],
                ":total_cost_usd" => $component["total_cost_usd"]
            ]);
        }

        $pdo->commit();

        redirectWithMessage(
            "success",
            "Finished product added successfully. Product Code: " . $productCode,
            $redirectUrl
        );
    } catch (PDOException $e) {
        $pdo->rollBack();
        redirectWithMessage("error", "Failed to add finished product.", $redirectUrl);
    }
}

if ($action === "update") {
    $productId = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);
    $productName = trim($_POST["product_name"] ?? "");
    $categoryId = filter_var($_POST["category_id"] ?? null, FILTER_VALIDATE_INT);
    $familyCode = normalizeFamilyCode($_POST["family_code"] ?? "");
    $unitId = filter_var($_POST["unit_id"] ?? null, FILTER_VALIDATE_INT);
    $profitPercentage = validateDecimal($_POST["profit_percentage"] ?? 60, 60);
    $minStockQty = validateDecimal($_POST["min_stock_qty"] ?? 0);
    $status = $_POST["status"] ?? "active";

    $componentItemIds = $_POST["component_item_id"] ?? [];
    $quantitiesUsed = $_POST["quantity_used"] ?? [];

    if (!$productId) {
        redirectWithMessage("error", "Invalid product selected.", $redirectUrl);
    }

    if ($productName === "") {
        redirectWithMessage("error", "Product name is required.", $redirectUrl);
    }

    // Check for duplicate product name (exclude current product)
    $dupStmt = $pdo->prepare("
    SELECT product_id FROM finished_products
    WHERE LOWER(product_name) = LOWER(:product_name)
    AND product_id != :product_id
    LIMIT 1
");
    $dupStmt->execute([
        ":product_name" => $productName,
        ":product_id"   => $productId
    ]);
    if ($dupStmt->fetch()) {
        redirectWithMessage("error", "A product with the name \"" . htmlspecialchars($productName) . "\" already exists. Please use a different name.", $redirectUrl . "?edit=" . $productId);
    }

    if (!$categoryId || !getProductCategoryData($pdo, $categoryId)) {
        redirectWithMessage("error", "Invalid or inactive finished product category selected.", $redirectUrl);
    }

    if ($familyCode === "") {
        redirectWithMessage("error", "Family code is required.", $redirectUrl);
    }

    if (!$unitId || !activeUnitExists($pdo, $unitId)) {
        redirectWithMessage("error", "Invalid or inactive unit selected.", $redirectUrl);
    }

    if ($profitPercentage === false) {
        redirectWithMessage("error", "Profit percentage must be a valid number.", $redirectUrl);
    }

    if ($minStockQty === false) {
        redirectWithMessage("error", "Minimum stock must be a valid number.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    $componentResult = prepareComponents($pdo, $componentItemIds, $quantitiesUsed);

    if (!$componentResult["success"]) {
        redirectWithMessage("error", $componentResult["message"], $redirectUrl . "?edit=" . $productId);
    }

    $components = $componentResult["components"];
    $totals = calculateTotals($components, $profitPercentage);

    try {
        $pdo->beginTransaction();

        $checkStmt = $pdo->prepare("
            SELECT product_id
            FROM finished_products
            WHERE product_id = :product_id
            LIMIT 1
        ");

        $checkStmt->execute([
            ":product_id" => $productId
        ]);

        if (!$checkStmt->fetch()) {
            $pdo->rollBack();
            redirectWithMessage("error", "Product not found.", $redirectUrl);
        }

        $stmt = $pdo->prepare("
            UPDATE finished_products
            SET
                product_name = :product_name,
                family_code = :family_code,
                category_id = :category_id,
                unit_id = :unit_id,
                calculated_cost_usd = :calculated_cost_usd,
                profit_percentage = :profit_percentage,
                selling_price_usd = :selling_price_usd,
                min_stock_qty = :min_stock_qty,
                status = :status
            WHERE product_id = :product_id
        ");

        $stmt->execute([
            ":product_name" => $productName,
            ":family_code" => $familyCode,
            ":category_id" => $categoryId,
            ":unit_id" => $unitId,
            ":calculated_cost_usd" => $totals["calculated_cost_usd"],
            ":profit_percentage" => $profitPercentage,
            ":selling_price_usd" => $totals["selling_price_usd"],
            ":min_stock_qty" => $minStockQty,
            ":status" => $status,
            ":product_id" => $productId
        ]);

        $deleteStmt = $pdo->prepare("
            DELETE FROM finished_product_components
            WHERE product_id = :product_id
        ");

        $deleteStmt->execute([
            ":product_id" => $productId
        ]);

        $componentStmt = $pdo->prepare("
            INSERT INTO finished_product_components
                (
                    product_id,
                    item_id,
                    quantity_used,
                    unit_cost_usd,
                    total_cost_usd
                )
            VALUES
                (
                    :product_id,
                    :item_id,
                    :quantity_used,
                    :unit_cost_usd,
                    :total_cost_usd
                )
        ");

        foreach ($components as $component) {
            $componentStmt->execute([
                ":product_id" => $productId,
                ":item_id" => $component["item_id"],
                ":quantity_used" => $component["quantity_used"],
                ":unit_cost_usd" => $component["unit_cost_usd"],
                ":total_cost_usd" => $component["total_cost_usd"]
            ]);
        }

        $pdo->commit();

        redirectWithMessage("success", "Finished product updated successfully.", $redirectUrl);
    } catch (PDOException $e) {
        $pdo->rollBack();
        redirectWithMessage("error", "Failed to update finished product.", $redirectUrl . "?edit=" . $productId);
    }
}

if ($action === "delete") {
    $productId = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$productId) {
        redirectWithMessage("error", "Invalid product selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE finished_products
            SET status = 'inactive'
            WHERE product_id = :product_id
        ");

        $stmt->execute([
            ":product_id" => $productId
        ]);

        redirectWithMessage("success", "Finished product deactivated successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to deactivate finished product.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);
