<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/account.php'; // Adjust case depending on your file structure

// Initialize DB and Account class
$db = new Database();
$pdo = $db->connect();
$accountObj = new Account($pdo);

// User Session Data
$username = $_SESSION['full_name'] ?? 'User';
$role     = $_SESSION['role'] ?? 'Role';
$initials = $_SESSION['initials'] ?? 'U';
$userId   = (int)($_SESSION['user_id'] ?? 0);

// Fetch dynamic data — Admin users only, Super Admin excluded
$users = $accountObj->getAdminOnlyUsers();
$logs  = $accountObj->getAdminAuditLogs(100);

// Calculate Stats
$totalUsers  = count($users);
$totalLogs   = $accountObj->countAdminLogs();
$activeToday = 0;
$todayDate   = date('Y-m-d');

foreach ($users as $u) {
    if ($u['last_login'] && substr($u['last_login'], 0, 10) === $todayDate) {
        $activeToday++;
    }
}

require_once __DIR__ . '/../class/notification.php';
$notifObj      = new Notification();
$notifications = $notifObj->getRecentActivity((int)$_SESSION['user_id'], 10);
$unreadCount   = $notifObj->countUnread($userId);
$approvedPwReq = $notifObj->getApprovedPasswordNotification($userId);
$cancelledCount = (int)$pdo->query("SELECT COUNT(*) FROM saro WHERE status='cancelled'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs | DICT SARO Monitoring</title>
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
        .btn-ghost { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .btn-ghost:hover { border-color: #94a3b8; color: #0f172a; background: #f1f5f9; }
        .btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

        /* Search */
        .search-wrap { position: relative; }
        .search-input {
            padding: 8px 12px 8px 34px; border: 1px solid #e2e8f0; border-radius: 8px;
            background: #f8fafc; font-size: 12px; font-family: 'Poppins', sans-serif;
            width: 180px; outline: none; transition: all 0.2s ease;
        }
        .search-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }

        /* Two-column panels grid */
        .panels-grid {
            display: grid;
            grid-template-columns: 1fr 1.55fr;
            gap: 20px;
            align-items: start;
        }

        /* Table panel */
        .table-panel {
            background: #fff; border: 1px solid #e8edf5;
            border-radius: 16px; overflow: hidden;
            display: flex; flex-direction: column;
        }
        .panel-header {
            padding: 16px 20px; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        thead tr th {
            padding: 11px 16px;
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.1em;
            color: #fff; background: #0f172a;
            white-space: nowrap; text-align: left;
            border-right: 1px solid rgba(255,255,255,0.07);
        }
        thead tr th:last-child { border-right: none; }
        tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.15s ease; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 12px 16px; font-size: 12px; color: #475569; vertical-align: middle; }

        /* Badges */
        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px; border-radius: 99px;
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge-green  { background: #dcfce7; color: #16a34a; }
        .badge-red    { background: #fee2e2; color: #dc2626; }

        /* Role pill */
        .role-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px; border-radius: 7px;
            font-size: 10px; font-weight: 700;
        }
        .role-Super   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .role-Admin   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .role-default { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }

        /* Action type pill */
        .action-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px; border-radius: 6px;
            font-size: 10px; font-weight: 700; white-space: nowrap; text-transform: capitalize;
        }
        .action-login     { background: #eff6ff; color: #1d4ed8; }
        .action-create    { background: #f0fdf4; color: #15803d; }
        .action-edit      { background: #fef9c3; color: #b45309; }
        .action-delete    { background: #fee2e2; color: #dc2626; }
        .action-view      { background: #f5f3ff; color: #6d28d9; }
        .action-cancelled { background: #fee2e2; color: #dc2626; }
        .action-logout    { background: #f1f5f9; color: #475569; }

        /* User avatar small */
        .u-avatar {
            width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 800; color: #fff;
        }

        /* Show rows */
        .show-rows-wrap { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #64748b; font-weight: 500; }
        .show-rows-select {
            padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 11px; font-family: 'Poppins', sans-serif;
            color: #0f172a; background: #f8fafc; outline: none; cursor: pointer;
        }
        .panel-footer {
            padding: 12px 20px; border-top: 1px solid #f1f5f9; background: #fafbfe;
            display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        }
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
            <a href="procurement_stat.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Procurement Status
            </a>
            <p class="nav-section-label">Reports</p>
            <a href="#" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Export Records
            </a>
            <a href="audit_logs.php" class="nav-item active">
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
                <span class="breadcrumb-active">Activity Logs</span>
            </div>
            <div class="topbar-right">
                <!-- Notification -->
                <?php $isAdmin = false; $pendingPwCount = $pendingPwCount ?? 0; $approvedPwReq = $approvedPwReq ?? null; include __DIR__ . '/../includes/notif_dropdown.php'; ?>
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
                <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                                   text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">
                            Reports
                        </p>
                        <h2 style="font-size:22px;font-weight:900;color:#fff;
                                   text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">
                            Activity Logs
                        </h2>
                        <p style="font-size:13px;color:rgba(255,255,255,0.6);font-weight:400;max-width:480px;line-height:1.6;">
                            Monitor system users and track all actions performed within the SARO Monitoring System.
                        </p>
                    </div>
                    <!-- Summary chips -->
                    <div style="display:flex;gap:12px;flex-shrink:0;">
                        <div style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);
                                    border-radius:12px;padding:14px 20px;text-align:center;min-width:90px;">
                            <p style="font-size:22px;font-weight:900;color:#fff;line-height:1;"><?= $totalUsers ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Users</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);
                                    border-radius:12px;padding:14px 20px;text-align:center;min-width:90px;">
                            <p style="font-size:22px;font-weight:900;color:#60a5fa;line-height:1;"><?= $totalLogs ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Log Entries</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);
                                    border-radius:12px;padding:14px 20px;text-align:center;min-width:90px;">
                            <p style="font-size:22px;font-weight:900;color:#4ade80;line-height:1;"><?= $activeToday ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Active Today</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Two-column panels -->
            <div class="panels-grid">

                <!-- ── Users Panel ── -->
                <div class="table-panel">
                    <div class="panel-header">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:30px;height:30px;border-radius:8px;background:#eff6ff;
                                        display:flex;align-items:center;justify-content:center;">
                                <svg width="14" height="14" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            </div>
                            <div>
                                <p style="font-size:12px;font-weight:800;color:#0f172a;">Users</p>
                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Registered system accounts</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="search-wrap">
                                <svg class="search-icon" width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input type="text" class="search-input" placeholder="Type to search…">
                            </div>
                            <button class="btn btn-ghost btn-sm">
                                <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                                Filter
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th style="text-align:center;">Role</th>
                                    <th style="text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($users)): ?>
                                    <tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;">No users found.</td></tr>
                                <?php else: ?>
                                    <?php foreach($users as $u): 
                                        $fInitial = strtoupper(substr($u['first_name'], 0, 1));
                                        $lInitial = strtoupper(substr($u['last_name'], 0, 1));
                                        
                                        $lastActiveText = "Never logged in";
                                        if ($u['last_login']) {
                                            $lastActiveText = (substr($u['last_login'], 0, 10) === $todayDate) ? "Last active today" : "Last active " . date('M d', strtotime($u['last_login']));
                                        }

                                        $roleClass = 'role-default';
                                        if (strpos(strtolower($u['role']), 'super') !== false) $roleClass = 'role-Super';
                                        elseif (strpos(strtolower($u['role']), 'admin') !== false) $roleClass = 'role-Admin';
                                        
                                        $statusClass = $u['status'] === 'active' ? 'badge-green' : 'badge-red';
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:9px;">
                                                <span class="u-avatar" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);">
                                                    <?= htmlspecialchars($fInitial . $lInitial) ?>
                                                </span>
                                                <div>
                                                    <p style="font-weight:700;color:#0f172a;font-size:12px;line-height:1.2;"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></p>
                                                    <p style="font-size:10px;color:#94a3b8;font-weight:500;"><?= htmlspecialchars($lastActiveText) ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-size:11px;color:#64748b;"><?= htmlspecialchars($u['email']) ?></td>
                                        <td style="text-align:center;"><span class="role-pill <?= $roleClass ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                                        <td style="text-align:center;"><span class="badge <?= $statusClass ?>"><span class="badge-dot"></span><?= ucfirst(htmlspecialchars($u['status'])) ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="panel-footer">
                        <p style="font-size:11px;color:#94a3b8;font-weight:500;"><?= count($users) ?> users</p>
                    </div>
                </div>

                <!-- ── Logs Panel ── -->
                <div class="table-panel">
                    <div class="panel-header">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:30px;height:30px;border-radius:8px;background:#eff6ff;
                                        display:flex;align-items:center;justify-content:center;">
                                <svg width="14" height="14" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            </div>
                            <div>
                                <p style="font-size:12px;font-weight:800;color:#0f172a;">Logs</p>
                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Recent system activity records</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="search-wrap">
                                <svg class="search-icon" width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input type="text" class="search-input" placeholder="Type to search…">
                            </div>
                            <button class="btn btn-ghost btn-sm">
                                <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                                Filter
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th style="min-width:130px;">Timestamp</th>
                                    <th style="min-width:90px;">Name</th>
                                    <th style="text-align:center;min-width:90px;">Action</th>
                                    <th style="min-width:160px;">Details</th>
                                    <th style="min-width:130px;">Target Ref.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($logs)): ?>
                                    <tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8;">No activity logs found.</td></tr>
                                <?php else: ?>
                                    <?php foreach($logs as $l): 
                                        $logTime = strtotime($l['created_at']);
                                        $actionClass = 'action-' . strtolower($l['action']);
                                        $actorName = $l['first_name'] ? ($l['first_name'] . ' ' . $l['last_name']) : 'System / Deleted User';

                                        $tableMap = [
                                            'saro'        => 'SARO',
                                            'procurement' => 'Procurement Activity',
                                            'object_code' => 'Object Code',
                                        ];
                                        $tbl = strtolower($l['affected_table'] ?? '');
                                        $tableLabel = $tableMap[$tbl] ?? ucfirst($tbl);
                                        $actionLabel = match($l['action']) {
                                            'login'     => 'Logged In',
                                            'logout'    => 'Logged Out',
                                            'create'    => 'Created ' . $tableLabel,
                                            'edit'      => 'Edited ' . $tableLabel,
                                            'delete'    => 'Deleted ' . $tableLabel,
                                            'view'      => 'Viewed ' . $tableLabel,
                                            'cancelled' => 'Cancelled Procurement Activity',
                                            default     => ucfirst($l['action']),
                                        };

                                        $targetRef = '—';
                                        if (!empty($l['affected_table']) && !empty($l['record_id'])) {
                                            $targetRef = htmlspecialchars(ucfirst($l['affected_table']) . ' ID: ' . $l['record_id']);
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <p style="font-size:11px;font-weight:700;color:#0f172a;"><?= date('M d, Y', $logTime) ?></p>
                                            <p style="font-size:10px;color:#94a3b8;font-weight:500;"><?= date('h:i A', $logTime) ?></p>
                                        </td>
                                        <td style="font-size:12px;font-weight:600;color:#334155;white-space:nowrap;"><?= htmlspecialchars($actorName) ?></td>
                                        <td style="text-align:center;"><span class="action-pill <?= htmlspecialchars($actionClass) ?>"><?= htmlspecialchars($actionLabel) ?></span></td>
                                        <td style="font-size:11px;color:#64748b;"><?= htmlspecialchars($l['details']) ?></td>
                                        <td>
                                            <?php if($targetRef !== '—'): ?>
                                                <span style="font-size:11px;font-weight:700;color:#1d4ed8;background:#dbeafe;
                                                             padding:2px 8px;border-radius:5px;white-space:nowrap;">
                                                    <?= $targetRef ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size:11px;color:#94a3b8;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="panel-footer">
                        <div class="show-rows-wrap">
                            <span>Show</span>
                            <select class="show-rows-select">
                                <option>10 rows</option>
                                <option selected>50 rows</option>
                                <option>100 rows</option>
                            </select>
                        </div>
                        <p style="font-size:11px;color:#94a3b8;font-weight:500;">
                            Displaying <strong style="color:#475569;"><?= count($logs) ?></strong> of <strong style="color:#475569;"><?= $totalLogs ?></strong> entries
                        </p>
                    </div>
                </div>

            </div><!-- /panels-grid -->
        </div><!-- /content -->
    </main>
</div>
<script>
</script>
</body>
</html>