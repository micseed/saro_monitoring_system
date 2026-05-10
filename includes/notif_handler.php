<?php
/**
 * includes/notif_handler.php
 * Central AJAX endpoint for notification read/dismiss actions.
 * Called via fetch() from notif_dropdown.php JS.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../class/notification.php';

$userId = (int)$_SESSION['user_id'];
$action = trim($_POST['action'] ?? '');
$notif  = new Notification();

switch ($action) {

    case 'mark_all_read':
        $notif->markAllRead($userId);
        echo json_encode(['success' => true]);
        break;

    case 'dismiss':
        $logId = (int)($_POST['logId'] ?? 0);
        if ($logId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid logId']);
            break;
        }
        $notif->dismissNotification($userId, $logId);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
exit;
