<?php
// includes/resend_mail.php — Resend email service wrapper
// Replaces native mail() with Resend API for Railway

function sendResendEmail(string $to, string $toName, string $subject, string $html): array {
    $apiKey = getenv('RESEND_API_KEY') ?: '';

    // Fallback to native mail if no Resend key
    if (!$apiKey) {
        $from    = getenv('MAIL_FROM') ?: 'noreply@citadel.edu';
        $headers = "From: Citadel <$from>\r\nContent-Type: text/html; charset=UTF-8\r\nMIME-Version: 1.0";
        $sent    = mail($to, $subject, $html, $headers);
        return ['success' => $sent, 'method' => 'native_mail'];
    }

    $fromEmail = getenv('MAIL_FROM')      ?: 'citadel@resend.dev';
    $fromName  = getenv('MAIL_FROM_NAME') ?: 'Citadel Attendance System';

    $payload = json_encode([
        'from'    => "$fromName <$fromEmail>",
        'to'      => ["$toName <$to>"],
        'subject' => $subject,
        'html'    => $html,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    return [
        'success'  => $httpCode === 200 || $httpCode === 201,
        'method'   => 'resend',
        'id'       => $data['id'] ?? null,
        'error'    => $data['message'] ?? null,
        'httpCode' => $httpCode,
    ];
}
