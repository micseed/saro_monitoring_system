<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
// Assuming Database.php is located in a classes or similar directory based on your references. 
// Adjust the path if necessary.
require_once __DIR__ . '/../class/Database.php'; 

$username = $_SESSION['full_name'] ?? 'User';
$role     = $_SESSION['role'] ?? 'Role';
$initials = $_SESSION['initials'] ?? 'U';

// Initialize Database connection
$db = new Database();
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

// 2. Fetch Object Codes and related expense items
$stmtObj = $conn->prepare("
    SELECT oc.*, GROUP_CONCAT(ei.item_name SEPARATOR ', ') as expense_items
    FROM object_code oc
    LEFT JOIN expense_items ei ON oc.objectId = ei.objectId
    WHERE oc.saroId = ?
    GROUP BY oc.objectId
");
$stmtObj->execute([$saroId]);
$objectCodes = $stmtObj->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Procurement Activities
$stmtProc = $conn->prepare("
    SELECT p.*, oc.code as object_code_str
    FROM procurement p
    JOIN object_code oc ON p.objectId = oc.objectId
    WHERE oc.saroId = ?
    ORDER BY p.created_at DESC
");
$stmtProc->execute([$saroId]);
$procurements = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

// Calculations
$totalBudget = (float)$saro['total_budget'];
$totalObligated = 0.0;

foreach ($procurements as $p) {
    // Using obligated_amount or falling back to (unit_cost * quantity) if empty
    $budgetAlloc = !empty($p['obligated_amount']) ? (float)$p['obligated_amount'] : ((float)$p['unit_cost'] * (int)$p['quantity']);
    $totalObligated += $budgetAlloc;
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
                        <button class="btn btn-ghost btn-sm" onclick="openEditSaroModal()">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                            Edit
                        </button>
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
                        <button class="btn btn-primary btn-sm" onclick="openAddObjModal()">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Object Code
                        </button>
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
                                                <button class="action-btn action-btn-edit" title="Edit" 
                                                        onclick="openEditObjModal('<?= htmlspecialchars($obj['code']) ?>','<?= $obj['projected_cost'] ?>','<?= htmlspecialchars($obj['expense_items'] ?? '') ?>')">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                </button>
                                                <button class="action-btn action-btn-del" title="Remove" onclick="openDeleteObjModal('<?= htmlspecialchars($obj['code']) ?>')">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
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
                        <button class="btn btn-primary btn-sm" onclick="openProcModal()">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Activity
                        </button>
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
                                <th style="text-align:right;">Budget Alloc.</th>
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
                                    $alloc  = !empty($p['obligated_amount']) ? $p['obligated_amount'] : ($p['unit_cost'] * $p['quantity']);
                                    // Placeholder Status based on date presence, update to real logic if/when schema incorporates status for this table
                                    $pStatus = empty($p['proc_date']) ? 'Pending' : 'Ongoing'; 
                                    $badge = $pStatus === 'Pending' ? 'badge-amber' : 'badge-blue';
                                ?>
                                <tr>
                                    <td style="color:#cbd5e1;font-weight:700;font-size:11px;"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></td>
                                    <td><span style="font-weight:700;color:#1d4ed8;font-size:11px;"><?= htmlspecialchars($p['object_code_str']) ?></span></td>
                                    <td><p style="font-weight:600;color:#0f172a;font-size:12px;"><?= htmlspecialchars($p['pro_act'] ?? '—') ?></p></td>
                                    <td style="text-align:center;font-weight:700;color:#0f172a;"><?= htmlspecialchars($p['quantity']) ?></td>
                                    <td style="text-align:center;color:#64748b;"><?= htmlspecialchars($p['unit'] ?? '—') ?></td>
                                    <td style="text-align:right;font-weight:600;color:#334155;">₱<?= number_format((float)$p['unit_cost'], 2) ?></td>
                                    <td style="text-align:right;font-weight:700;color:#1d4ed8;">₱<?= number_format((float)$alloc, 2) ?></td>
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
                                            <button class="action-btn action-btn-edit" title="Edit Remarks" style="width:22px;height:22px;border-radius:5px;flex-shrink:0;" onclick="openRemarksModal('<?= htmlspecialchars(addslashes($p['remarks'] ?? '')) ?>')">
                                                <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;align-items:center;justify-content:center;gap:4px;">
                                            <button class="action-btn action-btn-edit" title="Edit" onclick="openEditProcModal('<?= htmlspecialchars($p['object_code_str']) ?>','<?= htmlspecialchars(addslashes($p['pro_act'] ?? '')) ?>','<?= $p['quantity'] ?>','<?= htmlspecialchars($p['unit'] ?? '') ?>','<?= $p['unit_cost'] ?>','<?= date('F', strtotime($p['period_start'] ?? 'now')) ?>','<?= date('Y', strtotime($p['period_start'] ?? 'now')) ?>','<?= date('F', strtotime($p['period_end'] ?? 'now')) ?>','<?= date('Y', strtotime($p['period_end'] ?? 'now')) ?>','<?= $p['proc_date'] ?>','<?= $pStatus ?>','<?= htmlspecialchars(addslashes($p['remarks'] ?? '')) ?>')">
                                                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </button>
                                            <button class="action-btn action-btn-del" title="Delete" onclick="openDeleteProcModal('<?= htmlspecialchars(addslashes($p['pro_act'] ?? '')) ?>')">
                                                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
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
                        Displaying <strong style="color:#475569;"><?= count($procurements) ?></strong> of <strong style="color:#475569;"><?= count($procurements) ?></strong> activities
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
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Object Code</label>
                    <select class="form-input">
                        <option value="">Select object code…</option>
                        <?php foreach ($objectCodes as $obj): ?>
                            <option value="<?= htmlspecialchars($obj['objectId']) ?>"><?= htmlspecialchars($obj['code']) ?> — <?= htmlspecialchars(mb_strimwidth($obj['expense_items'] ?? '', 0, 30, '...')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <select class="form-input">
                        <option>Pending</option>
                        <option>Ongoing</option>
                        <option>Delivered</option>
                        <option>Cancelled</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Activity</label>
                <input type="text" class="form-input" placeholder="e.g. Laptop Computer (Core i7)">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Quantity</label>
                    <input type="number" class="form-input" placeholder="0" min="1">
                </div>
                <div>
                    <label class="form-label">Unit</label>
                    <select class="form-input">
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Unit Cost (₱)</label>
                    <input type="number" class="form-input" placeholder="0.00" min="0" step="0.01">
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Period</label>
                <div class="period-pair" style="margin-top:4px;">
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">Period Start</span>
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;">
                            <select class="form-input">
                                <option>January</option><option>February</option><option>March</option>
                                <option>April</option><option>May</option><option>June</option>
                                <option>July</option><option>August</option><option>September</option>
                                <option>October</option><option>November</option><option>December</option>
                            </select>
                            <input type="number" class="form-input" value="<?= htmlspecialchars($saro['fiscal_year']) ?>" min="2020" max="2099">
                        </div>
                    </div>
                    <div class="period-label-sep" style="padding-top:20px;">—</div>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">Period End</span>
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;">
                            <select class="form-input">
                                <option>January</option><option>February</option><option>March</option>
                                <option>April</option><option>May</option><option>June</option>
                                <option>July</option><option>August</option><option>September</option>
                                <option>October</option><option>November</option><option>December</option>
                            </select>
                            <input type="number" class="form-input" value="<?= htmlspecialchars($saro['fiscal_year']) ?>" min="2020" max="2099">
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Date</label>
                <input type="date" class="form-input">
            </div>
            <div>
                <label class="form-label">Remarks</label>
                <input type="text" class="form-input" placeholder="Optional notes…">
            </div>
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;
                    display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="closeProcModal()">Cancel</button>
            <button class="btn btn-primary">
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
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Object Code</label>
                    <select class="form-input" id="ep-obj-code">
                        <option value="">Select object code…</option>
                        <?php foreach ($objectCodes as $obj): ?>
                            <option value="<?= htmlspecialchars($obj['code']) ?>"><?= htmlspecialchars($obj['code']) ?> — <?= htmlspecialchars(mb_strimwidth($obj['expense_items'] ?? '', 0, 30, '...')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <select class="form-input" id="ep-status">
                        <option value="Pending">Pending</option>
                        <option value="Ongoing">Ongoing</option>
                        <option value="Delivered">Delivered</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Activity</label>
                <input type="text" class="form-input" id="ep-activity" placeholder="e.g. Laptop Computer (Core i7)">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                <div>
                    <label class="form-label">Quantity</label>
                    <input type="number" class="form-input" id="ep-qty" placeholder="0" min="1">
                </div>
                <div>
                    <label class="form-label">Unit</label>
                    <select class="form-input" id="ep-unit">
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Unit Cost (₱)</label>
                    <input type="number" class="form-input" id="ep-unit-cost" placeholder="0.00" min="0" step="0.01">
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Period</label>
                <div class="period-pair" style="margin-top:4px;">
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">Period Start</span>
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;">
                            <select class="form-input" id="ep-start-month">
                                <option>January</option><option>February</option><option>March</option>
                                <option>April</option><option>May</option><option>June</option>
                                <option>July</option><option>August</option><option>September</option>
                                <option>October</option><option>November</option><option>December</option>
                            </select>
                            <input type="number" class="form-input" id="ep-start-year" min="2020" max="2099">
                        </div>
                    </div>
                    <div class="period-label-sep" style="padding-top:20px;">—</div>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <span style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;">Period End</span>
                        <div style="display:grid;grid-template-columns:1fr 80px;gap:8px;">
                            <select class="form-input" id="ep-end-month">
                                <option>January</option><option>February</option><option>March</option>
                                <option>April</option><option>May</option><option>June</option>
                                <option>July</option><option>August</option><option>September</option>
                                <option>October</option><option>November</option><option>December</option>
                            </select>
                            <input type="number" class="form-input" id="ep-end-year" min="2020" max="2099">
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <label class="form-label">Procurement Date</label>
                <input type="date" class="form-input" id="ep-date">
            </div>
            <div>
                <label class="form-label">Remarks</label>
                <input type="text" class="form-input" id="ep-remarks" placeholder="Optional notes…">
            </div>
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;
                    display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="closeEditProcModal()">Cancel</button>
            <button class="btn btn-primary">
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
                <label class="form-label">SARO Title</label>
                <input type="text" class="form-input" id="edit-saro-title" value="<?= htmlspecialchars($saro['saro_title']) ?>">
            </div>
            <div>
                <label class="form-label">Fiscal Year</label>
                <input type="number" class="form-input" id="edit-fiscal-year" value="<?= htmlspecialchars($saro['fiscal_year']) ?>" min="2020" max="2099">
            </div>
            <div>
                <label class="form-label">Total Budget (₱)</label>
                <input type="number" class="form-input" id="edit-total-budget" value="<?= htmlspecialchars($saro['total_budget']) ?>" min="0" step="0.01">
            </div>
            <div>
                <label class="form-label">Status</label>
                <select class="form-input" id="edit-status">
                    <option value="active" <?= $saro['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="cancelled" <?= $saro['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeEditSaroModal()">Cancel</button>
            <button class="btn btn-primary">
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
            <div style="display:grid;grid-template-columns:1fr 130px 1fr 32px;gap:8px;
                        padding:6px 10px;background:#f8fafc;border:1px solid #e8edf5;
                        border-radius:8px 8px 0 0;border-bottom:none;margin-bottom:0;">
                <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Object Code</p>
                <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Projected Cost (₱)</p>
                <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Expense Item</p>
                <span></span>
            </div>
            <!-- Rows container -->
            <div id="objCodeListView" style="border:1px solid #e8edf5;border-radius:0 0 8px 8px;overflow:hidden;margin-top:0;">
            </div>
            <p id="objViewEmptyHint" style="font-size:11px;color:#94a3b8;margin-top:4px;">Click "Add Row" to add object codes.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeAddObjModal()">Cancel</button>
            <button class="btn btn-primary">
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
            <div>
                <label class="form-label">Object Code</label>
                <input type="text" class="form-input" id="edit-obj-code">
            </div>
            <div>
                <label class="form-label">Projected Cost (₱)</label>
                <input type="number" class="form-input" id="edit-obj-cost" min="0" step="0.01">
            </div>
            <div>
                <label class="form-label">Expense Item</label>
                <input type="text" class="form-input" id="edit-obj-expense-item" placeholder="Enter expense item description…">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeEditObjModal()">Cancel</button>
            <button class="btn btn-primary">
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
            <button class="btn btn-primary" style="background:#dc2626;border-color:#dc2626;">
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
            <button class="btn btn-primary">
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
            <button class="btn btn-primary" style="background:#dc2626;border-color:#dc2626;">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Delete
            </button>
        </div>
    </div>
</div>

<script>
    // Add Activity Modal
    function openProcModal()  { document.getElementById('procModal').style.display = 'flex'; }
    function closeProcModal() { document.getElementById('procModal').style.display = 'none'; }
    document.getElementById('procModal').addEventListener('click', function(e) {
        if (e.target === this) closeProcModal();
    });

    // Edit Activity Modal
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function openEditProcModal(objCode, activity, qty, unit, unitCost, startMonth, startYear, endMonth, endYear, date, status, remarks) {
        const setVal = (id, val) => { const el = document.getElementById(id); if(el) el.value = val; };
        const setOpt = (id, val) => {
            const el = document.getElementById(id);
            if (!el) return;
            [...el.options].forEach(o => { o.selected = o.value === val || o.text === val; });
        };
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
        setOpt('ep-status', status);
        setVal('ep-remarks', remarks);
        document.getElementById('editProcModal').style.display = 'flex';
    }
    function closeEditProcModal() { document.getElementById('editProcModal').style.display = 'none'; }
    document.getElementById('editProcModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditProcModal();
    });

    // Edit SARO Modal
    function openEditSaroModal() { document.getElementById('editSaroModal').classList.add('open'); }
    function closeEditSaroModal() { document.getElementById('editSaroModal').classList.remove('open'); }
    document.getElementById('editSaroModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditSaroModal();
    });

    // Add Object Code Modal
    function openAddObjModal() { document.getElementById('addObjModal').classList.add('open'); }
    function closeAddObjModal() { document.getElementById('addObjModal').classList.remove('open'); }
    document.getElementById('addObjModal').addEventListener('click', function(e) {
        if (e.target === this) closeAddObjModal();
    });

    // Edit Object Code Modal
    function openEditObjModal(code, cost, item) {
        document.getElementById('edit-obj-code').value = code;
        document.getElementById('edit-obj-cost').value = cost;
        document.getElementById('edit-obj-expense-item').value = item || '';
        document.getElementById('editObjModal').classList.add('open');
        setTimeout(() => document.getElementById('edit-obj-code').focus(), 100);
    }
    function closeEditObjModal() { document.getElementById('editObjModal').classList.remove('open'); }
    document.getElementById('editObjModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditObjModal();
    });

    // Delete Object Code Modal
    function openDeleteObjModal(code) {
        document.getElementById('delete-obj-label').textContent = code;
        document.getElementById('deleteObjModal').classList.add('open');
    }
    function closeDeleteObjModal() { document.getElementById('deleteObjModal').classList.remove('open'); }
    document.getElementById('deleteObjModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteObjModal();
    });

    // Edit Remarks Modal
    function openRemarksModal(text) {
        document.getElementById('remarks-textarea').value = text;
        document.getElementById('remarksModal').classList.add('open');
    }
    function closeRemarksModal() { document.getElementById('remarksModal').classList.remove('open'); }
    document.getElementById('remarksModal').addEventListener('click', function(e) {
        if (e.target === this) closeRemarksModal();
    });

    // Delete Procurement Modal
    function openDeleteProcModal(name) {
        document.getElementById('delete-proc-label').textContent = name;
        document.getElementById('deleteProcModal').classList.add('open');
    }
    function closeDeleteProcModal() { document.getElementById('deleteProcModal').classList.remove('open'); }
    document.getElementById('deleteProcModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteProcModal();
    });

    // Add Object Row (view)
    function addObjRowView() {
        const list = document.getElementById('objCodeListView');
        const hint = document.getElementById('objViewEmptyHint');
        if (hint) hint.style.display = 'none';
        const row = document.createElement('div');
        row.style.cssText = 'padding:10px 10px 8px;border-bottom:1px solid #f1f5f9;background:#fff;transition:background 0.15s ease;';
        row.onmouseenter = () => row.style.background = '#f5f8ff';
        row.onmouseleave = () => row.style.background = '#fff';
        row.innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 130px 1fr 32px;gap:8px;align-items:center;padding:10px 10px 8px;">
                <input type="text" placeholder="e.g. 5-02-03-070"
                       style="width:100%;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;
                              font-size:12px;font-family:'Poppins',sans-serif;font-weight:500;color:#0f172a;
                              background:#f8fafc;outline:none;transition:all 0.2s ease;"
                       onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.1)'"
                       onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc';this.style.boxShadow='none'">
                <input type="number" placeholder="0.00" min="0" step="0.01"
                       style="width:100%;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;
                              font-size:12px;font-family:'Poppins',sans-serif;font-weight:500;color:#0f172a;
                              background:#f8fafc;outline:none;transition:all 0.2s ease;"
                       onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.1)'"
                       onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc';this.style.boxShadow='none'">
                <input type="text" placeholder="e.g. ICT Equipment"
                       style="width:100%;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;
                              font-size:12px;font-family:'Poppins',sans-serif;font-weight:500;color:#0f172a;
                              background:#f8fafc;outline:none;transition:all 0.2s ease;"
                       onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.1)'"
                       onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc';this.style.boxShadow='none'">
                <button type="button" onclick="removeObjRowView(this)" title="Remove row"
                        style="width:28px;height:28px;border-radius:6px;border:1px solid transparent;
                               background:transparent;cursor:pointer;color:#94a3b8;
                               display:flex;align-items:center;justify-content:center;
                               transition:all 0.2s ease;flex-shrink:0;"
                        onmouseenter="this.style.background='#fee2e2';this.style.borderColor='#fecaca';this.style.color='#dc2626'"
                        onmouseleave="this.style.background='transparent';this.style.borderColor='transparent';this.style.color='#94a3b8'">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </button>
            </div>`;
        list.appendChild(row);
        row.querySelector('input').focus();
    }

    function removeObjRowView(btn) {
        const list = document.getElementById('objCodeListView');
        btn.closest('div[style]').remove();
        if (list.children.length === 0) {
            const hint = document.getElementById('objViewEmptyHint');
            if (hint) hint.style.display = '';
        }
    }
</script>
</body>
</html>