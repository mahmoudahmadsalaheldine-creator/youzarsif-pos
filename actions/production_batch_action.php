<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/production-batches.php";
$createdBy   = 1;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . $redirectUrl);
    exit;
}

$action = $_POST["action"] ?? "";

// Helper: Redirect with session message
function redirectWithMessage($type, $message, $redirectUrl)
{
    $_SESSION[$type] = $message;
    header("Location: " . $redirectUrl);
    exit;
}

// Helper: Get available stock for item at location
function getAvailableStock($pdo, $itemId, $locationId)
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN direction = 'in'  AND status = 'active' THEN quantity
                    WHEN direction = 'out' AND status = 'active' THEN -quantity
                    ELSE 0
                END
            ), 0) AS available_stock
        FROM stock_movements
        WHERE item_id     = :item_id
        AND location_id   = :location_id
    ");

    $stmt->execute([
        ":item_id"     => $itemId,
        ":location_id" => $locationId
    ]);

    $row = $stmt->fetch();
    return $row ? (float)$row["available_stock"] : 0;
}

// Helper: Get average unit cost from purchase_in
function getAverageUnitCost($pdo, $itemId)
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(quantity), 0)       AS total_qty,
            COALESCE(SUM(total_cost_usd), 0) AS total_cost
        FROM stock_movements
        WHERE item_id       = :item_id
        AND movement_type   = 'purchase_in'
        AND status          = 'active'
    ");

    $stmt->execute([":item_id" => $itemId]);

    $row      = $stmt->fetch();
    $totalQty = $row ? (float)$row["total_qty"]  : 0;
    $totalCost = $row ? (float)$row["total_cost"] : 0;

    if ($totalQty <= 0) return 0;
    return $totalCost / $totalQty;
}

