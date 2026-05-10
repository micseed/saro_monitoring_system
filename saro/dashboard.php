<?php
require_once __DIR__ . '/../includes/auth.php';
$username = $_SESSION['full_name'];
$role     = $_SESSION['role'];
$initials = $_SESSION['initials'];

require_once __DIR__ . '/../class/database.php';
$db  = new Database();
$pdo = $db->connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

require_once __DIR__ . '/../class/notification.php';

$totalSaros     = (int)$pdo->query("SELECT COUNT(*) FROM saro WHERE status='active'")->fetchColumn();
$totalBudget    = (float)$pdo->query("SELECT COALESCE(SUM(total_budget),0) FROM saro WHERE status='active'")->fetchColumn();
$totalObligated = (float)$pdo->query("
    SELECT COALESCE(SUM(p.obligated_amount),0)
    FROM procurement p
    JOIN object_code o ON p.objectId = o.objectId
    WHERE p.status = 'obligated'
")->fetchColumn();
$unobligated = $totalBudget - $totalObligated;
$utilRate    = $totalBudget > 0 ? round($totalObligated / $totalBudget * 100, 1) : 0;

$notifObj      = new Notification();
$notifications = $notifObj->getRecentActivity((int)$_SESSION['user_id'], 10);
$unreadCount   = $notifObj->countUnread((int)$_SESSION['user_id']);
$approvedPwReq = $notifObj->getApprovedPasswordNotification((int)$_SESSION['user_id']);
$cancelledCount = (int)$pdo->query("SELECT COUNT(*) FROM saro WHERE status='cancelled'")->fetchColumn();

$saros = $pdo->query("
    SELECT s.saroId, s.saroNo, s.saro_title, s.total_budget, s.status,
           COALESCE(SUM(p.obligated_amount),0) AS obligated
    FROM saro s
    LEFT JOIN object_code o ON o.saroId = s.saroId
    LEFT JOIN procurement p ON p.objectId = o.objectId AND p.status = 'obligated'
    WHERE s.status = 'active'
    GROUP BY s.saroId
    ORDER BY s.created_at ASC
")->fetchAll();

$chartLabels    = json_encode(array_column($saros, 'saroNo'));
$chartBudgets   = json_encode(array_map(fn($r) => (float)$r['total_budget'], $saros));
$chartObligated = json_encode(array_map(fn($r) => (float)$r['obligated'], $saros));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | DICT SARO Monitoring</title>
    <link href="../dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; overflow: hidden; background: #f0f4ff; }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #c7d7fe; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #93c5fd; }

        /* ── Layout ── */
        .layout { display: flex; height: 100vh; }

        /* ── Sidebar ── */
        .sidebar {
            width: 256px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            background: #0f172a;
            position: relative;
            overflow: hidden;
        }
        .sidebar::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 220px; height: 220px;
            background: #1e3a8a;
            border-radius: 50%;
            opacity: 0.4;
            pointer-events: none;
        }
        .sidebar::after {
            content: '';
            position: absolute;
            bottom: -60px; left: -60px;
            width: 180px; height: 180px;
            background: #1d4ed8;
            border-radius: 50%;
            opacity: 0.2;
            pointer-events: none;
        }

        /* Brand */
        .sidebar-brand {
            display: flex; align-items: center; gap: 12px;
            padding: 28px 24px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            position: relative; z-index: 1;
        }
        .brand-logo {
            width: 40px; height: 40px; border-radius: 50%;
            background: #fff;
            display: flex; align-items: center; justify-content: center;
            padding: 6px;
            flex-shrink: 0;
        }

        /* Nav */
        .sidebar-nav { flex: 1; padding: 20px 16px; overflow-y: auto; position: relative; z-index: 1; }
        .nav-section-label {
            font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.25);
            text-transform: uppercase; letter-spacing: 0.16em;
            padding: 0 8px; margin-bottom: 8px; margin-top: 20px;
        }
        .nav-section-label:first-child { margin-top: 0; }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px; font-weight: 500;
            color: rgba(255,255,255,0.45);
            text-decoration: none; cursor: pointer;
            border: none; background: none; width: 100%; text-align: left;
            transition: all 0.2s ease;
            margin-bottom: 2px;
        }
        .nav-item:hover {
            background: rgba(255,255,255,0.07);
            color: rgba(255,255,255,0.85);
        }
        .nav-item.active {
            background: #1e3a8a;
            color: #fff;
            box-shadow: 0 0 0 1px rgba(59,130,246,0.3), inset 0 1px 0 rgba(255,255,255,0.08);
        }
        .nav-item.active .nav-icon { color: #60a5fa; }
        .nav-icon { width: 16px; height: 16px; flex-shrink: 0; }

        /* Sidebar footer */
        .sidebar-footer { padding: 16px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
        .user-card {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 10px;
            background: rgba(255,255,255,0.05);
            margin-bottom: 8px;
        }
        .user-avatar {
            width: 34px; height: 34px; border-radius: 8px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; color: #fff;
            flex-shrink: 0;
        }
        .signout-btn {
            display: flex; align-items: center; gap: 10px;
            width: 100%; padding: 9px 12px; border-radius: 10px;
            font-size: 12px; font-weight: 600;
            color: rgba(255,255,255,0.4);
            background: none; border: none; cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .signout-btn:hover { background: rgba(239,68,68,0.12); color: #fca5a5; }

        /* ── Main ── */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

        /* Topbar */
        .topbar {
            height: 64px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 32px;
            background: #fff;
            border-bottom: 1px solid #e8edf5;
        }
        .breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 600; color: #64748b;
        }
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

        /* ── Content ── */
        .content { flex: 1; overflow-y: auto; padding: 28px 32px; }

        /* Hero banner */
        .hero-banner {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            border-radius: 16px;
            padding: 28px 32px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            top: -60px; right: -40px;
            width: 220px; height: 220px;
            background: rgba(255,255,255,0.07);
            border-radius: 50%;
        }
        .hero-banner::after {
            content: '';
            position: absolute;
            bottom: -40px; right: 120px;
            width: 140px; height: 140px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }

        /* Stat cards */
        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card {
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 14px;
            padding: 22px 24px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.07); }
        .stat-card-accent {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 14px 14px 0 0;
        }
        .stat-icon-wrap {
            width: 64px; height: 64px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .stat-label {
            font-size: 10px; font-weight: 700; color: #94a3b8;
            text-transform: uppercase; letter-spacing: 0.1em;
            margin-bottom: 6px;
        }
        .stat-value {
            font-size: 26px; font-weight: 900; color: #0f172a;
            letter-spacing: -0.03em;
            line-height: 1;
        }
        .stat-meta {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        /* Progress bar */
        .progress-bar {
            width: 100%; height: 5px;
            background: #f1f5f9;
            border-radius: 99px;
            overflow: hidden;
            margin-bottom: 6px;
        }
        .progress-fill { height: 100%; border-radius: 99px; }

        /* ── Table panel ── */
        .table-panel {
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .panel-header {
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .search-wrap { position: relative; }
        .search-input {
            padding: 8px 12px 8px 36px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            font-size: 12px;
            font-family: 'Poppins', sans-serif;
            width: 240px;
            outline: none;
            transition: all 0.2s ease;
        }
        .search-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; }

        .tb-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 8px;
            font-size: 12px; font-weight: 600;
            font-family: 'Poppins', sans-serif;
            border: 1px solid #e2e8f0; background: #fff; color: #475569;
            cursor: pointer; text-decoration: none;
            transition: all 0.2s ease;
        }
        .tb-btn:hover { border-color: #94a3b8; color: #0f172a; background: #f8fafc; }
        .tb-btn-primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .tb-btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; box-shadow: 0 4px 12px rgba(37,99,235,0.3); }

        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 12px 20px;
            font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.12em;
            color: #94a3b8; background: #fafbfe;
            border-bottom: 1px solid #f1f5f9;
            white-space: nowrap;
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
        .badge-red   { background: #fee2e2; color: #dc2626; }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

        .panel-footer {
            padding: 14px 24px;
            border-top: 1px solid #f1f5f9;
            background: #fafbfe;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
<div class="layout">

    <!-- ══ Sidebar ══ -->
    <aside class="sidebar">

        <!-- Brand -->
        <div class="sidebar-brand">
            <div class="brand-logo">
                <img src="../assets/dict_logo.png" alt="DICT Logo" style="width:100%;height:100%;object-fit:contain;">
            </div>
            <div>
                <p style="font-size:13px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:0.05em;line-height:1.1;">DICT Portal</p>
                <p style="font-size:8px;color:rgba(255,255,255,0.3);font-weight:700;text-transform:uppercase;letter-spacing:0.2em;">Region IX &amp; BASULTA</p>
            </div>
        </div>

        <!-- Nav -->
        <nav class="sidebar-nav">
            <p class="nav-section-label">Main</p>
            <a href="#" class="nav-item active">
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
            <p class="nav-section-label">Account</p>
            <a href="settings.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
                Settings
            </a>
        </nav>

        <!-- Footer -->
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
                <span class="breadcrumb-active">Dashboard</span>
            </div>
            <div class="topbar-right">
                <!-- Notification -->
                <?php $isAdmin = false; $pendingPwCount = $pendingPwCount ?? 0; $approvedPwReq = $approvedPwReq ?? null; include __DIR__ . '/../includes/notif_dropdown.php'; ?>
                <!-- User chip -->
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

        <!-- Content -->
        <div class="content">

            <!-- Hero banner -->
            <div class="hero-banner">
                <div style="position:relative;z-index:1;">
                    <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                               text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">
                        Dashboard Overview
                    </p>
                    <h2 style="font-size:22px;font-weight:900;color:#fff;
                               text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">
                        Welcome back, <span style="color:#93c5fd;"><?= htmlspecialchars($username) ?>!</span>
                    </h2>
                    <p style="font-size:13px;color:rgba(255,255,255,0.6);font-weight:400;max-width:520px;line-height:1.6;">
                        Real-time SARO tracking is active for the <strong style="color:rgba(255,255,255,0.85);font-weight:600;">Zamboanga-BASULTA</strong> cluster.
                        System status is <span style="color:#86efac;font-weight:700;">Operational</span>.
                    </p>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="stat-grid">

                <!-- Total SAROs -->
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#2563eb,#60a5fa);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                        <div style="flex:1;min-width:0;">
                            <p class="stat-label">Total SAROs</p>
                            <p class="stat-value"><?= $totalSaros ?></p>
                            <div class="stat-meta">
                                <span class="badge badge-green">
                                    <span class="badge-dot" style="animation:pulse 2s infinite;"></span>
                                    Live System
                                </span>
                            </div>
                        </div>
                        <div class="stat-icon-wrap" style="background:#eff6ff;">
                            <svg width="32" height="32" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                    </div>
                </div>

                <!-- Total Obligated -->
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#1d4ed8,#3b82f6);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                        <div style="flex:1;min-width:0;">
                            <p class="stat-label">Total Obligated</p>
                            <p class="stat-value" style="font-size:20px;">₱<?= number_format($totalObligated,2) ?></p>
                            <div class="stat-meta">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width:<?= $utilRate ?>%;background:linear-gradient(90deg,#2563eb,#60a5fa);"></div>
                                </div>
                                <p style="font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;"><?= $utilRate ?>% Utilization Rate</p>
                            </div>
                        </div>
                        <div class="stat-icon-wrap" style="background:#eff6ff;">
                            <svg width="32" height="32" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                </div>

                <!-- Unobligated -->
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#f59e0b,#fcd34d);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                        <div style="flex:1;min-width:0;">
                            <p class="stat-label">Unobligated</p>
                            <p class="stat-value" style="color:#b45309;font-size:20px;">₱<?= number_format($unobligated,2) ?></p>
                            <div class="stat-meta">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width:<?= 100-$utilRate ?>%;background:linear-gradient(90deg,#f59e0b,#fcd34d);"></div>
                                </div>
                                <p style="font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;">Available Balance</p>
                            </div>
                        </div>
                        <div class="stat-icon-wrap" style="background:#fffbeb;">
                            <svg width="32" height="32" fill="none" stroke="#d97706" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Charts row -->
            <div style="display:grid;grid-template-columns:1fr 1.8fr;gap:16px;margin-bottom:24px;">

                <!-- Donut: Obligated vs Unobligated -->
                <div style="background:#fff;border:1px solid #e8edf5;border-radius:14px;padding:22px 24px;">
                    <div style="margin-bottom:16px;">
                        <p style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:2px;">Fund Utilization</p>
                        <p style="font-size:10px;color:#94a3b8;font-weight:500;">Obligated vs Unobligated</p>
                    </div>
                    <div style="position:relative;width:180px;height:180px;margin:0 auto 20px;">
                        <canvas id="donutChart"></canvas>
                        <div style="position:absolute;inset:0;display:flex;flex-direction:column;
                                    align-items:center;justify-content:center;pointer-events:none;">
                            <p style="font-size:20px;font-weight:900;color:#0f172a;line-height:1;"><?= $utilRate ?>%</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;">Utilized</p>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="width:10px;height:10px;border-radius:3px;background:#2563eb;flex-shrink:0;display:inline-block;"></span>
                                <span style="font-size:12px;color:#64748b;font-weight:500;">Obligated</span>
                            </div>
                            <span style="font-size:12px;font-weight:700;color:#0f172a;">₱<?= number_format($totalObligated,2) ?></span>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="width:10px;height:10px;border-radius:3px;background:#e2e8f0;flex-shrink:0;display:inline-block;"></span>
                                <span style="font-size:12px;color:#64748b;font-weight:500;">Unobligated</span>
                            </div>
                            <span style="font-size:12px;font-weight:700;color:#0f172a;">₱<?= number_format($unobligated,2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Bar: Per-SARO Budget vs Obligated -->
                <div style="background:#fff;border:1px solid #e8edf5;border-radius:14px;padding:22px 24px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:2px;">SARO Budget Overview</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">Allocated vs Obligated per SARO</p>
                        </div>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="width:10px;height:10px;border-radius:3px;background:#dbeafe;display:inline-block;"></span>
                                <span style="font-size:11px;color:#64748b;font-weight:500;">Allocated</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="width:10px;height:10px;border-radius:3px;background:#2563eb;display:inline-block;"></span>
                                <span style="font-size:11px;color:#64748b;font-weight:500;">Obligated</span>
                            </div>
                        </div>
                    </div>
                    <div style="height:180px;position:relative;">
                        <?php if (empty($saros)): ?>
                        <div style="height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;">
                            <svg width="32" height="32" fill="none" stroke="#cbd5e1" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <p style="font-size:12px;font-weight:600;color:#94a3b8;">No SARO data available</p>
                        </div>
                        <?php else: ?>
                        <canvas id="barChart" height="180"></canvas>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Table panel -->
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
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">April 2026</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="search-wrap">
                            <svg class="search-icon" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" class="search-input" placeholder="Search SARO ID or amount…">
                        </div>
                        <button class="tb-btn">
                            <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                            Filter
                        </button>
                        <button class="tb-btn tb-btn-primary">
                            <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Export
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="text-align:left;">No.</th>
                                <th style="text-align:left;">SARO Number</th>
                                <th style="text-align:right;">Total Budget</th>
                                <th style="text-align:right;">Obligated</th>
                                <th style="text-align:right;">Unobligated</th>
                                <th style="text-align:center;min-width:130px;">BUR</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($saros)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center;padding:48px 24px;color:#94a3b8;font-size:13px;font-weight:500;">
                                    No SARO records found.
                                </td>
                            </tr>
                            <?php else: foreach ($saros as $i => $s):
                                $bur     = $s['total_budget'] > 0 ? round($s['obligated'] / $s['total_budget'] * 100, 1) : 0;
                                $unoblig = $s['total_budget'] - $s['obligated'];
                                if ($bur >= 80)     { $burColor = '#16a34a'; $burBg = 'linear-gradient(90deg,#16a34a,#4ade80)'; $burLabel = 'Green — High Utilization'; }
                                elseif ($bur >= 50) { $burColor = '#b45309'; $burBg = 'linear-gradient(90deg,#f59e0b,#fcd34d)'; $burLabel = 'Yellow — Moderate'; }
                                else                { $burColor = '#dc2626'; $burBg = 'linear-gradient(90deg,#ef4444,#fca5a5)'; $burLabel = 'Red — Low Utilization'; }
                                $badgeClass = $s['status'] === 'active' ? 'badge-green' : 'badge-red';
                            ?>
                            <tr>
                                <td style="color:#cbd5e1;font-weight:700;font-size:12px;"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></td>
                                <td>
                                    <span style="font-weight:700;color:#0f172a;font-size:13px;"><?= htmlspecialchars($s['saroNo']) ?></span>
                                </td>
                                <td style="text-align:right;font-weight:600;color:#334155;">₱<?= number_format($s['total_budget'],2) ?></td>
                                <td style="text-align:right;">
                                    <p style="font-weight:700;color:#1d4ed8;font-size:12px;margin-bottom:1px;">₱<?= number_format($s['obligated'],2) ?></p>
                                    <p style="font-size:10px;color:#60a5fa;font-weight:600;"><?= $bur ?>% of budget</p>
                                </td>
                                <td style="text-align:right;">
                                    <p style="font-weight:700;color:#b45309;font-size:12px;margin-bottom:1px;">₱<?= number_format($unoblig,2) ?></p>
                                    <p style="font-size:10px;color:#d97706;font-weight:600;"><?= round(100-$bur,1) ?>% remaining</p>
                                </td>
                                <td style="text-align:center;">
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
                                        <div style="display:flex;align-items:center;gap:6px;width:100%;">
                                            <div style="flex:1;height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                                                <div style="width:<?= $bur ?>%;height:100%;background:<?= $burBg ?>;border-radius:99px;"></div>
                                            </div>
                                            <span style="font-size:11px;font-weight:800;color:<?= $burColor ?>;white-space:nowrap;"><?= $bur ?>%</span>
                                        </div>
                                        <span style="font-size:9px;font-weight:700;color:<?= $burColor ?>;text-transform:uppercase;letter-spacing:0.06em;"><?= $burLabel ?></span>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <span class="badge <?= $badgeClass ?>">
                                        <span class="badge-dot"></span> <?= ucfirst($s['status']) ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <a href="view_saro.php?id=<?= $s['saroId'] ?>" class="tb-btn" style="padding:5px 12px;font-size:11px;">View</a>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Panel footer -->
                <div class="panel-footer">
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Displaying <strong style="color:#475569;"><?= count($saros) ?></strong> of <strong style="color:#475569;"><?= $totalSaros ?></strong> SARO entries</p>
                    <div style="display:flex;align-items:center;gap:20px;">
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Total Allocation</p>
                            <p style="font-size:15px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;">₱<?= number_format($totalBudget,2) ?></p>
                        </div>
                        <div style="width:1px;height:32px;background:#e2e8f0;"></div>
                        <div style="text-align:right;">
                            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Utilized Amount</p>
                            <p style="font-size:15px;font-weight:900;color:#2563eb;letter-spacing:-0.02em;">₱<?= number_format($totalObligated,2) ?></p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

</div>

<style>
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
</style>
<script>
</script>

<script>
    // Shared font defaults
    Chart.defaults.font.family = "'Poppins', sans-serif";
    Chart.defaults.font.size   = 11;

    // ── Donut chart ──
    new Chart(document.getElementById('donutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Obligated', 'Unobligated'],
            datasets: [{
                data: [<?= $totalObligated ?>, <?= $unobligated ?>],
                backgroundColor: ['#2563eb', '#e2e8f0'],
                borderWidth: 0,
                hoverOffset: 6,
            }]
        },
        options: {
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₱' + (ctx.raw / 1000000).toFixed(2) + 'M'
                    }
                }
            }
        }
    });

    // ── Bar chart ──
    if (document.getElementById('barChart')) new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: {
            labels: <?= $chartLabels ?>,
            datasets: [
                {
                    label: 'Allocated',
                    data: <?= $chartBudgets ?>,
                    backgroundColor: '#dbeafe',
                    borderRadius: 6,
                    borderSkipped: false,
                },
                {
                    label: 'Obligated',
                    data: <?= $chartObligated ?>,
                    backgroundColor: '#2563eb',
                    borderRadius: 6,
                    borderSkipped: false,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₱' + ctx.raw.toLocaleString()
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#94a3b8', font: { weight: '600' } },
                    border: { display: false }
                },
                y: {
                    grid: { color: '#f1f5f9' },
                    ticks: {
                        color: '#94a3b8',
                        font: { weight: '600' },
                        callback: v => '₱' + Number(v).toLocaleString('en-US')
                    },
                    border: { display: false }
                }
            }
        }
    });
</script>
</body>
</html>
