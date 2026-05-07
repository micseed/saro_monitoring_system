<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/database.php';

$username = $_SESSION['full_name'];
$role     = $_SESSION['role'];
$initials = $_SESSION['initials'];

$db  = new Database();
$pdo = $db->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Fetch all users (all roles visible in admin panel)
$allUsers = $pdo->query("
    SELECT u.userId, u.first_name, u.last_name, u.status, u.last_login, ur.role
    FROM user u
    JOIN user_role ur ON ur.roleId = u.roleId
    ORDER BY u.first_name ASC
")->fetchAll();

// Fetch audit logs with actor names
$logs = $pdo->query("
    SELECT a.*, u.first_name, u.last_name
    FROM audit_logs a
    LEFT JOIN user u ON a.userId = u.userId
    ORDER BY a.timestamp DESC
    LIMIT 100
")->fetchAll();

$totalUsers  = count($allUsers);
$totalLogs   = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$activeToday = 0;
$todayDate   = date('Y-m-d');
foreach ($allUsers as $u) {
    if ($u['last_login'] && substr($u['last_login'], 0, 10) === $todayDate) {
        $activeToday++;
    }
}
$pendingReqCount = (int)$pdo->query("SELECT COUNT(*) FROM password_requests WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs | Admin Panel | DICT SARO Monitoring</title>
    <link href="../../dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; background: #f0f4ff; }
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #c7d7fe; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #93c5fd; }
        .layout { display: flex; height: 100vh; }

        /* ── Sidebar ── */
        .sidebar { width: 256px; flex-shrink: 0; display: flex; flex-direction: column; background: #0f172a; position: relative; overflow: hidden; }
        .sidebar::before { content: ''; position: absolute; top: -80px; right: -80px; width: 220px; height: 220px; background: #7f1d1d; border-radius: 50%; opacity: 0.3; pointer-events: none; }
        .sidebar::after { content: ''; position: absolute; bottom: -60px; left: -60px; width: 180px; height: 180px; background: #991b1b; border-radius: 50%; opacity: 0.15; pointer-events: none; }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; padding: 28px 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; padding: 6px; flex-shrink: 0; }
        .admin-tag { margin: 14px 16px 0; padding: 7px 12px; background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.25); border-radius: 8px; position: relative; z-index: 1; display: flex; align-items: center; gap: 8px; }
        .admin-tag-dot { width: 7px; height: 7px; border-radius: 50%; background: #ef4444; animation: blink 2s infinite; flex-shrink: 0; }
        .sidebar-nav { flex: 1; padding: 16px 16px 20px; overflow-y: auto; position: relative; z-index: 1; }
        .nav-section-label { font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.25); text-transform: uppercase; letter-spacing: 0.16em; padding: 0 8px; margin-bottom: 8px; margin-top: 20px; }
        .nav-section-label:first-child { margin-top: 0; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px; font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.45); text-decoration: none; cursor: pointer; border: none; background: none; width: 100%; text-align: left; transition: all 0.2s ease; margin-bottom: 2px; }
        .nav-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.85); }
        .nav-item.active { background: #7f1d1d; color: #fff; box-shadow: 0 0 0 1px rgba(239,68,68,0.3), inset 0 1px 0 rgba(255,255,255,0.08); }
        .nav-item.active .nav-icon { color: #fca5a5; }
        .nav-icon { width: 16px; height: 16px; flex-shrink: 0; }
        .nav-badge { margin-left: auto; background: #ef4444; color: #fff; font-size: 9px; font-weight: 800; padding: 2px 7px; border-radius: 99px; }
        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 16px 0; }
        .sidebar-footer { padding: 16px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
        .user-card { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; background: rgba(255,255,255,0.05); margin-bottom: 8px; }
        .user-avatar { width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg, #dc2626, #b91c1c); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; color: #fff; flex-shrink: 0; }
        .signout-btn { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.4); background: none; border: none; cursor: pointer; text-decoration: none; transition: all 0.2s ease; }
        .signout-btn:hover { background: rgba(239,68,68,0.12); color: #fca5a5; }

        /* ── Main ── */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { height: 64px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: #fff; border-bottom: 1px solid #e8edf5; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #64748b; }
        .breadcrumb-active { color: #0f172a; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 9px; background: #f8fafc; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b; transition: all 0.2s ease; position: relative; }
        .icon-btn:hover { border-color: #ef4444; color: #dc2626; background: #fef2f2; }
        .notif-dot { position: absolute; top: 7px; right: 7px; width: 7px; height: 7px; background: #ef4444; border-radius: 50%; border: 1.5px solid #fff; }
        .content { flex: 1; overflow-y: auto; padding: 28px 32px; }

        /* ── Hero ── */
        .hero-banner { background: linear-gradient(135deg, #7f1d1d 0%, #dc2626 100%); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; position: relative; overflow: hidden; }
        .hero-banner::before { content: ''; position: absolute; top: -60px; right: -40px; width: 220px; height: 220px; background: rgba(255,255,255,0.07); border-radius: 50%; }
        .hero-banner::after { content: ''; position: absolute; bottom: -40px; right: 120px; width: 140px; height: 140px; background: rgba(255,255,255,0.05); border-radius: 50%; }

        /* ── Buttons ── */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 9px; font-size: 12px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; text-decoration: none; transition: all 0.2s ease; border: 1px solid transparent; }
        .btn-ghost { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .btn-ghost:hover { border-color: #94a3b8; color: #0f172a; background: #f1f5f9; }
        .btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

        /* ── Search ── */
        .search-wrap { position: relative; }
        .search-input { padding: 8px 12px 8px 34px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; font-size: 12px; font-family: 'Poppins', sans-serif; width: 180px; outline: none; transition: all 0.2s ease; }
        .search-input:focus { border-color: #ef4444; background: #fff; box-shadow: 0 0 0 3px rgba(239,68,68,0.1); }
        .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }

        /* ── Filter tabs ── */
        .filter-tabs { display: flex; align-items: center; gap: 4px; background: #f1f5f9; border-radius: 10px; padding: 4px; }
        .filter-tab { padding: 6px 16px; border-radius: 7px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.2s; color: #64748b; border: none; background: transparent; font-family: 'Poppins', sans-serif; }
        .filter-tab.active { background: #fff; color: #0f172a; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        .filter-tab:hover:not(.active) { color: #0f172a; }

        /* ── Panels grid ── */
        .panels-grid { display: grid; grid-template-columns: 1fr 1.55fr; gap: 20px; align-items: start; }

        /* ── Table panel ── */
        .table-panel { background: #fff; border: 1px solid #e8edf5; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; }
        .panel-header { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .panel-footer { padding: 12px 20px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }

        /* ── Tables ── */
        table { width: 100%; border-collapse: collapse; }
        thead tr th { padding: 11px 16px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #fff; background: #0f172a; white-space: nowrap; text-align: left; border-right: 1px solid rgba(255,255,255,0.07); }
        thead tr th:last-child { border-right: none; }
        tbody tr { border-bottom: 1px solid #f1f5f9; transition: background 0.15s ease; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 12px 16px; font-size: 12px; color: #475569; vertical-align: middle; }

        /* ── Badges ── */
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge-green  { background: #dcfce7; color: #16a34a; }
        .badge-red    { background: #fee2e2; color: #dc2626; }
        .badge-blue   { background: #dbeafe; color: #1d4ed8; }
        .badge-amber  { background: #fef9c3; color: #b45309; }

        /* ── Role pill ── */
        .role-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 7px; font-size: 10px; font-weight: 700; }
        .role-admin   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .role-encoder { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .role-viewer  { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }

        /* ── Action type pill ── */
        .action-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 6px; font-size: 10px; font-weight: 700; white-space: nowrap; }
        .action-login   { background: #eff6ff; color: #1d4ed8; }
        .action-create  { background: #f0fdf4; color: #15803d; }
        .action-edit    { background: #fef9c3; color: #b45309; }
        .action-delete  { background: #fee2e2; color: #dc2626; }
        .action-view    { background: #f5f3ff; color: #6d28d9; }
        .action-logout  { background: #f1f5f9; color: #475569; }
        .action-approve { background: #dcfce7; color: #15803d; }
        .action-reject  { background: #fee2e2; color: #dc2626; }

        /* ── User avatar small ── */
        .u-avatar { width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: #fff; }

        /* ── Show rows ── */
        .show-rows-wrap { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #64748b; font-weight: 500; }
        .show-rows-select { padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 11px; font-family: 'Poppins', sans-serif; color: #0f172a; background: #f8fafc; outline: none; cursor: pointer; }

        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
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

        <div class="admin-tag">
            <div class="admin-tag-dot"></div>
            <p style="font-size:9px;font-weight:800;color:#fca5a5;text-transform:uppercase;letter-spacing:0.15em;">Admin Control Panel</p>
        </div>

        <nav class="sidebar-nav">
            <p class="nav-section-label">Overview</p>
            <a href="dashboard.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                Dashboard
            </a>

            <p class="nav-section-label">Management</p>
            <a href="users.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                Users
            </a>
            <a href="password_requests.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                Password Requests
                <?php if ($pendingReqCount > 0): ?><span class="nav-badge"><?= $pendingReqCount ?></span><?php endif; ?>
            </a>
            <a href="activity_logs.php" class="nav-item active">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Activity Logs
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
                <a href="dashboard.php" style="text-decoration:none;color:inherit;">Admin Panel</a>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="breadcrumb-active">Activity Logs</span>
            </div>
            <div class="topbar-right">
                <div class="icon-btn">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <span class="notif-dot"></span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                    <div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,#dc2626,#b91c1c);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;"><?= htmlspecialchars($initials) ?></div>
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
                <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:24px;">
                    <div>
                        <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">Admin Control Panel</p>
                        <h2 style="font-size:22px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">Activity Logs</h2>
                        <p style="font-size:13px;color:rgba(255,255,255,0.65);font-weight:400;max-width:500px;line-height:1.6;">
                            Full audit trail of all user actions across the DICT SARO Monitoring System.
                        </p>
                    </div>
                    <div style="display:flex;gap:12px;flex-shrink:0;">
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fff;line-height:1;"><?= $totalUsers ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Users</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fca5a5;line-height:1;"><?= $totalLogs ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Log Entries</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#86efac;line-height:1;"><?= $activeToday ?></p>
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
                            <div style="width:30px;height:30px;border-radius:8px;background:#fef2f2;display:flex;align-items:center;justify-content:center;">
                                <svg width="14" height="14" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            </div>
                            <div>
                                <p style="font-size:12px;font-weight:800;color:#0f172a;">Users</p>
                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Registered system accounts</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="search-wrap">
                                <svg class="search-icon" width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input type="text" class="search-input" placeholder="Search users…">
                            </div>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th style="text-align:center;">Role</th>
                                    <th style="text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allUsers)): ?>
                                <tr><td colspan="3" style="text-align:center;padding:20px;color:#94a3b8;font-size:12px;">No users found.</td></tr>
                                <?php else: foreach ($allUsers as $u):
                                    $fI = strtoupper(substr($u['first_name'], 0, 1));
                                    $lI = strtoupper(substr($u['last_name'],  0, 1));
                                    $lastActiveText = 'Never logged in';
                                    if ($u['last_login']) {
                                        $lastActiveText = (substr($u['last_login'], 0, 10) === $todayDate)
                                            ? 'Last active today'
                                            : 'Last active ' . date('M d', strtotime($u['last_login']));
                                    }
                                    $roleClass  = strpos(strtolower($u['role']), 'super') !== false ? 'role-admin' : 'role-admin';
                                    $statusCls  = $u['status'] === 'active' ? 'badge-green' : 'badge-red';
                                    $statusLabel = ucfirst($u['status']);
                                ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:9px;">
                                            <span class="u-avatar" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><?= htmlspecialchars($fI . $lI) ?></span>
                                            <div>
                                                <p style="font-weight:700;color:#0f172a;font-size:12px;line-height:1.2;"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></p>
                                                <p style="font-size:10px;color:#94a3b8;font-weight:500;"><?= htmlspecialchars($lastActiveText) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="text-align:center;"><span class="role-pill role-admin"><?= htmlspecialchars($u['role']) ?></span></td>
                                    <td style="text-align:center;"><span class="badge <?= $statusCls ?>"><span class="badge-dot"></span><?= $statusLabel ?></span></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="panel-footer">
                        <div class="show-rows-wrap">
                            <span>Show</span>
                            <select class="show-rows-select">
                                <option>10 rows</option>
                                <option selected>20 rows</option>
                                <option>50 rows</option>
                            </select>
                        </div>
                        <p style="font-size:11px;color:#94a3b8;font-weight:500;"><?= $totalUsers ?> user<?= $totalUsers !== 1 ? 's' : '' ?></p>
                    </div>
                </div>

                <!-- ── Logs Panel ── -->
                <div class="table-panel">
                    <div class="panel-header">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:30px;height:30px;border-radius:8px;background:#fef2f2;display:flex;align-items:center;justify-content:center;">
                                <svg width="14" height="14" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            </div>
                            <div>
                                <p style="font-size:12px;font-weight:800;color:#0f172a;">All Activity Logs</p>
                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Complete audit trail of system actions</p>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div class="filter-tabs">
                                <button class="filter-tab active" onclick="filterTab(this)">All</button>
                                <button class="filter-tab" onclick="filterTab(this)">Login</button>
                                <button class="filter-tab" onclick="filterTab(this)">Changes</button>
                                <button class="filter-tab" onclick="filterTab(this)">Admin</button>
                            </div>
                            <div class="search-wrap">
                                <svg class="search-icon" width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                <input type="text" class="search-input" placeholder="Search logs…">
                            </div>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th style="min-width:130px;">Timestamp</th>
                                    <th style="min-width:110px;">User</th>
                                    <th style="text-align:center;min-width:90px;">Action</th>
                                    <th style="min-width:200px;">Details</th>
                                    <th style="min-width:130px;">Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:24px;color:#94a3b8;font-size:12px;">No activity logs found.</td></tr>
                                <?php else: foreach ($logs as $l):
                                    $logTs      = strtotime($l['timestamp']);
                                    $logDate    = date('M j, Y', $logTs);
                                    $logTime    = date('h:i A', $logTs);
                                    $action     = $l['action'] ?? 'Unknown';
                                    $actionCls  = 'action-' . strtolower(preg_replace('/\s+/', '-', $action));
                                    $actorFirst = $l['first_name'] ?? '';
                                    $actorLast  = $l['last_name']  ?? '';
                                    $actorName  = $actorFirst ? htmlspecialchars($actorFirst . ' ' . $actorLast) : 'System / Deleted User';
                                    $actorInit  = $actorFirst ? strtoupper(substr($actorFirst,0,1) . substr($actorLast,0,1)) : 'SYS';
                                    $details    = htmlspecialchars($l['details'] ?? '');
                                    $targetRef  = $l['target_ref'] ?? '';
                                    $avatarColors = ['#2563eb,#1d4ed8','#16a34a,#15803d','#dc2626,#b91c1c','#7c3aed,#6d28d9','#0891b2,#0e7490','#d97706,#b45309'];
                                    $colorIdx   = $actorFirst ? (crc32($actorFirst . $actorLast) % count($avatarColors)) : 0;
                                    $avatarColor = $avatarColors[abs($colorIdx)];
                                ?>
                                <tr>
                                    <td>
                                        <p style="font-size:11px;font-weight:700;color:#0f172a;"><?= $logDate ?></p>
                                        <p style="font-size:10px;color:#94a3b8;font-weight:500;"><?= $logTime ?></p>
                                    </td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:7px;">
                                            <span class="u-avatar" style="background:linear-gradient(135deg,<?= $avatarColor ?>);"><?= $actorInit ?></span>
                                            <span style="font-size:12px;font-weight:600;color:#334155;"><?= $actorName ?></span>
                                        </div>
                                    </td>
                                    <td style="text-align:center;"><span class="action-pill <?= htmlspecialchars($actionCls) ?>"><?= htmlspecialchars($action) ?></span></td>
                                    <td style="font-size:11px;color:#64748b;"><?= $details ?></td>
                                    <td>
                                        <?php if ($targetRef): ?>
                                        <span style="font-size:11px;font-weight:700;color:#1d4ed8;background:#dbeafe;padding:2px 8px;border-radius:5px;white-space:nowrap;"><?= htmlspecialchars($targetRef) ?></span>
                                        <?php else: ?>
                                        <span style="font-size:11px;color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

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
                            Displaying <strong style="color:#475569;"><?= count($logs) ?></strong> of <strong style="color:#475569;"><?= $totalLogs ?></strong> entries
                        </p>
                    </div>
                </div>

            </div><!-- /panels-grid -->
        </div><!-- /content -->
    </main>
</div>

<script>
    function filterTab(el) {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
    }
</script>
</body>
</html>
