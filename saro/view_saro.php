<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/saro.php';
require_once __DIR__ . '/../class/procurement.php';
require_once __DIR__ . '/../class/notification.php';

// ── AJAX handler ─────────────────────────────────────────────────────────────
if (!empty($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $db     = new Database();
    $conn   = $db->connect();
    $saroId = (int)($_POST['saroId'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);

    /** @return ?string yyyy-mm-dd or null when column empty */
    $fetchSaroValidUntil = static function (PDO $conn, int $id): ?string {
        $st = $conn->prepare('SELECT valid_until FROM saro WHERE saroId = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $v = $row['valid_until'] ?? null;
        return ($v !== null && $v !== '') ? (string)$v : null;
    };

    /** Period + activity date cannot extend past SARO validity; period start must precede/end with period end. */
    $procurementScheduleViolation = static function (?string $validUntil, ?string $periodStart, ?string $periodEnd, ?string $procDate): ?string {
        if ($periodStart && $periodEnd) {
            $tS = strtotime($periodStart);
            $tE = strtotime($periodEnd);
            if ($tS !== false && $tE !== false && $tS > $tE) {
                return 'Procurement period start must fall on or before the period end.';
            }
        }
        $vu = trim((string)($validUntil ?? ''));
        if ($vu === '') {
            return null;
        }
        $fmt = static function (?string $d): string {
            if (!$d) {
                return '';
            }
            $t = strtotime($d . (strlen($d) <= 10 ? ' 12:00:00' : ''));
            return $t ? date('M j, Y', $t) : $d;
        };
        $tV = strtotime($vu . ' 23:59:59');
        if ($tV === false) {
            return null;
        }
        $vuLbl = $fmt($vu);
        if ($periodStart) {
            $t = strtotime($periodStart . ' 00:00:00');
            if ($t !== false && $t > $tV) {
                return 'Procurement period begins on ' . $fmt($periodStart) . ', which is after the SARO valid-until date (' . $vuLbl . '). Shift the period or extend SARO validity.';
            }
        }
        if ($periodEnd) {
            $t = strtotime($periodEnd . ' 23:59:59');
            if ($t !== false && $t > $tV) {
                return 'Procurement period ends on ' . $fmt($periodEnd) . '. The entire period must end on or before the SARO valid-until date (' . $vuLbl . ').';
            }
        }
        if ($procDate) {
            $t = strtotime($procDate . ' 23:59:59');
            if ($t !== false && $t > $tV) {
                return 'Procurement activity date (' . $fmt($procDate) . ') cannot be after the SARO valid-until date (' . $vuLbl . ').';
            }
        }

        return null;
    };

    $procurementBelongsToSaro = static function (PDO $conn, int $procId, int $saro): bool {
        $st = $conn->prepare('SELECT 1 FROM procurement p INNER JOIN object_code oc ON p.objectId = oc.objectId WHERE p.procurementId = ? AND oc.saroId = ?');
        $st->execute([$procId, $saro]);

        return (bool)$st->fetchColumn();
    };
    $objectCodeBelongsToSaro = static function (PDO $conn, int $objectId, int $saro): bool {
        $st = $conn->prepare('SELECT 1 FROM object_code WHERE objectId = ? AND saroId = ?');
        $st->execute([$objectId, $saro]);
        return (bool)$st->fetchColumn();
    };
    $isSaroOwner = static function (PDO $conn, int $saro, int $uid): bool {
        if ($saro <= 0 || $uid <= 0) return false;
        $st = $conn->prepare('SELECT 1 FROM saro WHERE saroId = ? AND userId = ?');
        $st->execute([$saro, $uid]);
        return (bool)$st->fetchColumn();
    };
    switch ($_POST['ajax_action']) {

        case 'add_procurement': {
            if (!$saroId) {
                echo json_encode(['success' => false, 'error' => 'Missing SARO.']);
                break;
            }
            if (!$isSaroOwner($conn, $saroId, $userId)) {
                echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can add procurement activities.']);
                break;
            }
            $psm = $_POST['period_start_month'] ?? '';
            $psy = $_POST['period_start_year']  ?? '';
            $pem = $_POST['period_end_month']   ?? '';
            $pey = $_POST['period_end_year']    ?? '';
            $periodStart = ($psm && $psy) ? date('Y-m-01', strtotime("$psm $psy")) : null;
            $periodEnd   = ($pem && $pey) ? date('Y-m-t',  strtotime("$pem $pey")) : null;
            $isTravel    = (int)($_POST['is_travelExpense'] ?? 0);
            $qty         = max(1, (int)($_POST['quantity']  ?? 1));
            $unitCost    = (float)($_POST['unit_cost']      ?? 0);
            $proActName  = trim($_POST['pro_act'] ?? '');
            $procDateRaw = trim((string)($_POST['proc_date'] ?? ''));

            $vuRow = $fetchSaroValidUntil($conn, $saroId);
            $vio   = $procurementScheduleViolation($vuRow, $periodStart, $periodEnd, $procDateRaw !== '' ? $procDateRaw : null);
            if ($vio !== null) {
                echo json_encode(['success' => false, 'error' => $vio, 'code' => 'valid_until_period']);
                break;
            }

            $stmt = $conn->prepare("
                INSERT INTO procurement
                    (objectId, userId, pro_act, is_travelExpense, quantity, unit, unit_cost,
                     obligated_amount, period_start, period_end, proc_date, remarks, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                (int)$_POST['objectId'], $userId,
                $proActName, $isTravel, $qty,
                $_POST['unit'] ?: null, $unitCost, $unitCost * $qty,
                $periodStart, $periodEnd,
                $procDateRaw !== '' ? $procDateRaw : null,
                $_POST['remarks']   ?: null,
                'on_process',
            ]);
            $newProcId = (int)$conn->lastInsertId();

            // Audit log: procurement activity created
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $logDetails = 'Created procurement activity' . ($proActName ? ': ' . $proActName : '') . ' (SARO ID: ' . $saroId . ')';
            $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'create', ?, 'procurement', ?, ?)")
                 ->execute([$userId, $logDetails, $newProcId, $ip]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'edit_procurement': {
            $procId = (int)($_POST['procurementId'] ?? 0);
            if (!$isSaroOwner($conn, $saroId, $userId)) {
                echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can update procurement activities.']);
                break;
            }
            if (!$procId || !$saroId || !$procurementBelongsToSaro($conn, $procId, $saroId)) {
                echo json_encode(['success' => false, 'error' => 'Procurement not found for this SARO.']);
                break;
            }
            $psm = $_POST['period_start_month'] ?? ''; $psy = $_POST['period_start_year'] ?? '';
            $pem = $_POST['period_end_month']   ?? ''; $pey = $_POST['period_end_year']   ?? '';
            $periodStart = ($psm && $psy) ? date('Y-m-01', strtotime("$psm $psy")) : null;
            $periodEnd   = ($pem && $pey) ? date('Y-m-t',  strtotime("$pem $pey")) : null;
            $qty = max(1,(int)($_POST['quantity'] ?? 1)); $unitCost = (float)($_POST['unit_cost'] ?? 0);
            $editProActName = trim($_POST['pro_act'] ?? '');
            $procDateEdit = trim((string)($_POST['proc_date'] ?? ''));

            $vuEdit = $fetchSaroValidUntil($conn, $saroId);
            $vioE   = $procurementScheduleViolation($vuEdit, $periodStart, $periodEnd, $procDateEdit !== '' ? $procDateEdit : null);
            if ($vioE !== null) {
                echo json_encode(['success' => false, 'error' => $vioE, 'code' => 'valid_until_period']);
                break;
            }

            $conn->prepare("UPDATE procurement SET pro_act=?,quantity=?,unit=?,unit_cost=?,obligated_amount=?,period_start=?,period_end=?,proc_date=?,remarks=? WHERE procurementId=?")
                 ->execute([$editProActName,$qty,$_POST['unit']?:null,$unitCost,$unitCost*$qty,$periodStart,$periodEnd,$procDateEdit !== '' ? $procDateEdit : null,$_POST['remarks']?:null,$procId]);

            // Audit log: procurement activity edited
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $editDetails = 'Edited procurement activity' . ($editProActName ? ': ' . $editProActName : '') . ' (SARO ID: ' . $saroId . ')';
            $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'edit', ?, 'procurement', ?, ?)")
                 ->execute([$userId, $editDetails, $procId, $ip]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'edit_remarks': {
            $procId  = (int)($_POST['procurementId'] ?? 0);
            if (!$isSaroOwner($conn, $saroId, $userId)) {
                echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can edit remarks.']);
                break;
            }
            if (!$procId || !$saroId || !$procurementBelongsToSaro($conn, $procId, $saroId)) {
                echo json_encode(['success' => false, 'error' => 'Procurement not found for this SARO.']);
                break;
            }
            $remarks = trim($_POST['remarks'] ?? '') ?: null;
            $conn->prepare("UPDATE procurement SET remarks=? WHERE procurementId=?")
                 ->execute([$remarks, $procId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'delete_procurement': {
            $delProcId = (int)($_POST['procurementId'] ?? 0);
            if (!$isSaroOwner($conn, $saroId, $userId)) {
                echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can delete procurement activities.']);
                break;
            }
            if (!$delProcId || !$saroId || !$procurementBelongsToSaro($conn, $delProcId, $saroId)) {
                echo json_encode(['success' => false, 'error' => 'Procurement not found for this SARO.']);
                break;
            }
            // Fetch name before deleting for the audit message
            $delStmt = $conn->prepare("SELECT pro_act FROM procurement WHERE procurementId=?");
            $delStmt->execute([$delProcId]);
            $delRow = $delStmt->fetch(PDO::FETCH_ASSOC);
            $delProActName = $delRow['pro_act'] ?? '';

            $conn->prepare("DELETE FROM procurement WHERE procurementId=?")->execute([$delProcId]);

            // Audit log: procurement activity deleted
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $delDetails = 'Deleted procurement activity' . ($delProActName ? ': ' . $delProActName : '') . ' (SARO ID: ' . $saroId . ')';
            $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'delete', ?, 'procurement', ?, ?)")
                 ->execute([$userId, $delDetails, $delProcId, $ip]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'edit_saro': {
            if (!$isSaroOwner($conn, $saroId, $userId)) {
                echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can update this SARO.']);
                break;
            }
            $dateReleased = !empty($_POST['date_released']) ? strip_tags($_POST['date_released']) : null;
            $validUntil   = !empty($_POST['valid_until'])   ? strip_tags($_POST['valid_until'])   : null;
            $saroTitle    = strip_tags(trim($_POST['saro_title'] ?? ''));
            $conn->prepare("UPDATE saro SET saro_title=?,fiscal_year=?,total_budget=?,date_released=?,valid_until=? WHERE saroId=?")
                 ->execute([$saroTitle, (int)($_POST['fiscal_year']??0), (float)($_POST['total_budget']??0), $dateReleased, $validUntil, $saroId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'add_object_codes': {
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!$saroId || empty($items)) { echo json_encode(['success'=>false,'error'=>'No data']); break; }
            if (!$isSaroOwner($conn, $saroId, $userId)) {
                echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can add object codes.']);
                break;
            }
            $stO = $conn->prepare("INSERT INTO object_code (saroId,code,projected_cost,is_travelExpense) VALUES (?,?,?,?)");
            $stI = $conn->prepare("INSERT INTO expense_items (objectId,item_name) VALUES (?,?)");
            $conn->beginTransaction();
            $addedCodes = [];
            $lastObjId  = null;
            try {
                foreach ($items as $it) {
                    if (empty(trim($it['code']??''))) continue;
                    $stO->execute([$saroId, trim($it['code']), (float)($it['cost']??0), (int)($it['is_travel']??0)]);
                    $nId = $conn->lastInsertId();
                    $lastObjId = $nId;
                    $addedCodes[] = trim($it['code']);
                    if (!empty(trim($it['expense_item']??''))) $stI->execute([$nId, trim($it['expense_item'])]);
                }
                $conn->commit();

                // Audit log: object code(s) added
                if (!empty($addedCodes)) {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $codeList = implode(', ', $addedCodes);
                    $addObjDetails = 'Added object code(s): ' . $codeList . ' (SARO ID: ' . $saroId . ')';
                    $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'create', ?, 'object_code', ?, ?)")
                         ->execute([$userId, $addObjDetails, $lastObjId, $ip]);
                }

                echo json_encode(['success'=>true]);
            } catch (Exception $e) { $conn->rollBack(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'edit_object_code': {
            $oid     = (int)($_POST['objectId']??0);
            if (!$isSaroOwner($conn, $saroId, $userId)) {
                echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can update object codes.']);
                break;
            }
            if (!$oid || !$saroId || !$objectCodeBelongsToSaro($conn, $oid, $saroId)) {
                echo json_encode(['success' => false, 'error' => 'Object code not found for this SARO.']);
                break;
            }
            $newCode = strip_tags(trim($_POST['code']??''));
            $conn->prepare("UPDATE object_code SET code=?,projected_cost=? WHERE objectId=?")->execute([$newCode, (float)($_POST['projected_cost']??0), $oid]);
            $conn->prepare("DELETE FROM expense_items WHERE objectId=?")->execute([$oid]);
            if (!empty(trim($_POST['expense_item']??''))) $conn->prepare("INSERT INTO expense_items (objectId,item_name) VALUES (?,?)")->execute([$oid, strip_tags(trim($_POST['expense_item']))]);

            // Audit log: object code edited
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $editObjDetails = 'Edited object code' . ($newCode ? ': ' . $newCode : '') . ' (SARO ID: ' . $saroId . ')';
            $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'edit', ?, 'object_code', ?, ?)")
                 ->execute([$userId, $editObjDetails, $oid, $ip]);

            echo json_encode(['success'=>true]);
            break;
        }

        case 'delete_object_code': {
            $delOid = (int)($_POST['objectId']??0);
            if (!$isSaroOwner($conn, $saroId, $userId)) {
                echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can delete object codes.']);
                break;
            }
            if (!$delOid || !$saroId || !$objectCodeBelongsToSaro($conn, $delOid, $saroId)) {
                echo json_encode(['success' => false, 'error' => 'Object code not found for this SARO.']);
                break;
            }
            // Fetch code before deleting for the audit message
            $delObjStmt = $conn->prepare("SELECT code FROM object_code WHERE objectId=?");
            $delObjStmt->execute([$delOid]);
            $delObjRow  = $delObjStmt->fetch(PDO::FETCH_ASSOC);
            $delObjCode = $delObjRow['code'] ?? '';

            $conn->prepare("DELETE FROM object_code WHERE objectId=?")->execute([$delOid]);

            // Audit log: object code deleted
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $delObjDetails = 'Deleted object code' . ($delObjCode ? ': ' . $delObjCode : '') . ' (SARO ID: ' . $saroId . ')';
            $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'delete', ?, 'object_code', ?, ?)")
                 ->execute([$userId, $delObjDetails, $delOid, $ip]);

            echo json_encode(['success'=>true]);
            break;
        }

        default:
            echo json_encode(['success'=>false,'error'=>'Unknown action']);
    }
    exit;
}
// ── End AJAX handler ──────────────────────────────────────────────────────────

$username = $_SESSION['full_name'] ?? 'User';
$role     = $_SESSION['role'] ?? 'Role';
$initials = $_SESSION['initials'] ?? 'U';

$db   = new Database();
$conn = $db->connect();

// Get the SARO ID from the URL parameter
$saroId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$saroId) {
    // Redirect back to data entry if no ID is provided
    header("Location: data_entry.php");
    exit;
}

// 1. Fetch SARO Details[cite: 3]
$stmtSaro = $conn->prepare("SELECT * FROM saro WHERE saroId = ?");
$stmtSaro->execute([$saroId]);
$saro = $stmtSaro->fetch(PDO::FETCH_ASSOC);

if (!$saro) {
    die("SARO record not found.");
}
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$canManageSaro = ((int)($saro['userId'] ?? 0) === $currentUserId);

// 2. Fetch Object Codes and related expense items
$stmtObj = $conn->prepare("
    SELECT oc.objectId, oc.code, oc.projected_cost,
           COALESCE(oc.is_travelExpense,0) AS is_travelExpense,
           GROUP_CONCAT(ei.item_name SEPARATOR ', ') AS expense_items
    FROM object_code oc
    LEFT JOIN expense_items ei ON oc.objectId = ei.objectId
    WHERE oc.saroId = ?
    GROUP BY oc.objectId
    ORDER BY oc.objectId ASC
");
$stmtObj->execute([$saroId]);
$objectCodes = $stmtObj->fetchAll(PDO::FETCH_ASSOC);

// Travel documents for travel-expense procurements
$travelDocs = $conn->query("SELECT documentId, document_name FROM required_documents WHERE applies_to_travel=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

// Pass object-code data to JS
$jsonEmbedFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$objCodesJson = json_encode(array_map(fn($oc) => [
    'objectId'        => (int)$oc['objectId'],
    'code'            => $oc['code'],
    'is_travelExpense'=> (bool)(int)$oc['is_travelExpense'],
    'projected_cost'  => (float)$oc['projected_cost'],
], $objectCodes), $jsonEmbedFlags);

// Notifications for topbar
$notifObj      = new Notification();
$notifications = $notifObj->getRecentActivity((int)$_SESSION['user_id'], 10);
$unreadCount   = $notifObj->countUnread((int)$_SESSION['user_id']);
$approvedPwReq = $notifObj->getApprovedPasswordNotification((int)$_SESSION['user_id']);
$cancelledCount = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='cancelled'")->fetchColumn();
$obligatedCount = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='obligated'")->fetchColumn();
$lapsedCount    = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='lapsed'")->fetchColumn();

// 3. Fetch Procurement Activities
$stmtProc = $conn->prepare("
    SELECT p.*, oc.code as object_code_str
    FROM procurement p
    JOIN object_code oc ON p.objectId = oc.objectId
    WHERE oc.saroId = ?
    ORDER BY p.created_at ASC
");
$stmtProc->execute([$saroId]);
$allProcurements = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

$procurements          = array_values(array_filter($allProcurements, fn($p) => ($p['status'] ?? 'on_process') !== 'cancelled'));
$cancelledProcurements = array_values(array_filter($allProcurements, fn($p) => ($p['status'] ?? 'on_process') === 'cancelled'));

$calTitleTrunc = static function (string $s, int $max = 40): string {
    $t = trim($s);
    if ($t === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($t) > $max) {
        return mb_substr($t, 0, $max - 1) . '…';
    }
    if (!function_exists('mb_strlen') && strlen($t) > $max) {
        return substr($t, 0, $max - 1) . '…';
    }
    return $t;
};

/** Events for FullCalendar: valid-until, release date, procurement period ranges, activity (proc) dates */
$calendarEvents = [];
if (!empty($saro['valid_until'])) {
    $calendarEvents[] = [
        'title' => 'SARO valid until',
        'start' => date('Y-m-d', strtotime($saro['valid_until'])),
        'allDay' => true,
        'color' => '#ef4444',
    ];
}
if (!empty($saro['date_released'])) {
    $calendarEvents[] = [
        'title' => 'SARO date released',
        'start' => date('Y-m-d', strtotime($saro['date_released'])),
        'allDay' => true,
        'color' => '#64748b',
    ];
}
foreach ($procurements as $p) {
    $actRaw   = isset($p['pro_act']) ? (string)$p['pro_act'] : 'Activity';
    $actShort = $calTitleTrunc($actRaw);
    if (!empty($p['period_start']) && !empty($p['period_end'])) {
        // Foreground span (not background) so FullCalendar stacks lanes and respects dayMaxEvents
        $calendarEvents[] = [
            'title'      => 'Period: ' . $actShort,
            'start'      => date('Y-m-d', strtotime($p['period_start'])),
            'end'        => date('Y-m-d', strtotime($p['period_end'] . ' +1 day')),
            'allDay'     => true,
            'color'      => '#e9d5ff',
            'textColor'  => '#4c1d95',
            'classNames' => ['fc-ev-period-span'],
            'extendedProps' => ['kind' => 'period-span'],
        ];
    } else {
        if (!empty($p['period_start'])) {
            $calendarEvents[] = [
                'title'      => 'Period start: ' . $actShort,
                'start'      => date('Y-m-d', strtotime($p['period_start'])),
                'allDay'     => true,
                'color'      => '#c4b5fd',
                'textColor'  => '#3730a3',
                'classNames' => ['fc-ev-period'],
            ];
        }
        if (!empty($p['period_end'])) {
            $calendarEvents[] = [
                'title'      => 'Period end: ' . $actShort,
                'start'      => date('Y-m-d', strtotime($p['period_end'])),
                'allDay'     => true,
                'color'      => '#a78bfa',
                'textColor'  => '#312e81',
                'classNames' => ['fc-ev-period'],
            ];
        }
    }
    if (!empty($p['proc_date'])) {
        $calendarEvents[] = [
            'title'      => 'Activity date: ' . $actShort,
            'start'      => date('Y-m-d', strtotime($p['proc_date'])),
            'allDay'     => true,
            'color'      => '#3b82f6',
            'textColor'  => '#ffffff',
            'classNames' => ['fc-ev-proc-date'],
            'extendedProps' => ['kind' => 'proc-date'],
        ];
    }
}
$calendarEventsJson = json_encode($calendarEvents, $jsonEmbedFlags);

// Calculations
$totalBudget = (float)$saro['total_budget'];
$totalObligated = 0.0;

foreach ($procurements as $p) {
    if (($p['status'] ?? 'on_process') === 'obligated') {
        $totalObligated += !empty($p['obligated_amount'])
            ? (float)$p['obligated_amount']
            : ((float)$p['unit_cost'] * (int)$p['quantity']);
    }
}

$unobligated = $totalBudget - $totalObligated;
$bur = $totalBudget > 0 ? ($totalObligated / $totalBudget) * 100 : 0;
// Cap BUR display at 100% for the progress bar
$burDisplay = $bur > 100 ? 100 : $bur;

// Determine BUR Color
$burColorHex = '#f59e0b'; // Yellow (25-75%)
$burBadgeClass = 'badge-amber';
$burLabelColor = '#b45309';
$burStatusText = 'Yellow — 25% to 75% utilized';

if ($bur < 25) {
    $burColorHex = '#dc2626'; // Red (< 25%)
    $burBadgeClass = 'badge-red';
    $burLabelColor = '#dc2626';
    $burStatusText = 'Red — Under 25% utilized';
} elseif ($bur >= 75) {
    $burColorHex = '#16a34a'; // Green (>= 75%)
    $burBadgeClass = 'badge-green';
    $burLabelColor = '#16a34a';
    $burStatusText = 'Green — Highly utilized (75%+)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View SARO | DICT SARO Monitoring</title>
    <link href="../dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; overflow: hidden; background: #f0f4ff; }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #c7d7fe; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #93c5fd; }

        .layout { display: flex; height: 100vh; }

        /* ── Sidebar ── */
        .sidebar {
            width: 256px; flex-shrink: 0;
            display: flex; flex-direction: column;
            background: #0f172a; position: relative; overflow: hidden;
        }
        .sidebar::before {
            content: ''; position: absolute;
            top: -80px; right: -80px;
            width: 220px; height: 220px;
            background: #1e3a8a; border-radius: 50%;
            opacity: 0.4; pointer-events: none;
        }
        .sidebar::after {
            content: ''; position: absolute;
            bottom: -60px; left: -60px;
            width: 180px; height: 180px;
            background: #1d4ed8; border-radius: 50%;
            opacity: 0.2; pointer-events: none;
        }
        .sidebar-brand {
            display: flex; align-items: center; gap: 12px;
            padding: 28px 24px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            position: relative; z-index: 1;
        }
        .brand-logo {
            width: 40px; height: 40px; border-radius: 50%; background: #fff;
            display: flex; align-items: center; justify-content: center;
            padding: 6px; flex-shrink: 0;
        }
        .sidebar-nav { flex: 1; padding: 20px 16px; overflow-y: auto; position: relative; z-index: 1; }
        .nav-section-label {
            font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.25);
            text-transform: uppercase; letter-spacing: 0.16em;
            padding: 0 8px; margin-bottom: 8px; margin-top: 20px;
        }
        .nav-section-label:first-child { margin-top: 0; }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 10px;
            font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.45);
            text-decoration: none; cursor: pointer;
            border: none; background: none; width: 100%; text-align: left;
            transition: all 0.2s ease; margin-bottom: 2px;
        }
        .nav-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.85); }
        .nav-item.active {
            background: #1e3a8a; color: #fff;
            box-shadow: 0 0 0 1px rgba(59,130,246,0.3), inset 0 1px 0 rgba(255,255,255,0.08);
        }
        .nav-item.active .nav-icon { color: #60a5fa; }
        .nav-icon { width: 16px; height: 16px; flex-shrink: 0; }
        .sidebar-footer { padding: 16px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
        .user-card {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 10px;
            background: rgba(255,255,255,0.05); margin-bottom: 8px;
        }
        .user-avatar {
            width: 34px; height: 34px; border-radius: 8px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; color: #fff; flex-shrink: 0;
        }
        .signout-btn {
            display: flex; align-items: center; gap: 10px;
            width: 100%; padding: 9px 12px; border-radius: 10px;
            font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.4);
            background: none; border: none; cursor: pointer;
            text-decoration: none; transition: all 0.2s ease;
        }
        .signout-btn:hover { background: rgba(239,68,68,0.12); color: #fca5a5; }

        /* ── Main ── */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar {
            height: 64px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 32px; background: #fff; border-bottom: 1px solid #e8edf5;
        }
        .breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #64748b; }
        .breadcrumb-active { color: #0f172a; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn {
            width: 36px; height: 36px; border-radius: 9px;
            background: #f8fafc; border: 1px solid #e2e8f0;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: #64748b;
            transition: all 0.2s ease; position: relative;
        }
        .icon-btn:hover { border-color: #3b82f6; color: #2563eb; background: #eff6ff; }
        .notif-dot {
            position: absolute; top: 7px; right: 7px;
            width: 7px; height: 7px; background: #ef4444;
            border-radius: 50%; border: 1.5px solid #fff;
        }
        .content { flex: 1; overflow-y: auto; padding: 28px 32px; }

        /* Hero */
        .hero-banner {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            border-radius: 16px; padding: 28px 32px; margin-bottom: 24px;
            position: relative; overflow: hidden;
        }
        .hero-banner::before {
            content: ''; position: absolute; top: -60px; right: -40px;
            width: 220px; height: 220px; background: rgba(255,255,255,0.07); border-radius: 50%;
        }
        .hero-banner::after {
            content: ''; position: absolute; bottom: -40px; right: 120px;
            width: 140px; height: 140px; background: rgba(255,255,255,0.05); border-radius: 50%;
        }

        /* Cards */
        .card { background: #fff; border: 1px solid #e8edf5; border-radius: 14px; overflow: hidden; }
        .card-header {
            padding: 18px 24px; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 9px;
            font-size: 12px; font-weight: 600; font-family: 'Poppins', sans-serif;
            cursor: pointer; text-decoration: none; transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .btn-primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
        .btn-ghost { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .btn-ghost:hover { border-color: #94a3b8; color: #0f172a; background: #f1f5f9; }
        .btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

        /* Form */
        .form-label {
            display: block; font-size: 10px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 6px;
        }
        .form-input {
            width: 100%; padding: 9px 14px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 13px; font-weight: 500; color: #0f172a;
            font-family: 'Poppins', sans-serif; background: #f8fafc; outline: none;
            transition: all 0.2s ease; resize: vertical;
        }
        .form-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        select.form-input { cursor: pointer; }
        .form-input.input-error { border-color: #dc2626 !important; box-shadow: 0 0 0 3px rgba(220,38,38,0.1) !important; }
        .field-error { font-size: 10px; color: #dc2626; font-weight: 600; margin-top: 4px; display: none; }

        /* Search */
        .search-wrap { position: relative; }
        .search-input {
            padding: 8px 12px 8px 36px; border: 1px solid #e2e8f0; border-radius: 8px;
            background: #f8fafc; font-size: 12px; font-family: 'Poppins', sans-serif;
            width: 220px; outline: none; transition: all 0.2s ease;
        }
        .search-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 11px 16px; font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.12em;
            color: #94a3b8; background: #fafbfe;
            border-bottom: 1px solid #f1f5f9; white-space: nowrap; text-align: left;
        }
        tbody tr { border-bottom: 1px solid #f8fafc; transition: background 0.15s ease; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 12px 16px; font-size: 12px; color: #475569; }

        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 99px;
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
        }
        .badge-green { background: #dcfce7; color: #16a34a; }
        .badge-amber { background: #fef9c3; color: #b45309; }
        .badge-blue  { background: #dbeafe; color: #1d4ed8; }
        .badge-red   { background: #fee2e2; color: #dc2626; }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

        .show-rows-wrap { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #64748b; font-weight: 500; }
        .show-rows-select {
            padding: 5px 10px; border: 1px solid #e2e8f0; border-radius: 7px;
            font-size: 12px; font-family: 'Poppins', sans-serif;
            color: #0f172a; background: #f8fafc; outline: none; cursor: pointer;
        }

        .action-btn {
            width: 30px; height: 30px; border-radius: 7px;
            display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid transparent; cursor: pointer;
            background: transparent; transition: all 0.2s ease;
        }
        .action-btn-view { color: #2563eb; }
        .action-btn-view:hover { background: #dbeafe; border-color: #bfdbfe; }
        .action-btn-edit { color: #64748b; }
        .action-btn-edit:hover { background: #f1f5f9; border-color: #e2e8f0; color: #0f172a; }
        .action-btn-del { color: #94a3b8; }
        .action-btn-del:hover { background: #fee2e2; border-color: #fecaca; color: #dc2626; }

        .proc-table-wrap { overflow-x: auto; }
        .proc-table thead th { padding: 11px 14px; }
        .proc-table tbody td { padding: 11px 14px; white-space: nowrap; }
        .mini-table thead th { padding: 9px 14px; font-size: 9px; }
        .mini-table tbody td { padding: 10px 14px; font-size: 12px; }

        /* Modal */
        .modal-overlay { position:fixed;inset:0;z-index:200;background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;padding:24px; }
        .modal-overlay.open { display:flex; }
        .modal-card { background:#fff;border-radius:18px;width:100%;max-width:520px;box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden; }
        .modal-card-lg { max-width:680px; }
        .modal-header-blue { padding:22px 28px;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:space-between; }
        .modal-body { padding:24px 28px;display:flex;flex-direction:column;gap:16px;max-height:72vh;overflow-y:auto; }
        .modal-footer { padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;display:flex;align-items:center;justify-content:flex-end;gap:10px; }
        .modal-close-btn { width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,0.12);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff; }
        /* Month period display */
        .period-range { display:flex;align-items:center;gap:6px;font-size:11px;color:#475569;font-weight:500;white-space:nowrap; }
        .period-sep { color:#94a3b8;font-weight:400; }
        /* Month select pair */
        .period-pair { display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px; }
        .period-label-sep { font-size:13px;color:#94a3b8;font-weight:500;text-align:center; }
    </style>
    <style>
        .fc { font-family: 'Poppins', sans-serif; }
        .fc-toolbar-title { font-size: 16px !important; font-weight: 700; color: #0f172a; }
        .fc-button-primary { background-color: #3b82f6 !important; border-color: #3b82f6 !important; text-transform: capitalize; border-radius: 8px !important; }
        .fc-button-primary:hover { background-color: #1d4ed8 !important; border-color: #1d4ed8 !important; }
        .fc-event { border-radius: 4px; border: none; font-size: 10px; font-weight: 600; padding: 1px 3px; line-height: 1.25; }
        .fc-daygrid-event { white-space: nowrap !important; overflow: hidden; text-overflow: ellipsis; align-items: center; }
        .fc-daygrid-more-link { font-size: 10px !important; font-weight: 700; }
        .fc .fc-highlight { opacity: 0.12; }
        .fc-ev-period-span,.fc-ev-period { border-left: 3px solid rgba(109,40,217,0.85) !important; }
        .fc-ev-proc-date { border-left: 3px solid #1e40af !important; }
        .calendar-legend { display:flex; flex-wrap:wrap; gap:10px 18px; margin-bottom:14px; font-size:11px; font-weight:600; color:#475569; }
        .calendar-legend span { display:inline-flex; align-items:center; gap:6px; }
        .cal-lg-dot { width:10px; height:10px; border-radius:3px; flex-shrink:0; }
        .cal-lg-bar { width:14px; height:6px; border-radius:2px; flex-shrink:0; }
        /* Explicit height avoids 0-height month grid inside flex/card layouts */
        #calendar { width:100%; min-height: 560px; background:#f8fafc; border-radius:12px; border:1px solid #e8edf5; }
        #calendar .calendar-load-error { border-radius: 12px; }
        .schedule-overview-card { overflow: visible !important; }
        .schedule-overview-card > .card-header { position: relative; z-index: 2; }
    </style>
</head>
<body>
<div class="layout">

    <!-- ══ Sidebar ══ -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-logo">
                <img src="../assets/dict_logo.png" alt="DICT Logo" style="width:100%;height:100%;object-fit:contain;">
            </div>
            <div>
                <p style="font-size:13px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:0.05em;line-height:1.1;">DICT Portal</p>
                <p style="font-size:8px;color:rgba(255,255,255,0.3);font-weight:700;text-transform:uppercase;letter-spacing:0.2em;">Region IX &amp; BASULTA</p>
            </div>
        </div>
        <nav class="sidebar-nav">
            <p class="nav-section-label">Main</p>
            <a href="dashboard.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                Dashboard
            </a>
            <a href="data_entry.php" class="nav-item active">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Data Entry
            </a>
            <a href="procurement_stat.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Procurement Status
            </a>
            <p class="nav-section-label">Reports</p>
            
            <a href="export_records.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Export Records
            </a>
                        <a href="audit_logs.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Activity Logs
            </a>
            <p class="nav-section-label">History</p>
            <a href="cancelled_saro.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                Cancelled SAROs
                <?php if ($cancelledCount > 0): ?>
                <span style="margin-left:auto;min-width:18px;height:18px;border-radius:99px;background:#b45309;color:#fff;font-size:9px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;"><?= $cancelledCount ?></span>
                <?php endif; ?>
            </a>
            <a href="obligated_saro.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Obligated SAROs
                <?php if ($obligatedCount > 0): ?>
                <span style="margin-left:auto;min-width:18px;height:18px;border-radius:99px;background:#16a34a;color:#fff;font-size:9px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;"><?= $obligatedCount ?></span>
                <?php endif; ?>
            </a>
            <a href="lapsed_saro.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Lapsed SAROs
                <?php if ($lapsedCount > 0): ?>
                <span style="margin-left:auto;min-width:18px;height:18px;border-radius:99px;background:#dc2626;color:#fff;font-size:9px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;"><?= $lapsedCount ?></span>
                <?php endif; ?>
            </a>
            <p class="nav-section-label">Account</p>
            <a href="settings.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                Settings
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
                <div style="min-width:0;">
                    <p style="font-size:12px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($username) ?></p>
                    <p style="font-size:10px;color:rgba(255,255,255,0.3);font-weight:500;"><?= htmlspecialchars($role) ?></p>
                </div>
            </div>
            <a href="../logout.php" class="signout-btn">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Sign Out
            </a>
        </div>
    </aside>

    <!-- ══ Main ══ -->
    <main class="main">

        <!-- Topbar -->
        <header class="topbar">
            <div class="breadcrumb">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m0 0l-7 7-7-7M19 10v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Home</span>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <a href="dashboard.php" style="text-decoration:none;color:inherit;">Dashboard</a>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <a href="data_entry.php" style="text-decoration:none;color:inherit;">Data Entry</a>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="breadcrumb-active">View SARO</span>
            </div>
            <div class="topbar-right">
                <!-- Notification -->
                <?php $isAdmin = false; $pendingPwCount = $pendingPwCount ?? 0; $approvedPwReq = $approvedPwReq ?? null; include __DIR__ . '/../includes/notif_dropdown.php'; ?>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                    <div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,#2563eb,#1d4ed8);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div>
                        <p style="font-size:12px;font-weight:700;color:#0f172a;line-height:1.1;"><?= htmlspecialchars($username) ?></p>
                        <p style="font-size:10px;color:#94a3b8;font-weight:500;"><?= htmlspecialchars($role) ?></p>
                    </div>
                </div>
            </div>
        </header>

        <!-- ══ Content ══ -->
        <div class="content">

            <!-- Hero -->
            <div class="hero-banner">
                <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
                    <div>
                        <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                                   text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">
                            Data Entry
                        </p>
                        <h2 style="font-size:22px;font-weight:900;color:#fff;
                                   text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">
                            View SARO
                        </h2>
                        <p style="font-size:13px;color:rgba(255,255,255,0.6);font-weight:400;max-width:480px;line-height:1.6;">
                            Viewing details and procurement activities for
                            <strong style="color:rgba(255,255,255,0.85);font-weight:600;"><?= htmlspecialchars($saro['saroNo']) ?></strong>.
                        </p>
                    </div>
                    <a href="data_entry.php" class="btn"
                       style="background:rgba(255,255,255,0.15);border-color:rgba(255,255,255,0.25);
                              color:#fff;backdrop-filter:blur(8px);flex-shrink:0;"
                       onmouseover="this.style.background='rgba(255,255,255,0.25)'"
                       onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back to Data Entry
                    </a>
                </div>
            </div>

            <!-- Row 1: SARO Info + Object Codes -->
            <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:16px;margin-bottom:16px;">

                <!-- SARO Info -->
                <div class="card">
                    <div class="card-header">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:32px;height:32px;border-radius:8px;background:#eff6ff;
                                        display:flex;align-items:center;justify-content:center;">
                                <svg width="15" height="15" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <div>
                                <p style="font-size:13px;font-weight:800;color:#0f172a;">SARO Details</p>
                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Selected record information</p>
                            </div>
                        </div>
                        <?php if ($canManageSaro): ?>
                        <button class="btn btn-ghost btn-sm" onclick="openEditSaroModal()">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                            Edit
                        </button>
                        <?php else: ?>
                        <span style="font-size:10px;color:#94a3b8;font-weight:600;">View only</span>
                        <?php endif; ?>
                    </div>
                    <div style="padding:20px 24px;display:flex;flex-direction:column;gap:16px;">
                        <div>
                            <p class="form-label">SARO Number</p>
                            <p style="font-size:15px;font-weight:800;color:#0f172a;letter-spacing:-0.01em;"><?= htmlspecialchars($saro['saroNo']) ?></p>
                        </div>
                        <div>
                            <p class="form-label">SARO Title</p>
                            <p style="font-size:13px;font-weight:600;color:#334155;line-height:1.5;">
                                <?= htmlspecialchars($saro['saro_title']) ?>
                            </p>
                        </div>
                        <div>
                            <p class="form-label">Fiscal Year</p>
                            <p style="font-size:14px;font-weight:700;color:#0f172a;"><?= htmlspecialchars($saro['fiscal_year']) ?></p>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div>
                                <p class="form-label">Date Released</p>
                                <p style="font-size:13px;font-weight:700;color:#0f172a;"><?= $saro['date_released'] ? date('M d, Y', strtotime($saro['date_released'])) : '<span style="color:#94a3b8;font-style:italic;font-weight:400;">Not set</span>' ?></p>
                            </div>
                            <div>
                                <p class="form-label">Valid Until</p>
                                <p style="font-size:13px;font-weight:700;color:#0f172a;"><?= $saro['valid_until'] ? date('M d, Y', strtotime($saro['valid_until'])) : '<span style="color:#94a3b8;font-style:italic;font-weight:400;">Not set</span>' ?></p>
                            </div>
                        </div>
                        <div style="padding:12px 14px;background:#f8fafc;border:1px solid #e8edf5;border-radius:10px;">
                            <p class="form-label" style="margin-bottom:4px;">Total Budget</p>
                            <p style="font-size:16px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;">₱<?= number_format($totalBudget, 2) ?></p>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div style="padding:10px 12px;background:#eff6ff;border:1px solid #dbeafe;border-radius:8px;">
                                <p class="form-label" style="margin-bottom:3px;color:#1d4ed8;">Obligated</p>
                                <p style="font-size:13px;font-weight:800;color:#1d4ed8;">₱<?= number_format($totalObligated, 2) ?></p>
                                <p style="font-size:10px;color:#60a5fa;font-weight:600;"><?= number_format($bur, 1) ?>% of budget</p>
                            </div>
                            <div style="padding:10px 12px;background:#fef9c3;border:1px solid #fde68a;border-radius:8px;">
                                <p class="form-label" style="margin-bottom:3px;color:#b45309;">Unobligated</p>
                                <p style="font-size:13px;font-weight:800;color:#b45309;">₱<?= number_format($unobligated, 2) ?></p>
                                <p style="font-size:10px;color:#d97706;font-weight:600;"><?= number_format(100 - $bur, 1) ?>% of budget</p>
                            </div>
                        </div>
                        <!-- BUR -->
                        <div style="padding:10px 14px;background:#f8fafc;border:1px solid #fde68a;border-radius:10px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                                <p class="form-label" style="margin:0;">Budget Utilization Rate (BUR)</p>
                                <span style="font-size:12px;font-weight:900;color:<?= $burLabelColor ?>;"><?= number_format($bur, 1) ?>%</span>
                            </div>
                            <div style="width:100%;height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                                <div style="width:<?= $burDisplay ?>%;height:100%;background:<?= $burColorHex ?>;border-radius:99px;transition:width 0.5s ease;"></div>
                            </div>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;margin-top:4px;"><?= $burStatusText ?></p>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <span class="form-label" style="margin:0;">Status</span>
                            <?php if ($saro['status'] === 'active'): ?>
                                <span class="badge badge-green"><span class="badge-dot"></span>Active</span>
                            <?php else: ?>
                                <span class="badge badge-red"><span class="badge-dot"></span>Cancelled</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Object Codes -->
                <div class="card" style="display:flex;flex-direction:column;">
                    <div class="card-header">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:32px;height:32px;border-radius:8px;background:#eff6ff;
                                        display:flex;align-items:center;justify-content:center;">
                                <svg width="15" height="15" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                            </div>
                            <div>
                                <p style="font-size:13px;font-weight:800;color:#0f172a;">Object Codes</p>
                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Linked to <?= htmlspecialchars($saro['saroNo']) ?></p>
                            </div>
                        </div>
                        <?php if ($canManageSaro): ?>
                        <button class="btn btn-primary btn-sm" onclick="openAddObjModal()">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Object Code
                        </button>
                        <?php else: ?>
                        <span style="font-size:10px;color:#94a3b8;font-weight:600;">Owner can edit</span>
                        <?php endif; ?>
                    </div>
                    <div style="overflow-x:auto;flex:1;">
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th style="width:44px;">No.</th>
                                    <th>Object Code</th>
                                    <th>Expense Items</th>
                                    <th style="text-align:right;">Projected Cost</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($objectCodes)): ?>
                                    <tr><td colspan="5" style="text-align:center;color:#94a3b8;">No object codes found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($objectCodes as $index => $obj): ?>
                                    <tr>
                                        <td style="color:#cbd5e1;font-weight:700;font-size:11px;"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></td>
                                        <td><span style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($obj['code']) ?></span></td>
                                        <td style="color:#334155;font-weight:500;"><?= htmlspecialchars($obj['expense_items'] ?: '—') ?></td>
                                        <td style="text-align:right;font-weight:700;color:#0f172a;">₱<?= number_format($obj['projected_cost'], 2) ?></td>
                                        <td style="text-align:right;">
                                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:4px;">
                                                <?php if ($canManageSaro): ?>
                                                <button class="action-btn action-btn-edit" title="Edit"
                                                        onclick="openEditObjModal(<?= $obj['objectId'] ?>,'<?= htmlspecialchars($obj['code'],ENT_QUOTES) ?>','<?= $obj['projected_cost'] ?>','<?= htmlspecialchars($obj['expense_items']??'',ENT_QUOTES) ?>')">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                </button>
                                                <button class="action-btn action-btn-del" title="Remove" onclick="openDeleteObjModal(<?= $obj['objectId'] ?>,'<?= htmlspecialchars($obj['code'],ENT_QUOTES) ?>')">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                                <?php else: ?>
                                                <span style="font-size:10px;color:#cbd5e1;font-style:italic;">View only</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Row 1.5: Calendar View -->
            <div class="card schedule-overview-card" style="margin-bottom:20px;">
                <div class="card-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#f0fdf4;
                                    display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#16a34a" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">Schedule Overview</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">SARO validity, procurement periods, and activity dates</p>
                        </div>
                    </div>
                </div>
                <div style="padding:20px;">
                    <div class="calendar-legend">
                        <span><i class="cal-lg-dot" style="background:#ef4444;"></i>SARO valid until</span>
                        <span><i class="cal-lg-dot" style="background:#64748b;"></i>SARO released</span>
                        <span><i class="cal-lg-bar" style="background:#e9d5ff;border:1px solid #c4b5fd;"></i>Procurement period (spans start–end)</span>
                        <span><i class="cal-lg-dot" style="background:#7c3aed;"></i>Period end only / partial range</span>
                        <span><i class="cal-lg-dot" style="background:#3b82f6;"></i>Procurement activity date</span>
                    </div>
                    <div id="calendar"><p style="margin:20px;color:#64748b;font-size:13px;">Loading calendar…</p></div>
                </div>
            </div>

            <!-- Row 2: Procurement Activities -->
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#eff6ff;
                                    display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">Procurement Activities</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">All items under <?= htmlspecialchars($saro['saroNo']) ?></p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="show-rows-wrap">
                            <span>Show</span>
                            <select class="show-rows-select">
                                <option>10 rows</option>
                                <option selected>20 rows</option>
                                <option>50 rows</option>
                            </select>
                        </div>
                        <div class="search-wrap">
                            <svg class="search-icon" width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" class="search-input" placeholder="Type to search…">
                        </div>
                        <button class="btn btn-ghost btn-sm">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                            Filter
                        </button>
                        <?php if (!$canManageSaro): ?>
                        <button class="btn btn-ghost btn-sm" disabled title="Only the SARO owner can add activities"
                                style="opacity:0.6;cursor:not-allowed;">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Activity
                        </button>
                        <?php elseif (empty($objectCodes)): ?>
                        <button class="btn btn-ghost btn-sm" disabled title="Add at least one object code first"
                                style="opacity:0.5;cursor:not-allowed;">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Activity
                        </button>
                        <?php else: ?>
                        <button class="btn btn-primary btn-sm" onclick="openProcModal()">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Activity
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="proc-table-wrap">
                    <table class="proc-table">
                        <thead>
                            <tr>
                                <th style="width:44px;">No.</th>
                                <th>Object Code</th>
                                <th>Procurement Activity</th>
                                <th style="text-align:center;">Qty</th>
                                <th style="text-align:center;">Unit</th>
                                <th style="text-align:right;">Unit Cost</th>
                                <th style="text-align:right;">Amt. Unobligated</th>
                                <th style="text-align:right;">Amt. Obligated</th>
                                <th>Period</th>
                                <th>Procurement Date</th>
                                <th style="text-align:center;">Status</th>
                                <th>Remarks</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($procurements)): ?>
                                <tr><td colspan="12" style="text-align:center;padding:20px;color:#94a3b8;">No procurement activities recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($procurements as $index => $p): 
                                    $pStart = !empty($p['period_start']) ? date('M Y', strtotime($p['period_start'])) : 'TBD';
                                    $pEnd   = !empty($p['period_end'])   ? date('M Y', strtotime($p['period_end']))   : 'TBD';
                                    $pDate  = !empty($p['proc_date'])    ? date('M d, Y', strtotime($p['proc_date'])) : 'TBD';
                                    $alloc    = !empty($p['obligated_amount']) ? $p['obligated_amount'] : ($p['unit_cost'] * $p['quantity']);
                                    $dbStatus = $p['status'] ?? 'on_process';
                                    $isObligated = $dbStatus === 'obligated';
                                    [$pStatus, $badge] = match($dbStatus) {
                                        'obligated' => ['Obligated', 'badge-green'],
                                        'cancelled' => ['Cancelled', 'badge-red'],
                                        default     => ['On Process', 'badge-amber'],
                                    };
                                ?>
                                <tr>
                                    <td style="color:#cbd5e1;font-weight:700;font-size:11px;"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></td>
                                    <td><span style="font-weight:700;color:#1d4ed8;font-size:11px;"><?= htmlspecialchars($p['object_code_str']) ?></span></td>
                                    <td><p style="font-weight:600;color:#0f172a;font-size:12px;"><?= htmlspecialchars($p['pro_act'] ?? '—') ?></p></td>
                                    <td style="text-align:center;font-weight:700;color:#0f172a;"><?= htmlspecialchars($p['quantity']) ?></td>
                                    <td style="text-align:center;color:#64748b;"><?= htmlspecialchars($p['unit'] ?? '—') ?></td>
                                    <td style="text-align:right;font-weight:600;color:#334155;">₱<?= number_format((float)$p['unit_cost'], 2) ?></td>
                                    <td style="text-align:right;">
                                        <?php if (!$isObligated): ?>
                                            <span style="font-weight:700;color:#b45309;font-size:12px;">₱<?= number_format((float)$alloc, 2) ?></span>
                                        <?php else: ?>
                                            <span style="font-size:11px;color:#cbd5e1;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if ($isObligated): ?>
                                            <span style="font-weight:700;color:#16a34a;font-size:12px;">₱<?= number_format((float)$alloc, 2) ?></span>
                                        <?php else: ?>
                                            <span style="font-size:11px;color:#cbd5e1;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="period-range">
                                            <span><?= $pStart ?></span>
                                            <span class="period-sep">—</span>
                                            <span><?= $pEnd ?></span>
                                        </div>
                                    </td>
                                    <td style="color:#64748b;"><?= $pDate ?></td>
                                    <td style="text-align:center;"><span class="badge <?= $badge ?>"><span class="badge-dot"></span><?= $pStatus ?></span></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <span style="color:#94a3b8;font-size:11px;"><?= htmlspecialchars($p['remarks'] ?: '—') ?></span>
                                            <?php if ($canManageSaro): ?>
                                            <button class="action-btn action-btn-edit" title="Edit Remarks" style="width:22px;height:22px;border-radius:5px;flex-shrink:0;" onclick="openRemarksModal(<?= $p['procurementId'] ?>,'<?= htmlspecialchars(addslashes($p['remarks'] ?? '')) ?>')">
                                                <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;align-items:center;justify-content:center;gap:4px;">
                                            <?php if ($canManageSaro): ?>
                                            <button class="action-btn action-btn-edit" title="Edit" onclick="openEditProcModal(<?= $p['procurementId'] ?>,'<?= htmlspecialchars($p['object_code_str'],ENT_QUOTES) ?>','<?= htmlspecialchars(addslashes($p['pro_act'] ?? ''),ENT_QUOTES) ?>','<?= $p['quantity'] ?>','<?= htmlspecialchars($p['unit'] ?? '',ENT_QUOTES) ?>','<?= $p['unit_cost'] ?>','<?= date('F', strtotime($p['period_start'] ?? 'now')) ?>','<?= date('Y', strtotime($p['period_start'] ?? 'now')) ?>','<?= date('F', strtotime($p['period_end'] ?? 'now')) ?>','<?= date('Y', strtotime($p['period_end'] ?? 'now')) ?>','<?= $p['proc_date'] ?>','<?= htmlspecialchars(addslashes($p['remarks'] ?? ''),ENT_QUOTES) ?>')">
                                                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </button>
                                            <button class="action-btn action-btn-del" title="Delete" onclick="openDeleteProcModal(<?= $p['procurementId'] ?>,'<?= htmlspecialchars(addslashes($p['pro_act'] ?? ''),ENT_QUOTES) ?>')">
                                                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                            <?php else: ?>
                                            <span style="font-size:10px;color:#cbd5e1;font-style:italic;">View only</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="padding:14px 24px;border-top:1px solid #f1f5f9;background:#fafbfe;display:flex;align-items:center;justify-content:space-between;">
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">
                        Displaying <strong style="color:#475569;"><?= count($procurements) ?></strong> active <?= count($procurements) === 1 ? 'activity' : 'activities' ?>
                    </p>
                    <div style="display:flex;align-items:center;gap:20px;">
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Overall Total Budget</p>
                            <p style="font-size:15px;font-weight:900;color:#1d4ed8;letter-spacing:-0.02em;">₱<?= number_format($totalBudget, 2) ?></p>
                        </div>
                        <div style="width:1px;height:32px;background:#e2e8f0;"></div>
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Remaining Balance</p>
                            <p style="font-size:15px;font-weight:900;color:#16a34a;letter-spacing:-0.02em;">₱<?= number_format($unobligated, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($cancelledProcurements)): ?>
            <div style="background:#fff;border:1px solid #fecaca;border-radius:16px;overflow:hidden;margin-top:20px;">
                <div style="padding:14px 24px;border-bottom:1px solid #fee2e2;display:flex;align-items:center;gap:10px;background:#fff7f7;">
                    <div style="width:30px;height:30px;border-radius:8px;background:#fee2e2;display:flex;align-items:center;justify-content:center;">
                        <svg width="14" height="14" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </div>
                    <div>
                        <p style="font-size:13px;font-weight:800;color:#dc2626;">Cancelled Activities</p>
                        <p style="font-size:10px;color:#f87171;font-weight:500;"><?= count($cancelledProcurements) ?> cancelled procurement <?= count($cancelledProcurements) === 1 ? 'activity' : 'activities' ?></p>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="background:#fff7f7;">
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;width:40px;text-align:left;">No.</th>
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;text-align:left;">Object Code</th>
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;text-align:left;">Activity</th>
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;text-align:center;">Qty</th>
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;text-align:center;">Unit</th>
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;text-align:right;">Unit Cost</th>
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;text-align:left;">Period</th>
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;text-align:left;">Proc. Date</th>
                                <th style="padding:10px 14px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f87171;text-align:left;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cancelledProcurements as $ci => $cp):
                                $cpStart = !empty($cp['period_start']) ? date('M Y', strtotime($cp['period_start'])) : 'TBD';
                                $cpEnd   = !empty($cp['period_end'])   ? date('M Y', strtotime($cp['period_end']))   : 'TBD';
                                $cpDate  = !empty($cp['proc_date'])    ? date('M d, Y', strtotime($cp['proc_date'])) : 'TBD';
                            ?>
                            <tr style="border-top:1px solid #fee2e2;opacity:0.8;">
                                <td style="padding:11px 14px;font-size:11px;font-weight:700;color:#f87171;"><?= str_pad($ci + 1, 2, '0', STR_PAD_LEFT) ?></td>
                                <td style="padding:11px 14px;font-size:11px;font-weight:700;color:#1d4ed8;"><?= htmlspecialchars($cp['object_code_str']) ?></td>
                                <td style="padding:11px 14px;font-size:12px;font-weight:600;color:#6b7280;text-decoration:line-through;"><?= htmlspecialchars($cp['pro_act'] ?? '—') ?></td>
                                <td style="padding:11px 14px;font-size:12px;color:#9ca3af;text-align:center;"><?= htmlspecialchars($cp['quantity']) ?></td>
                                <td style="padding:11px 14px;font-size:12px;color:#9ca3af;text-align:center;"><?= htmlspecialchars($cp['unit'] ?? '—') ?></td>
                                <td style="padding:11px 14px;font-size:12px;color:#9ca3af;text-align:right;">₱<?= number_format((float)$cp['unit_cost'], 2) ?></td>
                                <td style="padding:11px 14px;font-size:11px;color:#9ca3af;"><?= $cpStart ?> — <?= $cpEnd ?></td>
                                <td style="padding:11px 14px;font-size:11px;color:#9ca3af;"><?= $cpDate ?></td>
                                <td style="padding:11px 14px;font-size:11px;color:#9ca3af;"><?= htmlspecialchars($cp['remarks'] ?: '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /content -->
    </main>
</div>

<!-- ══ Add Activity Modal ══ -->
<div id="procModal" style="display:none;position:fixed;inset:0;z-index:100;
     background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:18px;width:100%;max-width:680px;
                box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden;">
        <div style="padding:22px 28px;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;
                    background:linear-gradient(135deg,#1e3a8a,#2563eb);">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                           text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">New Entry</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Add Procurement Activity</h3>
            </div>
            <button onclick="closeProcModal()" style="width:32px;height:32px;border-radius:8px;
                    background:rgba(255,255,255,0.12);border:none;cursor:pointer;
                    display:flex;align-items:center;justify-content:center;color:#fff;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div style="padding:24px 28px;display:flex;flex-direction:column;gap:16px;max-height:70vh;overflow-y:auto;">
            <input type="hidden" id="proc-is-travel" value="0">
            <input type="hidden" id="proc-all-docs-checked" value="0">
            <div>
                <label class="form-label">Object Code <span style="color:#dc2626;">*</span></label>
                <select class="form-input" id="proc-obj-code" onchange="handleProcObjChange(this); if(this.value!==''){this.classList.remove('input-error');document.getElementById('err-proc-obj-code').style.display='none';}else{this.classList.add('input-error');document.getElementById('err-proc-obj-code').style.display='block';}">
                    <option value="">Select object code…</option>
                    <?php foreach ($objectCodes as $obj): ?>
                        <option value="<?= (int)$obj['objectId'] ?>" data-travel="<?= (int)$obj['is_travelExpense'] ?>">
                            <?= htmlspecialchars($obj['code']) ?><?= !empty($obj['expense_items']) ? ' — '.htmlspecialchars(mb_strimwidth($obj['expense_items'],0,38,'...')) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-error" id="err-proc-obj-code">Object code is required!</p>
            </div>
            <div>
                <label class="form-label">Procurement Activity <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-input" id="proc-activity" placeholder="e.g. Laptop Computer (Core i7)" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-proc-activity').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-proc-activity').style.display='none'; }">
                <p class="field-error" id="err-proc-activity">Activity name is required!</p>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Quantity <span style="color:#dc2626;">*</span></label>
                    <input type="number" class="form-input" id="proc-qty" placeholder="0" min="1" oninput="updateBudgetAlloc(); if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-proc-qty').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-proc-qty').style.display='none'; }">
                    <p class="field-error" id="err-proc-qty">Quantity is required!</p>
                </div>
                <div>
                    <label class="form-label">Unit <span style="font-size:9px;color:#94a3b8;font-weight:500;text-transform:none;">(optional)</span></label>
                    <select class="form-input" id="proc-unit">
                        <option value="">— None —</option>
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                        <option>Pack</option><option>Trip</option><option>Batch</option>
                        <option>Session</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Unit Cost (₱) <span style="color:#dc2626;">*</span></label>
                    <input type="number" class="form-input" id="proc-unit-cost" placeholder="0.00" min="0" step="0.01" oninput="updateBudgetAlloc(); if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-proc-unit-cost').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-proc-unit-cost').style.display='none'; }">
                    <p class="field-error" id="err-proc-unit-cost">Unit cost is required!</p>
                </div>
            </div>
            <!-- Budget allocation live display -->
            <div id="proc-budget-alloc-wrap" style="display:none;padding:10px 14px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:0.08em;">Budget Allocation (Qty × Unit Cost)</span>
                    <span id="proc-budget-alloc-val" style="font-size:15px;font-weight:900;color:#1d4ed8;letter-spacing:-0.02em;">₱0.00</span>
                </div>
                <div id="proc-budget-warning" style="display:none;margin-top:8px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:7px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <svg width="13" height="13" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span style="font-size:11px;font-weight:600;color:#b91c1c;">Exceeds projected cost of <span id="proc-projected-cost-val">₱0.00</span> — projected cost is just an estimate.</span>
                        </div>
                        <button type="button" onclick="dismissCostWarning()"
                                style="padding:3px 10px;border-radius:6px;border:1px solid #fca5a5;background:#fee2e2;color:#b91c1c;font-size:10px;font-weight:700;font-family:'Poppins',sans-serif;cursor:pointer;white-space:nowrap;flex-shrink:0;">
                            Proceed Anyway
                        </button>
                    </div>
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Period <span style="color:#dc2626;">*</span></label>
                <?php if (!empty($saro['valid_until'])): ?>
                <p style="font-size:10px;color:#92400e;font-weight:600;margin:0 0 8px 0;line-height:1.45;background:#fef3c7;padding:8px 10px;border-radius:8px;border:1px solid #fde68a;">
                    SARO is valid until <strong><?= htmlspecialchars(date('M j, Y', strtotime($saro['valid_until']))) ?></strong>.
                    Period start/end and procurement date cannot extend beyond that date — adjust below or lengthen validity under SARO Details.
                </p>
                <?php endif; ?>
                <div class="period-pair" style="margin-top:4px;">
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">Period Start</span>
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;">
                            <select class="form-input" id="proc-start-month" onchange="if(this.value!==''){this.classList.remove('input-error');document.getElementById('err-proc-start-month').style.display='none';}else{this.classList.add('input-error');document.getElementById('err-proc-start-month').style.display='block';}">
                                <option value="">Select Month</option>
                                <option>January</option><option>February</option><option>March</option>
                                <option>April</option><option>May</option><option>June</option>
                                <option>July</option><option>August</option><option>September</option>
                                <option>October</option><option>November</option><option>December</option>
                            </select>
                            <input type="number" class="form-input" id="proc-start-year" value="<?= htmlspecialchars($saro['fiscal_year']) ?>" min="2020" max="2099" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-proc-start-year').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-proc-start-year').style.display='none'; }">
                        </div>
                        <p class="field-error" id="err-proc-start-month">Start month is required!</p>
                        <p class="field-error" id="err-proc-start-year">Start year is required!</p>
                    </div>
                    <div class="period-label-sep" style="padding-top:20px;">—</div>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">Period End</span>
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;">
                            <select class="form-input" id="proc-end-month" onchange="if(this.value!==''){this.classList.remove('input-error');document.getElementById('err-proc-end-month').style.display='none';}else{this.classList.add('input-error');document.getElementById('err-proc-end-month').style.display='block';}">
                                <option value="">Select Month</option>
                                <option>January</option><option>February</option><option>March</option>
                                <option>April</option><option>May</option><option>June</option>
                                <option>July</option><option>August</option><option>September</option>
                                <option>October</option><option>November</option><option>December</option>
                            </select>
                            <input type="number" class="form-input" id="proc-end-year" value="<?= htmlspecialchars($saro['fiscal_year']) ?>" min="2020" max="2099" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-proc-end-year').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-proc-end-year').style.display='none'; }">
                        </div>
                        <p class="field-error" id="err-proc-end-month">End month is required!</p>
                        <p class="field-error" id="err-proc-end-year">End year is required!</p>
                    </div>
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Date <span style="color:#dc2626;">*</span></label>
                <input type="date" class="form-input" id="proc-date" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-proc-date').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-proc-date').style.display='none'; }">
                <p class="field-error" id="err-proc-date">Procurement date is required!</p>
            </div>
            <div>
                <label class="form-label">Remarks</label>
                <input type="text" class="form-input" id="proc-remarks" placeholder="Optional notes…">
            </div>
            <!-- Travel note (shown when OC is travel expense) -->
            <div id="travel-note-section" style="display:none;border:1.5px solid #3b82f6;border-radius:12px;padding:14px 16px;background:#eff6ff;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <svg width="15" height="15" fill="none" stroke="#3b82f6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px;font-weight:600;color:#1d4ed8;line-height:1.5;">
                        <strong>Travel Expense</strong> — Required documents will be checked in
                        <em>View Procurement Activities</em>. This activity will be saved as <strong>On Process</strong> and obligation happens after all travel documents are verified there.
                    </p>
                </div>
            </div>
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;
                    display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="closeProcModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveProcActivity()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save Activity
            </button>
        </div>
    </div>
</div>

<!-- ══ Edit Activity Modal ══ -->
<div id="editProcModal" style="display:none;position:fixed;inset:0;z-index:100;
     background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:18px;width:100%;max-width:680px;
                box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden;">
        <div style="padding:22px 28px;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;
                    background:linear-gradient(135deg,#1e3a8a,#2563eb);">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                           text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">Edit Record</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Edit Procurement Activity</h3>
            </div>
            <button onclick="closeEditProcModal()" style="width:32px;height:32px;border-radius:8px;
                    background:rgba(255,255,255,0.12);border:none;cursor:pointer;
                    display:flex;align-items:center;justify-content:center;color:#fff;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div style="padding:24px 28px;display:flex;flex-direction:column;gap:16px;max-height:70vh;overflow-y:auto;">
            <input type="hidden" id="edit-proc-id">
            <div>
                <label class="form-label">Object Code <span style="color:#dc2626;">*</span></label>
                <select class="form-input" id="ep-obj-code" onchange="if(this.value!==''){this.classList.remove('input-error');document.getElementById('err-ep-obj-code').style.display='none';}else{this.classList.add('input-error');document.getElementById('err-ep-obj-code').style.display='block';}">
                    <option value="">Select object code…</option>
                    <?php foreach ($objectCodes as $obj): ?>
                        <option value="<?= htmlspecialchars($obj['code'],ENT_QUOTES) ?>">
                            <?= htmlspecialchars($obj['code']) ?><?= !empty($obj['expense_items']) ? ' — '.htmlspecialchars(mb_strimwidth($obj['expense_items'],0,30,'...')) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-error" id="err-ep-obj-code">Object code is required!</p>
            </div>
            <div>
                <label class="form-label">Procurement Activity <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-input" id="ep-activity" placeholder="e.g. Laptop Computer (Core i7)" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-ep-activity').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-ep-activity').style.display='none'; }">
                <p class="field-error" id="err-ep-activity">Activity name is required!</p>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Quantity <span style="color:#dc2626;">*</span></label>
                    <input type="number" class="form-input" id="ep-qty" placeholder="0" min="1" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-ep-qty').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-ep-qty').style.display='none'; }">
                    <p class="field-error" id="err-ep-qty">Quantity is required!</p>
                </div>
                <div>
                    <label class="form-label">Unit <span style="font-size:9px;color:#94a3b8;font-weight:500;text-transform:none;">(optional)</span></label>
                    <select class="form-input" id="ep-unit">
                        <option value="">— None —</option>
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                        
                    </select>
                </div>
                <div>
                    <label class="form-label">Unit Cost (₱) <span style="color:#dc2626;">*</span></label>
                    <input type="number" class="form-input" id="ep-unit-cost" placeholder="0.00" min="0" step="0.01" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-ep-unit-cost').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-ep-unit-cost').style.display='none'; }">
                    <p class="field-error" id="err-ep-unit-cost">Unit cost is required!</p>
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Period <span style="color:#dc2626;">*</span></label>
                <?php if (!empty($saro['valid_until'])): ?>
                <p style="font-size:10px;color:#92400e;font-weight:600;margin:0 0 8px 0;line-height:1.45;background:#fef3c7;padding:8px 10px;border-radius:8px;border:1px solid #fde68a;">
                    SARO is valid until <strong><?= htmlspecialchars(date('M j, Y', strtotime($saro['valid_until']))) ?></strong>.
                    Period start/end and procurement date cannot extend beyond that date — adjust below or lengthen validity under SARO Details.
                </p>
                <?php endif; ?>
                <div class="period-pair" style="margin-top:4px;">
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">Period Start</span>
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;">
                            <select class="form-input" id="ep-start-month" onchange="if(this.value!==''){this.classList.remove('input-error');document.getElementById('err-ep-start-month').style.display='none';}else{this.classList.add('input-error');document.getElementById('err-ep-start-month').style.display='block';}">
                                <option value="">Select Month</option>
                                <option>January</option><option>February</option><option>March</option>
                                <option>April</option><option>May</option><option>June</option>
                                <option>July</option><option>August</option><option>September</option>
                                <option>October</option><option>November</option><option>December</option>
                            </select>
                            <input type="number" class="form-input" id="ep-start-year" min="2020" max="2099" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-ep-start-year').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-ep-start-year').style.display='none'; }">
                        </div>
                        <p class="field-error" id="err-ep-start-month">Start month is required!</p>
                        <p class="field-error" id="err-ep-start-year">Start year is required!</p>
                    </div>
                    <div class="period-label-sep" style="padding-top:20px;">—</div>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">Period End</span>
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;">
                            <select class="form-input" id="ep-end-month" onchange="if(this.value!==''){this.classList.remove('input-error');document.getElementById('err-ep-end-month').style.display='none';}else{this.classList.add('input-error');document.getElementById('err-ep-end-month').style.display='block';}">
                                <option value="">Select Month</option>
                                <option>January</option><option>February</option><option>March</option>
                                <option>April</option><option>May</option><option>June</option>
                                <option>July</option><option>August</option><option>September</option>
                                <option>October</option><option>November</option><option>December</option>
                            </select>
                            <input type="number" class="form-input" id="ep-end-year" min="2020" max="2099" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-ep-end-year').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-ep-end-year').style.display='none'; }">
                        </div>
                        <p class="field-error" id="err-ep-end-month">End month is required!</p>
                        <p class="field-error" id="err-ep-end-year">End year is required!</p>
                    </div>
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Date <span style="color:#dc2626;">*</span></label>
                <input type="date" class="form-input" id="ep-date" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-ep-date').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-ep-date').style.display='none'; }">
                <p class="field-error" id="err-ep-date">Procurement date is required!</p>
            </div>
            <div>
                <label class="form-label">Remarks</label>
                <input type="text" class="form-input" id="ep-remarks" placeholder="Optional notes…">
            </div>
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;
                    display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="closeEditProcModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEditProc()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ══ Edit SARO Modal ══ -->
<div id="editSaroModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header-blue">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">Edit Record</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Edit SARO Details</h3>
            </div>
            <button class="modal-close-btn" onclick="closeEditSaroModal()">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div>
                <label class="form-label">SARO No.</label>
                <input type="text" class="form-input" id="edit-saro-no" readonly value="<?= htmlspecialchars($saro['saroNo']) ?>" style="background:#f1f5f9;color:#64748b;cursor:not-allowed;">
            </div>
            <div>
                <label class="form-label">SARO Title <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-input" id="edit-saro-title" value="<?= htmlspecialchars($saro['saro_title']) ?>" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-edit-saro-title').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-edit-saro-title').style.display='none'; }">
                <p class="field-error" id="err-edit-saro-title">SARO title is required!</p>
            </div>
            <div>
                <label class="form-label">Fiscal Year <span style="color:#dc2626;">*</span></label>
                <input type="number" class="form-input" id="edit-fiscal-year" value="<?= htmlspecialchars($saro['fiscal_year']) ?>" min="2020" max="2099" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-edit-fiscal-year').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-edit-fiscal-year').style.display='none'; }">
                <p class="field-error" id="err-edit-fiscal-year">Fiscal year is required!</p>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label class="form-label">Date Released <span style="color:#dc2626;">*</span></label>
                    <input type="date" class="form-input" id="edit-date-released" value="<?= htmlspecialchars($saro['date_released'] ?? '') ?>" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-edit-date-released').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-edit-date-released').style.display='none'; }">
                    <p class="field-error" id="err-edit-date-released">Date released is required!</p>
                </div>
                <div>
                    <label class="form-label">Valid Until <span style="color:#dc2626;">*</span></label>
                    <input type="date" class="form-input" id="edit-valid-until" value="<?= htmlspecialchars($saro['valid_until'] ?? '') ?>" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-edit-valid-until').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-edit-valid-until').style.display='none'; }">
                    <p class="field-error" id="err-edit-valid-until">Valid until is required!</p>
                </div>
            </div>
            <div>
                <label class="form-label">Total Budget (₱) <span style="color:#dc2626;">*</span></label>
                <input type="number" class="form-input" id="edit-total-budget" value="<?= htmlspecialchars($saro['total_budget']) ?>" min="0" step="0.01" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-edit-total-budget').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-edit-total-budget').style.display='none'; }">
                <p class="field-error" id="err-edit-total-budget">Total budget is required!</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeEditSaroModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEditSaro()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ══ Add Object Code Modal ══ -->
<div id="addObjModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header-blue">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">New Entry</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Add Object Code</h3>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" onclick="addObjRowView()"
                        style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;
                               border:1.5px solid rgba(255,255,255,0.35);background:rgba(255,255,255,0.12);
                               color:#fff;font-size:11px;font-weight:700;font-family:'Poppins',sans-serif;
                               cursor:pointer;transition:all 0.2s ease;"
                        onmouseover="this.style.background='rgba(255,255,255,0.25)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                    <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                    Add Row
                </button>
                <button class="modal-close-btn" onclick="closeAddObjModal()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="modal-body">
            <!-- Header row -->
            <div style="display:grid;grid-template-columns:1fr 120px 1fr 76px 32px;gap:8px;
                        padding:6px 10px;background:#f8fafc;border:1px solid #e8edf5;
                        border-radius:8px 8px 0 0;border-bottom:none;margin-bottom:0;">
                <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Object Code <span style="color:#dc2626;">*</span></p>
                <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Projected Cost (₱)</p>
                <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Expense Item <span style="color:#dc2626;">*</span></p>
                <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Travel?</p>
                <span></span>
            </div>
            <!-- Rows container -->
            <div id="objCodeListView" style="border:1px solid #e8edf5;border-radius:0 0 8px 8px;overflow:hidden;margin-top:0;">
            </div>
            <p id="objViewEmptyHint" style="font-size:11px;color:#94a3b8;margin-top:4px;">Click "Add Row" to add object codes.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeAddObjModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveObjCodes()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save Object Codes
            </button>
        </div>
    </div>
</div>

<!-- ══ Edit Object Code Modal ══ -->
<div id="editObjModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header-blue">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">Edit Record</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Edit Object Code</h3>
            </div>
            <button class="modal-close-btn" onclick="closeEditObjModal()">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit-obj-id">
            <div>
                <label class="form-label">Object Code <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-input" id="edit-obj-code" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-edit-obj-code').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-edit-obj-code').style.display='none'; }">
                <p class="field-error" id="err-edit-obj-code">Object code is required!</p>
            </div>
            <div>
                <label class="form-label">Projected Cost (₱) <span style="color:#dc2626;">*</span></label>
                <input type="number" class="form-input" id="edit-obj-cost" min="0" step="0.01" oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-edit-obj-cost').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-edit-obj-cost').style.display='none'; }">
                <p class="field-error" id="err-edit-obj-cost">Projected cost is required!</p>
            </div>
            <div>
                <label class="form-label">Expense Item</label>
                <input type="text" class="form-input" id="edit-obj-expense-item" placeholder="Enter expense item description…">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeEditObjModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEditObj()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ══ Delete Object Code Modal ══ -->
<div id="deleteObjModal" class="modal-overlay">
    <div class="modal-card" style="max-width:400px;">
        <div style="padding:32px 28px 24px;display:flex;flex-direction:column;align-items:center;gap:16px;text-align:center;">
            <input type="hidden" id="delete-obj-id">
            <div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;border:1px solid #fecaca;
                        display:flex;align-items:center;justify-content:center;color:#dc2626;">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </div>
            <div>
                <h3 style="font-size:16px;font-weight:800;color:#0f172a;margin-bottom:8px;">Delete Object Code</h3>
                <p style="font-size:13px;color:#64748b;line-height:1.6;">
                    Are you sure you want to delete object code <span id="delete-obj-label" style="font-weight:700;color:#0f172a;"></span>? This action cannot be undone.
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeDeleteObjModal()">Cancel</button>
            <button class="btn btn-primary" style="background:#dc2626;border-color:#dc2626;" onclick="confirmDeleteObj()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Delete
            </button>
        </div>
    </div>
</div>

<!-- ══ Edit Remarks Modal ══ -->
<div id="remarksModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header-blue">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">Edit</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Edit Remarks</h3>
            </div>
            <button class="modal-close-btn" onclick="closeRemarksModal()">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div>
                <label class="form-label">Remarks</label>
                <textarea class="form-input" id="remarks-textarea" style="min-height:80px;" placeholder="Enter remarks…"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeRemarksModal()">Cancel</button>
            <button class="btn btn-primary" id="saveRemarksBtn">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save Remarks
            </button>
        </div>
    </div>
</div>

<!-- ══ Delete Procurement Modal ══ -->
<div id="deleteProcModal" class="modal-overlay">
    <div class="modal-card" style="max-width:400px;">
        <div style="padding:32px 28px 24px;display:flex;flex-direction:column;align-items:center;gap:16px;text-align:center;">
            <input type="hidden" id="delete-proc-id">
            <div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;border:1px solid #fecaca;
                        display:flex;align-items:center;justify-content:center;color:#dc2626;">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div>
                <h3 style="font-size:16px;font-weight:800;color:#0f172a;margin-bottom:8px;">Delete Procurement Activity</h3>
                <p style="font-size:13px;color:#64748b;line-height:1.6;">
                    Are you sure you want to delete <strong id="delete-proc-label" style="color:#0f172a;"></strong>? This action cannot be undone.
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeDeleteProcModal()">Cancel</button>
            <button class="btn btn-primary" style="background:#dc2626;border-color:#dc2626;" onclick="confirmDeleteProc()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Delete
            </button>
        </div>
    </div>
</div>

<script src="../assets/js/fullcalendar-6.1.11.index.global.min.js"></script>
<script>
    const currentSaroId = <?= (int)$saroId ?>;
    const canManageSaro = <?= $canManageSaro ? 'true' : 'false' ?>;
    const objCodesData  = <?= $objCodesJson ?>;
    const travelDocsCount = <?= count($travelDocs) ?>;
    const saroValidUntil = "<?= htmlspecialchars($saro['valid_until'] ?? '') ?>";
    const calendarEventsData = <?= $calendarEventsJson ?>;

    const MONTH_NAMES_CAL = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function monthYearFirstIso(monthName, yearStr) {
        const mi = MONTH_NAMES_CAL.indexOf(monthName);
        const y = parseInt(yearStr, 10);
        if (mi < 0 || !Number.isFinite(y)) return null;
        const mm = String(mi + 1).padStart(2, '0');
        return y + '-' + mm + '-01';
    }

    function monthYearLastIso(monthName, yearStr) {
        const mi = MONTH_NAMES_CAL.indexOf(monthName);
        const y = parseInt(yearStr, 10);
        if (mi < 0 || !Number.isFinite(y)) return null;
        const d = new Date(y, mi + 1, 0);
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    /** Plain-language reason or null if OK vs SARO valid-until and period order */
    function procurementOutsideSaroValidity(procDateIso, startMonthName, startYearStr, endMonthName, endYearStr) {
        const vu = (typeof saroValidUntil === 'string') ? saroValidUntil.trim() : '';
        const sm = startMonthName || ''; const sy = String(startYearStr || '').trim();
        const em = endMonthName || ''; const ey = String(endYearStr || '').trim();
        const pFirst = monthYearFirstIso(sm, sy);
        const pLast = monthYearLastIso(em, ey);
        if (pFirst && pLast && pFirst > pLast) {
            return 'Procurement period start must come before or line up with the period end (the start month cannot be after the end month/year range).';
        }
        if (!vu) return null;
        if (pFirst && pFirst > vu) {
            return 'Procurement period start exceeds SARO validity.\nYour period begins on ' + pFirst + ', after the SARO valid-until date (' + vu + ').';
        }
        if (pLast && pLast > vu) {
            return 'Procurement period end exceeds SARO validity.\nYour period covers through ' + pLast + ', but the SARO is only valid until ' + vu + '.';
        }
        const pd = procDateIso ? procDateIso.trim() : '';
        if (pd && pd > vu) {
            return 'Procurement activity date (' + pd + ') cannot be after SARO valid-until (' + vu + ').';
        }
        return null;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────
    function getVal(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }
    function setVal(id, val) { const el = document.getElementById(id); if (el) el.value = (val ?? ''); }
    function setOpt(id, val) {
        const el = document.getElementById(id); if (!el) return;
        [...el.options].forEach(o => { o.selected = o.value === String(val) || o.text === String(val); });
    }
    function setFieldError(fieldId, errId, msg) {
        const f = document.getElementById(fieldId);
        const e = document.getElementById(errId);
        if (f) { f.classList.toggle('input-error', !!msg); }
        if (e) { e.textContent = msg; e.style.display = msg ? 'block' : 'none'; }
    }
    function clearErrors(...pairs) { pairs.forEach(([f, e]) => setFieldError(f, e, '')); }
    function postAjax(data) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => {
            if (Array.isArray(v)) v.forEach(i => fd.append(k + '[]', i));
            else fd.append(k, v);
        });
        return fetch(location.href, { method: 'POST', body: fd }).then(r => r.json());
    }

    // ─── Notification dropdown ────────────────────────────────────────────────
    // ─── Add Activity Modal ───────────────────────────────────────────────────
    function openProcModal() {
        if (!canManageSaro) {
            openWarningModal('This SARO is in view-only mode for your account. Only the creator can add, edit, or delete data here.', 'View only');
            return;
        }
        setVal('proc-obj-code', ''); setVal('proc-activity', '');
        setVal('proc-qty', ''); setVal('proc-unit', ''); setVal('proc-unit-cost', '');
        setVal('proc-date', ''); setVal('proc-remarks', '');
        document.getElementById('proc-is-travel').value = '0';
        document.getElementById('travel-note-section').style.display = 'none';
        document.getElementById('proc-budget-alloc-wrap').style.display = 'none';
        document.getElementById('proc-budget-warning').style.display = 'none';
        clearErrors(['proc-obj-code','err-proc-obj-code'],['proc-activity','err-proc-activity'],
                    ['proc-qty','err-proc-qty'],['proc-unit-cost','err-proc-unit-cost'],
                    ['proc-start-month','err-proc-start-month'],['proc-start-year','err-proc-start-year'],
                    ['proc-end-month','err-proc-end-month'],['proc-end-year','err-proc-end-year'],
                    ['proc-date','err-proc-date']);
        document.getElementById('procModal').style.display = 'flex';
    }
    function closeProcModal() { document.getElementById('procModal').style.display = 'none'; }
    document.getElementById('procModal').addEventListener('click', function(e) {
        if (e.target === this) closeProcModal();
    });

    function handleProcObjChange(sel) {
        const opt = sel.options[sel.selectedIndex];
        const isTravel = opt && opt.dataset.travel === '1';
        document.getElementById('proc-is-travel').value = isTravel ? '1' : '0';
        document.getElementById('travel-note-section').style.display = isTravel ? '' : 'none';
        updateBudgetAlloc();
    }

    function updateBudgetAlloc() {
        const objId = parseInt(getVal('proc-obj-code')) || 0;
        const qty   = parseFloat(getVal('proc-qty'))       || 0;
        const cost  = parseFloat(getVal('proc-unit-cost')) || 0;
        const alloc = qty * cost;
        const wrap  = document.getElementById('proc-budget-alloc-wrap');
        const warnEl = document.getElementById('proc-budget-warning');

        if (qty > 0 && cost > 0) {
            wrap.style.display = '';
            document.getElementById('proc-budget-alloc-val').textContent =
                '₱' + alloc.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            const oc = objCodesData.find(o => o.objectId === objId);
            if (oc && oc.projected_cost > 0 && alloc > oc.projected_cost) {
                document.getElementById('proc-projected-cost-val').textContent =
                    '₱' + oc.projected_cost.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
                warnEl.style.display = '';
            } else {
                warnEl.style.display = 'none';
            }
        } else {
            wrap.style.display = 'none';
        }
    }

    function dismissCostWarning() {
        document.getElementById('proc-budget-warning').style.display = 'none';
    }

    function saveProcActivity() {
        clearErrors(['proc-obj-code','err-proc-obj-code'],['proc-activity','err-proc-activity'],
                    ['proc-qty','err-proc-qty'],['proc-unit-cost','err-proc-unit-cost'],
                    ['proc-start-month','err-proc-start-month'],['proc-start-year','err-proc-start-year'],
                    ['proc-end-month','err-proc-end-month'],['proc-end-year','err-proc-end-year'],
                    ['proc-date','err-proc-date']);
        let ok = true;
        
        if (!getVal('proc-obj-code'))     { setFieldError('proc-obj-code','err-proc-obj-code','Object code is required!'); ok = false; }
        if (!getVal('proc-activity'))     { setFieldError('proc-activity','err-proc-activity','Activity name is required!'); ok = false; }
        if (!getVal('proc-qty') || parseInt(getVal('proc-qty')) < 1) { setFieldError('proc-qty','err-proc-qty','Quantity is required!'); ok = false; }
        if (getVal('proc-unit-cost') === '' || parseFloat(getVal('proc-unit-cost')) < 0) { setFieldError('proc-unit-cost','err-proc-unit-cost','Unit cost is required!'); ok = false; }
        
        if (!getVal('proc-start-month')) { setFieldError('proc-start-month','err-proc-start-month','Start month is required!'); ok = false; }
        if (!getVal('proc-start-year'))  { setFieldError('proc-start-year','err-proc-start-year','Start year is required!'); ok = false; }
        if (!getVal('proc-end-month'))   { setFieldError('proc-end-month','err-proc-end-month','End month is required!'); ok = false; }
        if (!getVal('proc-end-year'))    { setFieldError('proc-end-year','err-proc-end-year','End year is required!'); ok = false; }
        if (!getVal('proc-date'))        { setFieldError('proc-date','err-proc-date','Procurement date is required!'); ok = false; }
        
        if (!ok) return;

        const smAdd = document.getElementById('proc-start-month').value.trim();
        const emAdd = document.getElementById('proc-end-month').value.trim();
        const pvErr = procurementOutsideSaroValidity(getVal('proc-date'), smAdd, getVal('proc-start-year'), emAdd, getVal('proc-end-year'));
        if (pvErr) {
            const vuT = (saroValidUntil || '').trim();
            const p0 = monthYearFirstIso(smAdd, getVal('proc-start-year'));
            const p1 = monthYearLastIso(emAdd, getVal('proc-end-year'));
            clearErrors(['proc-start-month','err-proc-start-month'],['proc-start-year','err-proc-start-year'],['proc-end-month','err-proc-end-month'],['proc-end-year','err-proc-end-year'],['proc-date','err-proc-date']);
            if (p0 && p1 && p0 > p1) {
                setFieldError('proc-end-month', 'err-proc-end-month', 'After period start');
                setFieldError('proc-end-year', 'err-proc-end-year', 'Adjust year');
            } else if (vuT && p0 && p0 > vuT) {
                setFieldError('proc-start-month', 'err-proc-start-month', 'Begins after SARO expiry');
                setFieldError('proc-start-year', 'err-proc-start-year', ' ');
            } else if (vuT && p1 && p1 > vuT) {
                setFieldError('proc-end-month', 'err-proc-end-month', 'Ends after SARO expiry');
                setFieldError('proc-end-year', 'err-proc-end-year', ' ');
            } else if (vuT && getVal('proc-date') > vuT) {
                setFieldError('proc-date', 'err-proc-date', 'After SARO expiry');
            }
            openWarningModal(pvErr + '\n\nShorten or move the procurement period and date, or extend “Valid Until” in SARO Details.', 'Cannot save — SARO validity limit');
            return;
        }

        // Frontend Sanitization to prevent XSS
        const sanitize = (str) => {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML.replace(/<[^>]*>?/gm, '');
        };

        postAjax({
            ajax_action: 'add_procurement', saroId: currentSaroId,
            objectId: getVal('proc-obj-code'), pro_act: sanitize(getVal('proc-activity')),
            quantity: getVal('proc-qty'), unit: sanitize(document.getElementById('proc-unit').value),
            unit_cost: getVal('proc-unit-cost'),
            period_start_month: sanitize(document.getElementById('proc-start-month').value),
            period_start_year: sanitize(getVal('proc-start-year')),
            period_end_month: sanitize(document.getElementById('proc-end-month').value),
            period_end_year: sanitize(getVal('proc-end-year')),
            proc_date: sanitize(getVal('proc-date')), remarks: sanitize(getVal('proc-remarks')),
            is_travelExpense: document.getElementById('proc-is-travel').value,
        }).then(d => {
            if (d.success) location.reload();
            else openWarningModal(d.error || 'Save failed.', 'Could not save activity');
        }).catch(() => openWarningModal('Network error — try again.', 'Connection problem'));
    }

    // ─── Edit Activity Modal ──────────────────────────────────────────────────
    function openEditProcModal(procId, objCode, activity, qty, unit, unitCost, startMonth, startYear, endMonth, endYear, date, remarks) {
        if (!canManageSaro) {
            openWarningModal('This SARO is in view-only mode for your account. Only the creator can add, edit, or delete data here.', 'View only');
            return;
        }
        clearErrors(['ep-obj-code','err-ep-obj-code'],['ep-activity','err-ep-activity'],
                    ['ep-qty','err-ep-qty'],['ep-unit-cost','err-ep-unit-cost'],
                    ['ep-start-month','err-ep-start-month'],['ep-start-year','err-ep-start-year'],
                    ['ep-end-month','err-ep-end-month'],['ep-end-year','err-ep-end-year'],
                    ['ep-date','err-ep-date']);
        setVal('edit-proc-id', procId);
        setOpt('ep-obj-code', objCode);
        setVal('ep-activity', activity);
        setVal('ep-qty', qty);
        setOpt('ep-unit', unit);
        setVal('ep-unit-cost', unitCost);
        setOpt('ep-start-month', startMonth);
        setVal('ep-start-year', startYear);
        setOpt('ep-end-month', endMonth);
        setVal('ep-end-year', endYear);
        setVal('ep-date', date);
        setVal('ep-remarks', remarks);
        document.getElementById('editProcModal').style.display = 'flex';
    }
    function closeEditProcModal() { document.getElementById('editProcModal').style.display = 'none'; }
    document.getElementById('editProcModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditProcModal();
    });

    function saveEditProc() {
        const procId = getVal('edit-proc-id'); if (!procId) return;

        clearErrors(['ep-obj-code','err-ep-obj-code'],['ep-activity','err-ep-activity'],
                    ['ep-qty','err-ep-qty'],['ep-unit-cost','err-ep-unit-cost'],
                    ['ep-start-month','err-ep-start-month'],['ep-start-year','err-ep-start-year'],
                    ['ep-end-month','err-ep-end-month'],['ep-end-year','err-ep-end-year'],
                    ['ep-date','err-ep-date']);
        let ok = true;
        
        if (!getVal('ep-obj-code'))     { setFieldError('ep-obj-code','err-ep-obj-code','Object code is required!'); ok = false; }
        if (!getVal('ep-activity'))     { setFieldError('ep-activity','err-ep-activity','Activity name is required!'); ok = false; }
        if (!getVal('ep-qty') || parseInt(getVal('ep-qty')) < 1) { setFieldError('ep-qty','err-ep-qty','Quantity is required!'); ok = false; }
        if (getVal('ep-unit-cost') === '' || parseFloat(getVal('ep-unit-cost')) < 0) { setFieldError('ep-unit-cost','err-ep-unit-cost','Unit cost is required!'); ok = false; }
        
        if (!getVal('ep-start-month')) { setFieldError('ep-start-month','err-ep-start-month','Start month is required!'); ok = false; }
        if (!getVal('ep-start-year'))  { setFieldError('ep-start-year','err-ep-start-year','Start year is required!'); ok = false; }
        if (!getVal('ep-end-month'))   { setFieldError('ep-end-month','err-ep-end-month','End month is required!'); ok = false; }
        if (!getVal('ep-end-year'))    { setFieldError('ep-end-year','err-ep-end-year','End year is required!'); ok = false; }
        if (!getVal('ep-date'))        { setFieldError('ep-date','err-ep-date','Procurement date is required!'); ok = false; }
        
        if (!ok) return;

        const smEd = document.getElementById('ep-start-month').value.trim();
        const emEd = document.getElementById('ep-end-month').value.trim();
        const pvEd = procurementOutsideSaroValidity(getVal('ep-date'), smEd, getVal('ep-start-year'), emEd, getVal('ep-end-year'));
        if (pvEd) {
            const vuT = (saroValidUntil || '').trim();
            const p0 = monthYearFirstIso(smEd, getVal('ep-start-year'));
            const p1 = monthYearLastIso(emEd, getVal('ep-end-year'));
            clearErrors(['ep-start-month','err-ep-start-month'],['ep-start-year','err-ep-start-year'],['ep-end-month','err-ep-end-month'],['ep-end-year','err-ep-end-year'],['ep-date','err-ep-date']);
            if (p0 && p1 && p0 > p1) {
                setFieldError('ep-end-month', 'err-ep-end-month', 'After period start');
                setFieldError('ep-end-year', 'err-ep-end-year', 'Adjust year');
            } else if (vuT && p0 && p0 > vuT) {
                setFieldError('ep-start-month', 'err-ep-start-month', 'Begins after SARO expiry');
                setFieldError('ep-start-year', 'err-ep-start-year', ' ');
            } else if (vuT && p1 && p1 > vuT) {
                setFieldError('ep-end-month', 'err-ep-end-month', 'Ends after SARO expiry');
                setFieldError('ep-end-year', 'err-ep-end-year', ' ');
            } else if (vuT && getVal('ep-date') > vuT) {
                setFieldError('ep-date', 'err-ep-date', 'After SARO expiry');
            }
            openWarningModal(pvEd + '\n\nShorten or move the procurement period and date, or extend “Valid Until” in SARO Details.', 'Cannot save — SARO validity limit');
            return;
        }

        // Frontend Sanitization to prevent XSS
        const sanitize = (str) => {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML.replace(/<[^>]*>?/gm, '');
        };

        postAjax({
            ajax_action: 'edit_procurement', saroId: currentSaroId, procurementId: procId,
            pro_act: sanitize(getVal('ep-activity')), quantity: getVal('ep-qty'),
            unit: sanitize(document.getElementById('ep-unit').value), unit_cost: getVal('ep-unit-cost'),
            period_start_month: sanitize(document.getElementById('ep-start-month').value),
            period_start_year: sanitize(getVal('ep-start-year')),
            period_end_month: sanitize(document.getElementById('ep-end-month').value),
            period_end_year: sanitize(getVal('ep-end-year')),
            proc_date: sanitize(getVal('ep-date')), remarks: sanitize(getVal('ep-remarks')),
        }).then(d => {
            if (d.success) location.reload();
            else openWarningModal(d.error || 'Save failed.', 'Could not update activity');
        }).catch(() => openWarningModal('Network error — try again.', 'Connection problem'));
    }

    // ─── Edit SARO Modal ──────────────────────────────────────────────────────
    function openEditSaroModal() { 
        if (!canManageSaro) {
            openWarningModal('Only the SARO creator can edit SARO details.', 'View only');
            return;
        }
        clearErrors(
            ['edit-saro-title', 'err-edit-saro-title'],
            ['edit-fiscal-year', 'err-edit-fiscal-year'],
            ['edit-date-released', 'err-edit-date-released'],
            ['edit-valid-until', 'err-edit-valid-until'],
            ['edit-total-budget', 'err-edit-total-budget']
        );
        document.getElementById('editSaroModal').classList.add('open'); 
    }
    function closeEditSaroModal() { document.getElementById('editSaroModal').classList.remove('open'); }
    document.getElementById('editSaroModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditSaroModal();
    });

    function saveEditSaro() {
        clearErrors(
            ['edit-saro-title', 'err-edit-saro-title'],
            ['edit-fiscal-year', 'err-edit-fiscal-year'],
            ['edit-date-released', 'err-edit-date-released'],
            ['edit-valid-until', 'err-edit-valid-until'],
            ['edit-total-budget', 'err-edit-total-budget']
        );
        
        let ok = true;
        if (!getVal('edit-saro-title')) { setFieldError('edit-saro-title', 'err-edit-saro-title', 'SARO title is required!'); ok = false; }
        if (!getVal('edit-fiscal-year')) { setFieldError('edit-fiscal-year', 'err-edit-fiscal-year', 'Fiscal year is required!'); ok = false; }
        if (!getVal('edit-date-released')) { setFieldError('edit-date-released', 'err-edit-date-released', 'Date released is required!'); ok = false; }
        if (!getVal('edit-valid-until')) { setFieldError('edit-valid-until', 'err-edit-valid-until', 'Valid until is required!'); ok = false; }
        if (!getVal('edit-total-budget')) { setFieldError('edit-total-budget', 'err-edit-total-budget', 'Total budget is required!'); ok = false; }
        
        if (!ok) return;

        // Frontend Sanitization to prevent XSS
        const sanitize = (str) => {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML.replace(/<[^>]*>?/gm, '');
        };

        postAjax({
            ajax_action: 'edit_saro', saroId: currentSaroId,
            saro_title: sanitize(getVal('edit-saro-title')), fiscal_year: sanitize(getVal('edit-fiscal-year')),
            date_released: sanitize(getVal('edit-date-released')), valid_until: sanitize(getVal('edit-valid-until')),
            total_budget: sanitize(getVal('edit-total-budget')),
        }).then(d => {
            if (d.success) location.reload();
            else openWarningModal(d.error || 'Save failed.', 'Could not update SARO');
        }).catch(() => openWarningModal('Network error — try again.', 'Connection problem'));
    }

    // ─── Add Object Code Modal ────────────────────────────────────────────────
    function openAddObjModal() {
        if (!canManageSaro) {
            openWarningModal('Only the SARO creator can add object codes.', 'View only');
            return;
        }
        document.getElementById('addObjModal').classList.add('open');
    }
    function closeAddObjModal() {
        document.getElementById('addObjModal').classList.remove('open');
        document.getElementById('objCodeListView').innerHTML = '';
        const hint = document.getElementById('objViewEmptyHint');
        if (hint) hint.style.display = '';
    }
    document.getElementById('addObjModal').addEventListener('click', function(e) {
        if (e.target === this) closeAddObjModal();
    });

    function addObjRowView() {
        const list = document.getElementById('objCodeListView');
        const hint = document.getElementById('objViewEmptyHint');
        if (hint) hint.style.display = 'none';
        const row = document.createElement('div');
        row.className = 'obj-row';
        row.style.cssText = 'background:#fff;border-bottom:1px solid #f1f5f9;transition:background 0.15s ease;padding:10px;';
        row.onmouseenter = () => row.style.background = '#f5f8ff';
        row.onmouseleave = () => row.style.background = '#fff';
        const inp = 'width:100%;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-family:\'Poppins\',sans-serif;font-weight:500;color:#0f172a;background:#f8fafc;outline:none;transition:all 0.2s ease;';
        const foc = "onfocus=\"this.style.borderColor='#3b82f6';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.1)'\"";
        const blr = "onblur=\"if(!this.classList.contains('input-error')){this.style.borderColor='#e2e8f0';this.style.background='#f8fafc';this.style.boxShadow='none'}\"";
        const onInpCode = "oninput=\"if(this.value.trim()!==''){this.classList.remove('input-error');this.nextElementSibling.style.display='none';this.style.borderColor='#3b82f6';this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.1)';}else{this.classList.add('input-error');this.nextElementSibling.style.display='block';this.style.borderColor='#dc2626';this.style.boxShadow='0 0 0 3px rgba(220,38,38,0.1)';}\"";
        
        row.innerHTML = `<div style="display:grid;grid-template-columns:1fr 120px 1fr auto 32px;gap:8px;align-items:start;">
            <div>
                <input type="text" class="obj-code-inp" placeholder="e.g. 5-02-03-070" style="${inp}" ${foc} ${blr} ${onInpCode}>
                <p class="field-error" style="margin-top:4px;display:none;">Object code is required!</p>
            </div>
            <div>
                <input type="number" class="obj-cost-inp" placeholder="0.00" min="0" step="0.01" style="${inp}" ${foc} ${blr}>
            </div>
            <div>
                <input type="text" class="obj-exp-inp" placeholder="e.g. ICT Equipment" style="${inp}" ${foc} ${blr} ${onInpCode.replace('Object code', 'Expense item')}>
                <p class="field-error" style="margin-top:4px;display:none;">Expense item is required!</p>
            </div>
            <label style="display:flex;align-items:center;gap:5px;font-size:10px;font-weight:600;color:#64748b;white-space:nowrap;cursor:pointer;padding-top:7px;">
                <input type="checkbox" class="obj-trv-inp" style="width:13px;height:13px;accent-color:#3b82f6;cursor:pointer;"> Travel
            </label>
            <button type="button" onclick="removeObjRowView(this)" title="Remove row"
                    style="margin-top:2px;width:28px;height:28px;border-radius:6px;border:1px solid transparent;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;justify-content:center;transition:all 0.2s ease;flex-shrink:0;"
                    onmouseenter="this.style.background='#fee2e2';this.style.borderColor='#fecaca';this.style.color='#dc2626'"
                    onmouseleave="this.style.background='transparent';this.style.borderColor='transparent';this.style.color='#94a3b8'">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>
        </div>`;
        list.appendChild(row);
        row.querySelector('.obj-code-inp').focus();
    }

    function removeObjRowView(btn) {
        const list = document.getElementById('objCodeListView');
        btn.closest('.obj-row').remove();
        if (list.children.length === 0) {
            const hint = document.getElementById('objViewEmptyHint');
            if (hint) hint.style.display = '';
        }
    }

    function saveObjCodes() {
        const rows = document.querySelectorAll('#objCodeListView > div.obj-row');
        const items = [];
        let ok = true;
        
        rows.forEach(row => {
            const codeInp = row.querySelector('.obj-code-inp');
            const costInp = row.querySelector('.obj-cost-inp');
            const expInp  = row.querySelector('.obj-exp-inp');
            const trvInp  = row.querySelector('.obj-trv-inp');
            
            const code = codeInp.value.trim();
            const exp = expInp.value.trim();
            
            if (code === '') {
                codeInp.classList.add('input-error');
                codeInp.style.borderColor = '#dc2626';
                codeInp.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.1)';
                codeInp.nextElementSibling.style.display = 'block';
                ok = false;
            }
            if (exp === '') {
                expInp.classList.add('input-error');
                expInp.style.borderColor = '#dc2626';
                expInp.style.boxShadow = '0 0 0 3px rgba(220,38,38,0.1)';
                expInp.nextElementSibling.style.display = 'block';
                ok = false;
            }
            
            if (code !== '' && exp !== '') {
                items.push({ code, cost: parseFloat(costInp.value) || 0, expense_item: exp, is_travel: trvInp.checked ? 1 : 0 });
            }
        });
        
        if (rows.length === 0) { openWarningModal('Add at least one object code row.', 'Nothing to save'); return; }
        if (!ok) return;

        // Frontend Sanitization to prevent XSS
        const sanitize = (str) => {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML.replace(/<[^>]*>?/gm, '');
        };
        items.forEach(it => {
            it.code = sanitize(it.code);
            it.expense_item = sanitize(it.expense_item);
        });

        postAjax({ ajax_action: 'add_object_codes', saroId: currentSaroId, items: JSON.stringify(items) })
            .then(d => {
                if (d.success) location.reload();
                else openWarningModal(d.error || 'Save failed.', 'Could not add object codes');
            })
            .catch(() => openWarningModal('Network error — try again.', 'Connection problem'));
    }

    // ─── Edit Object Code Modal ───────────────────────────────────────────────
    function openEditObjModal(objectId, code, cost, item) {
        if (!canManageSaro) {
            openWarningModal('Only the SARO creator can edit object codes.', 'View only');
            return;
        }
        clearErrors(['edit-obj-code','err-edit-obj-code'], ['edit-obj-cost','err-edit-obj-cost']);
        setVal('edit-obj-id', objectId);
        setVal('edit-obj-code', code);
        setVal('edit-obj-cost', cost);
        setVal('edit-obj-expense-item', item || '');
        document.getElementById('editObjModal').classList.add('open');
        setTimeout(() => document.getElementById('edit-obj-code').focus(), 100);
    }
    function closeEditObjModal() { document.getElementById('editObjModal').classList.remove('open'); }
    document.getElementById('editObjModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditObjModal();
    });

    function saveEditObj() {
        const objectId = getVal('edit-obj-id'); if (!objectId) return;
        
        clearErrors(['edit-obj-code','err-edit-obj-code'], ['edit-obj-cost','err-edit-obj-cost']);
        let ok = true;
        if (!getVal('edit-obj-code')) { setFieldError('edit-obj-code', 'err-edit-obj-code', 'Object code is required!'); ok = false; }
        if (!getVal('edit-obj-cost')) { setFieldError('edit-obj-cost', 'err-edit-obj-cost', 'Projected cost is required!'); ok = false; }
        
        if (!ok) return;

        // Frontend Sanitization to prevent XSS
        const sanitize = (str) => {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML.replace(/<[^>]*>?/gm, '');
        };

        postAjax({
            ajax_action: 'edit_object_code', saroId: currentSaroId, objectId,
            code: sanitize(getVal('edit-obj-code')), projected_cost: getVal('edit-obj-cost'),
            expense_item: sanitize(getVal('edit-obj-expense-item')),
        }).then(d => {
            if (d.success) location.reload();
            else openWarningModal(d.error || 'Save failed.', 'Could not update object code');
        }).catch(() => openWarningModal('Network error — try again.', 'Connection problem'));
    }

    // ─── Delete Object Code Modal ─────────────────────────────────────────────
    function openDeleteObjModal(objectId, code) {
        if (!canManageSaro) {
            openWarningModal('Only the SARO creator can delete object codes.', 'View only');
            return;
        }
        setVal('delete-obj-id', objectId);
        document.getElementById('delete-obj-label').textContent = code;
        document.getElementById('deleteObjModal').classList.add('open');
    }
    function closeDeleteObjModal() { document.getElementById('deleteObjModal').classList.remove('open'); }
    document.getElementById('deleteObjModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteObjModal();
    });

    function confirmDeleteObj() {
        const objectId = getVal('delete-obj-id'); if (!objectId) return;
        postAjax({ ajax_action: 'delete_object_code', saroId: currentSaroId, objectId })
            .then(d => {
                if (d.success) location.reload();
                else openWarningModal(d.error || 'Delete failed.', 'Could not delete object code');
            })
            .catch(() => openWarningModal('Network error — try again.', 'Connection problem'));
    }

    // ─── Edit Remarks Modal ───────────────────────────────────────────────────
    let _remarksProcId = null;
    function openRemarksModal(procId, text) {
        if (!canManageSaro) {
            openWarningModal('Only the SARO creator can edit remarks.', 'View only');
            return;
        }
        _remarksProcId = procId;
        document.getElementById('remarks-textarea').value = text;
        document.getElementById('remarksModal').classList.add('open');
    }
    function closeRemarksModal() { document.getElementById('remarksModal').classList.remove('open'); }
    document.getElementById('remarksModal').addEventListener('click', function(e) {
        if (e.target === this) closeRemarksModal();
    });
    document.getElementById('saveRemarksBtn').addEventListener('click', function() {
        if (!_remarksProcId) return;
        postAjax({ ajax_action: 'edit_remarks', saroId: currentSaroId, procurementId: _remarksProcId, remarks: document.getElementById('remarks-textarea').value })
            .then(d => {
                if (d.success) location.reload();
                else openWarningModal(d.error || 'Save failed.', 'Could not update remarks');
            })
            .catch(() => openWarningModal('Network error — try again.', 'Connection problem'));
    });

    // ─── Delete Procurement Modal ─────────────────────────────────────────────
    function openDeleteProcModal(procId, name) {
        if (!canManageSaro) {
            openWarningModal('Only the SARO creator can delete procurement activities.', 'View only');
            return;
        }
        setVal('delete-proc-id', procId);
        document.getElementById('delete-proc-label').textContent = name;
        document.getElementById('deleteProcModal').classList.add('open');
    }
    function closeDeleteProcModal() { document.getElementById('deleteProcModal').classList.remove('open'); }
    document.getElementById('deleteProcModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteProcModal();
    });

    function confirmDeleteProc() {
        const procId = getVal('delete-proc-id'); if (!procId) return;
        postAjax({ ajax_action: 'delete_procurement', saroId: currentSaroId, procurementId: procId })
            .then(d => {
                if (d.success) location.reload();
                else openWarningModal(d.error || 'Delete failed.', 'Could not delete activity');
            })
            .catch(() => openWarningModal('Network error — try again.', 'Connection problem'));
    }

    // Date / validity warning modal (shared)
    function openWarningModal(msg, title) {
        var th = document.getElementById('warning-modal-title');
        if (th) th.textContent = title || 'Validation Error';
        document.getElementById('warning-msg').textContent = msg || '';
        document.getElementById('warningModal').classList.add('open');
    }
    function closeWarningModal() {
        document.getElementById('warningModal').classList.remove('open');
    }
    function scheduleCalendarAndOverlaysInit() {
        const warningModalEl = document.getElementById('warningModal');
        if (warningModalEl) {
            warningModalEl.addEventListener('click', function(e) {
                if (e.target === this) closeWarningModal();
            });
        }

        const calendarEl = document.getElementById('calendar');
        if (!calendarEl) return;
        if (typeof FullCalendar === 'undefined') {
            calendarEl.innerHTML = '<p class="calendar-load-error" style="padding:24px 20px;line-height:1.6;color:#b45309;font-size:13px;">The schedule calendar script did not load. Enable JavaScript, hard-refresh this page, and confirm <code style="background:#fef3c7;padding:2px 6px;border-radius:4px;">assets/js/fullcalendar-6.1.11.index.global.min.js</code> exists on the server.</p>';
            return;
        }
        calendarEl.innerHTML = '';
        try {
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                initialDate: <?= json_encode(date('Y-m-d'), $jsonEmbedFlags) ?>,
                height: 620,
                fixedWeekCount: false,
                eventOverlap: false,
                dayMaxEvents: 4,
                moreLinkClick: 'popover',
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,dayGridWeek' },
                views: {
                    dayGridWeek: { dayMaxEvents: 8 },
                },
                events: Array.isArray(calendarEventsData) ? calendarEventsData : []
            });
            calendar.render();
        } catch (err) {
            calendarEl.innerHTML = '<p class="calendar-load-error" style="padding:24px 20px;line-height:1.6;color:#b45309;font-size:13px;">Could not start the calendar. If this persists, open the browser console (F12) for details.</p>';
            console.error(err);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleCalendarAndOverlaysInit);
    } else {
        scheduleCalendarAndOverlaysInit();
    }
</script>

<!-- Generic Warning Modal for Validation -->
<div class="modal-overlay" id="warningModal" style="z-index: 10000;">
    <div class="modal-card" style="max-width:400px;">
        <div style="padding:24px;text-align:center;">
            <div style="width:48px;height:48px;border-radius:50%;background:#fef2f2;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <svg width="24" height="24" fill="none" stroke="#ef4444" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <h3 id="warning-modal-title" style="font-size:18px;font-weight:800;color:#0f172a;margin-bottom:10px;">Validation Error</h3>
            <p id="warning-msg" style="font-size:13px;color:#475569;line-height:1.65;margin-bottom:24px;white-space:pre-line;text-align:left;"></p>
            <button class="btn-submit" onclick="closeWarningModal()">Understood</button>
        </div>
    </div>
</div>

<script src="../assets/js/table_controls.js"></script>
</body>
</html>