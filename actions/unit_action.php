<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/units.php";

$allowedStatuses = [
    "active",
    "inactive"
];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . $redirectUrl);
    exit;
}

$action = $_POST["action"] ?? "";

/*
|--------------------------------------------------------------------------
| Helper: Redirect with message
|--------------------------------------------------------------------------
*/
function redirectWithMessage($type, $message, $redirectUrl)
{
    $_SESSION[$type] = $message;
    header("Location: " . $redirectUrl);
    exit;
}

/*
|--------------------------------------------------------------------------
| Add Unit
|--------------------------------------------------------------------------
*/
if ($action === "add") {
    $unitName = trim($_POST["unit_name"] ?? "");
    $abbreviation = trim($_POST["abbreviation"] ?? "");
    $status = $_POST["status"] ?? "active";

    if ($unitName === "") {
        redirectWithMessage("error", "Unit name is required.", $redirectUrl);
    }

    if ($abbreviation === "") {
        redirectWithMessage("error", "Unit abbreviation is required.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    try {
        $checkStmt = $pdo->prepare("
            SELECT unit_id 
            FROM units 
            WHERE unit_name = :unit_name 
            OR abbreviation = :abbreviation
            LIMIT 1
        ");

        $checkStmt->execute([
            ":unit_name" => $unitName,
            ":abbreviation" => $abbreviation
        ]);

        if ($checkStmt->fetch()) {
            redirectWithMessage(
                "error", 
                "This unit name or abbreviation already exists.", 
                $redirectUrl
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO units 
                (unit_name, abbreviation, status)
            VALUES 
                (:unit_name, :abbreviation, :status)
        ");

        $stmt->execute([
            ":unit_name" => $unitName,
            ":abbreviation" => $abbreviation,
            ":status" => $status
        ]);

        redirectWithMessage("success", "Unit added successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to add unit.", $redirectUrl);
    }
}

/*
|--------------------------------------------------------------------------
| Update Unit
|--------------------------------------------------------------------------
*/
if ($action === "update") {
    $unitId = filter_var($_POST["unit_id"] ?? null, FILTER_VALIDATE_INT);
    $unitName = trim($_POST["unit_name"] ?? "");
    $abbreviation = trim($_POST["abbreviation"] ?? "");
    $status = $_POST["status"] ?? "active";

    if (!$unitId) {
        redirectWithMessage("error", "Invalid unit selected.", $redirectUrl);
    }

    if ($unitName === "") {
        redirectWithMessage("error", "Unit name is required.", $redirectUrl);
    }

    if ($abbreviation === "") {
        redirectWithMessage("error", "Unit abbreviation is required.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    try {
        $checkStmt = $pdo->prepare("
            SELECT unit_id 
            FROM units 
            WHERE 
                (unit_name = :unit_name OR abbreviation = :abbreviation)
                AND unit_id != :unit_id
            LIMIT 1
        ");

        $checkStmt->execute([
            ":unit_name" => $unitName,
            ":abbreviation" => $abbreviation,
            ":unit_id" => $unitId
        ]);

        if ($checkStmt->fetch()) {
            redirectWithMessage(
                "error", 
                "Another unit with the same name or abbreviation already exists.", 
                $redirectUrl
            );
        }

        $stmt = $pdo->prepare("
            UPDATE units
            SET 
                unit_name = :unit_name,
                abbreviation = :abbreviation,
                status = :status
            WHERE unit_id = :unit_id
        ");

        $stmt->execute([
            ":unit_name" => $unitName,
            ":abbreviation" => $abbreviation,
            ":status" => $status,
            ":unit_id" => $unitId
        ]);

        redirectWithMessage("success", "Unit updated successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to update unit.", $redirectUrl);
    }
}

/*
|--------------------------------------------------------------------------
| Soft Delete / Deactivate Unit
|--------------------------------------------------------------------------
*/
if ($action === "delete") {
    $unitId = filter_var($_POST["unit_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$unitId) {
        redirectWithMessage("error", "Invalid unit selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE units
            SET status = 'inactive'
            WHERE unit_id = :unit_id
        ");

        $stmt->execute([
            ":unit_id" => $unitId
        ]);

        redirectWithMessage("success", "Unit deactivated successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to deactivate unit.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);