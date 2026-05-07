<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/saro.php';

$saroObj = new Saro();
$userId  = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'add') {
        $saroNo  = trim($_POST['saro_no']    ?? '');
        $title   = trim($_POST['saro_title'] ?? '');
        $year    = trim($_POST['fiscal_year'] ?? '');
        $budget  = $_POST['total_budget'] ?? 0;
        $codes   = json_decode($_POST['object_codes'] ?? '[]', true) ?: [];
        if (!$saroNo || !$title || !$year || !$budget) {
            echo json_encode(['success' => false, 'error' => 'All fields are required.']);
            exit;
        }
        echo json_encode($saroObj->createSaro($userId, $saroNo, $title, $year, $budget, $codes));
        exit;
    }

    if ($action === 'edit') {
        $id     = (int)($_POST['saro_id']     ?? 0);
        $saroNo = trim($_POST['saro_no']      ?? '');
        $title  = trim($_POST['saro_title']   ?? '');
        $year   = trim($_POST['fiscal_year']  ?? '');
        $budget = $_POST['total_budget']      ?? 0;
        $status = $_POST['status']            ?? 'active';
        if (!$id || !$saroNo || !$title || !$year) {
            echo json_encode(['success' => false, 'error' => 'All fields are required.']);
            exit;
        }
        echo json_encode($saroObj->updateSaro($id, $saroNo, $title, $year, $budget, $status));
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['saro_id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Invalid ID.']); exit; }
        echo json_encode($saroObj->deleteSaro($id));
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

