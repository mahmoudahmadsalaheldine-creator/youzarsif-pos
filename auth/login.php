<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/auth.php";
require_once "../includes/login_throttle.php";
noCacheHeaders();

if (!empty($_SESSION["cashier_user_id"])) {
    header("Location: " . BASE_URL . "admin/store-pos.php");
    exit;
}
if (!empty($_SESSION["staff_user_id"])) {
    header("Location: " . BASE_URL . "admin/dashboard.php");
    exit;
}

$error = $_GET["error"] ?? null;

$lockedSeconds = loginThrottleLockedSecondsRemaining();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($lockedSeconds !== null) {
        $error = "Too many failed attempts. Please try again in " . ceil($lockedSeconds / 60) . " minute(s).";
    } else {
        $identifier = trim($_POST["identifier"] ?? "");
        $password = $_POST["password"] ?? "";

        if ($identifier === "" || $password === "") {
            $error = "Please enter your email/username and password.";
        } else {
            $stmt = $pdo->prepare("
                SELECT u.user_id, u.full_name, u.password, u.role, u.status, u.location_id, l.location_name
                FROM users u
                LEFT JOIN locations l ON u.location_id = l.location_id
                WHERE u.email = :identifier OR u.username = :identifier
                LIMIT 1
            ");
            $stmt->execute([":identifier" => $identifier]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user["password"])) {
                loginThrottleRecordFailure();
                $error = "Invalid credentials.";
            } elseif ($user["status"] !== "active") {
                $error = "This account is inactive. Contact an admin.";
            } elseif ($user["role"] === "cashier") {
                if (!$user["location_id"]) {
                    $error = "Your account is not assigned to a store. Contact an admin.";
                } else {
                    loginThrottleClear();
                    $_SESSION["cashier_user_id"] = $user["user_id"];
                    $_SESSION["cashier_name"] = $user["full_name"];
                    $_SESSION["cashier_location_id"] = $user["location_id"];
                    $_SESSION["cashier_location_name"] = $user["location_name"];
                    header("Location: " . BASE_URL . "admin/store-pos.php");
                    exit;
                }
            } else {
                // admin / factory_user / accountant — full admin panel access
                loginThrottleClear();
                $_SESSION["staff_user_id"] = $user["user_id"];
                $_SESSION["staff_name"] = $user["full_name"];
                $_SESSION["staff_role"] = $user["role"];
                header("Location: " . BASE_URL . "admin/dashboard.php");
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= APP_SHORT_NAME; ?> | Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { background: linear-gradient(135deg, var(--caramel) 0%, var(--chocolate) 65%); display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-card { width: 380px; max-width: 100%; background: #fff; border-radius: 20px; box-shadow: 0 30px 70px rgba(40,24,14,.4); padding: 32px; margin: 20px; }
        .login-logo { display: block; max-width: 200px; height: auto; margin: 0 auto 20px; }
    </style>
    <script>
        window.addEventListener("pageshow", function (event) { if (event.persisted) window.location.reload(); });
    </script>
</head>
<body>
    <div class="login-card">
        <img src="../assets/img/mainlogo.png" alt="Youzarsif" class="login-logo">
        <div style="text-align:center; margin-bottom:24px;">
            <div style="font-family:var(--font-display); font-weight:800; font-size:19px;">Sign In</div>
            <div style="font-size:13px; color:var(--text-faint); margin-top:4px;">Staff and store cashiers use the same login</div>
        </div>

        <?php if ($error): ?>
            <div style="background:var(--danger-soft); color:var(--danger); border-radius:11px; padding:11px 14px; font-size:13px; margin-bottom:16px;"><?= htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="yz-field">
                <label>Email or Username</label>
                <input type="text" name="identifier" placeholder="email (admin) or username (staff/cashier)" required autofocus>
            </div>
            <div class="yz-field">
                <label>Password</label>
                <div class="yz-password-wrap">
                    <input type="password" name="password" id="loginPassword" placeholder="••••••••" required>
                    <button type="button" class="yz-password-toggle" onclick="toggleYzPassword(this, 'loginPassword')"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
            </div>
            <button type="submit" class="btn-yz btn-yz-dark" style="width:100%; justify-content:center; padding:13px; margin-top:6px;">Sign In</button>
            <div style="text-align:center; margin-top:16px;">
                <a href="forgot-password.php" style="font-size:12.5px; color:var(--text-faint); text-decoration:none;">Forgot your password?</a>
            </div>
        </form>
    </div>

    <script>
        const YZ_EYE_ICON = '<svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
        const YZ_EYE_OFF_ICON = '<svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a18.5 18.5 0 0 1 4.22-5.07M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        function toggleYzPassword(btn, inputId) {
            const input = document.getElementById(inputId);
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.innerHTML = showing ? YZ_EYE_ICON : YZ_EYE_OFF_ICON;
        }
    </script>
</body>
</html>
