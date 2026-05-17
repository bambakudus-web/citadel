<?php
// includes/auth.php — Updated with institution scoping + super_admin support

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
        // Super admin can access everything
        if ($_SESSION['role'] === 'super_admin') return;
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

function institutionId(): int {
    return (int)($_SESSION['institution_id'] ?? 1);
}

function isSuperAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'super_admin';
}
