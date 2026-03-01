<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';

use Twilio\Rest\Client;

header('Content-Type: application/json');

$sid   = getenv('TWILIO_SID');
$token = getenv('TWILIO_TOKEN');
$from  = getenv('TWILIO_FROM') ?: 'whatsapp:+14155238886';

function sendWhatsApp($to, $message) {
    global $sid, $token, $from;
    if (!$sid || !$token || !$to) return false;
    try {
        $client = new Client($sid, $token);
        $client->messages->create(
            'whatsapp:' . $to,
            ['from' => $from, 'body' => $message]
        );
        return true;
    } catch (Exception $e) {
        error_log('WhatsApp error: ' . $e->getMessage());
        return false;
    }
}
