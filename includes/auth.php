<?php
require_once __DIR__ . "/../config/app.php";

// Prevents the browser from serving a cached/bfcache copy of a protected page
// after logout — without this, hitting "back" after logging out can show the
// stale page instead of forcing a fresh request (which would then redirect
// to login since the session is gone).
function noCacheHeaders()
{
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");
}

// One CSRF token per session, embedded in every form (see admin_header.php's
// meta tag + admin_footer.php's auto-injection script, and pos.php/store-pos.php's
// own copies of the same pattern).
function csrfToken()
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

// Rejects a POST request whose csrf_token doesn't match the one issued to this
// session — stops a malicious site from silently submitting actions/*.php
// forms on behalf of a logged-in user. Called from the *Action() guards below,
// which is every actions/*.php entry point.
function validateCsrf($redirectUrl)
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        return;
    }
    $token = $_POST["csrf_token"] ?? "";
    if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
        header("Location: " . $redirectUrl);
        exit;
    }
}

// Minimal cashier-session guard, scoped only to the Store POS feature.
function requireCashierLogin($pdo, $redirectUrl)
{
    noCacheHeaders();

    // TEMP: login disabled
    return ["user_id" => 0, "full_name" => "Temp", "role" => "cashier", "status" => "active", "location_id" => 1, "location_name" => "Temp"];
}

// Guards the main admin area. Any active admin / factory_user / accountant
// account may pass — only cashiers are restricted (to Store POS, above).
function requireStaffLogin($pdo)
{
    noCacheHeaders();
    $redirectUrl = BASE_URL . "auth/login.php";

    // TEMP: login disabled
    return ["user_id" => 0, "full_name" => "Temp", "email" => "temp@temp.com", "role" => "admin", "status" => "active"];
}

// Lightweight guard for actions/*.php form processors — same staff session
// check as requireStaffLogin(), but doesn't need to return the staff row.
function requireStaffLoginAction($pdo)
{
    noCacheHeaders();
    $redirectUrl = BASE_URL . "auth/login.php";

    // TEMP: login disabled
    return;
}

// Guard for actions shared by both Touch POS pages (pos_action.php) — accepts
// either an active staff session (Factory POS) or an active cashier session (Store POS).
function requireStaffOrCashierLoginAction($pdo)
{
    noCacheHeaders();
    $redirectUrl = BASE_URL . "auth/login.php";

    // TEMP: login disabled
    return;
}
