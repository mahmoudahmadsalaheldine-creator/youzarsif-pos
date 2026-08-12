<?php
require_once __DIR__ . "/../config/mail.php";

// Minimal, self-contained SMTP client (no external library) — sends a single
// HTML email over an implicit-TLS connection (Gmail's port 465). Good enough
// for low-volume transactional emails like password resets.
class SmtpMailer
{
    public static function send($toEmail, $subject, $htmlBody)
    {
        if (MAIL_SMTP_USERNAME === "" || MAIL_SMTP_PASSWORD === "") {
            return ["ok" => false, "error" => "Email sending is not configured yet. Ask an admin to set up Gmail SMTP in config/mail.php."];
        }

        $socket = @stream_socket_client(
            "ssl://" . MAIL_SMTP_HOST . ":" . MAIL_SMTP_PORT,
            $errno,
            $errstr,
            15
        );

        if (!$socket) {
            return ["ok" => false, "error" => "Could not connect to mail server: " . $errstr];
        }

        try {
            self::expect($socket, "220");
            self::command($socket, "EHLO " . MAIL_SMTP_HOST, "250");
            self::command($socket, "AUTH LOGIN", "334");
            self::command($socket, base64_encode(MAIL_SMTP_USERNAME), "334");
            self::command($socket, base64_encode(MAIL_SMTP_PASSWORD), "235");
            self::command($socket, "MAIL FROM:<" . MAIL_FROM_EMAIL . ">", "250");
            self::command($socket, "RCPT TO:<" . $toEmail . ">", "250");
            self::command($socket, "DATA", "354");

            $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_EMAIL . ">\r\n"
                . "To: <" . $toEmail . ">\r\n"
                . "Subject: " . $subject . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n";

            fwrite($socket, $headers . "\r\n" . $htmlBody . "\r\n.\r\n");
            self::expect($socket, "250");
            fwrite($socket, "QUIT\r\n");
            fclose($socket);

            return ["ok" => true];
        } catch (Exception $e) {
            fclose($socket);
            return ["ok" => false, "error" => $e->getMessage()];
        }
    }

    private static function command($socket, $cmd, $expectCode)
    {
        fwrite($socket, $cmd . "\r\n");
        self::expect($socket, $expectCode);
    }

    private static function expect($socket, $expectCode)
    {
        $response = "";
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === " ") {
                break;
            }
        }
        if (substr($response, 0, 3) !== $expectCode) {
            throw new Exception("SMTP server error (expected $expectCode): " . trim($response));
        }
    }
}
