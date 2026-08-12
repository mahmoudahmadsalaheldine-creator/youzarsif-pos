<?php
session_start();

require_once "../config/app.php";
require_once "../config/database.php";
require_once "../includes/SmtpMailer.php";

$notice = null;
$noticeKind = "info";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");

    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $notice = "Please enter a valid email address.";
        $noticeKind = "danger";
    } elseif (MAIL_SMTP_USERNAME === "" || MAIL_SMTP_PASSWORD === "") {
        // Shown identically regardless of whether the email matches an account,
        // so it can't be used to enumerate which emails exist in the system.
        $notice = "Email sending isn't configured yet. Ask an admin to set up Gmail SMTP in config/mail.php, then try again.";
        $noticeKind = "warn";
    } else {
        $stmt = $pdo->prepare("SELECT user_id, full_name, email, status FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([":email" => $email]);
        $user = $stmt->fetch();

        if ($user && $user["status"] === "active") {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash("sha256", $token);

            // Expiry is computed by MySQL's own clock (NOW() + INTERVAL), not PHP's
            // date() — PHP runs in UTC while MySQL's session can be on system time,
            // and mixing the two clocks made tokens look expired immediately.
            $update = $pdo->prepare("UPDATE users SET reset_token = :hash, reset_token_expires = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE user_id = :id");
            $update->execute([":hash" => $tokenHash, ":id" => $user["user_id"]]);

            $resetLink = BASE_URL . "auth/reset-password.php?token=" . $token;
            $html = "
                <div style='font-family:sans-serif; max-width:480px; margin:0 auto;'>
                    <h2 style='color:#3b2416;'>Reset your password</h2>
                    <p>Hi " . htmlspecialchars($user["full_name"]) . ",</p>
                    <p>We received a request to reset your Youzarsif Sweets account password. This link is valid for 30 minutes:</p>
                    <p><a href='" . $resetLink . "' style='background:#3b2416;color:#f8f1e8;padding:12px 22px;border-radius:9999px;text-decoration:none;display:inline-block;'>Reset Password</a></p>
                    <p style='color:#888;font-size:13px;'>If you didn't request this, you can safely ignore this email.</p>
                </div>
            ";
            SmtpMailer::send($user["email"], "Reset your Youzarsif Sweets password", $html);
        }

        // Same message whether or not the email matched an account.
        $notice = "If that email is registered, a reset link has been sent to it.";
        $noticeKind = "ok";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= APP_SHORT_NAME; ?> | Forgot Password</title>
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
            <div style="font-family:var(--font-display); font-weight:800; font-size:19px;">Forgot Password</div>
            <div style="font-size:13px; color:var(--text-faint); margin-top:4px;">Enter your account email and we'll send you a reset link</div>
        </div>

        <?php if ($notice): ?>
            <?php
            $bg = $noticeKind === 'ok' ? 'var(--success-soft)' : ($noticeKind === 'warn' ? 'var(--warn-soft)' : 'var(--danger-soft)');
            $fg = $noticeKind === 'ok' ? 'var(--success)' : ($noticeKind === 'warn' ? 'var(--warn)' : 'var(--danger)');
            ?>
            <div style="background:<?= $bg; ?>; color:<?= $fg; ?>; border-radius:11px; padding:11px 14px; font-size:13px; margin-bottom:16px;"><?= htmlspecialchars($notice); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="yz-field">
                <label>Email</label>
                <input type="email" name="email" placeholder="you@youzarsifsweets.com" required autofocus>
            </div>
            <button type="submit" class="btn-yz btn-yz-dark" style="width:100%; justify-content:center; padding:13px; margin-top:6px;">Send Reset Link</button>
            <div style="text-align:center; margin-top:16px;">
                <a href="login.php" style="font-size:12.5px; color:var(--text-faint); text-decoration:none;">← Back to Sign In</a>
            </div>
        </form>
    </div>
</body>
</html>
