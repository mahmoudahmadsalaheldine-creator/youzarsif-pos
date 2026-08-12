<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/factory-to-store.php";
$createdBy   = 1;

function generateTransferRef($pdo)
{
    $dateStr = date("Ymd");
    $prefix  = "TRF-" . $dateStr . "-";

    $stmt = $pdo->prepare("
        SELECT reference_no
        FROM product_stock_movements
        WHERE reference_no LIKE :prefix
        AND movement_type = 'transfer_out'
        ORDER BY reference_no DESC
        LIMIT 1
    ");

    $stmt->execute([":prefix" => $prefix . "%"]);
    $last = $stmt->fetch();

    if ($last) {
        $lastNum = (int) substr($last["reference_no"], strrpos($last["reference_no"], "-") + 1);
        $next    = $lastNum + 1;
    } else {
        $next = 1;
    }

    return $prefix . str_pad($next, 3, "0", STR_PAD_LEFT);
}

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

// Helper: Get available product stock at a location
function getProductStock($pdo, $productId, $locationId)
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN direction = 'in'  AND status = 'active' THEN quantity
                    WHEN direction = 'out' AND status = 'active' THEN -quantity
                    ELSE 0
                END
            ), 0) AS current_stock
        FROM product_stock_movements
        WHERE product_id  = :product_id
        AND location_id   = :location_id
    ");

    $stmt->execute([
        ":product_id"  => $productId,
        ":location_id" => $locationId
    ]);

    $row = $stmt->fetch();
    return $row ? (float)$row["current_stock"] : 0;
}

