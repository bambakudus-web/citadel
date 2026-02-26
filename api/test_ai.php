<?php
echo json_encode([
    'api_key_set' => !empty(getenv('ANTHROPIC_API_KEY')),
    'api_key_preview' => substr(getenv('ANTHROPIC_API_KEY'), 0, 15) . '...',
    'curl_enabled' => function_exists('curl_init'),
]);
