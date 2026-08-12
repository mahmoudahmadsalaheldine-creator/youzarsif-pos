<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/categories.php";

$allowedTypes = [
    "ingredient",
    "supporting_item",
    "finished_product",
    "expense",
    "other"
];

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

if ($action === "add") {
    $categoryName = trim($_POST["category_name"] ?? "");
    $categoryType = $_POST["category_type"] ?? "";
    $status = $_POST["status"] ?? "active";

    if ($categoryName === "") {
        redirectWithMessage("error", "Category name is required.", $redirectUrl);
    }

    if (!in_array($categoryType, $allowedTypes)) {
        redirectWithMessage("error", "Invalid category type selected.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    try {
        $checkStmt = $pdo->prepare("
            SELECT category_id 
            FROM categories 
            WHERE category_name = :category_name 
            AND category_type = :category_type
            LIMIT 1
        ");

        $checkStmt->execute([
            ":category_name" => $categoryName,
            ":category_type" => $categoryType
        ]);

        if ($checkStmt->fetch()) {
            redirectWithMessage("error", "This category already exists for the selected type.", $redirectUrl);
        }

        $stmt = $pdo->prepare("
            INSERT INTO categories 
                (category_name, category_type, status)
            VALUES 
                (:category_name, :category_type, :status)
        ");

        $stmt->execute([
            ":category_name" => $categoryName,
            ":category_type" => $categoryType,
            ":status" => $status
        ]);

        redirectWithMessage("success", "Category added successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to add category.", $redirectUrl);
    }
}

if ($action === "update") {
    $categoryId = filter_var($_POST["category_id"] ?? null, FILTER_VALIDATE_INT);
    $categoryName = trim($_POST["category_name"] ?? "");
    $categoryType = $_POST["category_type"] ?? "";
    $status = $_POST["status"] ?? "active";

    if (!$categoryId) {
        redirectWithMessage("error", "Invalid category selected.", $redirectUrl);
    }

    if ($categoryName === "") {
        redirectWithMessage("error", "Category name is required.", $redirectUrl);
    }

    if (!in_array($categoryType, $allowedTypes)) {
        redirectWithMessage("error", "Invalid category type selected.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    try {
        $checkStmt = $pdo->prepare("
            SELECT category_id 
            FROM categories 
            WHERE category_name = :category_name 
            AND category_type = :category_type
            AND category_id != :category_id
            LIMIT 1
        ");

        $checkStmt->execute([
            ":category_name" => $categoryName,
            ":category_type" => $categoryType,
            ":category_id" => $categoryId
        ]);

        if ($checkStmt->fetch()) {
            redirectWithMessage("error", "Another category with the same name and type already exists.", $redirectUrl);
        }

        $stmt = $pdo->prepare("
            UPDATE categories
            SET 
                category_name = :category_name,
                category_type = :category_type,
                status = :status
            WHERE category_id = :category_id
        ");

        $stmt->execute([
            ":category_name" => $categoryName,
            ":category_type" => $categoryType,
            ":status" => $status,
            ":category_id" => $categoryId
        ]);

        redirectWithMessage("success", "Category updated successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to update category.", $redirectUrl);
    }
}

if ($action === "delete") {
    $categoryId = filter_var($_POST["category_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$categoryId) {
        redirectWithMessage("error", "Invalid category selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE categories
            SET status = 'inactive'
            WHERE category_id = :category_id
        ");

        $stmt->execute([
            ":category_id" => $categoryId
        ]);

        redirectWithMessage("success", "Category deactivated successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to deactivate category.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);
