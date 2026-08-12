<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffOrCashierLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/pos.php";
$createdBy   = 1;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . $redirectUrl);
    exit;
}

$action = $_POST["action"] ?? "";
$isAjax = ($_POST["ajax"] ?? "") === "1";

function redirectWithMessage($type, $message, $redirectUrl)
{
    $_SESSION[$type] = $message;
    header("Location: " . $redirectUrl);
    exit;
}

// Touch POS submits the charge via fetch() (no page reload, so Fullscreen
// mode survives) — respond with JSON instead of a redirect in that case.
function respondError($message, $redirectUrl, $isAjax)
{
    if ($isAjax) {
        header("Content-Type: application/json");
        echo json_encode(["success" => false, "message" => $message]);
        exit;
    }
    redirectWithMessage("error", $message, $redirectUrl);
}

// Helper: Get available product stock at location
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

// Helper: Auto-generate sale reference
function generateSaleRef($pdo)
{
    $dateStr = date("Ymd");
    $prefix  = "SALE-" . $dateStr . "-";

    $stmt = $pdo->prepare("
        SELECT sale_ref FROM sales
        WHERE sale_ref LIKE :prefix
        ORDER BY sale_ref DESC
        LIMIT 1
    ");
    $stmt->execute([":prefix" => $prefix . "%"]);
    $last = $stmt->fetch();

    if ($last) {
        $lastNum = (int) substr($last["sale_ref"], strrpos($last["sale_ref"], "-") + 1);
        $next    = $lastNum + 1;
    } else {
        $next = 1;
    }

    return $prefix . str_pad($next, 3, "0", STR_PAD_LEFT);
}

// COMPLETE SALE
if ($action === "complete_sale") {

    $locationId    = filter_var($_POST["location_id"]    ?? null, FILTER_VALIDATE_INT);
    $exchangeRate  = $_POST["exchange_rate"]  ?? "";
    $paidUsd       = $_POST["paid_usd"]       ?? "0";
    $paidLbp       = $_POST["paid_lbp"]       ?? "0";
    $discountType  = $_POST["discount_type"]  ?? "none";
    $discountValue = $_POST["discount_value"] ?? "0";
    $notes         = trim($_POST["notes"]     ?? "");
    $cartJson      = $_POST["cart"]           ?? "[]";

    // --- Validate ---
    if (!$locationId) {
        respondError("Please select a store location.", $redirectUrl, $isAjax);
    }

    if ($exchangeRate === "" || !is_numeric($exchangeRate) || (float)$exchangeRate <= 0) {
        respondError("Invalid exchange rate.", $redirectUrl, $isAjax);
    }

    $cart = json_decode($cartJson, true);

    if (empty($cart)) {
        respondError("Cart is empty. Please add products.", $redirectUrl, $isAjax);
    }

    // --- Validate location is a store ---
    $locStmt = $pdo->prepare("
        SELECT location_id, location_name FROM locations
        WHERE location_id   = :location_id
        AND location_type   = 'store'
        AND status          = 'active'
        LIMIT 1
    ");
    $locStmt->execute([":location_id" => $locationId]);
    $location = $locStmt->fetch();

    if (!$location) {
        respondError("Invalid or inactive store location.", $redirectUrl, $isAjax);
    }

    $exchangeRate  = (float)$exchangeRate;
    $paidUsd       = (float)$paidUsd;
    $paidLbp       = (float)$paidLbp;
    $discountValue = (float)$discountValue;

    // --- Validate discount type ---
    $allowedDiscountTypes = ["none", "percentage", "fixed"];
    if (!in_array($discountType, $allowedDiscountTypes)) {
        $discountType = "none";
    }

    // --- Validate cart items and check stock ---
    $validatedCart = [];
    $subtotalUsd   = 0;

    foreach ($cart as $item) {
        $productId = filter_var($item["product_id"] ?? null, FILTER_VALIDATE_INT);
        $quantity  = (float)($item["quantity"] ?? 0);

        if (!$productId || $quantity <= 0) {
            respondError("Invalid cart item detected.", $redirectUrl, $isAjax);
        }

        // Get product details
        $prodStmt = $pdo->prepare("
            SELECT
                fp.product_id,
                fp.product_name,
                fp.selling_price_usd,
                fp.status
            FROM finished_products fp
            WHERE fp.product_id = :product_id
            AND fp.status = 'active'
            LIMIT 1
        ");
        $prodStmt->execute([":product_id" => $productId]);
        $product = $prodStmt->fetch();

        if (!$product) {
            respondError("Invalid or inactive product in cart.", $redirectUrl, $isAjax);
        }

        // Check stock
        $availableStock = getProductStock($pdo, $productId, $locationId);

        if ($quantity > $availableStock) {
            respondError(
                "Not enough stock for " . $product["product_name"] . ". " .
                    "Required: " . number_format($quantity, 2) . " | " .
                    "Available: " . number_format($availableStock, 2) . ".",
                $redirectUrl,
                $isAjax
            );
        }

        $unitPriceUsd  = (float)$product["selling_price_usd"];
        $unitPriceLbp  = $unitPriceUsd * $exchangeRate;
        $totalPriceUsd = $unitPriceUsd * $quantity;
        $totalPriceLbp = $unitPriceLbp * $quantity;
        $subtotalUsd  += $totalPriceUsd;

        $validatedCart[] = [
            "product_id"      => $productId,
            "product_name"    => $product["product_name"],
            "quantity"        => $quantity,
            "unit_price_usd"  => round($unitPriceUsd, 2),
            "unit_price_lbp"  => round($unitPriceLbp, 2),
            "total_price_usd" => round($totalPriceUsd, 2),
            "total_price_lbp" => round($totalPriceLbp, 2)
        ];
    }

    // --- Calculate discount ---
    $discountAmountUsd = 0;

    if ($discountType === "percentage" && $discountValue > 0) {
        $discountAmountUsd = $subtotalUsd * ($discountValue / 100);
    } elseif ($discountType === "fixed" && $discountValue > 0) {
        $discountAmountUsd = $discountValue;
    }

    $totalUsd = $subtotalUsd - $discountAmountUsd;
    if ($totalUsd < 0) $totalUsd = 0;
    $totalLbp = $totalUsd * $exchangeRate;

    // --- Calculate total paid in USD ---
    $paidLbpInUsd  = $paidLbp / $exchangeRate;
    $totalPaidUsd  = $paidUsd + $paidLbpInUsd;

    if ($totalPaidUsd < $totalUsd) {
        respondError("Amount paid is less than the total. Please check payment.", $redirectUrl, $isAjax);
    }

    // --- Calculate change ---
    $changeUsd = $totalPaidUsd - $totalUsd;
    $changeLbp = $changeUsd * $exchangeRate;

    // --- Generate sale reference ---
    $saleRef = generateSaleRef($pdo);

    // --- Run Transaction ---
    try {
        $pdo->beginTransaction();

        // 1. INSERT into sales
        $saleStmt = $pdo->prepare("
            INSERT INTO sales
                (sale_ref, location_id, subtotal_usd, discount_type, discount_value,
                 discount_amount_usd, total_usd, total_lbp, paid_usd, paid_lbp,
                 exchange_rate, change_usd, change_lbp, notes, status, created_by)
            VALUES
                (:sale_ref, :location_id, :subtotal_usd, :discount_type, :discount_value,
                 :discount_amount_usd, :total_usd, :total_lbp, :paid_usd, :paid_lbp,
                 :exchange_rate, :change_usd, :change_lbp, :notes, 'completed', :created_by)
        ");

        $saleStmt->execute([
            ":sale_ref"           => $saleRef,
            ":location_id"        => $locationId,
            ":subtotal_usd"       => round($subtotalUsd, 2),
            ":discount_type"      => $discountType,
            ":discount_value"     => round($discountValue, 2),
            ":discount_amount_usd" => round($discountAmountUsd, 2),
            ":total_usd"          => round($totalUsd, 2),
            ":total_lbp"          => round($totalLbp, 2),
            ":paid_usd"           => round($paidUsd, 2),
            ":paid_lbp"           => round($paidLbp, 2),
            ":exchange_rate"      => round($exchangeRate, 2),
            ":change_usd"         => round($changeUsd, 2),
            ":change_lbp"         => round($changeLbp, 2),
            ":notes"              => $notes,
            ":created_by"         => $createdBy
        ]);

        $saleId = $pdo->lastInsertId();

        // 2. INSERT sale items + deduct stock
        $saleItemStmt = $pdo->prepare("
            INSERT INTO sale_items
                (sale_id, product_id, quantity, unit_price_usd, unit_price_lbp,
                 total_price_usd, total_price_lbp)
            VALUES
                (:sale_id, :product_id, :quantity, :unit_price_usd, :unit_price_lbp,
                 :total_price_usd, :total_price_lbp)
        ");

        $stockDeductStmt = $pdo->prepare("
            INSERT INTO product_stock_movements
                (product_id, location_id, movement_type, direction, quantity,
                 unit_cost_usd, total_cost_usd, reference_no, notes, status, created_by)
            VALUES
                (:product_id, :location_id, 'sale_out', 'out', :quantity,
                 :unit_price_usd, :total_price_usd, :reference_no, :notes, 'active', :created_by)
        ");

        foreach ($validatedCart as $item) {
            $saleItemStmt->execute([
                ":sale_id"        => $saleId,
                ":product_id"     => $item["product_id"],
                ":quantity"       => $item["quantity"],
                ":unit_price_usd" => $item["unit_price_usd"],
                ":unit_price_lbp" => $item["unit_price_lbp"],
                ":total_price_usd" => $item["total_price_usd"],
                ":total_price_lbp" => $item["total_price_lbp"]
            ]);

            $stockDeductStmt->execute([
                ":product_id"     => $item["product_id"],
                ":location_id"    => $locationId,
                ":quantity"       => $item["quantity"],
                ":unit_price_usd" => $item["unit_price_usd"],
                ":total_price_usd" => $item["total_price_usd"],
                ":reference_no"   => $saleRef,
                ":notes"          => "Sale: " . $saleRef,
                ":created_by"     => $createdBy
            ]);
        }

        $pdo->commit();

        $saleData = [
            "sale_ref"      => $saleRef,
            "location_name" => $location["location_name"],
            "items"         => $validatedCart,
            "subtotal_usd"  => round($subtotalUsd, 2),
            "discount_type" => $discountType,
            "discount_value" => round($discountValue, 2),
            "discount_amount_usd" => round($discountAmountUsd, 2),
            "total_usd"     => round($totalUsd, 2),
            "total_lbp"     => round($totalLbp, 2),
            "paid_usd"      => round($paidUsd, 2),
            "paid_lbp"      => round($paidLbp, 2),
            "exchange_rate" => round($exchangeRate, 2),
            "change_usd"    => round($changeUsd, 2),
            "change_lbp"    => round($changeLbp, 2),
            "created_at"    => date("Y-m-d H:i:s")
        ];

        if ($isAjax) {
            header("Content-Type: application/json");
            echo json_encode(["success" => true, "message" => "Sale " . $saleRef . " completed successfully.", "sale" => $saleData]);
            exit;
        }

        // Legacy non-AJAX fallback: stash sale data in session for the receipt
        // modal to pick up after the redirect-triggered page reload.
        $_SESSION["last_sale"] = $saleData;
        redirectWithMessage("success", "Sale " . $saleRef . " completed successfully.", $redirectUrl);
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError("Failed to complete sale. Please try again.", $redirectUrl, $isAjax);
    }
}

// VOID SALE
if ($action === "void_sale") {

    $saleId     = filter_var($_POST["sale_id"] ?? null, FILTER_VALIDATE_INT);
    $voidReason = trim($_POST["void_reason"] ?? "");

    if (!$saleId) {
        redirectWithMessage("error", "Invalid sale selected.", $redirectUrl);
    }

    if ($voidReason === "" || strlen($voidReason) < 5) {
        redirectWithMessage("error", "Void reason is required (min 5 characters).", $redirectUrl);
    }

    try {
        $pdo->beginTransaction();

        $checkStmt = $pdo->prepare("
            SELECT sale_id, sale_ref FROM sales
            WHERE sale_id = :sale_id
            AND status = 'completed'
            LIMIT 1
        ");
        $checkStmt->execute([":sale_id" => $saleId]);
        $sale = $checkStmt->fetch();

        if (!$sale) {
            $pdo->rollBack();
            redirectWithMessage("error", "Sale not found or already voided.", $redirectUrl);
        }

        // Void the sale
        $voidSaleStmt = $pdo->prepare("
            UPDATE sales
            SET status = 'voided', void_reason = :void_reason
            WHERE sale_id = :sale_id
        ");
        $voidSaleStmt->execute([
            ":void_reason" => $voidReason,
            ":sale_id"     => $saleId
        ]);

        // Void stock movements
        $voidStockStmt = $pdo->prepare("
            UPDATE product_stock_movements
            SET status = 'voided',
                notes  = CONCAT(notes, ' | Voided: ', :void_reason)
            WHERE reference_no = :sale_ref
            AND movement_type  = 'sale_out'
            AND status         = 'active'
        ");
        $voidStockStmt->execute([
            ":void_reason" => $voidReason,
            ":sale_ref"    => $sale["sale_ref"]
        ]);

        $pdo->commit();

        redirectWithMessage("success", "Sale " . $sale["sale_ref"] . " voided. Stock returned.", $redirectUrl);
    } catch (PDOException $e) {
        $pdo->rollBack();
        redirectWithMessage("error", "Failed to void sale. Please try again.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);
