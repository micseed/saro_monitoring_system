<?php
session_start();

// Audit log: record logout BEFORE session is destroyed
if (!empty($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/class/database.php';
        $db   = new Database();
        $conn = $db->connect();
        $role     = $_SESSION['role']      ?? 'User';
        $fullName = $_SESSION['full_name'] ?? 'Unknown';
        $userId   = (int)$_SESSION['user_id'];
        $conn->prepare("
            INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address)
            VALUES (?, 'logout', ?, 'user', ?, ?)
        ")->execute([
            $userId,
            $role . ' logged out: ' . $fullName,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // Fail silently — logout must always proceed
    }
}

session_unset();
session_destroy();
header('Location: login.php');
exit;