$saros    = $saroObj->getAllSaros();
$username = $_SESSION['full_name'];
$role     = $_SESSION['role'];
$initials = $_SESSION['initials'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Entry | DICT SARO Monitoring</title>
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
            transition: all 0.2s ease;
        }
        .form-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        select.form-input { cursor: pointer; }
        textarea.form-input { resize: vertical; min-height: 72px; line-height: 1.6; }

        /* Search */
        .search-wrap { position: relative; }
        .search-input {
            padding: 8px 12px 8px 36px; border: 1px solid #e2e8f0; border-radius: 8px;
            background: #f8fafc; font-size: 12px; font-family: 'Poppins', sans-serif;
            width: 220px; outline: none; transition: all 0.2s ease;
        }
        .search-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }

        /* Table panel */
        .table-panel {
            background: #fff; border: 1px solid #e8edf5;
            border-radius: 16px; overflow: hidden;
            display: flex; flex-direction: column;
        }
        .panel-header {
            padding: 18px 24px; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }

        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 12px 20px; font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.12em;
            color: #94a3b8; background: #fafbfe;
            border-bottom: 1px solid #f1f5f9; white-space: nowrap; text-align: left;
        }
        tbody tr { border-bottom: 1px solid #f8fafc; transition: background 0.15s ease; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 14px 20px; font-size: 13px; color: #475569; }

        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 99px;
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
        }
        .badge-green { background: #dcfce7; color: #16a34a; }
        .badge-amber { background: #fef9c3; color: #b45309; }
        .badge-blue  { background: #dbeafe; color: #1d4ed8; }
        .badge-red   { background: #fee2e2; color: #dc2626; }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

        /* Show rows */
        .show-rows-wrap { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #64748b; font-weight: 500; }
        .show-rows-select {
            padding: 5px 10px; border: 1px solid #e2e8f0; border-radius: 7px;
            font-size: 12px; font-family: 'Poppins', sans-serif;
            color: #0f172a; background: #f8fafc; outline: none; cursor: pointer;
        }

        /* Action buttons */
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

        .panel-footer {
            padding: 14px 24px; border-top: 1px solid #f1f5f9; background: #fafbfe;
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }

        /* Object code tag inside modal */
        .obj-tag {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 10px 5px 12px; border-radius: 7px;
            background: #eff6ff; border: 1px solid #bfdbfe;
            font-size: 12px; font-weight: 600; color: #1d4ed8;
        }
        .obj-tag button {
            width: 18px; height: 18px; border-radius: 4px; border: none;
            background: none; cursor: pointer; color: #93c5fd;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s ease;
        }
        .obj-tag button:hover { background: #dbeafe; color: #1d4ed8; }
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
                <span class="breadcrumb-active">Data Entry</span>
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
                <div style="position:relative;z-index:1;">
                    <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                               text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">
                        SARO Management
                    </p>
                    <h2 style="font-size:22px;font-weight:900;color:#fff;
                               text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">
                        Data Entry
                    </h2>
                    <p style="font-size:13px;color:rgba(255,255,255,0.6);font-weight:400;max-width:520px;line-height:1.6;">
                        This is where SARO entries can be added, and all entered SARO records can also be viewed here.
                    </p>
                </div>
            </div>

            <!-- SARO Table Panel -->
            <div class="table-panel">

                <!-- Panel header -->
                <div class="panel-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#eff6ff;
                                    display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">SARO Records</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">All entered SARO entries</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="search-wrap">
                            <svg class="search-icon" width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" class="search-input" placeholder="Type to search…">
                        </div>
                        <button class="btn btn-ghost btn-sm">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                            Filter
                        </button>
                        <button class="btn btn-primary btn-sm" onclick="openAddSaroModal()">
                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add SARO
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:52px;">No.</th>
                                <th>SARO No.</th>
                                <th>SARO Title</th>
                                <th style="text-align:right;">Total Budget</th>
                                <th style="text-align:center;">Object Codes</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($saros)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center;padding:52px 20px;color:#94a3b8;">
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:12px;">
                                        <svg width="40" height="40" fill="none" stroke="#cbd5e1" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <p style="font-size:13px;font-weight:600;color:#94a3b8;">No SARO records yet</p>
                                        <p style="font-size:12px;color:#cbd5e1;">Click <strong>Add SARO</strong> to create the first entry.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($saros as $i => $s):
                                $badgeClass = $s['status'] === 'active' ? 'badge-green' : 'badge-red';
                                $rowNum     = str_pad($i + 1, 2, '0', STR_PAD_LEFT);
                                $saroNoEsc  = htmlspecialchars($s['saroNo'], ENT_QUOTES);
                                $titleEsc   = htmlspecialchars($s['saro_title']);
                                $budgetFmt  = number_format((float)$s['total_budget'], 2);
                                $codeCount  = (int)$s['obj_count'];
                            ?>
                            <tr>
                                <td style="color:#cbd5e1;font-weight:700;font-size:12px;"><?= $rowNum ?></td>
                                <td>
                                    <span style="font-weight:800;color:#0f172a;font-size:13px;letter-spacing:-0.01em;"><?= $saroNoEsc ?></span>
                                </td>
                                <td style="max-width:280px;">
                                    <p style="font-weight:500;color:#334155;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $titleEsc ?></p>
                                </td>
                                <td style="text-align:right;">
                                    <span style="font-weight:800;color:#0f172a;font-size:13px;letter-spacing:-0.01em;">₱<?= $budgetFmt ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:#1d4ed8;background:#dbeafe;padding:3px 10px;border-radius:99px;">
                                        <?= $codeCount ?> <?= $codeCount === 1 ? 'code' : 'codes' ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="badge <?= $badgeClass ?>"><span class="badge-dot"></span><?= ucfirst($s['status']) ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <div style="display:flex;align-items:center;justify-content:center;gap:4px;">
                                        <a href="view_saro.php?id=<?= $s['saroId'] ?>" class="action-btn action-btn-view" title="View">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        </a>
                                        <button class="action-btn action-btn-edit" title="Edit"
                                            data-id="<?= $s['saroId'] ?>"
                                            data-no="<?= $saroNoEsc ?>"
                                            data-title="<?= htmlspecialchars($s['saro_title'], ENT_QUOTES) ?>"
                                            data-year="<?= $s['fiscal_year'] ?>"
                                            data-budget="<?= $s['total_budget'] ?>"
                                            data-status="<?= $s['status'] ?>">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </button>
                                        <button class="action-btn action-btn-del" title="Delete"
                                            data-id="<?= $s['saroId'] ?>"
                                            data-no="<?= $saroNoEsc ?>">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Panel footer -->
                <div class="panel-footer">
                    <div class="show-rows-wrap">
                        <span>Show</span>
                        <select class="show-rows-select">
                            <option>10 rows</option>
                            <option selected>20 rows</option>
                            <option>50 rows</option>
                        </select>
                    </div>
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">
                        Displaying <strong style="color:#475569;"><?= count($saros) ?></strong> of <strong style="color:#475569;"><?= count($saros) ?></strong> SARO entries
                    </p>
                </div>

            </div>
        </div><!-- /content -->
    </main>
</div>

<!-- ══ Add SARO Modal ══ -->
<div id="addSaroModal" style="display:none;position:fixed;inset:0;z-index:100;
     background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:18px;width:100%;max-width:520px;
                box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden;">

        <!-- Modal header -->
        <div style="padding:22px 28px;display:flex;align-items:center;justify-content:space-between;
                    background:linear-gradient(135deg,#1e3a8a,#2563eb);">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                           text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">New Entry</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Add SARO</h3>
            </div>
            <button onclick="closeAddSaroModal()"
                    style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,0.12);
                           border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Modal body -->
        <div style="padding:24px 28px;display:flex;flex-direction:column;gap:18px;max-height:72vh;overflow-y:auto;">

            <p style="font-size:12px;color:#94a3b8;font-weight:500;margin-top:-6px;">
                Please input the necessary fields.
            </p>

            <div>
                <label class="form-label">SARO Number</label>
                <input type="text" class="form-input" id="add-saro-no" placeholder="e.g. SARO-ROIX-2026-006">
            </div>

            <div>
                <label class="form-label">SARO Title</label>
                <input type="text" class="form-input" id="add-saro-title" placeholder="e.g. ICT Equipment Procurement…">
            </div>

            <div>
                <label class="form-label">Fiscal Year</label>
                <input type="number" class="form-input" id="add-fiscal-year" placeholder="e.g. 2026" min="2020" max="2099">
            </div>

            <div>
                <label class="form-label">Total Budget (₱)</label>
                <input type="number" class="form-input" id="add-total-budget" placeholder="0.00" min="0" step="0.01">
            </div>

            <!-- Object Codes -->
            <div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <label class="form-label" style="margin:0;">Object Codes</label>
                    <button type="button" onclick="addObjRow()"
                            style="display:inline-flex;align-items:center;gap:5px;
                                   padding:5px 12px;border-radius:7px;border:1.5px solid #2563eb;
                                   background:#eff6ff;color:#2563eb;font-size:11px;font-weight:700;
                                   font-family:'Poppins',sans-serif;cursor:pointer;transition:all 0.2s ease;"
                            onmouseover="this.style.background='#2563eb';this.style.color='#fff'"
                            onmouseout="this.style.background='#eff6ff';this.style.color='#2563eb'">
                        <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        Add Row
                    </button>
                </div>

                <!-- Header row -->
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 32px;gap:8px;
                            padding:6px 10px;background:#f8fafc;border:1px solid #e8edf5;
                            border-radius:8px 8px 0 0;border-bottom:none;">
                    <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Object Code</p>
                    <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Expense Items</p>
                    <p style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Projected Cost (₱)</p>
                    <span></span>
                </div>

                <!-- Rows container -->
                <div id="objCodeList" style="border:1px solid #e8edf5;border-radius:0 0 8px 8px;overflow:hidden;">
                    <!-- rows injected here -->
                </div>
                <p id="objEmptyHint" style="font-size:11px;color:#94a3b8;margin-top:8px;">Click "Add Row" to attach object codes to this SARO.</p>
            </div>

        </div>

        <!-- Modal footer -->
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;
                    display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="closeAddSaroModal()">Cancel</button>
            <button class="btn btn-primary" onclick="submitAddSaro()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Add SARO
            </button>
        </div>
    </div>
</div>

<!-- ══ Edit SARO Modal ══ -->
<div id="editSaroModal" style="display:none;position:fixed;inset:0;z-index:100;
     background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:18px;width:100%;max-width:520px;
                box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden;">

        <!-- Modal header -->
        <div style="padding:22px 28px;display:flex;align-items:center;justify-content:space-between;
                    background:linear-gradient(135deg,#1e3a8a,#2563eb);">
            <div>
                <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                           text-transform:uppercase;letter-spacing:0.14em;margin-bottom:4px;">Edit Record</p>
                <h3 style="font-size:16px;font-weight:900;color:#fff;">Edit SARO</h3>
            </div>
            <button onclick="closeEditSaroModal()"
                    style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,0.12);
                           border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Modal body -->
        <div style="padding:24px 28px;display:flex;flex-direction:column;gap:18px;max-height:72vh;overflow-y:auto;">
            <input type="hidden" id="edit-saro-id">
            <div>
                <label class="form-label">SARO Number</label>
                <input type="text" class="form-input" id="edit-saro-no">
            </div>
            <div>
                <label class="form-label">SARO Title</label>
                <input type="text" class="form-input" id="edit-saro-title">
            </div>
            <div>
                <label class="form-label">Fiscal Year</label>
                <input type="number" class="form-input" id="edit-fiscal-year" min="2020" max="2099">
            </div>
            <div>
                <label class="form-label">Total Budget (₱)</label>
                <input type="number" class="form-input" id="edit-total-budget" min="0" step="0.01">
            </div>
            <div>
                <label class="form-label">Status</label>
                <select class="form-input" id="edit-status">
                    <option value="active">Active</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
        </div>

        <!-- Modal footer -->
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;
                    display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="closeEditSaroModal()">Cancel</button>
            <button class="btn btn-primary" onclick="submitEditSaro()">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ══ Delete SARO Modal ══ -->
<div id="deleteSaroModal" style="display:none;position:fixed;inset:0;z-index:100;
     background:rgba(15,23,42,0.55);backdrop-filter:blur(4px);
     align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:18px;width:100%;max-width:400px;
                box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden;">

        <!-- Modal body -->
        <input type="hidden" id="delete-saro-id">
        <div style="padding:32px 28px 24px;display:flex;flex-direction:column;align-items:center;gap:16px;text-align:center;">
            <div style="width:56px;height:56px;border-radius:50%;background:#fee2e2;border:1px solid #fecaca;
                        display:flex;align-items:center;justify-content:center;color:#dc2626;">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </div>
            <div>
                <h3 style="font-size:16px;font-weight:800;color:#0f172a;margin-bottom:8px;">Delete SARO</h3>
                <p style="font-size:13px;color:#64748b;line-height:1.6;">
                    Are you sure you want to delete <strong id="delete-saro-label" style="color:#0f172a;"></strong>?
                    This action cannot be undone and will remove all associated object codes and procurement activities.
                </p>
            </div>
        </div>

        <!-- Modal footer -->
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;
                    display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="closeDeleteSaroModal()">Cancel</button>
            <button class="btn btn-primary" style="background:#dc2626;border-color:#dc2626;"
                    onmouseover="this.style.background='#b91c1c';this.style.borderColor='#b91c1c'"
                    onmouseout="this.style.background='#dc2626';this.style.borderColor='#dc2626'">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Delete Permanently
            </button>
        </div>
    </div>
</div>

<script>
    function openAddSaroModal()  { document.getElementById('addSaroModal').style.display = 'flex'; }
    function closeAddSaroModal() { document.getElementById('addSaroModal').style.display = 'none'; }
    document.getElementById('addSaroModal').addEventListener('click', function(e) {
        if (e.target === this) closeAddSaroModal();
    });

    function openEditSaroModal(saroNo, title, year, budget, status) {
        document.getElementById('edit-saro-no').value    = saroNo;
        document.getElementById('edit-saro-title').value = title;
        document.getElementById('edit-fiscal-year').value = year;
        document.getElementById('edit-total-budget').value = budget;
        document.getElementById('edit-status').value     = status;
        document.getElementById('editSaroModal').style.display = 'flex';
    }
    function closeEditSaroModal() { document.getElementById('editSaroModal').style.display = 'none'; }
    document.getElementById('editSaroModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditSaroModal();
    });

    function openDeleteSaroModal(saroNo) {
        document.getElementById('delete-saro-label').textContent = saroNo;
        document.getElementById('deleteSaroModal').style.display = 'flex';
    }
    function closeDeleteSaroModal() { document.getElementById('deleteSaroModal').style.display = 'none'; }
    document.getElementById('deleteSaroModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteSaroModal();
    });

    // Wire edit/delete buttons from data attributes set by PHP
    document.querySelectorAll('.action-btn-edit').forEach(btn => {
        btn.onclick = () => {
            document.getElementById('edit-saro-id').value     = btn.dataset.id;
            document.getElementById('edit-saro-no').value     = btn.dataset.no;
            document.getElementById('edit-saro-title').value  = btn.dataset.title;
            document.getElementById('edit-fiscal-year').value = btn.dataset.year;
            document.getElementById('edit-total-budget').value = btn.dataset.budget;
            document.getElementById('edit-status').value      = btn.dataset.status;
            document.getElementById('editSaroModal').style.display = 'flex';
        };
    });
    document.querySelectorAll('.action-btn-del').forEach(btn => {
        btn.onclick = () => {
            document.getElementById('delete-saro-id').value = btn.dataset.id;
            document.getElementById('delete-saro-label').textContent = btn.dataset.no;
            document.getElementById('deleteSaroModal').style.display = 'flex';
        };
    });

    // Wire the permanent delete button inside the delete modal
    document.querySelector('#deleteSaroModal .btn-primary').onclick = function() {
        submitDeleteSaro();
    };

    function submitAddSaro() {
        const codes = [];
        document.querySelectorAll('#objCodeList > div').forEach(row => {
            const inputs = row.querySelectorAll('input');
            if (inputs[0] && inputs[0].value.trim()) {
                codes.push({ code: inputs[0].value.trim(), item: inputs[1] ? inputs[1].value.trim() : '', cost: inputs[2] ? inputs[2].value : 0 });
            }
        });
        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('saro_no',      document.getElementById('add-saro-no').value.trim());
        fd.append('saro_title',   document.getElementById('add-saro-title').value.trim());
        fd.append('fiscal_year',  document.getElementById('add-fiscal-year').value.trim());
        fd.append('total_budget', document.getElementById('add-total-budget').value);
        fd.append('object_codes', JSON.stringify(codes));
        fetch('data_entry.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if (res.success) location.reload(); else alert(res.error || 'Error adding SARO.'); });
    }

    function submitEditSaro() {
        const fd = new FormData();
        fd.append('action',       'edit');
        fd.append('saro_id',      document.getElementById('edit-saro-id').value);
        fd.append('saro_no',      document.getElementById('edit-saro-no').value.trim());
        fd.append('saro_title',   document.getElementById('edit-saro-title').value.trim());
        fd.append('fiscal_year',  document.getElementById('edit-fiscal-year').value.trim());
        fd.append('total_budget', document.getElementById('edit-total-budget').value);
        fd.append('status',       document.getElementById('edit-status').value);
        fetch('data_entry.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if (res.success) location.reload(); else alert(res.error || 'Error updating SARO.'); });
    }

    function submitDeleteSaro() {
        const fd = new FormData();
        fd.append('action',  'delete');
        fd.append('saro_id', document.getElementById('delete-saro-id').value);
        fetch('data_entry.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if (res.success) location.reload(); else alert(res.error || 'Error deleting SARO.'); });
    }

    function addObjRow() {
        const list  = document.getElementById('objCodeList');
        const hint  = document.getElementById('objEmptyHint');
        if (hint) hint.style.display = 'none';
        const row = document.createElement('div');
        row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1fr 32px;gap:8px;' +
                            'padding:8px 10px;border-bottom:1px solid #f1f5f9;align-items:center;' +
                            'background:#fff;transition:background 0.15s ease;';
        row.onmouseenter = () => row.style.background = '#f5f8ff';
        row.onmouseleave = () => row.style.background = '#fff';
        row.innerHTML = `
            <input type="text" placeholder="e.g. 5-02-03-070"
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
            <input type="number" placeholder="0.00" min="0" step="0.01"
                   style="width:100%;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;
                          font-size:12px;font-family:'Poppins',sans-serif;font-weight:500;color:#0f172a;
                          background:#f8fafc;outline:none;transition:all 0.2s ease;"
                   onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.1)'"
                   onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc';this.style.boxShadow='none'">
            <button type="button" onclick="removeObjRow(this)"
                    title="Remove row"
                    style="width:28px;height:28px;border-radius:6px;border:1px solid transparent;
                           background:transparent;cursor:pointer;color:#94a3b8;
                           display:flex;align-items:center;justify-content:center;
                           transition:all 0.2s ease;flex-shrink:0;"
                    onmouseenter="this.style.background='#fee2e2';this.style.borderColor='#fecaca';this.style.color='#dc2626'"
                    onmouseleave="this.style.background='transparent';this.style.borderColor='transparent';this.style.color='#94a3b8'">
                <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </button>`;
        list.appendChild(row);
        row.querySelector('input').focus();
    }

    function removeObjRow(btn) {
        const list = document.getElementById('objCodeList');
        btn.closest('div[style]').remove();
        if (list.children.length === 0) {
            const hint = document.getElementById('objEmptyHint');
            if (hint) hint.style.display = '';
        }
    }
</script>
</body>
</html>
