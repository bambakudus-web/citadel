<?php
// Block cross-origin API access
$allowed = 'https://citadel-production-5edc.up.railway.app';
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $allowed) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Cross-origin request blocked.']));
}
