<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/stock-movements.php";

$createdBy = 1;
$voidedBy = 1;

$movementDirectionMap = [
    "purchase_in" => "in",
    "production_consumption" => "out",
    "adjustment_in" => "in",
    "adjustment_out" => "out",
    "waste_out" => "out",
    "return_in" => "in"
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

function validatePositiveDecimal($value)
{
    if ($value === "" || $value === null || !is_numeric($value)) {
        return false;
    }

    if ((float)$value <= 0) {
        return false;
    }

    return (float)$value;
}

function validateZeroOrPositiveDecimal($value, $default = 0)
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

function activeItemExists($pdo, $itemId)
{
    $stmt = $pdo->prepare("
        SELECT item_id
        FROM items
        WHERE item_id = :item_id
        AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        ":item_id" => $itemId
    ]);

    return (bool)$stmt->fetch();
}

function activeLocationExists($pdo, $locationId)
{
    $stmt = $pdo->prepare("
        SELECT location_id
        FROM locations
        WHERE location_id = :location_id
        AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([
        ":location_id" => $locationId
    ]);

    return (bool)$stmt->fetch();
}

function getAvailableStock($pdo, $itemId, $locationId)
{
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(
                CASE 
                    WHEN direction = 'in' THEN quantity
                    WHEN direction = 'out' THEN -quantity
                    ELSE 0
                END
            ), 0) AS available_stock
        FROM stock_movements
        WHERE item_id = :item_id
        AND location_id = :location_id
        AND status = 'active'
    ");

    $stmt->execute([
        ":item_id" => $itemId,
        ":location_id" => $locationId
    ]);

    $row = $stmt->fetch();

    return $row ? (float)$row["available_stock"] : 0;
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

if ($action === "add") {
    $itemId = filter_var($_POST["item_id"] ?? null, FILTER_VALIDATE_INT);
    $locationId = filter_var($_POST["location_id"] ?? null, FILTER_VALIDATE_INT);
    $movementType = $_POST["movement_type"] ?? "";
    $quantity = validatePositiveDecimal($_POST["quantity"] ?? null);
    $totalCostUsd = validateZeroOrPositiveDecimal($_POST["total_cost_usd"] ?? 0);
    $referenceNo = trim($_POST["reference_no"] ?? "");
    $notes = trim($_POST["notes"] ?? "");

    if (!$itemId || !activeItemExists($pdo, $itemId)) {
        redirectWithMessage("error", "Invalid or inactive item selected.", $redirectUrl);
    }

    if (!$locationId || !activeLocationExists($pdo, $locationId)) {
        redirectWithMessage("error", "Invalid or inactive location selected.", $redirectUrl);
    }

    if (!array_key_exists($movementType, $movementDirectionMap)) {
        redirectWithMessage("error", "Invalid movement type selected.", $redirectUrl);
    }

    if ($quantity === false) {
        redirectWithMessage("error", "Quantity must be greater than zero.", $redirectUrl);
    }

    if ($totalCostUsd === false) {
        redirectWithMessage("error", "Total cost must be zero or a valid positive number.", $redirectUrl);
    }

    $direction = $movementDirectionMap[$movementType];

    if ($movementType === "purchase_in") {
        if ($totalCostUsd <= 0) {
            redirectWithMessage("error", "Total purchase price is required for Purchase In.", $redirectUrl);
        }

        $unitCostUsd = $totalCostUsd / $quantity;
    } else {
        $averageUnitCost = getAverageUnitCost($pdo, $itemId);
        $unitCostUsd = $averageUnitCost;
        $totalCostUsd = $quantity * $unitCostUsd;
    }

    if ($direction === "out") {
        $availableStock = getAvailableStock($pdo, $itemId, $locationId);

        if ($quantity > $availableStock) {
            redirectWithMessage(
                "error",
                "Not enough stock available. Current available stock is " . number_format($availableStock, 3) . ".",
                $redirectUrl
            );
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO stock_movements
                (
                    item_id,
                    location_id,
                    movement_type,
                    direction,
                    quantity,
                    unit_cost_usd,
                    total_cost_usd,
                    reference_no,
                    notes,
                    status,
                    created_by
                )
            VALUES
                (
                    :item_id,
                    :location_id,
                    :movement_type,
                    :direction,
                    :quantity,
                    :unit_cost_usd,
                    :total_cost_usd,
                    :reference_no,
                    :notes,
                    'active',
                    :created_by
                )
        ");

        $stmt->execute([
            ":item_id" => $itemId,
            ":location_id" => $locationId,
            ":movement_type" => $movementType,
            ":direction" => $direction,
            ":quantity" => $quantity,
            ":unit_cost_usd" => $unitCostUsd,
            ":total_cost_usd" => $totalCostUsd,
            ":reference_no" => $referenceNo,
            ":notes" => $notes,
            ":created_by" => $createdBy
        ]);

        redirectWithMessage("success", "Stock movement saved successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to save stock movement.", $redirectUrl);
    }
}

if ($action === "void") {
    $movementId = filter_var($_POST["movement_id"] ?? null, FILTER_VALIDATE_INT);
    $voidReason = trim($_POST["void_reason"] ?? "");

    if (!$movementId) {
        redirectWithMessage("error", "Invalid movement selected.", $redirectUrl);
    }

    if ($voidReason === "") {
        redirectWithMessage("error", "Void reason is required.", $redirectUrl);
    }

    if (strlen($voidReason) < 5) {
        redirectWithMessage("error", "Void reason must be at least 5 characters.", $redirectUrl);
    }

    try {
        $checkStmt = $pdo->prepare("
            SELECT movement_id
            FROM stock_movements
            WHERE movement_id = :movement_id
            AND status = 'active'
            LIMIT 1
        ");

        $checkStmt->execute([
            ":movement_id" => $movementId
        ]);

        if (!$checkStmt->fetch()) {
            redirectWithMessage("error", "Movement does not exist or is already voided.", $redirectUrl);
        }

        $stmt = $pdo->prepare("
            UPDATE stock_movements
            SET 
                status = 'voided',
                void_reason = :void_reason,
                voided_by = :voided_by,
                voided_at = NOW()
            WHERE movement_id = :movement_id
            AND status = 'active'
        ");

        $stmt->execute([
            ":void_reason" => $voidReason,
            ":voided_by" => $voidedBy,
            ":movement_id" => $movementId
        ]);

        redirectWithMessage("success", "Stock movement voided successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to void movement.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);