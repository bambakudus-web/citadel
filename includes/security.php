<?php
// includes/security.php - Security helpers

// ── SESSION TIMEOUT (30 min inactivity) ──
function checkSessionTimeout(int $minutes = 30): void {
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > $minutes * 60) {
            session_unset();
            session_destroy();
            header('Location: /login.php?timeout=1');
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
}

// ── CSRF TOKEN ──
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Invalid request token.']));
    }
}

// ── RATE LIMITING (login brute force) ──
function checkRateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool {
    $file = sys_get_temp_dir() . '/citadel_rl_' . md5($key) . '.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['attempts' => 0, 'window_start' => time()];
    
    // Reset window if expired
    if (time() - $data['window_start'] > $windowSeconds) {
        $data = ['attempts' => 0, 'window_start' => time()];
    }
    
    if ($data['attempts'] >= $maxAttempts) {
        $remaining = $windowSeconds - (time() - $data['window_start']);
        return false; // Blocked
    }
    
    $data['attempts']++;
    file_put_contents($file, json_encode($data));
    return true; // Allowed
}

function resetRateLimit(string $key): void {
    $file = sys_get_temp_dir() . '/citadel_rl_' . md5($key) . '.json';
    if (file_exists($file)) unlink($file);
}

function getRateLimitRemaining(string $key, int $windowSeconds = 300): int {
    $file = sys_get_temp_dir() . '/citadel_rl_' . md5($key) . '.json';
    if (!file_exists($file)) return 0;
    $data = json_decode(file_get_contents($file), true);
    if (time() - $data['window_start'] > $windowSeconds) return 0;
    return $windowSeconds - (time() - $data['window_start']);
}

// ── XSS PROTECTION ──
function clean(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
