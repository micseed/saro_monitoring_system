<?php
require_once __DIR__ . '/database.php';

class Notification extends Database {

    /**
     * Fetch recent notification-worthy audit log entries for a given user,
     * excluding items the user has dismissed (is_hidden=1).
     * Each row gets an `is_read` flag from notification_state.
     */
    public function getRecentActivity(int $userId = 0, int $limit = 10): array {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT a.logId, a.action, a.details, a.affected_table, a.record_id,
                   a.created_at, u.first_name, u.last_name,
                   COALESCE(ns.is_read, 0) AS is_read
            FROM audit_logs a
            LEFT JOIN user u ON a.userId = u.userId
            LEFT JOIN notification_state ns
                   ON ns.logId = a.logId AND ns.userId = :uid
            WHERE a.action IN ('create', 'edit', 'delete', 'cancelled')
              AND a.affected_table IN ('saro', 'procurement', 'object_code')
              AND (ns.is_hidden IS NULL OR ns.is_hidden = 0)
            ORDER BY a.created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count unread notifications for a user (actions by OTHER users,
     * not yet marked as read, not hidden, within the last 7 days).
     */
    public function countUnread(int $userId): int {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM audit_logs a
            LEFT JOIN notification_state ns
                   ON ns.logId = a.logId AND ns.userId = :uid
            WHERE a.userId != :uid2
              AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND a.action IN ('create', 'edit', 'delete', 'cancelled')
              AND a.affected_table IN ('saro', 'procurement', 'object_code')
              AND (ns.is_read   IS NULL OR ns.is_read   = 0)
              AND (ns.is_hidden IS NULL OR ns.is_hidden = 0)
        ");
        $stmt->bindValue(':uid',  $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Mark all notifications as read for a user (upsert into notification_state).
     */
    public function markAllRead(int $userId): void {
        $conn = $this->connect();
        // Insert is_read=1 for every visible notification for this user.
        $conn->prepare("
            INSERT INTO notification_state (userId, logId, is_read, is_hidden)
            SELECT :uid, a.logId, 1, 0
            FROM audit_logs a
            WHERE a.action IN ('create', 'edit', 'delete', 'cancelled')
              AND a.affected_table IN ('saro', 'procurement', 'object_code')
            ON DUPLICATE KEY UPDATE is_read = 1
        ")->execute([':uid' => $userId]);
    }

    /**
     * Hide (dismiss) a single notification for a user.
     */
    public function dismissNotification(int $userId, int $logId): void {
        $conn = $this->connect();
        $conn->prepare("
            INSERT INTO notification_state (userId, logId, is_read, is_hidden)
            VALUES (:uid, :lid, 1, 1)
            ON DUPLICATE KEY UPDATE is_hidden = 1, is_read = 1
        ")->execute([':uid' => $userId, ':lid' => $logId]);
    }

    /* ── Admin-only helpers ─────────────────────────────────────── */

    public function getPendingPasswordRequests(): array {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT pr.requestId, pr.reason, pr.status, pr.requested_at,
                   u.first_name, u.last_name, u.email, ur.role
            FROM password_requests pr
            JOIN user u ON pr.userId = u.userId
            JOIN user_role ur ON ur.roleId = u.roleId
            WHERE pr.status = 'pending'
            ORDER BY pr.requested_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserPasswordRequests(int $userId): array {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT pr.requestId, pr.reason, pr.status, pr.admin_note,
                   pr.requested_at, pr.resolved_at, pr.requested_new_password, pr.applied_at,
                   resolver.first_name AS resolver_fname,
                   resolver.last_name  AS resolver_lname
            FROM password_requests pr
            LEFT JOIN user resolver ON pr.resolved_by = resolver.userId
            WHERE pr.userId = :uid
            ORDER BY pr.requested_at DESC
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Returns an approved-but-not-yet-applied password request for the user, if any. */
    public function getApprovedPasswordNotification(int $userId): ?array {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT requestId, requested_at, resolved_at, requested_new_password, admin_note
            FROM password_requests
            WHERE userId = :uid
              AND status = 'approved'
              AND applied_at IS NULL
              AND requested_new_password IS NOT NULL
            ORDER BY resolved_at DESC
            LIMIT 1
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Count pending password requests (for admin notification badge). */
    public function countPendingPasswordRequests(): int {
        $conn = $this->connect();
        return (int) $conn->query("SELECT COUNT(*) FROM password_requests WHERE status='pending'")->fetchColumn();
    }
}
