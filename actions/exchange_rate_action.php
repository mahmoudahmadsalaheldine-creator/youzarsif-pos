<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/exchange-rates.php";
$createdBy   = 1;

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

// ADD Exchange Rate
if ($action === "add") {

    $usdToLbp  = $_POST["usd_to_lbp"] ?? "";
    $rateDate  = trim($_POST["rate_date"] ?? "");
    $notes     = trim($_POST["notes"] ?? "");

    // Validate
    if ($usdToLbp === "" || !is_numeric($usdToLbp) || (float)$usdToLbp <= 0) {
        redirectWithMessage("error", "Please enter a valid exchange rate.", $redirectUrl);
    }

    if ($rateDate === "") {
        redirectWithMessage("error", "Please select a date.", $redirectUrl);
    }

    $usdToLbp = (float)$usdToLbp;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO exchange_rates
                (usd_to_lbp, rate_date, notes, created_by)
            VALUES
                (:usd_to_lbp, :rate_date, :notes, :created_by)
        ");

        $stmt->execute([
            ":usd_to_lbp"  => round($usdToLbp, 2),
            ":rate_date"   => $rateDate,
            ":notes"       => $notes,
            ":created_by"  => $createdBy
        ]);

        redirectWithMessage("success", "Exchange rate saved successfully. 1 USD = " . number_format($usdToLbp, 0, '.', ',') . " LBP.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to save exchange rate. Please try again.", $redirectUrl);
    }
}

// DELETE Exchange Rate
if ($action === "delete") {

    $rateId = filter_var($_POST["rate_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$rateId) {
        redirectWithMessage("error", "Invalid rate selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM exchange_rates
            WHERE rate_id = :rate_id
        ");
        $stmt->execute([":rate_id" => $rateId]);

        redirectWithMessage("success", "Exchange rate deleted successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to delete exchange rate.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);
