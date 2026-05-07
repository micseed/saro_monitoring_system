<?php
require_once 'Database.php';

class Saro extends Database {

    public function createSaro($userId, $saroNo, $title, $year, $budget, $objectCodes) {
        $conn = $this->connect();
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare(
                "INSERT INTO saro (userId, saroNo, saro_title, fiscal_year, total_budget) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $saroNo, $title, $year, $budget]);
            $saroId = $conn->lastInsertId();

            $stmtObj  = $conn->prepare("INSERT INTO object_code (saroId, code, projected_cost) VALUES (?, ?, ?)");
            $stmtItem = $conn->prepare("INSERT INTO expense_items (objectId, item_name) VALUES (?, ?)");
            foreach ($objectCodes as $obj) {
                if (!empty($obj['code'])) {
                    $stmtObj->execute([$saroId, $obj['code'], $obj['cost'] ?? 0]);
                    if (!empty($obj['item'])) {
                        $stmtItem->execute([$conn->lastInsertId(), $obj['item']]);
                    }
                }
            }

            $conn->commit();
            return ['success' => true, 'saroId' => $saroId];
        } catch (Exception $e) {
            $conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getAllSaros() {
        $sql = "SELECT s.saroId, s.saroNo, s.saro_title, s.fiscal_year, s.total_budget, s.status,
                       (SELECT COUNT(*) FROM object_code oc WHERE oc.saroId = s.saroId) AS obj_count
                FROM saro s
                ORDER BY s.created_at DESC";
        return $this->connect()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSaroById($saroId) {
        $stmt = $this->connect()->prepare(
            "SELECT saroId, saroNo, saro_title, fiscal_year, total_budget, status FROM saro WHERE saroId = ?"
        );
        $stmt->execute([$saroId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateSaro($saroId, $saroNo, $title, $year, $budget, $status) {
        try {
            $stmt = $this->connect()->prepare(
                "UPDATE saro SET saroNo=?, saro_title=?, fiscal_year=?, total_budget=?, status=? WHERE saroId=?"
            );
            $stmt->execute([$saroNo, $title, $year, $budget, $status, $saroId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function deleteSaro($saroId) {
        try {
            $stmt = $this->connect()->prepare("DELETE FROM saro WHERE saroId = ?");
            $stmt->execute([$saroId]);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
