<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/procurement_status.php';

$username = $_SESSION['full_name'] ?? 'User';
$role     = $_SESSION['role'] ?? 'Role';
$initials = $_SESSION['initials'] ?? 'U';
$userId   = (int)($_SESSION['user_id'] ?? 0);

$db   = new Database();
$conn = $db->connect();

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if (!empty($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $ownsProcurement = static function (PDO $conn, int $procId, int $uid): bool {
        if ($procId <= 0 || $uid <= 0) return false;
        $st = $conn->prepare("
            SELECT 1
            FROM procurement p
            INNER JOIN object_code oc ON p.objectId = oc.objectId
            INNER JOIN saro s ON oc.saroId = s.saroId
            WHERE p.procurementId = ? AND s.userId = ?
        ");
        $st->execute([$procId, $uid]);
        return (bool)$st->fetchColumn();
    };

    if ($action === 'toggle_signature') {
        $procId   = (int)($_POST['procurementId'] ?? 0);
        $signId   = (int)($_POST['signId']        ?? 0);
        $curState = (int)($_POST['current_state'] ?? 0);

        if (!$procId || !$signId) {
            echo json_encode(['success' => false, 'error' => 'Missing params']);
            exit;
        }
        if (!$ownsProcurement($conn, $procId, $userId)) {
            echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can update signatures.']);
            exit;
        }

        if ($curState == 0) {
            $s = $conn->prepare("INSERT INTO proc_signatures (procurementId, signId, status) VALUES (?, ?, 'waived') ON DUPLICATE KEY UPDATE status='waived'");
            $s->execute([$procId, $signId]);
            $newState = 1;
        } else {
            $s = $conn->prepare("DELETE FROM proc_signatures WHERE procurementId=? AND signId=?");
            $s->execute([$procId, $signId]);
            $newState = 0;
        }

        $sc = $conn->prepare("SELECT COUNT(*) FROM proc_signatures WHERE procurementId=? AND status='waived'");
        $sc->execute([$procId]);
        $signed = (int)$sc->fetchColumn();
        $total  = (int)$conn->query("SELECT COUNT(*) FROM signatory_role WHERE applies_to_regular=1")->fetchColumn();

        $newStatus = ($total > 0 && $signed >= $total) ? 'obligated' : 'on_process';
        $conn->prepare("UPDATE procurement SET status=? WHERE procurementId=?")->execute([$newStatus, $procId]);

        echo json_encode(['success' => true, 'new_state' => $newState, 'status' => $newStatus]);
        exit;
    }

    if ($action === 'toggle_travel_doc') {
        $procId   = (int)($_POST['procurementId'] ?? 0);
        $docId    = (int)($_POST['documentId']    ?? 0);
        $curState = (int)($_POST['current_state'] ?? 0);

        if (!$procId || !$docId) {
            echo json_encode(['success' => false, 'error' => 'Missing params']);
            exit;
        }
        if (!$ownsProcurement($conn, $procId, $userId)) {
            echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can update required documents.']);
            exit;
        }

        if ($curState == 0) {
            $s = $conn->prepare("INSERT INTO proc_documents (procurementId, documentId, status) VALUES (?, ?, 'waived') ON DUPLICATE KEY UPDATE status='waived'");
            $s->execute([$procId, $docId]);
            $newState = 1;
        } else {
            $s = $conn->prepare("DELETE FROM proc_documents WHERE procurementId=? AND documentId=?");
            $s->execute([$procId, $docId]);
            $newState = 0;
        }

        $sc = $conn->prepare("SELECT COUNT(*) FROM proc_documents WHERE procurementId=?");
        $sc->execute([$procId]);
        $checked = (int)$sc->fetchColumn();
        $total   = (int)$conn->query("SELECT COUNT(*) FROM required_documents WHERE applies_to_travel=1")->fetchColumn();

        $newStatus = ($total > 0 && $checked >= $total) ? 'obligated' : 'on_process';
        $conn->prepare("UPDATE procurement SET status=? WHERE procurementId=?")->execute([$newStatus, $procId]);

        echo json_encode(['success' => true, 'new_state' => $newState, 'status' => $newStatus]);
        exit;
    }

    if ($action === 'cancel_procurement') {
        $procId = (int)($_POST['procurementId'] ?? 0);
        if (!$procId) { echo json_encode(['success' => false, 'error' => 'Missing procurementId']); exit; }
        if (!$ownsProcurement($conn, $procId, $userId)) {
            echo json_encode(['success' => false, 'error' => 'View-only mode: only the SARO owner can cancel procurement activities.']);
            exit;
        }

        $conn->prepare("UPDATE procurement SET status='cancelled' WHERE procurementId=?")->execute([$procId]);

        $userId = $_SESSION['user_id'] ?? null;
        $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
        $conn->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'cancelled', 'Cancelled procurement activity', 'procurement', ?, ?)")
             ->execute([$userId, $procId, $ip]);

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

$statusObj = new ProcurementStatus();

$saroId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$saroId) {
    header("Location: procurement_stat.php");
    exit;
}

$stmtSaro = $conn->prepare("SELECT * FROM saro WHERE saroId = ?");
$stmtSaro->execute([$saroId]);
$saro = $stmtSaro->fetch(PDO::FETCH_ASSOC);

if (!$saro) {
    die("SARO Not Found");
}
$canManageSaro = ((int)($saro['userId'] ?? 0) === $userId);

$stmtProc = $conn->prepare("
    SELECT p.*, oc.code AS object_code_str
    FROM procurement p
    JOIN object_code oc ON p.objectId = oc.objectId
    WHERE oc.saroId = ?
    ORDER BY p.created_at ASC
");
$stmtProc->execute([$saroId]);
$procurements = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../class/notification.php';
$notifObj      = new Notification();
$notifications = $notifObj->getRecentActivity((int)$_SESSION['user_id'], 10);
$unreadCount   = $notifObj->countUnread($userId);
$approvedPwReq = $notifObj->getApprovedPasswordNotification($userId);
$cancelledCount = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='cancelled'")->fetchColumn();
$obligatedCount = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='obligated'")->fetchColumn();
$lapsedCount    = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='lapsed'")->fetchColumn();

$totalSaroBudget      = (float)$saro['total_budget'];
$totalObligatedAmount = 0;
foreach ($procurements as $p) {
    if ($p['status'] === 'obligated') {
        $totalObligatedAmount += (float)($p['unit_cost'] ?? 0) * (int)($p['quantity'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Procurement Activities | DICT SARO Monitoring</title>
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

        .sidebar {
            width: 256px; flex-shrink: 0;
            display: flex; flex-direction: column;
            background: #0f172a; position: relative; overflow: hidden;
        }
        .sidebar::before {
            content: ''; position: absolute; top: -80px; right: -80px;
            width: 220px; height: 220px; background: #1e3a8a;
            border-radius: 50%; opacity: 0.4; pointer-events: none;
        }
        .sidebar::after {
            content: ''; position: absolute; bottom: -60px; left: -60px;
            width: 180px; height: 180px; background: #1d4ed8;
            border-radius: 50%; opacity: 0.2; pointer-events: none;
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

        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { height: 64px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: #fff; border-bottom: 1px solid #e8edf5; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #64748b; }
        .breadcrumb-active { color: #0f172a; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 9px; background: #f8fafc; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b; transition: all 0.2s ease; position: relative; }
        .icon-btn:hover { border-color: #3b82f6; color: #2563eb; background: #eff6ff; }
        .notif-dot { position: absolute; top: 7px; right: 7px; width: 7px; height: 7px; background: #ef4444; border-radius: 50%; border: 1.5px solid #fff; }
        .content { flex: 1; overflow-y: auto; padding: 28px 32px; }

        .saro-card { background: #fff; border: 1px solid #e8edf5; border-radius: 14px; padding: 20px 28px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 24px; position: relative; overflow: hidden; }
        .table-panel { background: #fff; border: 1px solid #e8edf5; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; }
        .panel-header { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }

        table { width: 100%; border-collapse: collapse; }
        thead tr.th-primary th { padding: 11px 14px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #fff; background: #0f172a; border-right: 1px solid rgba(255,255,255,0.08); white-space: nowrap; text-align: center; }
        thead tr.th-primary th:first-child { text-align: left; }
        thead tr.th-primary th.group-pr { background: #1e3a8a; }
        thead tr.th-primary th.group-po { background: #1e40af; }
        thead tr.th-secondary th { padding: 8px 12px; font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #cbd5e1; background: #1e293b; border-right: 1px solid rgba(255,255,255,0.06); border-bottom: 1px solid rgba(255,255,255,0.08); white-space: nowrap; text-align: center; }
        thead tr.th-secondary th.sub-pr { background: #1e3a8a; color: #bfdbfe; }
        thead tr.th-secondary th.sub-po { background: #1e40af; color: #bfdbfe; }

        tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.15s ease; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 13px 14px; font-size: 12px; color: #475569; border-right: 1px solid #f1f5f9; text-align: center; vertical-align: middle; }
        tbody td:first-child { text-align: left; }
        tbody td:last-child { border-right: none; }

        .doc-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 6px; background: #f1f5f9; border: 1px solid #e2e8f0; font-size: 10px; font-weight: 600; color: #475569; white-space: nowrap; }
        .doc-badge.missing { background:#fff7ed;border-color:#fed7aa;color:#92400e; }
        .doc-badge.checked { background:#dcfce7;border-color:#bbf7d0;color:#16a34a; }
        button.doc-badge { font-family: inherit; cursor: pointer; transition: opacity 0.15s; }
        button.doc-badge:hover { opacity: 0.8; }
        button.doc-badge:disabled { opacity: 0.5; cursor: not-allowed; }

        .sig-btn { width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.2s ease; }
        .sig-btn[data-state="1"] { background:#dcfce7;border:1.5px solid #bbf7d0;color:#16a34a; }
        .sig-btn[data-state="0"] { background:#fef9c3;border:1.5px solid #fde68a;color:#b45309; }
        .sig-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .action-btn { width:28px;height:28px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;background:transparent;transition:all 0.2s ease; }
        .action-btn-del { color:#94a3b8; }
        .action-btn-del:hover { background:#fee2e2;border-color:#fecaca;color:#dc2626; }

        .panel-footer { padding: 14px 24px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    </style>
</head>
<body>
<div class="layout">

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
            <a href="data_entry.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Data Entry
            </a>
            <a href="procurement_stat.php" class="nav-item active">
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

    <main class="main">
        <header class="topbar">
            <div class="breadcrumb">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m0 0l-7 7-7-7M19 10v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Home</span>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <a href="procurement_stat.php" style="text-decoration:none;color:inherit;">Procurement Status</a>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="breadcrumb-active">View Activities</span>
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

        <div class="content">
            <div class="saro-card">
                <div style="display:flex;align-items:center;gap:20px;flex:1;min-width:0;position:relative;z-index:1;">
                    <a href="procurement_stat.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;font-weight:600;color:#475569;text-decoration:none;flex-shrink:0;transition:all 0.2s ease;">
                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg> Back
                    </a>
                    <div style="width:1px;height:36px;background:#e8edf5;flex-shrink:0;"></div>
                    <div style="min-width:0;">
                        <p style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:3px;">SARO No.</p>
                        <p style="font-size:14px;font-weight:900;color:#0f172a;letter-spacing:-0.01em;"><?= htmlspecialchars($saro['saroNo']) ?></p>
                    </div>
                    <div style="width:1px;height:36px;background:#e8edf5;flex-shrink:0;"></div>
                    <div style="min-width:0;flex:1;">
                        <p style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:3px;">SARO Title</p>
                        <p style="font-size:13px;font-weight:600;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($saro['saro_title']) ?></p>
                    </div>
                </div>
                <div style="flex-shrink:0;text-align:right;padding-left:24px;border-left:1px solid #e8edf5;position:relative;z-index:1;">
                    <p style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:3px;">Total Budget</p>
                    <p style="font-size:20px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;">₱<?= number_format($totalSaroBudget, 2) ?></p>
                </div>
            </div>

            <div class="table-panel">
                <div class="panel-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#eff6ff;display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">Procurement Activities</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">PR &amp; PO signature tracking per activity</p>
                        </div>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr class="th-primary">
                                <th rowspan="2" style="text-align:left;min-width:160px;">Procurement</th>
                                <th rowspan="2" style="min-width:160px;">Required Documents</th>
                                <th colspan="4" class="group-pr" style="text-align:center;border-left:2px solid rgba(255,255,255,0.15);">Status of Purchase Request (PR)</th>
                                <th colspan="3" class="group-po" style="text-align:center;border-left:2px solid rgba(255,255,255,0.15);">Status of Purchase Order (PO)</th>
                                <th rowspan="2" style="text-align:right;min-width:130px;">Amt. Unobligated</th>
                                <th rowspan="2" style="text-align:right;min-width:130px;">Amt. Obligated</th>
                                <th rowspan="2" style="text-align:left;min-width:160px;">Remarks</th>
                                <th rowspan="2" style="text-align:center;min-width:80px;">Actions</th>
                            </tr>
                            <tr class="th-secondary">
                                <th class="sub-pr" style="min-width:80px;border-left:2px solid rgba(255,255,255,0.12);">Budget Officer<br>Signature</th>
                                <th class="sub-pr" style="min-width:80px;">End-User<br>Signature</th>
                                <th class="sub-pr" style="min-width:80px;">BAC Chair<br>Signature</th>
                                <th class="sub-pr" style="min-width:80px;">RD<br>Signature</th>
                                <th class="sub-po" style="min-width:80px;border-left:2px solid rgba(255,255,255,0.12);">PO<br>Creation</th>
                                <th class="sub-po" style="min-width:80px;">Finance<br>Signature</th>
                                <th class="sub-po" style="min-width:80px;">Conforme<br>Signature</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($procurements)): ?>
                                <tr><td colspan="13" style="text-align:center;color:#94a3b8;padding:20px;">No procurement activities found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($procurements as $p):
                                    $procBudget   = (float)($p['unit_cost'] ?? 0) * (int)($p['quantity'] ?? 0);
                                    $isTravel     = (bool)$p['is_travelExpense'];
                                    $isCancelled  = ($p['status'] === 'cancelled');
                                    $isObligated  = ($p['status'] === 'obligated');
                                    $docs = $statusObj->getProcurementDocuments($p['procurementId'], $isTravel);
                                    $sigs = ($isTravel || $isCancelled) ? [] : $statusObj->getSignatures($p['procurementId']);
                                ?>
                                <tr data-proc-id="<?= (int)$p['procurementId'] ?>"
                                    data-amount="<?= $procBudget ?>"
                                    data-status="<?= htmlspecialchars($p['status']) ?>"
                                    style="<?= $isCancelled ? 'opacity:0.55;background:#fafafa;' : '' ?>">

                                    <td>
                                        <p style="font-weight:700;color:#0f172a;font-size:12px;"><?= htmlspecialchars($p['pro_act'] ?? '—') ?></p>
                                        <p style="font-size:10px;color:#94a3b8;font-weight:500;margin-top:2px;">Object Code: <?= htmlspecialchars($p['object_code_str']) ?></p>
                                        <?php if ($isTravel): ?>
                                        <span style="display:inline-flex;align-items:center;gap:3px;margin-top:4px;padding:2px 7px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:9px;font-weight:700;color:#1d4ed8;">
                                            ✈ Travel
                                        </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Required Documents -->
                                    <td>
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <?php if (empty($docs)): ?>
                                                <span class="doc-badge missing">No Documents Set</span>
                                            <?php else: ?>
                                                <?php foreach ($docs as $doc): ?>
                                                    <?php if ($isTravel): ?>
                                                        <button class="doc-badge <?= $doc['is_checked'] ? 'checked' : 'missing' ?> doc-cb-btn"
                                                                data-proc-id="<?= (int)$p['procurementId'] ?>"
                                                                data-doc-id="<?= (int)$doc['documentId'] ?>"
                                                                data-doc-name="<?= htmlspecialchars($doc['document_name']) ?>"
                                                                data-state="<?= (int)$doc['is_checked'] ?>"
                                                                <?= $canManageSaro ? '' : 'disabled title="View only"' ?>>
                                                            <?php if ($doc['is_checked']): ?>
                                                                <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                            <?php else: ?>
                                                                <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                            <?php endif; ?>
                                                            <?= htmlspecialchars($doc['document_name']) ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="doc-badge <?= $doc['is_checked'] ? 'checked' : 'missing' ?>">
                                                            <?php if ($doc['is_checked']): ?>
                                                                <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                            <?php else: ?>
                                                                <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                            <?php endif; ?>
                                                            <?= htmlspecialchars($doc['document_name']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- 7 Signature columns -->
                                    <?php for ($i = 0; $i < 7; $i++): ?>
                                        <?php if ($isTravel || $isCancelled): ?>
                                            <td><span style="color:#cbd5e1;font-size:11px;">—</span></td>
                                        <?php else:
                                            $sig    = $sigs[$i] ?? null;
                                            $state  = $sig ? (int)$sig['is_signed'] : 0;
                                            $signId = $sig ? (int)$sig['signId'] : 0;
                                        ?>
                                            <td>
                                                <button class="sig-btn"
                                                        data-state="<?= $state ?>"
                                                        data-proc-id="<?= (int)$p['procurementId'] ?>"
                                                        data-sign-id="<?= $signId ?>"
                                                        title="<?= $canManageSaro ? 'Toggle Status' : 'View only' ?>"
                                                        <?= $canManageSaro ? '' : 'disabled' ?>>
                                                    <?php if ($state == 1): ?>
                                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    <?php else: ?>
                                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <?php endif; ?>
                                                </button>
                                            </td>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <!-- Amt. Unobligated (first) -->
                                    <td class="cell-unobligated" style="text-align:right;">
                                        <?php if ($isCancelled || $isObligated): ?>
                                            <span style="color:#94a3b8;">—</span>
                                        <?php else: ?>
                                            <span style="font-weight:700;color:#d97706;font-size:12px;">₱<?= number_format($procBudget, 2) ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Amt. Obligated (second) -->
                                    <td class="cell-obligated" style="text-align:right;">
                                        <?php if ($isCancelled): ?>
                                            <span style="color:#94a3b8;">—</span>
                                        <?php elseif ($isObligated): ?>
                                            <span style="font-weight:700;color:#16a34a;font-size:12px;">₱<?= number_format($procBudget, 2) ?></span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="text-align:left;">
                                        <span style="font-size:11px;color:#64748b;"><?= htmlspecialchars($p['remarks'] ?: 'No remarks.') ?></span>
                                    </td>

                                    <!-- Actions -->
                                    <td class="cell-actions" style="text-align:center;">
                                        <?php if ($isCancelled): ?>
                                            <span style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;background:#fee2e2;border:1px solid #fecaca;border-radius:5px;font-size:9px;font-weight:700;color:#dc2626;">
                                                Cancelled
                                            </span>
                                        <?php elseif (!$canManageSaro): ?>
                                            <span style="font-size:10px;color:#cbd5e1;font-style:italic;">View only</span>
                                        <?php else: ?>
                                            <button class="cancel-btn"
                                                    data-proc-id="<?= (int)$p['procurementId'] ?>"
                                                    data-proc-name="<?= htmlspecialchars(addslashes($p['pro_act'] ?? '')) ?>"
                                                    title="Cancel Procurement Activity"
                                                    style="width:28px;height:28px;border-radius:7px;border:1px solid #fecaca;background:#fee2e2;color:#dc2626;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s ease;">
                                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel-footer" style="flex-direction:column;gap:12px;align-items:stretch;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <p style="font-size:11px;color:#94a3b8;font-weight:500;">
                            Displaying <strong style="color:#475569;"><?= count($procurements) ?></strong> procurement activities
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:flex-end;gap:20px;padding-top:10px;border-top:1px solid #f1f5f9;">
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Total Obligated</p>
                            <p id="footer-total-obligated" style="font-size:15px;font-weight:900;color:#1d4ed8;letter-spacing:-0.02em;">₱<?= number_format($totalObligatedAmount, 2) ?></p>
                        </div>
                        <div style="width:1px;height:32px;background:#e2e8f0;"></div>
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Total Budget</p>
                            <p style="font-size:15px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;">₱<?= number_format($totalSaroBudget, 2) ?></p>
                        </div>
                        <div style="width:1px;height:32px;background:#e2e8f0;"></div>
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Remaining Budget</p>
                            <p id="footer-remaining" style="font-size:15px;font-weight:900;color:#16a34a;letter-spacing:-0.02em;">₱<?= number_format($totalSaroBudget - $totalObligatedAmount, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</div>
    </main>
</div>

<!-- ══ Cancel Modal ══ -->
<div id="cancelModal" style="display:none;position:fixed;inset:0;z-index:100;
     background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:18px;width:100%;max-width:400px;
                box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden;">
        <div style="padding:22px 28px;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;
                    background:linear-gradient(135deg,#dc2626,#ef4444);">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                           text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">Confirmation</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Cancel Activity</h3>
            </div>
            <button onclick="closeCancelModal()" style="width:32px;height:32px;border-radius:8px;
                    background:rgba(255,255,255,0.12);border:none;cursor:pointer;
                    display:flex;align-items:center;justify-content:center;color:#fff;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div style="padding:24px 28px;display:flex;flex-direction:column;gap:16px;text-align:center;">
            <input type="hidden" id="cancel-proc-id">
            <svg width="48" height="48" fill="none" stroke="#ef4444" viewBox="0 0 24 24" style="margin:0 auto;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p style="font-size:14px;color:#334155;">Are you sure you want to cancel <strong id="cancel-proc-name"></strong>?</p>
            <p style="font-size:12px;color:#94a3b8;">This action cannot be undone.</p>
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;
                    display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button onclick="closeCancelModal()" style="padding:10px 16px;border-radius:10px;border:1px solid #e2e8f0;background:#fff;color:#64748b;font-size:12px;font-weight:700;cursor:pointer;">Keep Activity</button>
            <button onclick="confirmCancel()" id="confirm-cancel-btn" style="padding:10px 16px;border-radius:10px;border:none;background:#ef4444;color:#fff;font-size:12px;font-weight:700;cursor:pointer;">Yes, Cancel it</button>
        </div>
    </div>
</div>

<script>
    const checkSvg12 = `<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>`;
    const clockSvg12 = `<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
    const checkSvg10 = `<svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;
    const clockSvg10 = `<svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
    const saroBudget = <?= (float)$totalSaroBudget ?>;
    const canManageSaro = <?= $canManageSaro ? 'true' : 'false' ?>;

    function fmtMoney(n) {
        return '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function updateRowAmounts(procId, status) {
        const row = document.querySelector(`tr[data-proc-id="${procId}"]`);
        if (!row) return;
        row.dataset.status = status;
        const amt = parseFloat(row.dataset.amount) || 0;
        const fmt = fmtMoney(amt);

        if (status === 'cancelled') {
            row.querySelector('.cell-unobligated').innerHTML = '<span style="color:#94a3b8;">—</span>';
            row.querySelector('.cell-obligated').innerHTML   = '<span style="color:#94a3b8;">—</span>';
            row.style.opacity    = '0.55';
            row.style.background = '#fafafa';
            row.querySelectorAll('.sig-btn').forEach(b => { b.disabled = true; b.style.opacity = '0.35'; });
            row.querySelectorAll('.doc-cb-btn').forEach(b => { b.disabled = true; b.style.opacity = '0.35'; });
            const ac = row.querySelector('.cell-actions');
            if (ac) ac.innerHTML = `<span style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;background:#fee2e2;border:1px solid #fecaca;border-radius:5px;font-size:9px;font-weight:700;color:#dc2626;">Cancelled</span>`;
        } else {
            const isObligated = status === 'obligated';
            row.querySelector('.cell-unobligated').innerHTML = isObligated
                ? '<span style="color:#94a3b8;">—</span>'
                : `<span style="font-weight:700;color:#d97706;font-size:12px;">${fmt}</span>`;
            row.querySelector('.cell-obligated').innerHTML = isObligated
                ? `<span style="font-weight:700;color:#16a34a;font-size:12px;">${fmt}</span>`
                : '<span style="color:#94a3b8;">—</span>';
        }

        refreshFooter();
    }

    function refreshFooter() {
        let total = 0;
        document.querySelectorAll('tbody tr[data-proc-id]').forEach(row => {
            if (row.dataset.status === 'obligated') {
                total += parseFloat(row.dataset.amount) || 0;
            }
        });
        document.getElementById('footer-total-obligated').textContent = fmtMoney(total);
        document.getElementById('footer-remaining').textContent = fmtMoney(saroBudget - total);
    }

    // Signature toggle (regular procurement)
    document.querySelectorAll('.sig-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!canManageSaro) return;
            const procId   = this.dataset.procId;
            const signId   = this.dataset.signId;
            const curState = this.dataset.state;
            this.disabled  = true;

            fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    ajax_action:   'toggle_signature',
                    procurementId: procId,
                    signId:        signId,
                    current_state: curState
                })
            })
            .then(r => r.json())
            .then(data => {
                this.disabled = false;
                if (!data.success) return;
                this.dataset.state = data.new_state;
                this.innerHTML = data.new_state == 1 ? checkSvg12 : clockSvg12;
                updateRowAmounts(procId, data.status);
            })
            .catch(() => { this.disabled = false; });
        });
    });

    // Cancel procurement activity modal functions
    function openCancelModal(procId, procName) {
        if (!canManageSaro) return;
        document.getElementById('cancel-proc-id').value = procId;
        document.getElementById('cancel-proc-name').textContent = procName;
        document.getElementById('cancelModal').style.display = 'flex';
    }
    function closeCancelModal() {
        document.getElementById('cancelModal').style.display = 'none';
    }
    document.getElementById('cancelModal').addEventListener('click', function(e) {
        if (e.target === this) closeCancelModal();
    });

    function confirmCancel() {
        if (!canManageSaro) return;
        const procId = document.getElementById('cancel-proc-id').value;
        const btn = document.getElementById('confirm-cancel-btn');
        btn.disabled = true;
        btn.textContent = 'Cancelling...';

        fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ ajax_action: 'cancel_procurement', procurementId: procId })
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Yes, Cancel it';
            if (!data.success) { alert('Failed to cancel.'); return; }
            closeCancelModal();
            updateRowAmounts(procId, 'cancelled');
        })
        .catch(() => { 
            btn.disabled = false; 
            btn.textContent = 'Yes, Cancel it';
        });
    }

    document.querySelectorAll('.cancel-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const procId   = this.dataset.procId;
            const procName = this.dataset.procName;
            openCancelModal(procId, procName);
        });
    });

    // Travel document toggle
    document.querySelectorAll('.doc-cb-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!canManageSaro) return;
            const procId   = this.dataset.procId;
            const docId    = this.dataset.docId;
            const docName  = this.dataset.docName;
            const curState = this.dataset.state;
            this.disabled  = true;

            fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    ajax_action:   'toggle_travel_doc',
                    procurementId: procId,
                    documentId:    docId,
                    current_state: curState
                })
            })
            .then(r => r.json())
            .then(data => {
                this.disabled = false;
                if (!data.success) return;
                this.dataset.state = data.new_state;
                if (data.new_state == 1) {
                    this.classList.remove('missing');
                    this.classList.add('checked');
                    this.innerHTML = checkSvg10 + docName;
                } else {
                    this.classList.remove('checked');
                    this.classList.add('missing');
                    this.innerHTML = clockSvg10 + docName;
                }
                updateRowAmounts(procId, data.status);
            })
            .catch(() => { this.disabled = false; });
        });
    });
</script>
</body>
</html>
