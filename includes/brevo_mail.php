<?php
// includes/brevo_mail.php — Brevo (formerly Sendinblue) email service

function sendBrevoEmail(string $to, string $toName, string $subject, string $html, string $text = ''): array {
    // Load API key from env or .env file
    $apiKey = getenv('BREVO_API_KEY') ?: '';
    if (empty($apiKey)) {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, 'BREVO_API_KEY=') === 0) {
                    $apiKey = trim(substr($line, strlen('BREVO_API_KEY=')));
                    break;
                }
            }
        }
    }

    $fromEmail = getenv('MAIL_FROM')      ?: 'noreply@citadel.app';
    $fromName  = getenv('MAIL_FROM_NAME') ?: 'Citadel Attendance';

    // Load from .env if not in environment
    if ($fromEmail === 'noreply@citadel.app') {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, 'MAIL_FROM=') === 0)
                    $fromEmail = trim(substr($line, strlen('MAIL_FROM=')));
                if (strpos($line, 'MAIL_FROM_NAME=') === 0)
                    $fromName = trim(substr($line, strlen('MAIL_FROM_NAME=')));
            }
        }
    }

    if (empty($apiKey)) {
        // Fallback to native mail
        $headers = implode("\r\n", [
            "From: $fromName <$fromEmail>",
            "Content-Type: text/html; charset=UTF-8",
            "MIME-Version: 1.0"
        ]);
        $sent = mail($to, $subject, $html, $headers);
        return ['success' => $sent, 'method' => 'native_mail'];
    }

    $payload = json_encode([
        'sender'      => ['name' => $fromName, 'email' => $fromEmail],
        'to'          => [['email' => $to, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $html,
        'textContent' => $text ?: strip_tags($html),
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    return [
        'success'  => in_array($httpCode, [200, 201, 202]),
        'method'   => 'brevo',
        'id'       => $data['messageId'] ?? null,
        'error'    => $data['message'] ?? null,
        'httpCode' => $httpCode,
        'raw'      => $response,
    ];
}

// ── Email templates ──

function emailTemplate(string $title, string $body, string $footer = ''): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>$title</title></head>
<body style="margin:0;padding:0;background:#060910;font-family:'Helvetica Neue',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#060910;padding:40px 20px">
<tr><td align="center">
<table width="100%" style="max-width:540px;background:#0c1018;border:1px solid #1a2535;border-radius:4px;overflow:hidden">
  <tr><td style="background:linear-gradient(135deg,#7a5f28,#c9a84c);padding:3px 0"></td></tr>
  <tr><td style="padding:32px 36px 24px">
    <div style="font-family:'Courier New',monospace;font-size:20px;font-weight:700;color:#c9a84c;letter-spacing:6px;margin-bottom:6px">CITADEL</div>
    <div style="font-size:11px;color:#6b7a8d;letter-spacing:3px;text-transform:uppercase;margin-bottom:28px">Attendance Management System</div>
    $body
  </td></tr>
  <tr><td style="padding:20px 36px;border-top:1px solid #1a2535;font-size:12px;color:#6b7a8d;text-align:center">
    $footer
    <br>© $year Citadel Attendance System. Do not reply to this email.
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
}

function sendPasswordResetEmail(string $to, string $name, string $resetToken, string $baseUrl): array {
    $resetUrl = rtrim($baseUrl, '/') . '/reset_password.php?token=' . $resetToken;
    $subject  = 'Reset Your Citadel Password';
    $html = emailTemplate('Reset Your Password', '
        <h2 style="color:#e8eaf0;font-size:20px;margin:0 0 12px">Reset Your Password</h2>
        <p style="color:#6b7a8d;font-size:14px;line-height:1.6;margin:0 0 24px">Hi ' . htmlspecialchars($name) . ', we received a request to reset your Citadel password. Click the button below to set a new password.</p>
        <a href="' . $resetUrl . '" style="display:inline-block;background:linear-gradient(135deg,#7a5f28,#c9a84c);color:#060910;padding:14px 28px;border-radius:2px;text-decoration:none;font-weight:700;font-size:13px;letter-spacing:2px;font-family:Georgia,serif">RESET PASSWORD</a>
        <p style="color:#6b7a8d;font-size:12px;margin:20px 0 0">This link expires in <strong style="color:#e8eaf0">1 hour</strong>. If you did not request this, ignore this email.</p>
        <p style="color:#6b7a8d;font-size:11px;margin:8px 0 0">Or copy this link: <span style="color:#c9a84c">' . $resetUrl . '</span></p>
    ', 'Password reset requested · Citadel Security');
    return sendBrevoEmail($to, $name, $subject, $html);
}

function sendWelcomeEmail(string $to, string $name, string $indexNo, string $tempPassword, string $schoolName): array {
    $subject = 'Welcome to Citadel — ' . $schoolName;
    $html = emailTemplate('Welcome to Citadel', '
        <h2 style="color:#e8eaf0;font-size:20px;margin:0 0 12px">Welcome to Citadel! 🎓</h2>
        <p style="color:#6b7a8d;font-size:14px;line-height:1.6;margin:0 0 20px">Hi <strong style="color:#e8eaf0">' . htmlspecialchars($name) . '</strong>, your account has been created for <strong style="color:#c9a84c">' . htmlspecialchars($schoolName) . '</strong>.</p>
        <table style="background:#060910;border:1px solid #1a2535;border-radius:2px;width:100%;margin-bottom:24px">
          <tr><td style="padding:16px 20px">
            <div style="font-size:12px;color:#6b7a8d;letter-spacing:2px;text-transform:uppercase;margin-bottom:4px">Index Number</div>
            <div style="font-size:18px;color:#c9a84c;font-family:Georgia,serif;letter-spacing:3px">' . htmlspecialchars($indexNo) . '</div>
          </td></tr>
          <tr><td style="padding:0 20px 16px">
            <div style="font-size:12px;color:#6b7a8d;letter-spacing:2px;text-transform:uppercase;margin-bottom:4px">Temporary Password</div>
            <div style="font-size:18px;color:#4caf82;font-family:monospace;letter-spacing:2px">' . htmlspecialchars($tempPassword) . '</div>
          </td></tr>
        </table>
        <p style="color:#e05c5c;font-size:12px;margin:0 0 20px">⚠ Please log in and change your password immediately.</p>
    ', 'Your Citadel account · ' . htmlspecialchars($schoolName));
    return sendBrevoEmail($to, $name, $subject, $html);
}

function sendSessionStartEmail(string $to, string $name, string $courseCode, string $courseName, string $code): array {
    $subject = 'Session Started — ' . $courseCode;
    $html = emailTemplate('Session Started', '
        <h2 style="color:#e8eaf0;font-size:20px;margin:0 0 12px">📚 Session Now Active</h2>
        <p style="color:#6b7a8d;font-size:14px;line-height:1.6;margin:0 0 20px">Hi ' . htmlspecialchars($name) . ', a session has started for your course:</p>
        <div style="background:#060910;border:1px solid #1a2535;border-left:3px solid #c9a84c;border-radius:2px;padding:16px 20px;margin-bottom:20px">
          <div style="font-size:11px;color:#6b7a8d;letter-spacing:2px;text-transform:uppercase">' . htmlspecialchars($courseCode) . '</div>
          <div style="font-size:16px;color:#e8eaf0;margin-top:4px">' . htmlspecialchars($courseName) . '</div>
        </div>
        <p style="color:#6b7a8d;font-size:13px;margin:0 0 8px">Your attendance code:</p>
        <div style="font-size:36px;color:#c9a84c;font-family:Georgia,serif;letter-spacing:12px;text-align:center;padding:20px;background:#060910;border:1px solid #1a2535;border-radius:2px;margin-bottom:20px">' . htmlspecialchars($code) . '</div>
        <p style="color:#6b7a8d;font-size:12px;margin:0">Open Citadel and enter this code to mark your attendance. Code refreshes every 2 minutes.</p>
    ', 'Session notification · Citadel');
    return sendBrevoEmail($to, $name, $subject, $html);
}
