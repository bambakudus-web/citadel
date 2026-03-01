<?php
function requireLogin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/security.php';
    	checkSessionTimeout(30);
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function requireRole(string ...$roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], $roles)) {
        header('Location: /login.php');
        exit;
    }
}

function currentUser(): array {
    return $_SESSION['user'] ?? [];
}

function hasRole(string ...$roles): bool {
    return in_array($_SESSION['role'] ?? '', $roles);
}
