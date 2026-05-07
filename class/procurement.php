<?php
require_once __DIR__ . '/Database.php';

class Procurement extends Database {

    public function addActivity(array $data): array {
        try {
            $conn = $this->connect();
            $stmt = $conn->prepare("
                INSERT INTO procurement
                    (objectId, pro_act, is_travelExpense, quantity, unit, unit_cost,
                     obligated_amount, period_start, period_end, proc_date, remarks)
                VALUES
                    (:objectId, :pro_act, :is_travelExpense, :quantity, :unit, :unit_cost,
                     :obligated_amount, :period_start, :period_end, :proc_date, :remarks)
            ");
            $stmt->execute([
                ':objectId'         => $data['objectId'],
                ':pro_act'          => $data['pro_act']          ?? null,
                ':is_travelExpense' => (int)($data['is_travelExpense'] ?? 0),
                ':quantity'         => $data['quantity']         ?: null,
                ':unit'             => $data['unit']             ?: null,
                ':unit_cost'        => $data['unit_cost']        ?: null,
                ':obligated_amount' => $data['obligated_amount'] ?: null,
                ':period_start'     => $data['period_start']     ?: null,
                ':period_end'       => $data['period_end']       ?: null,
                ':proc_date'        => $data['proc_date']        ?: null,
                ':remarks'          => $data['remarks']          ?: null,
            ]);
            return ['success' => true, 'id' => (int)$conn->lastInsertId()];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function editActivity(int $id, array $data): array {
        try {
            $conn = $this->connect();
            $stmt = $conn->prepare("
                UPDATE procurement SET
                    pro_act          = :pro_act,
                    is_travelExpense = :is_travelExpense,
                    quantity         = :quantity,
                    unit             = :unit,
                    unit_cost        = :unit_cost,
                    obligated_amount = :obligated_amount,
                    period_start     = :period_start,
                    period_end       = :period_end,
                    proc_date        = :proc_date,
                    remarks          = :remarks
                WHERE procurementId = :id
            ");
            $stmt->execute([
                ':id'               => $id,
                ':pro_act'          => $data['pro_act']          ?? null,
                ':is_travelExpense' => (int)($data['is_travelExpense'] ?? 0),
                ':quantity'         => $data['quantity']         ?: null,
                ':unit'             => $data['unit']             ?: null,
                ':unit_cost'        => $data['unit_cost']        ?: null,
                ':obligated_amount' => $data['obligated_amount'] ?: null,
                ':period_start'     => $data['period_start']     ?: null,
                ':period_end'       => $data['period_end']       ?: null,
                ':proc_date'        => $data['proc_date']        ?: null,
                ':remarks'          => $data['remarks']          ?: null,
            ]);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function deleteActivity(int $id): array {
        try {
            $conn = $this->connect();
            $stmt = $conn->prepare("DELETE FROM procurement WHERE procurementId = ?");
            $stmt->execute([$id]);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getActivitiesByObjectId(int $objectId): array {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT p.*, oc.code AS object_code, oc.saroId
            FROM procurement p
            JOIN object_code oc ON oc.objectId = p.objectId
            WHERE p.objectId = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$objectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActivityById(int $id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT p.*, oc.code AS object_code, oc.saroId
            FROM procurement p
            JOIN object_code oc ON oc.objectId = p.objectId
            WHERE p.procurementId = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getActivitiesBySaroId(int $saroId): array {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT p.*, oc.code AS object_code
            FROM procurement p
            JOIN object_code oc ON oc.objectId = p.objectId
            WHERE oc.saroId = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$saroId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
