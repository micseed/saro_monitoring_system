<?php
require_once __DIR__ . '/Database.php';

class Notification extends Database {

    public function getRecentActivity(int $limit = 10): array {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT a.logId, a.action, a.details, a.affected_table, a.record_id,
                   a.timestamp, u.first_name, u.last_name
            FROM audit_logs a
            LEFT JOIN user u ON a.userId = u.userId
            ORDER BY a.timestamp DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countUnread(int $userId): int {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM audit_logs
            WHERE userId != :uid
              AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

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
                   pr.requested_at, pr.resolved_at,
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
}
