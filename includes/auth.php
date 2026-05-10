<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ' . str_repeat('../', substr_count($_SERVER['PHP_SELF'], '/', strlen($_SERVER['DOCUMENT_ROOT'])) - 1) . '../login.php');
    exit;
}

// Auto-lapse SAROs whose valid_until date has passed
require_once __DIR__ . '/../class/database.php';
try {
    $__authDb  = new Database();
    $__authPdo = $__authDb->connect();
    $__authPdo->exec("
        UPDATE saro
        SET status = 'lapsed'
        WHERE status = 'active'
          AND valid_until IS NOT NULL
          AND valid_until < CURDATE()
    ");
} catch (Exception $e) { /* silently continue if DB is unavailable */ }