// Helper: Validate active product
function getActiveProduct($pdo, $productId)
{
    $stmt = $pdo->prepare("
        SELECT product_id, product_name, calculated_cost_usd
        FROM finished_products
        WHERE product_id = :product_id
        AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([":product_id" => $productId]);
    return $stmt->fetch();
}

// Helper: Validate location by type
function getActiveLocation($pdo, $locationId, $locationType)
{
    $stmt = $pdo->prepare("
        SELECT location_id, location_name
        FROM locations
        WHERE location_id   = :location_id
        AND location_type   = :location_type
        AND status          = 'active'
        LIMIT 1
    ");
    $stmt->execute([
        ":location_id"   => $locationId,
        ":location_type" => $locationType
    ]);
    return $stmt->fetch();
}

// ADD Transfer
if ($action === "add") {

    $productId    = filter_var($_POST["product_id"]     ?? null, FILTER_VALIDATE_INT);
    $fromLocation = filter_var($_POST["from_location_id"] ?? null, FILTER_VALIDATE_INT);
    $toLocation   = filter_var($_POST["to_location_id"]   ?? null, FILTER_VALIDATE_INT);
    $quantity     = $_POST["quantity"] ?? "";
    $referenceNo  = generateTransferRef($pdo);
    $notes        = trim($_POST["notes"] ?? "");

    // --- Validation ---
    if (!$productId) {
        redirectWithMessage("error", "Please select a finished product.", $redirectUrl);
    }

    if (!$fromLocation) {
        redirectWithMessage("error", "Please select the factory location.", $redirectUrl);
    }

    if (!$toLocation) {
        redirectWithMessage("error", "Please select the store location.", $redirectUrl);
    }

    if ($fromLocation === $toLocation) {
        redirectWithMessage("error", "From and To locations cannot be the same.", $redirectUrl);
    }

    if ($quantity === "" || !is_numeric($quantity) || (float)$quantity <= 0) {
        redirectWithMessage("error", "Quantity must be greater than zero.", $redirectUrl);
    }

    $quantity = (float)$quantity;

    // --- Validate product ---
    $product = getActiveProduct($pdo, $productId);

    if (!$product) {
        redirectWithMessage("error", "Invalid or inactive product selected.", $redirectUrl);
    }

    // --- Validate locations ---
    $factory = getActiveLocation($pdo, $fromLocation, "factory");

    if (!$factory) {
        redirectWithMessage("error", "Invalid or inactive factory location.", $redirectUrl);
    }

    $store = getActiveLocation($pdo, $toLocation, "store");

    if (!$store) {
        redirectWithMessage("error", "Invalid or inactive store location.", $redirectUrl);
    }

    // --- Check reference is unique ---
    $refStmt = $pdo->prepare("
        SELECT product_movement_id FROM product_stock_movements
        WHERE reference_no = :reference_no
        LIMIT 1
    ");
    $refStmt->execute([":reference_no" => $referenceNo]);

    if ($refStmt->fetch()) {
        redirectWithMessage("error", "Transfer reference already exists. Please use a unique reference.", $redirectUrl);
    }

    // --- Check available stock at factory ---
    $availableStock = getProductStock($pdo, $productId, $fromLocation);

    if ($availableStock <= 0) {
        redirectWithMessage(
            "error",
            $product["product_name"] . " is out of stock at " . $factory["location_name"] . ". Cannot transfer.",
            $redirectUrl
        );
    }
    if ($quantity > $availableStock) {
        redirectWithMessage(
            "error",
            "Not enough " . $product["product_name"] . " available at " . $factory["location_name"] . ". " .
                "Required: " . number_format($quantity, 2) . " | " .
                "Available: " . number_format($availableStock, 2) . ".",
            $redirectUrl
        );
    }

    $unitCost  = (float)$product["calculated_cost_usd"];
    $totalCost = $quantity * $unitCost;

    // --- Run Transaction ---
    try {
        $pdo->beginTransaction();

        // transfer_out at factory
        $outStmt = $pdo->prepare("
            INSERT INTO product_stock_movements
                (product_id, location_id, movement_type, direction, quantity,
                 unit_cost_usd, total_cost_usd, reference_no, notes, status, created_by)
            VALUES
                (:product_id, :location_id, 'transfer_out', 'out', :quantity,
                 :unit_cost_usd, :total_cost_usd, :reference_no, :notes, 'active', :created_by)
        ");
        $outStmt->execute([
            ":product_id"     => $productId,
            ":location_id"    => $fromLocation,
            ":quantity"       => $quantity,
            ":unit_cost_usd"  => round($unitCost, 2),
            ":total_cost_usd" => round($totalCost, 2),
            ":reference_no"   => $referenceNo,
            ":notes"          => $notes,
            ":created_by"     => $createdBy
        ]);

        // transfer_in at store
        $inStmt = $pdo->prepare("
            INSERT INTO product_stock_movements
                (product_id, location_id, movement_type, direction, quantity,
                 unit_cost_usd, total_cost_usd, reference_no, notes, status, created_by)
            VALUES
                (:product_id, :location_id, 'transfer_in', 'in', :quantity,
                 :unit_cost_usd, :total_cost_usd, :reference_no, :notes, 'active', :created_by)
        ");
        $inStmt->execute([
            ":product_id"     => $productId,
            ":location_id"    => $toLocation,
            ":quantity"       => $quantity,
            ":unit_cost_usd"  => round($unitCost, 2),
            ":total_cost_usd" => round($totalCost, 2),
            ":reference_no"   => $referenceNo,
            ":notes"          => $notes,
            ":created_by"     => $createdBy
        ]);

        $pdo->commit();

        redirectWithMessage(
            "success",
            "Transfer completed. " . number_format($quantity, 2) . " " . $product["product_name"] .
                " moved from " . $factory["location_name"] . " to " . $store["location_name"] . ".",
            $redirectUrl
        );
    } catch (PDOException $e) {
        $pdo->rollBack();
        redirectWithMessage("error", "Failed to save transfer. Please try again.", $redirectUrl);
    }
}

// VOID Transfer
if ($action === "void") {

    $referenceNo = trim($_POST["reference_no"] ?? "");
    $voidReason  = trim($_POST["void_reason"] ?? "");

    if ($referenceNo === "") {
        redirectWithMessage("error", "Invalid transfer selected.", $redirectUrl);
    }

    if ($voidReason === "" || strlen($voidReason) < 5) {
        redirectWithMessage("error", "Void reason is required (min 5 characters).", $redirectUrl);
    }

    try {
        $pdo->beginTransaction();

        $checkStmt = $pdo->prepare("
            SELECT product_movement_id FROM product_stock_movements
            WHERE reference_no = :reference_no
            AND movement_type IN ('transfer_out', 'transfer_in')
            AND status = 'active'
        ");
        $checkStmt->execute([":reference_no" => $referenceNo]);

        if ($checkStmt->rowCount() === 0) {
            $pdo->rollBack();
            redirectWithMessage("error", "Transfer not found or already voided.", $redirectUrl);
        }

        $voidStmt = $pdo->prepare("
            UPDATE product_stock_movements
            SET
                status      = 'voided',
                notes       = CONCAT(notes, ' | Voided: ', :void_reason)
            WHERE reference_no = :reference_no
            AND movement_type IN ('transfer_out', 'transfer_in')
            AND status = 'active'
        ");
        $voidStmt->execute([
            ":void_reason"  => $voidReason,
            ":reference_no" => $referenceNo
        ]);

        $pdo->commit();

        redirectWithMessage("success", "Transfer " . $referenceNo . " voided successfully.", $redirectUrl);
    } catch (PDOException $e) {
        $pdo->rollBack();
        redirectWithMessage("error", "Failed to void transfer.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);
