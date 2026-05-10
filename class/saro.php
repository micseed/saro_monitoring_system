<?php
require_once 'database.php';

class Saro extends Database {

    public function createSaro($userId, $saroNo, $title, $year, $budget, $objectCodes, $dateReleased = null, $validUntil = null) {
        $conn = $this->connect();
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare(
                "INSERT INTO saro (userId, saroNo, saro_title, fiscal_year, total_budget, date_released, valid_until)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $saroNo, $title, $year, $budget,
                $dateReleased ?: null,
                $validUntil   ?: null]);
            $saroId = $conn->lastInsertId();

            $stmtObj  = $conn->prepare("INSERT INTO object_code (saroId, code, projected_cost, is_travelExpense) VALUES (?, ?, ?, ?)");
            $stmtItem = $conn->prepare("INSERT INTO expense_items (objectId, item_name) VALUES (?, ?)");
            foreach ($objectCodes as $obj) {
                if (!empty($obj['code'])) {
                    $stmtObj->execute([$saroId, $obj['code'], $obj['cost'] ?? 0, (int)($obj['is_travel'] ?? 0)]);
                    if (!empty($obj['item'])) {
                        $stmtItem->execute([$conn->lastInsertId(), $obj['item']]);
                    }
                }
            }

            $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'create', ?, 'saro', ?, ?)")
                 ->execute([$userId, "Created SARO: {$saroNo}", $saroId, $_SERVER['REMOTE_ADDR'] ?? '']);

            $conn->commit();
            return ['success' => true, 'saroId' => $saroId];
        } catch (Exception $e) {
            $conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getAllSaros() {
        $sql = "SELECT s.saroId, s.userId, s.saroNo, s.saro_title, s.fiscal_year, s.total_budget,
                       s.date_released, s.valid_until, s.status,
                       (SELECT COUNT(*) FROM object_code oc WHERE oc.saroId = s.saroId) AS obj_count,
                       CONCAT(u.first_name, ' ', u.last_name) AS owner_name
                FROM saro s
                LEFT JOIN user u ON s.userId = u.userId
                ORDER BY s.created_at ASC";
        return $this->connect()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSarosByStatus(array $statuses) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT s.saroId, s.userId, s.saroNo, s.saro_title, s.fiscal_year, s.total_budget,
                       s.date_released, s.valid_until, s.status,
                       (SELECT COUNT(*) FROM object_code oc WHERE oc.saroId = s.saroId) AS obj_count,
                       CONCAT(u.first_name, ' ', u.last_name) AS owner_name
                FROM saro s
                LEFT JOIN user u ON s.userId = u.userId
                WHERE s.status IN ({$placeholders})
                ORDER BY s.created_at ASC";
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($statuses);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSaroById($saroId) {
        $stmt = $this->connect()->prepare(
            "SELECT saroId, userId, saroNo, saro_title, fiscal_year, total_budget,
                    date_released, valid_until, status FROM saro WHERE saroId = ?"
        );
        $stmt->execute([$saroId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** Returns true if $userId is the creator/owner of the given SARO. */
    public function isOwner(int $saroId, int $userId): bool {
        $stmt = $this->connect()->prepare("SELECT userId FROM saro WHERE saroId = ?");
        $stmt->execute([$saroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (int)$row['userId'] === $userId;
    }

    public function updateSaro($saroId, $saroNo, $title, $year, $budget, $status, $userId = 0, $dateReleased = null, $validUntil = null) {
        try {
            $conn = $this->connect();
            $conn->prepare(
                "UPDATE saro SET saroNo=?, saro_title=?, fiscal_year=?, total_budget=?,
                         status=?, date_released=?, valid_until=? WHERE saroId=?"
            )->execute([$saroNo, $title, $year, $budget, $status,
                $dateReleased ?: null,
                $validUntil   ?: null,
                $saroId]);

            if ($userId) {
                $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'edit', ?, 'saro', ?, ?)")
                     ->execute([$userId, "Updated SARO: {$saroNo}", $saroId, $_SERVER['REMOTE_ADDR'] ?? '']);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Soft-delete: marks status = 'deleted' (keeps record in DB for audit). */
    public function deleteSaro($saroId, $userId = 0, $saroNo = '') {
        try {
            $conn = $this->connect();
            if (!$saroNo && $saroId) {
                $row = $conn->prepare("SELECT saroNo FROM saro WHERE saroId=?");
                $row->execute([$saroId]);
                $saroNo = $row->fetchColumn() ?: "ID:{$saroId}";
            }
            $conn->prepare("UPDATE saro SET status='deleted' WHERE saroId = ?")->execute([$saroId]);
            if ($userId) {
                $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'delete', ?, 'saro', ?, ?)")
                     ->execute([$userId, "Deleted SARO: {$saroNo}", $saroId, $_SERVER['REMOTE_ADDR'] ?? '']);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function cancelSaro($saroId, $userId = 0, $saroNo = '') {
        try {
            $conn = $this->connect();
            if (!$saroNo && $saroId) {
                $row = $conn->prepare("SELECT saroNo FROM saro WHERE saroId=?");
                $row->execute([$saroId]);
                $saroNo = $row->fetchColumn() ?: "ID:{$saroId}";
            }
            $conn->prepare("UPDATE saro SET status='cancelled' WHERE saroId = ?")->execute([$saroId]);
            if ($userId) {
                $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'cancelled', ?, 'saro', ?, ?)")
                     ->execute([$userId, "Cancelled SARO: {$saroNo}", $saroId, $_SERVER['REMOTE_ADDR'] ?? '']);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Checks all active SAROs and auto-updates their status:
     * - 'obligated'  when ALL procurement activities under it are obligated AND BUR = 100%
     * - 'lapsed'     when valid_until has passed and budget was NOT fully obligated
     *
     * Call this at the top of pages that list SAROs.
     */
    public function checkAndAutoUpdateStatus(int $userId = 0): void {
        $conn = $this->connect();

        // 1. Obligated check: all procurements under this SARO are 'obligated'
        //    AND total obligated amount >= total_budget (BUR ≥ 100%)
        $obligatedSql = "
            SELECT s.saroId, s.saroNo
            FROM saro s
            WHERE s.status = 'active'
            AND (
                SELECT COUNT(*) FROM object_code oc
                JOIN procurement p ON p.objectId = oc.objectId
                WHERE oc.saroId = s.saroId
            ) > 0
            AND (
                SELECT COUNT(*) FROM object_code oc
                JOIN procurement p ON p.objectId = oc.objectId
                WHERE oc.saroId = s.saroId AND p.status != 'obligated'
            ) = 0
            AND (
                SELECT COALESCE(SUM(p.obligated_amount), 0)
                FROM object_code oc
                JOIN procurement p ON p.objectId = oc.objectId
                WHERE oc.saroId = s.saroId AND p.status = 'obligated'
            ) >= s.total_budget
        ";
        $obligatedRows = $conn->query($obligatedSql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($obligatedRows as $row) {
            $conn->prepare("UPDATE saro SET status='obligated' WHERE saroId=?")->execute([$row['saroId']]);
            if ($userId) {
                $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address)
                                VALUES (?, 'obligated', ?, 'saro', ?, ?)")
                     ->execute([$userId, "Auto-obligated SARO: {$row['saroNo']}", $row['saroId'], $_SERVER['REMOTE_ADDR'] ?? '']);
            }
        }

        // 2. Lapsed check: valid_until has passed and not yet obligated/cancelled/lapsed
        $lapsedSql = "
            SELECT s.saroId, s.saroNo
            FROM saro s
            WHERE s.status = 'active'
            AND s.valid_until IS NOT NULL
            AND s.valid_until < CURDATE()
            AND (
                SELECT COALESCE(SUM(p.obligated_amount), 0)
                FROM object_code oc
                JOIN procurement p ON p.objectId = oc.objectId
                WHERE oc.saroId = s.saroId AND p.status = 'obligated'
            ) < s.total_budget
        ";
        $lapsedRows = $conn->query($lapsedSql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lapsedRows as $row) {
            $conn->prepare("UPDATE saro SET status='lapsed' WHERE saroId=?")->execute([$row['saroId']]);
            if ($userId) {
                $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address)
                                VALUES (?, 'lapsed', ?, 'saro', ?, ?)")
                     ->execute([$userId, "Auto-lapsed SARO: {$row['saroNo']}", $row['saroId'], $_SERVER['REMOTE_ADDR'] ?? '']);
            }
        }
    }

    public function getObjectCodesForSaro(int $saroId): array {
        $stmt = $this->connect()->prepare("
            SELECT oc.objectId, oc.code, oc.projected_cost,
                   COALESCE(oc.is_travelExpense, 0) AS is_travelExpense,
                   GROUP_CONCAT(ei.item_name SEPARATOR ', ') AS expense_items
            FROM object_code oc
            LEFT JOIN expense_items ei ON oc.objectId = ei.objectId
            WHERE oc.saroId = ?
            GROUP BY oc.objectId
            ORDER BY oc.objectId ASC
        ");
        $stmt->execute([$saroId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProcurementDocuments(int $procurementId, bool $isTravel = false): array {
        $conn = $this->connect();
        $cond = $isTravel ? "rd.applies_to_travel = 1" : "rd.applies_to_regular = 1";
        $sql  = "
            SELECT rd.documentId, rd.document_name,
                   IF(pd.procDocId IS NOT NULL, 1, 0) AS is_checked
            FROM required_documents rd
            LEFT JOIN proc_documents pd
                   ON rd.documentId = pd.documentId AND pd.procurementId = ?
            WHERE $cond
            ORDER BY rd.sort_order ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$procurementId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSignatures(int $procurementId): array {
        $conn = $this->connect();
        $sql  = "
            SELECT sr.signId, sr.sign_name, sr.sign_order,
                   IF(ps.status = 'waived', 1, 0) AS is_signed
            FROM signatory_role sr
            LEFT JOIN proc_signatures ps
                   ON sr.signId = ps.signId AND ps.procurementId = ?
            WHERE sr.applies_to_regular = 1
            ORDER BY sr.sign_order ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$procurementId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
