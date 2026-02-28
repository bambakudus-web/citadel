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
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            die(json_encode(['success' => false, 'message' => 'Invalid request token.']));
        }
        die('Invalid request token.');
    }
}

// ── RATE LIMITING via DB ──
function checkRateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool {
    global $pdo;
    if (!isset($pdo)) return true;
    try {
        $stmt = $pdo->prepare("SELECT attempts, window_start FROM rate_limits WHERE rl_key=?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $now = time();
        if ($row) {
            if ($now - $row['window_start'] > $windowSeconds) {
                $pdo->prepare("UPDATE rate_limits SET attempts=1, window_start=? WHERE rl_key=?")->execute([$now, $key]);
                return true;
            }
            if ($row['attempts'] >= $maxAttempts) return false;
            $pdo->prepare("UPDATE rate_limits SET attempts=attempts+1 WHERE rl_key=?")->execute([$key]);
        } else {
            $pdo->prepare("INSERT INTO rate_limits (rl_key, attempts, window_start) VALUES (?,1,?)")->execute([$key, $now]);
        }
        return true;
    } catch (Exception $e) { return true; }
}

function resetRateLimit(string $key): void {
    global $pdo;
    if (!isset($pdo)) return;
    try { $pdo->prepare("DELETE FROM rate_limits WHERE rl_key=?")->execute([$key]); } catch (Exception $e) {}
}

function getRateLimitRemaining(string $key, int $windowSeconds = 300): int {
    global $pdo;
    if (!isset($pdo)) return 0;
    try {
        $stmt = $pdo->prepare("SELECT window_start FROM rate_limits WHERE rl_key=?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) return 0;
        return max(0, $windowSeconds - (time() - $row['window_start']));
    } catch (Exception $e) { return 0; }
}

// ── XSS PROTECTION ──
function clean(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
