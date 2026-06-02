<?php
// includes/logger.php — Centralized error logging

function logError(string $context, \Throwable $e, array $extra = []): void {
    $logDir  = __DIR__ . '/../logs';
    $logFile = $logDir . '/citadel_' . date('Y-m') . '.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }

    $entry = implode(' | ', array_filter([
        date('Y-m-d H:i:s'),
        strtoupper($context),
        get_class($e) . ': ' . $e->getMessage(),
        'File: ' . basename($e->getFile()) . ':' . $e->getLine(),
        $extra ? json_encode($extra) : '',
    ])) . PHP_EOL;

    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}
