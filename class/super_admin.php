<?php
// classes/SuperAdmin.php

class SuperAdmin {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Fetch all top-level stats for the hero and cards
    public function getDashboardStats() {
        $stmtUser = $this->pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active FROM user");
        $users = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $stmtReq = $this->pdo->query("SELECT COUNT(*) as pending FROM password_requests WHERE status = 'pending'");
        $requests = $stmtReq->fetch(PDO::FETCH_ASSOC);

        $stmtSaro = $this->pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(total_budget),0) as total_budget FROM saro WHERE status IN ('active','obligated')");
        $saros = $stmtSaro->fetch(PDO::FETCH_ASSOC);

        $stmtProc = $this->pdo->query("
            SELECT COALESCE(SUM(p.obligated_amount),0) as total_obligated
            FROM procurement p
            JOIN object_code o ON p.objectId = o.objectId
            JOIN saro s ON s.saroId = o.saroId
            WHERE p.status = 'obligated'
              AND s.status IN ('active','obligated')
        ");
        $procurements = $stmtProc->fetch(PDO::FETCH_ASSOC);

        $totalBudget    = (float)($saros['total_budget'] ?? 0);
        $totalObligated = (float)($procurements['total_obligated'] ?? 0);
        $unobligated    = $totalBudget - $totalObligated;

        return [
            'total_users'       => $users['total']    ?? 0,
            'active_users'      => $users['active']   ?? 0,
            'pending_requests'  => $requests['pending'] ?? 0,
            'total_saros'       => $saros['total']    ?? 0,
            'total_budget'      => $totalBudget,
            'total_obligated'   => $totalObligated,
            'unobligated'       => $unobligated,
            'utilization_rate'  => $totalBudget > 0 ? round(($totalObligated / $totalBudget) * 100, 1) : 0,
        ];
    }

    public function getRecentUsers($limit = 4) {
        $sql = "SELECT u.first_name, u.last_name, u.email, u.status, r.role
                FROM user u
                LEFT JOIN user_role r ON u.roleId = r.roleId
                ORDER BY u.created_at DESC LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPendingRequests($limit = 4) {
        $sql = "SELECT pr.requestId, u.first_name, u.last_name, pr.reason, pr.requested_at
                FROM password_requests pr
                JOIN user u ON pr.userId = u.userId
                WHERE pr.status = 'pending'
                ORDER BY pr.requested_at ASC LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Per-SARO budget vs obligated (only obligated status rows)
    public function getSaroChartData($limit = 5) {
        $stmt = $this->pdo->prepare("
            SELECT s.saroNo, s.total_budget,
                   COALESCE(SUM(CASE WHEN p.status='obligated' THEN p.obligated_amount ELSE 0 END), 0) AS total_obligated
            FROM saro s
            LEFT JOIN object_code oc ON oc.saroId = s.saroId
            LEFT JOIN procurement p  ON p.objectId = oc.objectId
            WHERE s.status IN ('active','obligated')
            GROUP BY s.saroId, s.saroNo, s.total_budget
            ORDER BY s.created_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInitials($firstName, $lastName) {
        return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
    }
}
