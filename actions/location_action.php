<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/locations.php";

$allowedTypes = [
    "factory",
    "store"
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
| Add Location
|--------------------------------------------------------------------------
*/
if ($action === "add") {
    $locationName = trim($_POST["location_name"] ?? "");
    $locationType = $_POST["location_type"] ?? "";
    $status = $_POST["status"] ?? "active";

    if ($locationName === "") {
        redirectWithMessage("error", "Location name is required.", $redirectUrl);
    }

    if (!in_array($locationType, $allowedTypes)) {
        redirectWithMessage("error", "Invalid location type selected.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    try {
        $checkStmt = $pdo->prepare("
            SELECT location_id 
            FROM locations 
            WHERE location_name = :location_name
            LIMIT 1
        ");

        $checkStmt->execute([
            ":location_name" => $locationName
        ]);

        if ($checkStmt->fetch()) {
            redirectWithMessage(
                "error", 
                "This location already exists.", 
                $redirectUrl
            );
        }

        $stmt = $pdo->prepare("
            INSERT INTO locations 
                (location_name, location_type, status)
            VALUES 
                (:location_name, :location_type, :status)
        ");

        $stmt->execute([
            ":location_name" => $locationName,
            ":location_type" => $locationType,
            ":status" => $status
        ]);

        redirectWithMessage("success", "Location added successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to add location.", $redirectUrl);
    }
}

/*
|--------------------------------------------------------------------------
| Update Location
|--------------------------------------------------------------------------
*/
if ($action === "update") {
    $locationId = filter_var($_POST["location_id"] ?? null, FILTER_VALIDATE_INT);
    $locationName = trim($_POST["location_name"] ?? "");
    $locationType = $_POST["location_type"] ?? "";
    $status = $_POST["status"] ?? "active";

    if (!$locationId) {
        redirectWithMessage("error", "Invalid location selected.", $redirectUrl);
    }

    if ($locationName === "") {
        redirectWithMessage("error", "Location name is required.", $redirectUrl);
    }

    if (!in_array($locationType, $allowedTypes)) {
        redirectWithMessage("error", "Invalid location type selected.", $redirectUrl);
    }

    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }

    try {
        $checkStmt = $pdo->prepare("
            SELECT location_id 
            FROM locations 
            WHERE location_name = :location_name
            AND location_id != :location_id
            LIMIT 1
        ");

        $checkStmt->execute([
            ":location_name" => $locationName,
            ":location_id" => $locationId
        ]);

        if ($checkStmt->fetch()) {
            redirectWithMessage(
                "error", 
                "Another location with the same name already exists.", 
                $redirectUrl
            );
        }

        $stmt = $pdo->prepare("
            UPDATE locations
            SET 
                location_name = :location_name,
                location_type = :location_type,
                status = :status
            WHERE location_id = :location_id
        ");

        $stmt->execute([
            ":location_name" => $locationName,
            ":location_type" => $locationType,
            ":status" => $status,
            ":location_id" => $locationId
        ]);

        redirectWithMessage("success", "Location updated successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to update location.", $redirectUrl);
    }
}

/*
|--------------------------------------------------------------------------
| Soft Delete / Deactivate Location
|--------------------------------------------------------------------------
*/
if ($action === "delete") {
    $locationId = filter_var($_POST["location_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$locationId) {
        redirectWithMessage("error", "Invalid location selected.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE locations
            SET status = 'inactive'
            WHERE location_id = :location_id
        ");

        $stmt->execute([
            ":location_id" => $locationId
        ]);

        redirectWithMessage("success", "Location deactivated successfully.", $redirectUrl);

    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to deactivate location.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);