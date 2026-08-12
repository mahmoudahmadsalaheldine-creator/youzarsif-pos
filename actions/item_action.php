<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/items.php";

$allowedStatuses = [
    "active",
    "inactive"
];

$allowedCategoryTypesForItems = [
    "ingredient",
    "supporting_item",
    "other"
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

function getCategoryData($pdo, $categoryId)
{
    $stmt = $pdo->prepare("
        SELECT category_id, category_type
        FROM categories
        WHERE category_id = :category_id
        AND status = 'active'
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

function getPrefixFromCategoryType($categoryType)
{
    $prefixes = [
        "ingredient" => "ING",
        "supporting_item" => "SUP",
        "other" => "OTH"
    ];

    return $prefixes[$categoryType] ?? null;
}

function generateItemCode($pdo, $categoryType, $familyCode)
{
    $prefix = getPrefixFromCategoryType($categoryType);

    if (!$prefix) {
        return false;
    }

    $familyCode = normalizeFamilyCode($familyCode);

    if ($familyCode === "") {
        return false;
    }

    $codePrefix = $prefix . "-" . $familyCode . "-";

    $stmt = $pdo->prepare("
        SELECT item_code
        FROM items
        WHERE item_code LIKE :code_prefix
        ORDER BY CAST(SUBSTRING_INDEX(item_code, '-', -1) AS UNSIGNED) DESC
        LIMIT 1
    ");

    $stmt->execute([
        ":code_prefix" => $codePrefix . "%"
    ]);

    $lastItem = $stmt->fetch();

    if ($lastItem) {
        $lastCode = $lastItem["item_code"];
        $lastNumber = (int) substr($lastCode, strrpos($lastCode, "-") + 1);
        $nextNumber = $lastNumber + 1;
    } else {
        $nextNumber = 1;
    }

    return $codePrefix . str_pad($nextNumber, 3, "0", STR_PAD_LEFT);
}

if ($action === "add") {
    $itemName = trim($_POST["item_name"] ?? "");
    $categoryId = filter_var($_POST["category_id"] ?? null, FILTER_VALIDATE_INT);
    $familyCode = normalizeFamilyCode($_POST["family_code"] ?? "");
    $unitId = filter_var($_POST["unit_id"] ?? null, FILTER_VALIDATE_INT);
    $minStockQty = validateDecimal($_POST["min_stock_qty"] ?? 0);
    $status = $_POST["status"] ?? "active";

    if ($itemName === "") {
        redirectWithMessage("error", "Item name is required.", $redirectUrl);
    }

    if (!$categoryId) {
        redirectWithMessage("error", "Category is required.", $redirectUrl);
    }

    $categoryData = getCategoryData($pdo, $categoryId);

    if (!$categoryData) {
        redirectWithMessage("error", "Invalid or inactive category selected.", $redirectUrl);
    }

    if (!in_array($categoryData["category_type"], $allowedCategoryTypesForItems)) {
        redirectWithMessage("error", "Only Ingredient, Supporting Item, or Other categories can be used here.", $redirectUrl);
    }

    if ($familyCode === "") {
        redirectWithMessage("error", "Family code is required.", $redirectUrl);
    }

    if (!$unitId || !activeUnitExists($pdo, $unitId)) {
        redirectWithMessage("error", "Invalid or inactive unit selected.", $redirectUrl);
    }

    if ($minStockQty === false) {
        redirectWithMessage("error", "Minimum stock must be a valid number.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    $itemCode = generateItemCode($pdo, $categoryData["category_type"], $familyCode);

    if (!$itemCode) {
        redirectWithMessage("error", "Failed to generate item code.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO items 
                (
                    item_name,
                    item_code,
                    family_code,
                    category_id,
                    unit_id,
                    min_stock_qty,
                    status
                )
            VALUES
                (
                    :item_name,
                    :item_code,
                    :family_code,
                    :category_id,
                    :unit_id,
                    :min_stock_qty,
                    :status
                )
        ");

        $stmt->execute([
            ":item_name" => $itemName,
            ":item_code" => $itemCode,
            ":family_code" => $familyCode,
            ":category_id" => $categoryId,
            ":unit_id" => $unitId,
            ":min_stock_qty" => $minStockQty,
            ":status" => $status
        ]);

        redirectWithMessage(
            "success",
            "Item added successfully. Generated Code: " . $itemCode,
            $redirectUrl
        );

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to add item.", $redirectUrl);
    }
}

if ($action === "update") {
    $itemId = filter_var($_POST["item_id"] ?? null, FILTER_VALIDATE_INT);
    $itemName = trim($_POST["item_name"] ?? "");
    $categoryId = filter_var($_POST["category_id"] ?? null, FILTER_VALIDATE_INT);
    $familyCode = normalizeFamilyCode($_POST["family_code"] ?? "");
    $unitId = filter_var($_POST["unit_id"] ?? null, FILTER_VALIDATE_INT);
    $minStockQty = validateDecimal($_POST["min_stock_qty"] ?? 0);
    $status = $_POST["status"] ?? "active";

    if (!$itemId) {
        redirectWithMessage("error", "Invalid item selected.", $redirectUrl);
    }

    if ($itemName === "") {
        redirectWithMessage("error", "Item name is required.", $redirectUrl);
    }

    if (!$categoryId) {
        redirectWithMessage("error", "Category is required.", $redirectUrl);
    }

    $categoryData = getCategoryData($pdo, $categoryId);

    if (!$categoryData) {
        redirectWithMessage("error", "Invalid or inactive category selected.", $redirectUrl);
    }

    if (!in_array($categoryData["category_type"], $allowedCategoryTypesForItems)) {
        redirectWithMessage("error", "Only Ingredient, Supporting Item, or Other categories can be used here.", $redirectUrl);
    }

    if ($familyCode === "") {
        redirectWithMessage("error", "Family code is required.", $redirectUrl);
    }

    if (!$unitId || !activeUnitExists($pdo, $unitId)) {
        redirectWithMessage("error", "Invalid or inactive unit selected.", $redirectUrl);
    }

    if ($minStockQty === false) {
        redirectWithMessage("error", "Minimum stock must be a valid number.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE items
            SET
                item_name = :item_name,
                family_code = :family_code,
                category_id = :category_id,
                unit_id = :unit_id,
                min_stock_qty = :min_stock_qty,
                status = :status
            WHERE item_id = :item_id
        ");

        $stmt->execute([
            ":item_name" => $itemName,
            ":family_code" => $familyCode,
            ":category_id" => $categoryId,
            ":unit_id" => $unitId,
            ":min_stock_qty" => $minStockQty,
            ":status" => $status,
            ":item_id" => $itemId
        ]);

        redirectWithMessage("success", "Item updated successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to update item.", $redirectUrl);
    }
}

if ($action === "delete") {
    $itemId = filter_var($_POST["item_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$itemId) {
        redirectWithMessage("error", "Invalid item selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE items
            SET status = 'inactive'
            WHERE item_id = :item_id
        ");

        $stmt->execute([
            ":item_id" => $itemId
        ]);

        redirectWithMessage("success", "Item deactivated successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to deactivate item.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);