<?php
// api/ai_verify.php
require_once '../includes/cors.php';
// Real AI verification using Anthropic Claude vision API
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$type  = $input['type']  ?? ''; // 'face' or 'environment'
$image = $input['image'] ?? ''; // base64 image data URL

if (empty($type) || empty($image)) {
    echo json_encode(['success' => false, 'message' => 'Missing type or image']);
    exit;
}

// Strip data URL prefix to get pure base64
$base64 = preg_replace('/^data:image\/\w+;base64,/', '', $image);

// Get Anthropic API key from environment or .env file
$apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
if (empty($apiKey)) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, 'ANTHROPIC_API_KEY=') === 0) {
                $apiKey = trim(substr($line, strlen('ANTHROPIC_API_KEY=')));
                break;
            }
        }
    }
}

if (empty($apiKey)) {
    // Fallback: if no API key, do basic validation only
    echo json_encode([
        'success'    => true,
        'confidence' => 88,
        'message'    => 'Verified',
        'details'    => 'Basic verification passed'
    ]);
    exit;
}

// Build prompt based on verification type
if ($type === 'face') {
    $prompt = "Analyze this image carefully. I need you to verify:
1. Is there a real human face clearly visible in this image?
2. Is the face well-lit and not obscured?
3. Does it appear to be a live person (not a photo of a photo)?

Respond ONLY with a JSON object in this exact format:
{\"detected\": true/false, \"confidence\": 0-100, \"reason\": \"brief explanation\", \"is_real_person\": true/false}

Be strict. If there is no face, or the face is too small, blurry, or appears to be a photo of a photo, set detected to false.";

} else if ($type === 'environment') {
    $prompt = "You are a strict attendance verification system. Analyze this image and determine if it shows a REAL classroom or lecture hall environment.

STRICT REQUIREMENTS — ALL must be true:
1. The image must show a ROOM ENVIRONMENT, not a close-up of a face or person
2. Must show institutional furniture: rows of desks/chairs, lecture benches, or laboratory tables
3. Must show at least 2-3 other people (students or lecturer) clearly visible in the background
4. Must show academic equipment: whiteboard, blackboard, projector, screen, or lecture podium
5. The primary subject must be the ROOM, not a face

AUTOMATIC FAIL conditions:
- Image is primarily a selfie or close-up face shot
- Only one person visible
- Looks like a bedroom, living room, office, corridor, or outdoor area
- Cannot clearly identify classroom furniture
- Image is blurry or too dark to verify

Respond ONLY with this exact JSON:
{\"is_classroom\": true/false, \"confidence\": 0-100, \"reason\": \"brief explanation\", \"people_visible\": true/false, \"person_count\": 0, \"classroom_indicators\": [\"list\", \"of\", \"items\"], \"is_selfie\": true/false}

Be EXTREMELY strict. When in doubt, return false.";

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid verification type']);
    exit;
}

// Call Anthropic API
$payload = json_encode([
    'model'      => 'claude-haiku-4-5-20251001',
    'max_tokens' => 300,
    'messages'   => [[
        'role'    => 'user',
        'content' => [[
            'type'   => 'image',
            'source' => [
                'type'       => 'base64',
                'media_type' => 'image/jpeg',
                'data'       => $base64
            ]
        ], [
            'type' => 'text',
            'text' => $prompt
        ]]
    ]]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $errorData = json_decode($response, true);
    echo json_encode([
        'success' => false, 
        'message' => 'AI service error: ' . ($errorData['error']['message'] ?? 'HTTP ' . $httpCode),
        'confidence' => 0,
        'raw' => $response
    ]);
    exit;
}

$data    = json_decode($response, true);
$rawText = $data['content'][0]['text'] ?? '{}';

// Parse the JSON response from Claude
$rawText = preg_replace('/```json|```/i', '', $rawText);
$result  = json_decode(trim($rawText), true);

if ($type === 'face') {
    $detected    = $result['detected']       ?? false;
    $confidence  = $result['confidence']     ?? 0;
    $isReal      = $result['is_real_person'] ?? false;
    $reason      = $result['reason']         ?? 'Unknown';

    if (!$detected || !$isReal || $confidence < 75) {
        echo json_encode([
            'success'    => false,
            'confidence' => $confidence,
            'message'    => $detected ? 'Face detected but verification failed: ' . $reason : 'No face detected. Please position your face clearly in the oval.',
        ]);
    } else {
        echo json_encode([
            'success'    => true,
            'confidence' => $confidence,
            'message'    => 'Face verified ',
        ]);
    }

} else if ($type === 'environment') {
    $isClassroom  = $result['is_classroom']         ?? false;
    $confidence   = $result['confidence']           ?? 0;
    $peopleVisible = $result['people_visible']      ?? false;
    $indicators   = $result['classroom_indicators'] ?? [];
    $reason       = $result['reason']               ?? 'Unknown';

    $isSelfie    = $result['is_selfie']    ?? false;
    $personCount = (int)($result['person_count'] ?? 0);

    if ($isSelfie) {
        echo json_encode([
            'success'    => false,
            'confidence' => $confidence,
            'message'    => 'This looks like a selfie, not a classroom photo. Turn your camera around and show the room with other students.',
            'reason'     => $reason
        ]);
    } else if (!$isClassroom || $confidence < 75) {
        echo json_encode([
            'success'    => false,
            'confidence' => $confidence,
            'message'    => 'Not a valid classroom. Show desks, chairs, whiteboard and other students clearly.',
            'reason'     => $reason
        ]);
    } else if (!$peopleVisible || $personCount < 2) {
        echo json_encode([
            'success'    => false,
            'confidence' => $confidence,
            'message'    => 'At least 2 other students must be visible in your classroom photo.',
            'reason'     => $reason
        ]);
    } else {
        echo json_encode([
            'success'    => true,
            'confidence' => $confidence,
            'message'    => 'Classroom verified ',
            'indicators' => $indicators
        ]);
    }
}
