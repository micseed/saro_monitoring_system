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

$saroId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$saroId) {
    header("Location: procurement_stat.php");
    exit;
}

$db = new Database();
$conn = $db->connect();
$statusObj = new ProcurementStatus();

// Fetch SARO info
$stmtSaro = $conn->prepare("SELECT * FROM saro WHERE saroId = ?");
$stmtSaro->execute([$saroId]);
$saro = $stmtSaro->fetch(PDO::FETCH_ASSOC);

if(!$saro) {
    die("SARO Not Found");
}

// Fetch Procurement Activities
$stmtProc = $conn->prepare("
    SELECT p.*, oc.code as object_code_str 
    FROM procurement p
    JOIN object_code oc ON p.objectId = oc.objectId
    WHERE oc.saroId = ?
    ORDER BY p.created_at DESC
");
$stmtProc->execute([$saroId]);
$procurements = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

$totalSaroBudget = (float)$saro['total_budget'];
$totalObligatedAmount = 0;
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
        /* Basic Resets & Scrollbar */
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

        /* Panels */
        .saro-card { background: #fff; border: 1px solid #e8edf5; border-radius: 14px; padding: 20px 28px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 24px; position: relative; overflow: hidden; }
        .table-panel { background: #fff; border: 1px solid #e8edf5; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; }
        .panel-header { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }

        /* Complex table */
        table { width: 100%; border-collapse: collapse; }
        thead tr.th-primary th { padding: 11px 14px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #fff; background: #0f172a; border-right: 1px solid rgba(255,255,255,0.08); white-space: nowrap; text-align: center; }
        thead tr.th-primary th:first-child { text-align: left; border-radius: 0; }
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

        /* Badges & Buttons */
        .doc-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 6px; background: #f1f5f9; border: 1px solid #e2e8f0; font-size: 10px; font-weight: 600; color: #475569; white-space: nowrap; }
        .doc-badge.missing { background:#fff7ed;border-color:#fed7aa;color:#92400e; }
        .doc-badge.checked { background:#dcfce7;border-color:#bbf7d0;color:#16a34a; }

        .sig-btn { width:28px;height:28px;border-radius:50%;border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all 0.2s ease; }
        .sig-btn[data-state="1"] { background:#dcfce7;border:1.5px solid #bbf7d0;color:#16a34a; }
        .sig-btn[data-state="0"] { background:#fef9c3;border:1.5px solid #fde68a;color:#b45309; }
        
        .action-btn { width:28px;height:28px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;background:transparent;transition:all 0.2s ease; }
        .action-btn-del { color:#94a3b8; }
        .action-btn-del:hover { background:#fee2e2;border-color:#fecaca;color:#dc2626; }
        .action-btn-cancel { color:#b45309; }
        .action-btn-cancel:hover { background:#fef9c3;border-color:#fde68a;color:#92400e; }

        .panel-footer { padding: 14px 24px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
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
            <a href="data_entry.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Data Entry
            </a>
            <a href="procurement_stat.php" class="nav-item active">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Procurement Status
            </a>
            <p class="nav-section-label">Reports</p>
            <a href="#" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Export Records
            </a>
            <a href="audit_logs.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Activity Logs
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
                <div class="icon-btn">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <span class="notif-dot"></span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 12px;
                            background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                    <div style="width:28px;height:28px;border-radius:7px;
                                background:linear-gradient(135deg,#2563eb,#1d4ed8);
                                display:flex;align-items:center;justify-content:center;
                                font-size:10px;font-weight:800;color:#fff;">
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
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">PR & PO signature tracking per activity</p>
                        </div>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr class="th-primary">
                                <th rowspan="2" style="text-align:left;min-width:160px;">Procurement</th>
                                <th rowspan="2" style="min-width:130px;">Required Documents</th>
                                <!-- Adjusted Colspan to 4 for PR signatures and 3 for PO Signatures based on db schema signatory_role -->
                                <th colspan="4" class="group-pr" style="text-align:center;border-left:2px solid rgba(255,255,255,0.15);">Status of Purchase Request (PR)</th>
                                <th colspan="3" class="group-po" style="text-align:center;border-left:2px solid rgba(255,255,255,0.15);">Status of Purchase Order (PO)</th>
                                <th rowspan="2" style="text-align:right;min-width:120px;">Amount Obligated</th>
                                <th rowspan="2" style="text-align:right;min-width:120px;">Amount Unobligated</th>
                                <th rowspan="2" style="text-align:left;min-width:160px;">Remarks</th>
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
                                <tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:20px;">No procurement activities found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($procurements as $p): 
                                    $procBudget = (float)$p['unit_cost'] * (int)$p['quantity'];
                                    $obligatedAmount = (float)($p['obligated_amount'] ?? 0);
                                    if ($obligatedAmount == 0 && $procBudget > 0) { 
                                        $obligatedAmount = $procBudget; // fallback if null
                                    }
                                    $totalObligatedAmount += $obligatedAmount;

                                    $docs = $statusObj->getProcurementDocuments($p['procurementId'], $p['is_travelExpense']);
                                    $sigs = $statusObj->getSignatures($p['procurementId']);
                                ?>
                                <tr>
                                    <td>
                                        <p style="font-weight:700;color:#0f172a;font-size:12px;"><?= htmlspecialchars($p['pro_act'] ?? '—') ?></p>
                                        <p style="font-size:10px;color:#94a3b8;font-weight:500;margin-top:2px;">Object Code: <?= htmlspecialchars($p['object_code_str']) ?></p>
                                    </td>
                                    <td>
                                        <div style="display:flex;flex-direction:column;gap:4px;">
                                            <?php if (empty($docs)): ?>
                                                <span class="doc-badge missing">No Documents Set</span>
                                            <?php else: ?>
                                                <?php foreach($docs as $doc): ?>
                                                    <?php if($doc['is_checked'] == 1): ?>
                                                        <span class="doc-badge checked">
                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                            <?= htmlspecialchars($doc['document_name']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="doc-badge missing">
                                                            <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                            <?= htmlspecialchars($doc['document_name']) ?> Pending
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Signatures (Dynamically Mapping the 7 signature roles) -->
                                    <?php 
                                        for ($i = 0; $i < 7; $i++) {
                                            $state = isset($sigs[$i]) ? $sigs[$i]['is_signed'] : '0';
                                            echo '<td><button class="sig-btn" data-state="'.$state.'" title="Toggle Status"><svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24">'.($state == '1' ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>' : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>').'</svg></button></td>';
                                        }
                                    ?>
                                    
                                    <td style="text-align:right;"><span style="font-weight:700;color:#16a34a;font-size:12px;">₱<?= number_format($obligatedAmount, 2) ?></span></td>
                                    <td style="text-align:right;"><span style="font-weight:600;color:#475569;font-size:12px;">₱<?= number_format(max(0, $procBudget - $obligatedAmount), 2) ?></span></td>
                                    
                                    <td style="text-align:left;">
                                        <div style="display:flex;align-items:center;gap:6px;min-width:130px;">
                                            <span style="font-size:11px;color:#64748b;"><?= htmlspecialchars($p['remarks'] ?: 'No remarks.') ?></span>
                                        </div>
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
                            <p style="font-size:15px;font-weight:900;color:#1d4ed8;letter-spacing:-0.02em;">₱<?= number_format($totalObligatedAmount, 2) ?></p>
                        </div>
                        <div style="width:1px;height:32px;background:#e2e8f0;"></div>
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Total Budget</p>
                            <p style="font-size:15px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;">₱<?= number_format($totalSaroBudget, 2) ?></p>
                        </div>
                        <div style="width:1px;height:32px;background:#e2e8f0;"></div>
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Remaining Budget</p>
                            <p style="font-size:15px;font-weight:900;color:#16a34a;letter-spacing:-0.02em;">₱<?= number_format($totalSaroBudget - $totalObligatedAmount, 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    const checkSvg = `<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>`;
    const clockSvg = `<svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;

    document.querySelectorAll('.sig-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const newState = this.dataset.state === '1' ? '0' : '1';
            this.dataset.state = newState;
            this.innerHTML = newState === '1' ? checkSvg : clockSvg;
            // Optionally: Add AJAX call here to save signature status to database
        });
    });
</script>
</body>
</html>