// Helper: Get product with its components
function getProductWithComponents($pdo, $productId)
{
    $stmt = $pdo->prepare("
        SELECT
            fp.product_id,
            fp.product_name,
            fp.product_code,
            fp.calculated_cost_usd,
            fp.selling_price_usd,
            fp.status
        FROM finished_products fp
        WHERE fp.product_id = :product_id
        AND fp.status = 'active'
        LIMIT 1
    ");

    $stmt->execute([":product_id" => $productId]);
    $product = $stmt->fetch();

    if (!$product) return null;

    $compStmt = $pdo->prepare("
        SELECT
            fpc.item_id,
            fpc.quantity_used,
            i.item_name
        FROM finished_product_components fpc
        INNER JOIN items i ON fpc.item_id = i.item_id
        WHERE fpc.product_id = :product_id
    ");

    $compStmt->execute([":product_id" => $productId]);
    $product["components"] = $compStmt->fetchAll();

    return $product;
}

// Helper: Auto-generate batch reference
function generateBatchRef($pdo)
{
    $dateStr = date("Ymd");
    $prefix  = "PROD-" . $dateStr . "-";

    $stmt = $pdo->prepare("
        SELECT batch_ref
        FROM production_batches
        WHERE batch_ref LIKE :prefix
        ORDER BY batch_ref DESC
        LIMIT 1
    ");

    $stmt->execute([":prefix" => $prefix . "%"]);
    $last = $stmt->fetch();

    if ($last) {
        $lastNum = (int) substr($last["batch_ref"], strrpos($last["batch_ref"], "-") + 1);
        $next    = $lastNum + 1;
    } else {
        $next = 1;
    }

    return $prefix . str_pad($next, 3, "0", STR_PAD_LEFT);
}

// ADD Production Batch
if ($action === "add") {

    $productId        = filter_var($_POST["product_id"] ?? null, FILTER_VALIDATE_INT);
    $quantityProduced = $_POST["quantity_produced"] ?? "";
    $batchRef         = generateBatchRef($pdo);
    $notes            = trim($_POST["notes"] ?? "");

    // Auto-fetch first active factory location
    $autoLocStmt = $pdo->prepare("
    SELECT location_id FROM locations
    WHERE location_type = 'factory'
    AND status = 'active'
    ORDER BY location_id ASC
    LIMIT 1
");
    $autoLocStmt->execute();
    $autoLoc    = $autoLocStmt->fetch();
    $locationId = $autoLoc ? (int)$autoLoc["location_id"] : null;

    // --- Validate inputs ---
    if (!$productId) {
        redirectWithMessage("error", "Please select a finished product.", $redirectUrl);
    }

    if (!$locationId) {
        redirectWithMessage("error", "No active factory location found. Please add a factory location first.", $redirectUrl);
    }
    
    if ($quantityProduced === "" || !is_numeric($quantityProduced) || (float)$quantityProduced <= 0) {
        redirectWithMessage("error", "Quantity produced must be greater than zero.", $redirectUrl);
    }

    $quantityProduced = (float)$quantityProduced;

    // --- Check location exists and is factory ---
    $locStmt = $pdo->prepare("
        SELECT location_id FROM locations
        WHERE location_id   = :location_id
        AND location_type   = 'factory'
        AND status          = 'active'
        LIMIT 1
    ");
    $locStmt->execute([":location_id" => $locationId]);

    if (!$locStmt->fetch()) {
        redirectWithMessage("error", "Invalid or inactive factory location.", $redirectUrl);
    }

    // --- Check batch_ref is unique ---
    $refStmt = $pdo->prepare("
        SELECT batch_id FROM production_batches
        WHERE batch_ref = :batch_ref
        LIMIT 1
    ");
    $refStmt->execute([":batch_ref" => $batchRef]);

    if ($refStmt->fetch()) {
        redirectWithMessage("error", "Batch reference already exists. Please use a unique reference.", $redirectUrl);
    }

    // --- Load product + components ---
    $product = getProductWithComponents($pdo, $productId);

    if (!$product) {
        redirectWithMessage("error", "Invalid or inactive product selected.", $redirectUrl);
    }

    if (empty($product["components"])) {
        redirectWithMessage("error", "This product has no recipe components. Please add components to the product first.", $redirectUrl);
    }

    // --- Check stock for every ingredient ---
    foreach ($product["components"] as $comp) {
        $requiredQty    = $comp["quantity_used"] * $quantityProduced;
        $availableStock = getAvailableStock($pdo, $comp["item_id"], $locationId);

        if ($requiredQty > $availableStock) {
            redirectWithMessage(
                "error",
                "Not enough " . $comp["item_name"] . " available. " .
                    "Required: " . number_format($requiredQty, 3) . " | " .
                    "Available: " . number_format($availableStock, 3) . ".",
                $redirectUrl
            );
        }
    }

    // --- Calculate total batch cost ---
    $totalBatchCost = 0;

    foreach ($product["components"] as $comp) {
        $requiredQty  = $comp["quantity_used"] * $quantityProduced;
        $unitCost     = getAverageUnitCost($pdo, $comp["item_id"]);
        $totalBatchCost += $requiredQty * $unitCost;
    }

    $unitCostPerProduct = $quantityProduced > 0 ? $totalBatchCost / $quantityProduced : 0;

    // --- Run DB Transaction ---
    try {
        $pdo->beginTransaction();

        // 1. INSERT into production_batches
        $batchStmt = $pdo->prepare("
            INSERT INTO production_batches
                (batch_ref, product_id, location_id, quantity_produced,
                 unit_cost_usd, total_cost_usd, notes, status, created_by)
            VALUES
                (:batch_ref, :product_id, :location_id, :quantity_produced,
                 :unit_cost_usd, :total_cost_usd, :notes, 'completed', :created_by)
        ");

        $batchStmt->execute([
            ":batch_ref"         => $batchRef,
            ":product_id"        => $productId,
            ":location_id"       => $locationId,
            ":quantity_produced" => $quantityProduced,
            ":unit_cost_usd"     => round($unitCostPerProduct, 2),
            ":total_cost_usd"    => round($totalBatchCost, 2),
            ":notes"             => $notes,
            ":created_by"        => $createdBy
        ]);

        $batchId = $pdo->lastInsertId();

        // 2. INSERT each ingredient into production_batch_components
        // 3. INSERT production_consumption OUT into stock_movements
        $compInsertStmt = $pdo->prepare("
            INSERT INTO production_batch_components
                (batch_id, item_id, quantity_used, unit_cost_usd, total_cost_usd)
            VALUES
                (:batch_id, :item_id, :quantity_used, :unit_cost_usd, :total_cost_usd)
        ");

        $stockMovStmt = $pdo->prepare("
            INSERT INTO stock_movements
                (item_id, location_id, movement_type, direction, quantity,
                 unit_cost_usd, total_cost_usd, reference_no, notes, status, created_by)
            VALUES
                (:item_id, :location_id, 'production_consumption', 'out', :quantity,
                 :unit_cost_usd, :total_cost_usd, :reference_no, :notes, 'active', :created_by)
        ");

        foreach ($product["components"] as $comp) {
            $requiredQty = $comp["quantity_used"] * $quantityProduced;
            $unitCost    = getAverageUnitCost($pdo, $comp["item_id"]);
            $totalCost   = $requiredQty * $unitCost;

            // Save to production_batch_components
            $compInsertStmt->execute([
                ":batch_id"      => $batchId,
                ":item_id"       => $comp["item_id"],
                ":quantity_used" => $requiredQty,
                ":unit_cost_usd" => round($unitCost, 2),
                ":total_cost_usd" => round($totalCost, 2)
            ]);

            // Deduct from stock_movements
            $stockMovStmt->execute([
                ":item_id"        => $comp["item_id"],
                ":location_id"    => $locationId,
                ":quantity"       => $requiredQty,
                ":unit_cost_usd"  => round($unitCost, 2),
                ":total_cost_usd" => round($totalCost, 2),
                ":reference_no"   => $batchRef,
                ":notes"          => "Auto-deducted for batch: " . $batchRef,
                ":created_by"     => $createdBy
            ]);
        }

        // 4. INSERT production_in INTO product_stock_movements
        $prodStockStmt = $pdo->prepare("
            INSERT INTO product_stock_movements
                (product_id, location_id, movement_type, direction, quantity,
                 unit_cost_usd, total_cost_usd, reference_no, notes, status, created_by)
            VALUES
                (:product_id, :location_id, 'production_in', 'in', :quantity,
                 :unit_cost_usd, :total_cost_usd, :reference_no, :notes, 'active', :created_by)
        ");

        $prodStockStmt->execute([
            ":product_id"     => $productId,
            ":location_id"    => $locationId,
            ":quantity"       => $quantityProduced,
            ":unit_cost_usd"  => round($unitCostPerProduct, 2),
            ":total_cost_usd" => round($totalBatchCost, 2),
            ":reference_no"   => $batchRef,
            ":notes"          => "Production batch: " . $batchRef,
            ":created_by"     => $createdBy
        ]);

        $pdo->commit();

        redirectWithMessage(
            "success",
            "Production batch saved successfully. Ref: " . $batchRef .
                ". " . number_format($quantityProduced, 2) . " units produced. Ingredients deducted from stock.",
            $redirectUrl
        );
    } catch (PDOException $e) {
        $pdo->rollBack();
        redirectWithMessage("error", "Failed to save production batch. Please try again.", $redirectUrl);
    }
}

// VOID Production Batch
if ($action === "void") {

    $batchId    = filter_var($_POST["batch_id"]    ?? null, FILTER_VALIDATE_INT);
    $voidReason = trim($_POST["void_reason"] ?? "");

    if (!$batchId) {
        redirectWithMessage("error", "Invalid batch selected.", $redirectUrl);
    }

    if ($voidReason === "" || strlen($voidReason) < 5) {
        redirectWithMessage("error", "Void reason is required (min 5 characters).", $redirectUrl);
    }

    try {
        $pdo->beginTransaction();

        // Check batch exists and is completed
        $checkStmt = $pdo->prepare("
            SELECT batch_id, batch_ref
            FROM production_batches
            WHERE batch_id = :batch_id
            AND status     = 'completed'
            LIMIT 1
        ");
        $checkStmt->execute([":batch_id" => $batchId]);
        $batch = $checkStmt->fetch();

        if (!$batch) {
            $pdo->rollBack();
            redirectWithMessage("error", "Batch not found or already voided.", $redirectUrl);
        }

        // Void the batch
        $voidBatchStmt = $pdo->prepare("
            UPDATE production_batches
            SET status = 'voided'
            WHERE batch_id = :batch_id
        ");
        $voidBatchStmt->execute([":batch_id" => $batchId]);

        // Void stock_movements tied to this batch
        $voidMovStmt = $pdo->prepare("
            UPDATE stock_movements
            SET
                status      = 'voided',
                void_reason = :void_reason
            WHERE reference_no  = :batch_ref
            AND movement_type   = 'production_consumption'
            AND status          = 'active'
        ");
        $voidMovStmt->execute([
            ":void_reason" => "Batch voided: " . $voidReason,
            ":batch_ref"   => $batch["batch_ref"]
        ]);

        // Void product_stock_movements tied to this batch
        $voidProdStmt = $pdo->prepare("
            UPDATE product_stock_movements
            SET
                status      = 'voided',
                notes       = CONCAT(notes, ' | Voided: ', :void_reason)
            WHERE reference_no  = :batch_ref
            AND movement_type   = 'production_in'
            AND status          = 'active'
        ");
        $voidProdStmt->execute([
            ":void_reason" => $voidReason,
            ":batch_ref"   => $batch["batch_ref"]
        ]);

        $pdo->commit();

        redirectWithMessage(
            "success",
            "Batch " . $batch["batch_ref"] . " voided successfully. All stock deductions reversed.",
            $redirectUrl
        );
    } catch (PDOException $e) {
        $pdo->rollBack();
        redirectWithMessage("error", "Failed to void batch. Please try again.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);
