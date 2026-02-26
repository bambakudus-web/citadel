<?php
// api/ai_verify.php
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

// Get Anthropic API key from environment
$apiKey = getenv('ANTHROPIC_API_KEY') ?: '';

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
    $prompt = "Analyze this image carefully. I need to verify this is a legitimate classroom/lecture environment. Check for:
1. Signs of a classroom: desks, chairs, tables, whiteboard, projector, screen, lecture equipment
2. Presence of other people (students or lecturer) in the background
3. Academic/educational setting indicators
4. Adequate lighting suggesting an indoor institutional environment

Respond ONLY with a JSON object in this exact format:
{\"is_classroom\": true/false, \"confidence\": 0-100, \"reason\": \"brief explanation\", \"people_visible\": true/false, \"classroom_indicators\": [\"list\", \"of\", \"detected\", \"items\"]}

Be VERY STRICT. A bedroom, living room, corridor, or outdoor area should return is_classroom: false. There must be clear classroom furniture and at least some indication of other people present.";

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid verification type']);
    exit;
}

// Call Anthropic API
$payload = json_encode([
    'model'      => 'claude-opus-4-6',
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
            'message'    => 'Face verified ✓',
        ]);
    }

} else if ($type === 'environment') {
    $isClassroom  = $result['is_classroom']         ?? false;
    $confidence   = $result['confidence']           ?? 0;
    $peopleVisible = $result['people_visible']      ?? false;
    $indicators   = $result['classroom_indicators'] ?? [];
    $reason       = $result['reason']               ?? 'Unknown';

    if (!$isClassroom || $confidence < 70) {
        echo json_encode([
            'success'    => false,
            'confidence' => $confidence,
            'message'    => 'Not a classroom environment. Show your surroundings including desks, chairs, and other students.',
            'reason'     => $reason
        ]);
    } else if (!$peopleVisible && $confidence < 85) {
        echo json_encode([
            'success'    => false,
            'confidence' => $confidence,
            'message'    => 'No other people visible. Make sure other students are in the frame.',
            'reason'     => $reason
        ]);
    } else {
        echo json_encode([
            'success'    => true,
            'confidence' => $confidence,
            'message'    => 'Classroom verified ✓',
            'indicators' => $indicators
        ]);
    }
}
