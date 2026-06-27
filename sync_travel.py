import re

with open('saro/view_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

target = """            $isTravel = (int)($_POST['is_travelExpense'] ?? 0) === 1 ? 1 : 0;
            $conn->prepare("UPDATE object_code SET code=?,projected_cost=?,is_travelExpense=? WHERE objectId=?")->execute([$newCode, (float)($_POST['projected_cost']??0), $isTravel, $oid]);
            $conn->prepare("DELETE FROM expense_items WHERE objectId=?")->execute([$oid]);"""

replacement = """            $isTravel = (int)($_POST['is_travelExpense'] ?? 0) === 1 ? 1 : 0;
            
            // Check old is_travelExpense
            $stOldTravel = $conn->prepare("SELECT is_travelExpense FROM object_code WHERE objectId = ?");
            $stOldTravel->execute([$oid]);
            $oldIsTravel = (int)$stOldTravel->fetchColumn();

            $conn->prepare("UPDATE object_code SET code=?,projected_cost=?,is_travelExpense=? WHERE objectId=?")->execute([$newCode, (float)($_POST['projected_cost']??0), $isTravel, $oid]);
            
            if ($oldIsTravel !== $isTravel) {
                // Update all associated procurements to match the new is_travelExpense
                // and reset their status to 'on_process' (unless cancelled) since required documents have changed
                $conn->prepare("UPDATE procurement SET is_travelExpense = ?, status = IF(status='cancelled', 'cancelled', 'on_process') WHERE objectId = ?")->execute([$isTravel, $oid]);
                
                // Fetch all procurement IDs for this object code
                $stProcs = $conn->prepare("SELECT procurementId FROM procurement WHERE objectId = ?");
                $stProcs->execute([$oid]);
                $procIds = $stProcs->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($procIds)) {
                    $inQuery = implode(',', array_fill(0, count($procIds), '?'));
                    // Delete old documents and signatures because the requirements have changed
                    $conn->prepare("DELETE FROM proc_documents WHERE procurementId IN ($inQuery)")->execute($procIds);
                    $conn->prepare("DELETE FROM proc_signatures WHERE procurementId IN ($inQuery)")->execute($procIds);
                }
            }
            
            $conn->prepare("DELETE FROM expense_items WHERE objectId=?")->execute([$oid]);"""

content = content.replace(target, replacement)

with open('saro/view_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Updated edit_object_code logic to sync travel state")
