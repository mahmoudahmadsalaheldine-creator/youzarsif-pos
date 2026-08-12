<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/expenses.php";
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

// ADD Expense
if ($action === "add") {

    $expenseCategoryId = filter_var($_POST["expense_category_id"] ?? null, FILTER_VALIDATE_INT);
    $expenseTitle      = trim($_POST["expense_title"] ?? "");
    $amountUsd         = $_POST["amount_usd"] ?? "0";
    $amountLbp         = $_POST["amount_lbp"] ?? "0";
    $exchangeRate      = $_POST["exchange_rate"] ?? "0";
    $paymentMethod     = $_POST["payment_method"] ?? "";
    $expenseDate       = trim($_POST["expense_date"] ?? "");
    $notes             = trim($_POST["notes"] ?? "");

    // Validate
    if (!$expenseCategoryId) {
        redirectWithMessage("error", "Please select an expense category.", $redirectUrl);
    }

    if ($expenseTitle === "") {
        redirectWithMessage("error", "Expense title is required.", $redirectUrl);
    }

    if ($expenseDate === "") {
        redirectWithMessage("error", "Please select a date.", $redirectUrl);
    }

    $allowedPaymentMethods = ["cash_usd", "cash_lbp", "card", "mixed"];
    if (!in_array($paymentMethod, $allowedPaymentMethods)) {
        redirectWithMessage("error", "Invalid payment method selected.", $redirectUrl);
    }

    $amountUsd    = (float)$amountUsd;
    $amountLbp    = (float)$amountLbp;
    $exchangeRate = (float)$exchangeRate;

    if ($amountUsd <= 0 && $amountLbp <= 0) {
        redirectWithMessage("error", "Please enter an amount in USD or LBP.", $redirectUrl);
    }

    // Check category exists
    $catStmt = $pdo->prepare("
        SELECT expense_category_id FROM expense_categories
        WHERE expense_category_id = :id
        AND status = 'active'
        LIMIT 1
    ");
    $catStmt->execute([":id" => $expenseCategoryId]);

    if (!$catStmt->fetch()) {
        redirectWithMessage("error", "Invalid expense category selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO expenses
                (expense_category_id, expense_title, amount_usd, amount_lbp,
                 exchange_rate, payment_method, expense_date, notes, created_by)
            VALUES
                (:expense_category_id, :expense_title, :amount_usd, :amount_lbp,
                 :exchange_rate, :payment_method, :expense_date, :notes, :created_by)
        ");

        $stmt->execute([
            ":expense_category_id" => $expenseCategoryId,
            ":expense_title"       => $expenseTitle,
            ":amount_usd"          => round($amountUsd, 2),
            ":amount_lbp"          => round($amountLbp, 2),
            ":exchange_rate"       => round($exchangeRate, 2),
            ":payment_method"      => $paymentMethod,
            ":expense_date"        => $expenseDate,
            ":notes"               => $notes,
            ":created_by"          => $createdBy
        ]);

        redirectWithMessage("success", "Expense recorded successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to save expense. Please try again.", $redirectUrl);
    }
}

// DELETE Expense
if ($action === "delete") {

    $expenseId = filter_var($_POST["expense_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$expenseId) {
        redirectWithMessage("error", "Invalid expense selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM expenses
            WHERE expense_id = :expense_id
        ");
        $stmt->execute([":expense_id" => $expenseId]);

        redirectWithMessage("success", "Expense deleted successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to delete expense.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);
