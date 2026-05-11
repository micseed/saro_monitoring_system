<?php
// admin/dashboard.php
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../class/database.php'; // This includes your Database class
require_once __DIR__ . '/../class/super_admin.php';

// 1. Initialize your Database class
$db = new Database();

// 2. Call your connect() method to get the PDO connection
$pdo = $db->connect();

// 3. Now pass that valid PDO connection to the SuperAdmin class
$admin = new SuperAdmin($pdo);

// Fetch dynamic dashboard data
$stats = $admin->getDashboardStats();
$recentUsers = $admin->getRecentUsers(4);
$pendingRequests = $admin->getPendingRequests(4);
$saroChartData = $admin->getSaroChartData(5);

// Session details
$username = $_SESSION['full_name'] ?? 'Admin User';
$role     = $_SESSION['role'] ?? 'Admin';
$initials = $_SESSION['initials'] ?? 'AD';
$adminId  = (int)($_SESSION['user_id'] ?? 0);

require_once __DIR__ . '/../class/notification.php';
$notifObj      = new Notification();
$notifications = $notifObj->getRecentActivity((int)$adminId, 10);
$unreadCount   = $notifObj->countUnread($adminId);
$pendingPwCount = $notifObj->countPendingPasswordRequests();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | DICT SARO Monitoring</title>
    <link href="../../dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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

        /* ── Stat cards ── */
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border: 1px solid #e8edf5; border-radius: 14px; padding: 20px 22px; position: relative; overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.07); }
        .stat-card-accent { position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: 14px 14px 0 0; }
        .stat-icon-wrap { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .stat-label { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 900; color: #0f172a; letter-spacing: -0.03em; line-height: 1; }
        .stat-meta { margin-top: 12px; padding-top: 12px; border-top: 1px solid #f1f5f9; }

        /* ── Panels ── */
        .panel { background: #fff; border: 1px solid #e8edf5; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; }
        .panel-header { padding: 16px 22px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .panel-footer { padding: 12px 22px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .panel-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

        /* ── Table ── */
        table { width: 100%; border-collapse: collapse; }
        thead th { padding: 11px 18px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.12em; color: #94a3b8; background: #fafbfe; border-bottom: 1px solid #f1f5f9; white-space: nowrap; text-align: left; }
        tbody tr { border-bottom: 1px solid #f8fafc; transition: background 0.15s ease; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 13px 18px; font-size: 12px; color: #475569; vertical-align: middle; }

        /* ── Badges ── */
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge-green  { background: #dcfce7; color: #16a34a; }
        .badge-red    { background: #fee2e2; color: #dc2626; }
        .badge-amber  { background: #fef9c3; color: #b45309; }
        .badge-blue   { background: #dbeafe; color: #1d4ed8; }

        /* ── Role pills ── */
        .role-pill { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 7px; font-size: 10px; font-weight: 700; }
        .role-admin   { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .role-encoder { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .role-viewer  { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }

        /* ── Buttons ── */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 9px; font-size: 12px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; text-decoration: none; transition: all 0.2s ease; border: 1px solid transparent; }
        .btn-primary { background: #dc2626; color: #fff; border-color: #dc2626; }
        .btn-primary:hover { background: #b91c1c; border-color: #b91c1c; box-shadow: 0 4px 12px rgba(220,38,38,0.3); }
        .btn-ghost { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .btn-ghost:hover { border-color: #94a3b8; color: #0f172a; }
        .btn-success { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
        .btn-success:hover { background: #dcfce7; border-color: #86efac; }
        .btn-danger { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .btn-danger:hover { background: #fee2e2; border-color: #f87171; }
        .btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

        /* ── User avatar ── */
        .u-avatar { width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; color: #fff; }

        /* ── Progress bar ── */
        .progress-bar { width: 100%; height: 5px; background: #f1f5f9; border-radius: 99px; overflow: hidden; margin-bottom: 4px; }
        .progress-fill { height: 100%; border-radius: 99px; }

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
            <a href="dashboard.php" class="nav-item active">
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
                <?php if($stats['pending_requests'] > 0): ?>
                    <span class="nav-badge"><?= $stats['pending_requests'] ?></span>
                <?php endif; ?>
            </a>
            <a href="activity_logs.php" class="nav-item">
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
                <span>Admin Panel</span>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="breadcrumb-active">Dashboard</span>
            </div>
            <div class="topbar-right">
                <!-- Notification -->
                <?php $isAdmin = true; $pendingPwCount = $pendingPwCount ?? 0; $approvedPwReq = $approvedPwReq ?? null; include __DIR__ . '/../includes/notif_dropdown.php'; ?>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                    <div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,#dc2626,#b91c1c);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;"><?= htmlspecialchars($initials) ?></div>
                    <div>
                        <p style="font-size:12px;font-weight:700;color:#0f172a;line-height:1.1;"><?= htmlspecialchars($username) ?></p>
                        <p style="font-size:10px;color:#94a3b8;font-weight:500;"><?= htmlspecialchars($role) ?></p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="content">
            <!-- Hero -->
            <div class="hero-banner">
                <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:24px;">
                    <div>
                        <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">Admin Control Panel</p>
                        <h2 style="font-size:22px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">System Overview</h2>
                        <p style="font-size:13px;color:rgba(255,255,255,0.65);font-weight:400;max-width:500px;line-height:1.6;">
                            Manage accounts, monitor SARO fund utilization, and process password requests for the
                            <strong style="color:rgba(255,255,255,0.9);font-weight:600;">DRRM - DICT.</strong>
                        </p>
                    </div>
                    <div style="display:flex;gap:12px;flex-shrink:0;">
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fff;line-height:1;"><?= $stats['total_users'] ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Users</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fca5a5;line-height:1;"><?= $stats['total_saros'] ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">SAROs</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fed7aa;line-height:1;"><?= $stats['pending_requests'] ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Pending</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#2563eb,#60a5fa);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;">
                            <p class="stat-label">Total Users</p>
                            <p class="stat-value"><?= sprintf('%02d', $stats['total_users']) ?></p>
                            <div class="stat-meta"><span class="badge badge-green"><span class="badge-dot"></span><?= $stats['active_users'] ?> Active</span></div>
                        </div>
                        <div class="stat-icon-wrap" style="background:#eff6ff;">
                            <svg width="26" height="26" fill="none" stroke="#2563eb" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#16a34a,#4ade80);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;">
                            <p class="stat-label">Active Sessions</p>
                            <p class="stat-value" style="color:#16a34a;"><?= sprintf('%02d', $stats['active_users']) ?></p>
                            <div class="stat-meta"><span class="badge badge-green"><span class="badge-dot" style="animation:pulse 2s infinite;"></span>Online Now</span></div>
                        </div>
                        <div class="stat-icon-wrap" style="background:#f0fdf4;">
                            <svg width="26" height="26" fill="none" stroke="#16a34a" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#ef4444,#f87171);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;">
                            <p class="stat-label">Password Requests</p>
                            <p class="stat-value" style="color:#dc2626;"><?= sprintf('%02d', $stats['pending_requests']) ?></p>
                            <div class="stat-meta"><span class="badge badge-red"><span class="badge-dot"></span>Pending</span></div>
                        </div>
                        <div class="stat-icon-wrap" style="background:#fef2f2;">
                            <svg width="26" height="26" fill="none" stroke="#dc2626" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        </div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#f59e0b,#fcd34d);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;">
                            <p class="stat-label">Total SAROs</p>
                            <p class="stat-value"><?= sprintf('%02d', $stats['total_saros']) ?></p>
                            <div class="stat-meta">
                                <div class="progress-bar"><div class="progress-fill" style="width:<?= $stats['utilization_rate'] ?>%;background:linear-gradient(90deg,#f59e0b,#fcd34d);"></div></div>
                                <p style="font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;"><?= $stats['utilization_rate'] ?>% Utilized</p>
                            </div>
                        </div>
                        <div class="stat-icon-wrap" style="background:#fffbeb;">
                            <svg width="26" height="26" fill="none" stroke="#d97706" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts row -->
            <div style="display:grid;grid-template-columns:1fr 1.8fr;gap:16px;margin-bottom:24px;">
                <div style="background:#fff;border:1px solid #e8edf5;border-radius:14px;padding:22px 24px;">
                    <p style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:2px;">Fund Utilization</p>
                    <p style="font-size:10px;color:#94a3b8;font-weight:500;margin-bottom:16px;">Obligated vs Unobligated</p>
                    <div style="position:relative;width:170px;height:170px;margin:0 auto 20px;">
                        <canvas id="donutChart"></canvas>
                        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
                            <p style="font-size:20px;font-weight:900;color:#0f172a;line-height:1;"><?= $stats['utilization_rate'] ?>%</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;">Utilized</p>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:3px;background:#dc2626;display:inline-block;"></span><span style="font-size:12px;color:#64748b;font-weight:500;">Obligated</span></div>
                            <span style="font-size:12px;font-weight:700;color:#0f172a;">₱<?= number_format($stats['total_obligated'], 2) ?></span>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:3px;background:#e2e8f0;display:inline-block;"></span><span style="font-size:12px;color:#64748b;font-weight:500;">Unobligated</span></div>
                            <span style="font-size:12px;font-weight:700;color:#0f172a;">₱<?= number_format($stats['unobligated'], 2) ?></span>
                        </div>
                    </div>
                </div>
                <div style="background:#fff;border:1px solid #e8edf5;border-radius:14px;padding:22px 24px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:2px;">SARO Budget Overview</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">Allocated vs Obligated per SARO</p>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="display:flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:3px;background:#fecaca;display:inline-block;"></span><span style="font-size:11px;color:#64748b;font-weight:500;">Allocated</span></div>
                            <div style="display:flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:3px;background:#dc2626;display:inline-block;"></span><span style="font-size:11px;color:#64748b;font-weight:500;">Obligated</span></div>
                        </div>
                    </div>
                    <!-- Assuming dynamic chart data logic will be implemented here later. Hardcoded for visual structure. -->
                    <div style="height:180px;"><canvas id="barChart"></canvas></div>
                </div>
            </div>

            <!-- Users + Requests row -->
            <div style="display:grid;grid-template-columns:1fr 1.15fr;gap:16px;">

                <!-- Users panel -->
                <div class="panel">
                    <div class="panel-header">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="panel-icon" style="background:#eff6ff;">
                                <svg width="14" height="14" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            </div>
                            <div>
                                <p style="font-size:12px;font-weight:800;color:#0f172a;">System Users</p>
                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Registered accounts</p>
                            </div>
                        </div>
                        <a href="users.php" class="btn btn-ghost btn-sm">View All</a>
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
                                <?php foreach($recentUsers as $u): ?>
                                    <?php 
                                        $userInitials = $admin->getInitials($u['first_name'], $u['last_name']); 
                                        $fullName = htmlspecialchars($u['first_name'] . ' ' . $u['last_name']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:9px;">
                                                <span class="u-avatar" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><?= $userInitials ?></span>
                                                <div>
                                                    <p style="font-weight:700;color:#0f172a;font-size:12px;line-height:1.2;"><?= $fullName ?></p>
                                                    <p style="font-size:10px;color:#94a3b8;"><?= htmlspecialchars($u['email']) ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="role-pill <?= strtolower($u['role']) === 'admin' ? 'role-admin' : 'role-encoder' ?>">
                                                <?= htmlspecialchars($u['role'] ?? 'User') ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if($u['status'] === 'active'): ?>
                                                <span class="badge badge-green"><span class="badge-dot"></span>Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-red"><span class="badge-dot"></span>Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="panel-footer">
                        <p style="font-size:11px;color:#94a3b8;font-weight:500;"><?= $stats['total_users'] ?> total accounts</p>
                        <a href="users.php" class="btn btn-primary btn-sm">
                            <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Create Account
                        </a>
                    </div>
                </div>

                <!-- Password Requests panel -->
                <div class="panel">
                    <div class="panel-header">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="panel-icon" style="background:#fef2f2;">
                                <svg width="14" height="14" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            </div>
                            <div>
                                <p style="font-size:12px;font-weight:800;color:#0f172a;">Password Requests</p>
                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Pending approvals</p>
                            </div>
                        </div>
                        <a href="password_requests.php" class="btn btn-ghost btn-sm">View All</a>
                    </div>

                    <!-- Request list items -->
                    <div>
                        <?php if(empty($pendingRequests)): ?>
                            <p style="padding: 14px 22px; font-size:12px; color:#94a3b8;">No pending requests.</p>
                        <?php else: ?>
                            <?php foreach($pendingRequests as $req): ?>
                                <?php 
                                    $reqInitials = $admin->getInitials($req['first_name'], $req['last_name']); 
                                    $reqName = htmlspecialchars($req['first_name'] . ' ' . $req['last_name']);
                                    $date = date('M d, Y', strtotime($req['requested_at']));
                                ?>
                                <div style="padding:14px 22px;border-bottom:1px solid #f8fafc;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <span class="u-avatar" style="background:linear-gradient(135deg,#16a34a,#15803d);"><?= $reqInitials ?></span>
                                        <div>
                                            <p style="font-size:12px;font-weight:700;color:#0f172a;"><?= $reqName ?></p>
                                            <p style="font-size:10px;color:#94a3b8;"><?= $date ?> · <?= htmlspecialchars($req['reason']) ?></p>
                                        </div>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <span class="badge badge-amber"><span class="badge-dot"></span>Pending</span>
                                        <button class="btn btn-success btn-sm" style="padding:4px 10px;" title="Approve"><svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></button>
                                        <button class="btn btn-danger btn-sm" style="padding:4px 10px;" title="Reject"><svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="panel-footer">
                        <p style="font-size:11px;color:#94a3b8;font-weight:500;">
                            <?php if($stats['pending_requests'] > 0): ?>
                                <strong style="color:#dc2626;"><?= $stats['pending_requests'] ?> pending</strong>
                            <?php else: ?>
                                All caught up!
                            <?php endif; ?>
                        </p>
                        <a href="password_requests.php" class="btn btn-ghost btn-sm">Manage All →</a>
                    </div>
                </div>

            </div><!-- /row -->
        </div><!-- /content -->
    </main>
</div>

<script>
    Chart.defaults.font.family = "'Poppins', sans-serif";
    Chart.defaults.font.size   = 11;

    // Passing PHP variables into JS for dynamic Donut Chart
    const totalObligated = <?= $stats['total_obligated'] ? $stats['total_obligated'] : 0 ?>;
    const totalUnobligated = <?= $stats['unobligated'] ? $stats['unobligated'] : 0 ?>;

    new Chart(document.getElementById('donutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Obligated', 'Unobligated'],
            datasets: [{ data: [totalObligated, totalUnobligated], backgroundColor: ['#dc2626', '#fee2e2'], borderWidth: 0, hoverOffset: 6 }]
        },
        options: {
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' ₱' + ctx.raw.toLocaleString() } }
            }
        }
    });

    const saroLabels    = <?= json_encode(array_column($saroChartData, 'saroNo')) ?>;
    const saroAllocated = <?= json_encode(array_map('floatval', array_column($saroChartData, 'total_budget'))) ?>;
    const saroObligated = <?= json_encode(array_map('floatval', array_column($saroChartData, 'total_obligated'))) ?>;

    if (saroLabels.length > 0) {
        new Chart(document.getElementById('barChart'), {
            type: 'bar',
            data: {
                labels: saroLabels,
                datasets: [
                    { label: 'Allocated', data: saroAllocated, backgroundColor: '#fecaca', borderRadius: 6, borderSkipped: false },
                    { label: 'Obligated', data: saroObligated, backgroundColor: '#dc2626', borderRadius: 6, borderSkipped: false }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ' ₱' + ctx.raw.toLocaleString() } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { weight: '600' } }, border: { display: false } },
                    y: { grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', font: { weight: '600' }, callback: v => '₱' + v.toLocaleString() }, border: { display: false } }
                }
            }
        });
    } else {
        const barCtx = document.getElementById('barChart');
        barCtx.style.display = 'none';
        barCtx.insertAdjacentHTML('afterend', '<div style="height:180px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;"><svg width="32" height="32" fill="none" stroke="#cbd5e1" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg><p style="font-size:12px;color:#94a3b8;font-weight:600;">No SARO data available</p></div>');
    }
</script>
<script src="../assets/js/table_controls.js"></script>
</body>
</html>