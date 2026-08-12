<?php

session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
requireStaffLoginAction($pdo);

$redirectUrl = BASE_URL . "admin/users.php";

$allowedRoles = ["admin", "factory_user", "cashier", "accountant"];
$allowedStatuses = ["active", "inactive"];

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

function activeStoreExists($pdo, $locationId)
{
    $stmt = $pdo->prepare("SELECT location_id FROM locations WHERE location_id = :id AND location_type = 'store' AND status = 'active' LIMIT 1");
    $stmt->execute([":id" => $locationId]);
    return (bool) $stmt->fetch();
}

// Admin accounts log in with email; every other role logs in with a username.
function validateIdentifier($role, $email, $username)
{
    if ($role === "admin") {
        if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "A valid email is required for admin accounts.";
        }
        return null;
    }

    if ($username === "" || !preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
        return "Username must be 3-50 characters (letters, numbers, dot, underscore).";
    }
    return null;
}

if ($action === "add") {
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $role = $_POST["role"] ?? "";
    $locationId = filter_var($_POST["location_id"] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST["status"] ?? "active";

    if ($fullName === "") {
        redirectWithMessage("error", "Full name is required.", $redirectUrl);
    }
    if (!in_array($role, $allowedRoles)) {
        redirectWithMessage("error", "Invalid role selected.", $redirectUrl);
    }
    if ($identifierError = validateIdentifier($role, $email, $username)) {
        redirectWithMessage("error", $identifierError, $redirectUrl);
    }
    if ($password === "" || strlen($password) < 6) {
        redirectWithMessage("error", "Password must be at least 6 characters.", $redirectUrl);
    }
    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }
    if ($role === "cashier") {
        if (!$locationId || !activeStoreExists($pdo, $locationId)) {
            redirectWithMessage("error", "Cashiers must be assigned to an active store.", $redirectUrl);
        }
    } else {
        $locationId = null;
    }

    if ($role === "admin") {
        $username = null;
    } else {
        $email = null;
    }

    try {
        $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE (email = :email AND :email IS NOT NULL) OR (username = :username AND :username IS NOT NULL) LIMIT 1");
        $checkStmt->execute([":email" => $email, ":username" => $username]);
        if ($checkStmt->fetch()) {
            redirectWithMessage("error", "An account with this email/username already exists.", $redirectUrl);
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, email, username, password, role, location_id, status)
            VALUES (:full_name, :email, :username, :password, :role, :location_id, :status)
        ");
        $stmt->execute([
            ":full_name" => $fullName,
            ":email" => $email,
            ":username" => $username,
            ":password" => password_hash($password, PASSWORD_DEFAULT),
            ":role" => $role,
            ":location_id" => $locationId,
            ":status" => $status,
        ]);

        redirectWithMessage("success", "Account created successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to create account.", $redirectUrl);
    }
}

if ($action === "update") {
    $userId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $role = $_POST["role"] ?? "";
    $locationId = filter_var($_POST["location_id"] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST["status"] ?? "active";

    if (!$userId) {
        redirectWithMessage("error", "Invalid account selected.", $redirectUrl);
    }
    if ($fullName === "") {
        redirectWithMessage("error", "Full name is required.", $redirectUrl);
    }
    if (!in_array($role, $allowedRoles)) {
        redirectWithMessage("error", "Invalid role selected.", $redirectUrl);
    }
    if ($identifierError = validateIdentifier($role, $email, $username)) {
        redirectWithMessage("error", $identifierError, $redirectUrl);
    }
    if ($password !== "" && strlen($password) < 6) {
        redirectWithMessage("error", "Password must be at least 6 characters.", $redirectUrl);
    }
    if (!in_array($status, $allowedStatuses)) {
        redirectWithMessage("error", "Invalid status selected.", $redirectUrl);
    }
    if ($role === "cashier") {
        if (!$locationId || !activeStoreExists($pdo, $locationId)) {
            redirectWithMessage("error", "Cashiers must be assigned to an active store.", $redirectUrl);
        }
    } else {
        $locationId = null;
    }

    if ($role === "admin") {
        $username = null;
    } else {
        $email = null;
    }

    try {
        $checkStmt = $pdo->prepare("
            SELECT user_id FROM users
            WHERE ((email = :email AND :email IS NOT NULL) OR (username = :username AND :username IS NOT NULL))
            AND user_id != :user_id
            LIMIT 1
        ");
        $checkStmt->execute([":email" => $email, ":username" => $username, ":user_id" => $userId]);
        if ($checkStmt->fetch()) {
            redirectWithMessage("error", "Another account with this email/username already exists.", $redirectUrl);
        }

        if ($password !== "") {
            $stmt = $pdo->prepare("
                UPDATE users SET full_name = :full_name, email = :email, username = :username, password = :password,
                    role = :role, location_id = :location_id, status = :status
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ":full_name" => $fullName,
                ":email" => $email,
                ":username" => $username,
                ":password" => password_hash($password, PASSWORD_DEFAULT),
                ":role" => $role,
                ":location_id" => $locationId,
                ":status" => $status,
                ":user_id" => $userId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users SET full_name = :full_name, email = :email, username = :username,
                    role = :role, location_id = :location_id, status = :status
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ":full_name" => $fullName,
                ":email" => $email,
                ":username" => $username,
                ":role" => $role,
                ":location_id" => $locationId,
                ":status" => $status,
                ":user_id" => $userId,
            ]);
        }

        redirectWithMessage("success", "Account updated successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to update account.", $redirectUrl);
    }
}

if ($action === "delete") {
    $userId = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);

    if (!$userId) {
        redirectWithMessage("error", "Invalid account selected.", $redirectUrl);
    }
    if ($userId === 1) {
        redirectWithMessage("error", "Cannot deactivate the primary admin account.", $redirectUrl);
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE user_id = :user_id");
        $stmt->execute([":user_id" => $userId]);
        redirectWithMessage("success", "Account deactivated successfully.", $redirectUrl);
    } catch (PDOException $e) {
        redirectWithMessage("error", "Failed to deactivate account.", $redirectUrl);
    }
}

redirectWithMessage("error", "Invalid action.", $redirectUrl);
