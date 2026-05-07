<?php
require_once __DIR__ . '/Database.php';

class ProcurementStatus extends Database {

    /**
     * Gets required documents for a procurement and checks if they exist in procurement_status
     */
    public function getProcurementDocuments($procurementId, $isTravel = false) {
        $conn = $this->connect();
        
        // Filter depending on if it's a travel expense or regular
        $travelCondition = $isTravel ? "rd.applies_to_travel = 1" : "rd.applies_to_regular = 1";

        // Fetches documents and checks if there's a matching record in procurement_status
        $sql = "
            SELECT rd.documentId, rd.document_name, 
                   IF(ps.statusId IS NOT NULL, 1, 0) as is_checked
            FROM required_documents rd
            LEFT JOIN procurement_status ps 
                   ON rd.documentId = ps.documentId AND ps.procurementId = ?
            WHERE $travelCondition
            ORDER BY rd.sort_order ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([$procurementId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gets all signatory roles and checks if they are approved in proc_approval
     */
    public function getSignatures($procurementId) {
        $conn = $this->connect();
        
        $sql = "
            SELECT sr.signId, sr.sign_name, sr.sign_order,
                   IF(pa.status = 'approved', 1, 0) as is_signed
            FROM signatory_role sr
            LEFT JOIN proc_approval pa 
                   ON sr.signId = pa.signId AND pa.procurementId = ?
            ORDER BY sr.sign_order ASC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$procurementId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
