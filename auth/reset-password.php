<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";

$token = $_GET["token"] ?? $_POST["token"] ?? "";
$error = null;
$success = false;
$validUser = null;

if ($token !== "") {
    $tokenHash = hash("sha256", $token);
    $stmt = $pdo->prepare("
        SELECT user_id, full_name FROM users
        WHERE reset_token = :hash AND reset_token_expires > NOW() AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([":hash" => $tokenHash]);
    $validUser = $stmt->fetch();
}

if (!$validUser) {
    $error = "This reset link is invalid or has expired. Please request a new one.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        $update = $pdo->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_token_expires = NULL WHERE user_id = :id");
        $update->execute([":password" => password_hash($password, PASSWORD_DEFAULT), ":id" => $validUser["user_id"]]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= APP_SHORT_NAME; ?> | Reset Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { background: linear-gradient(160deg, var(--chocolate-dark) 0%, var(--chocolate) 45%, var(--purple) 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-card { width: 380px; max-width: 100%; background: #fff; border-radius: 20px; box-shadow: 0 30px 70px rgba(40,24,14,.4); padding: 32px; margin: 20px; }
        .login-logo { display: block; max-width: 200px; height: auto; margin: 0 auto 20px; }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="../assets/img/mainlogo.png" alt="Youzarsif" class="login-logo">
        <div style="text-align:center; margin-bottom:24px;">
            <div style="font-family:var(--font-display); font-weight:800; font-size:19px;">Reset Password</div>
            <?php if ($validUser && !$success): ?>
                <div style="font-size:13px; color:var(--text-faint); margin-top:4px;">Hi <?= htmlspecialchars($validUser["full_name"]); ?>, choose a new password</div>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div style="background:var(--success-soft); color:var(--success); border-radius:11px; padding:11px 14px; font-size:13px; margin-bottom:16px;">Your password has been reset. You can sign in now.</div>
            <a href="login.php" class="btn-yz btn-yz-dark" style="width:100%; justify-content:center; padding:13px; display:flex;">Go to Sign In</a>
        <?php elseif ($error): ?>
            <div style="background:var(--danger-soft); color:var(--danger); border-radius:11px; padding:11px 14px; font-size:13px; margin-bottom:16px;"><?= htmlspecialchars($error); ?></div>
            <a href="forgot-password.php" class="btn-yz btn-yz-outline" style="width:100%; justify-content:center; padding:13px; display:flex;">Request a New Link</a>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
                <div class="yz-field">
                    <label>New Password</label>
                    <div class="yz-password-wrap">
                        <input type="password" name="password" id="newPassword" placeholder="••••••••" required minlength="6">
                        <button type="button" class="yz-password-toggle" onclick="toggleYzPassword(this, 'newPassword')"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                </div>
                <div class="yz-field">
                    <label>Confirm Password</label>
                    <div class="yz-password-wrap">
                        <input type="password" name="confirm_password" id="confirmPassword" placeholder="••••••••" required minlength="6">
                        <button type="button" class="yz-password-toggle" onclick="toggleYzPassword(this, 'confirmPassword')"><svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                </div>
                <button type="submit" class="btn-yz btn-yz-dark" style="width:100%; justify-content:center; padding:13px; margin-top:6px;">Reset Password</button>
            </form>
        <?php endif; ?>
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
