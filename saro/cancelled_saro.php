<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/saro.php';
require_once __DIR__ . '/../class/notification.php';

$saroObj = new Saro();
$userId  = (int)$_SESSION['user_id'];



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['saro_id'])) {
    $action = $_POST['action'];
    $sId    = (int)$_POST['saro_id'];
    if ($action === 'delete') {
        $saroObj->deleteSaro($sId, $userId);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'uncancel') {
        $saroObj->uncancelSaro($sId, $userId);
        $saroObj->checkAndAutoUpdateStatus($userId);
        echo json_encode(['success' => true]);
        exit;
    }
}

$allSaros       = $saroObj->getAllSaros();
$cancelledSaros = array_values(array_filter($allSaros, fn($s) => $s['status'] === 'cancelled'));
$cancelledCount = count($cancelledSaros);
$obligatedCount = count(array_filter($allSaros, fn($s) => $s['status'] === 'obligated'));
$lapsedCount    = count(array_filter($allSaros, fn($s) => $s['status'] === 'lapsed'));

$username = $_SESSION['full_name'];
$role     = $_SESSION['role'];
$initials = $_SESSION['initials'];

$notifObj      = new Notification();
$notifications = $notifObj->getRecentActivity((int)$_SESSION['user_id'], 10);
$unreadCount   = $notifObj->countUnread($userId);
$approvedPwReq = $notifObj->getApprovedPasswordNotification($userId);
$totalNotif    = $unreadCount + ($approvedPwReq ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancelled SAROs | DICT SARO Monitoring</title>
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
        .sidebar { width: 256px; flex-shrink: 0; display: flex; flex-direction: column; background: #0f172a; position: relative; overflow: hidden; }
        .sidebar::before { content: ''; position: absolute; top: -80px; right: -80px; width: 220px; height: 220px; background: #1e3a8a; border-radius: 50%; opacity: 0.4; pointer-events: none; }
        .sidebar::after { content: ''; position: absolute; bottom: -60px; left: -60px; width: 180px; height: 180px; background: #1d4ed8; border-radius: 50%; opacity: 0.2; pointer-events: none; }
        .sidebar-brand { display: flex; align-items: center; gap: 12px; padding: 28px 24px 24px; border-bottom: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
        .brand-logo { width: 40px; height: 40px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; padding: 6px; flex-shrink: 0; }
        .sidebar-nav { flex: 1; padding: 20px 16px; overflow-y: auto; position: relative; z-index: 1; }
        .nav-section-label { font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.25); text-transform: uppercase; letter-spacing: 0.16em; padding: 0 8px; margin-bottom: 8px; margin-top: 20px; }
        .nav-section-label:first-child { margin-top: 0; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px; font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.45); text-decoration: none; cursor: pointer; border: none; background: none; width: 100%; text-align: left; transition: all 0.2s ease; margin-bottom: 2px; }
        .nav-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.85); }
        .nav-item.active { background: #1e3a8a; color: #fff; box-shadow: 0 0 0 1px rgba(59,130,246,0.3), inset 0 1px 0 rgba(255,255,255,0.08); }
        .nav-item.active .nav-icon { color: #60a5fa; }
        .nav-icon { width: 16px; height: 16px; flex-shrink: 0; }
        .sidebar-footer { padding: 16px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
        .user-card { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; background: rgba(255,255,255,0.05); margin-bottom: 8px; }
        .user-avatar { width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg, #2563eb, #1d4ed8); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; color: #fff; flex-shrink: 0; }
        .signout-btn { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.4); background: none; border: none; cursor: pointer; text-decoration: none; transition: all 0.2s ease; }
        .signout-btn:hover { background: rgba(239,68,68,0.12); color: #fca5a5; }
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 0; min-width: 0; }
        .topbar { height: 64px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: #fff; border-bottom: 1px solid #e8edf5; }
        .breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #64748b; }
        .breadcrumb-active { color: #0f172a; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .icon-btn { width: 36px; height: 36px; border-radius: 9px; background: #f8fafc; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b; transition: all 0.2s ease; position: relative; }
        .icon-btn:hover { border-color: #3b82f6; color: #2563eb; background: #eff6ff; }
        .content { flex: 1; overflow-y: auto; padding: 28px 32px; }
        .hero-banner { background: linear-gradient(135deg, #78350f 0%, #b45309 100%); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; position: relative; overflow: hidden; }
        .hero-banner::before { content: ''; position: absolute; top: -60px; right: -40px; width: 220px; height: 220px; background: rgba(255,255,255,0.07); border-radius: 50%; }
        .hero-banner::after { content: ''; position: absolute; bottom: -40px; right: 120px; width: 140px; height: 140px; background: rgba(255,255,255,0.05); border-radius: 50%; }
        .table-panel { background: #fff; border: 1px solid #e8edf5; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; }
        .panel-header { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .panel-footer { padding: 14px 24px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        table { width: 100%; border-collapse: collapse; }
        thead th { padding: 12px 20px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.12em; color: #94a3b8; background: #fafbfe; border-bottom: 1px solid #f1f5f9; white-space: nowrap; text-align: left; }
        tbody tr { border-bottom: 1px solid #f8fafc; transition: background 0.15s ease; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 14px 20px; font-size: 13px; color: #475569; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
        .badge-amber { background: #fef9c3; color: #b45309; }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .search-wrap { position: relative; }
        .search-input { padding: 8px 12px 8px 36px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; font-size: 12px; font-family: 'Poppins', sans-serif; width: 220px; outline: none; transition: all 0.2s ease; }
        .search-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
        .show-rows-wrap { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #94a3b8; font-weight: 500; }
        .show-rows-select { padding: 5px 8px; border: 1px solid #e2e8f0; border-radius: 7px; background: #f8fafc; font-size: 11px; font-family: 'Poppins', sans-serif; color: #475569; outline: none; cursor: pointer; }
    
.action-btn {
    width: 30px; height: 30px; border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid transparent; cursor: pointer;
    background: transparent; transition: all 0.2s ease;
}
.action-btn-del { color: #94a3b8; }
.action-btn-del:hover { background: #fee2e2; border-color: #fecaca; color: #dc2626; }

.action-btn-restore { color: #64748b; }
.action-btn-restore:hover { background: #dcfce7; border-color: #bbf7d0; color: #16a34a; }

        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .sidebar {
                position: absolute;
                z-index: 50;
                height: 100%;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.open {
                transform: translateX(0);
                box-shadow: 4px 0 24px rgba(0,0,0,0.1);
            }
            .topbar {
                padding: 0 16px;
                height: auto;
                min-height: 64px;
                flex-wrap: wrap;
            }
            .topbar-right {
                margin-left: auto;
            }
            .content {
                padding: 16px;
            }
            .stat-grid {
                grid-template-columns: 1fr;
            }
            .panel-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .table-panel {
                min-height: auto;
                overflow-x: auto;
            }
            .mobile-menu-btn {
                display: flex !important;
                margin-right: 12px;
                align-items: center;
                justify-content: center;
                background: none;
                border: none;
                cursor: pointer;
                color: #64748b;
            }
            .overlay {
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.4);
                z-index: 40;
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            .overlay.show {
                display: block;
                opacity: 1;
            }
        }
        @media (min-width: 769px) {
            .mobile-menu-btn { display: none !important; }
            .overlay { display: none !important; }
        }
    </style>
</head>
<body>
<div class="layout">

    <!-- -- Sidebar -- -->
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
            
            <a href="export_records.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Export Records
            </a>
                        <a href="audit_logs.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Activity Logs
            </a>
            <p class="nav-section-label">History</p>
            <a href="cancelled_saro.php" class="nav-item active">
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

    <!-- -- Main -- -->
        <div class="overlay"></div>
    <main class="main">
        <header class="topbar">
            <div class="breadcrumb">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m0 0l-7 7-7-7M19 10v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Home</span>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <a href="dashboard.php" style="text-decoration:none;color:inherit;">Dashboard</a>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="breadcrumb-active">Cancelled SAROs</span>
            </div>
            <div class="topbar-right">
                <?php $isAdmin = false; $pendingPwCount = $pendingPwCount ?? 0; $approvedPwReq = $approvedPwReq ?? null; include __DIR__ . '/../includes/notif_dropdown.php'; ?>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                    <div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,#2563eb,#1d4ed8);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;"><?= htmlspecialchars($initials) ?></div>
                    <div>
                        <p style="font-size:12px;font-weight:700;color:#0f172a;line-height:1.1;"><?= htmlspecialchars($username) ?></p>
                        <p style="font-size:10px;color:#94a3b8;font-weight:500;"><?= htmlspecialchars($role) ?></p>
                    </div>
                </div>
            </div>
        </header>

        <div class="content">
            <!-- Hero -->
            <div class="hero-banner">
                <div style="position:relative;z-index:1;">
                    <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">SARO History</p>
                    <h2 style="font-size:22px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">Cancelled SAROs</h2>
                    <p style="font-size:13px;color:rgba(255,255,255,0.6);font-weight:400;max-width:520px;line-height:1.6;">
                        Archive of all SARO records that have been cancelled. These are read-only records for reference.
                    </p>
                </div>
            </div>

            <!-- Cancelled SAROs Table -->
            <div class="table-panel">
                <div class="panel-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#fef9c3;display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#b45309" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">Cancelled SARO Records</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">SARO records that have been cancelled</p>
                        </div>
                    </div>
                    <div class="search-wrap">
                        <svg class="search-icon" width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" class="search-input" id="cancelSearch" placeholder="Search cancelled SAROs...">
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:52px;">No.</th>
                                <th>SARO No.</th>
                                <th>SARO Title</th>
                                <th style="text-align:right;">Total Budget</th>
                                <th style="text-align:center;">Object Codes</th>
                                <th style="text-align:center;">Created By</th>
                                  <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="cancelledTbody">
                            <?php if (empty($cancelledSaros)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:52px 20px;color:#94a3b8;">
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:12px;">
                                        <svg width="40" height="40" fill="none" stroke="#cbd5e1" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                        <p style="font-size:13px;font-weight:600;color:#94a3b8;">No cancelled SAROs</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: foreach ($cancelledSaros as $i => $s):
                                $rowNum    = str_pad($i + 1, 2, '0', STR_PAD_LEFT);
                                $saroNoEsc = htmlspecialchars($s['saroNo'], ENT_QUOTES);
                                $titleEsc  = htmlspecialchars($s['saro_title']);
                                $budgetFmt = number_format((float)$s['total_budget'], 2);
                                $codeCount = (int)$s['obj_count'];
                                $isOwner = (int)$s['userId'] === $userId;
                            ?>
                            <tr style="opacity:0.85;" class="cancel-row">
                                <td style="color:#cbd5e1;font-weight:700;font-size:12px;"><?= $rowNum ?></td>
                                <td><span style="font-weight:800;color:#64748b;font-size:13px;"><?= $saroNoEsc ?></span></td>
                                <td style="max-width:280px;"><p style="font-weight:500;color:#94a3b8;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $titleEsc ?></p></td>
                                <td style="text-align:right;"><span style="font-weight:800;color:#64748b;font-size:13px;">&#8369;<?= $budgetFmt ?></span></td>
                                <td style="text-align:center;">
                                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:#64748b;background:#f1f5f9;padding:3px 10px;border-radius:99px;"><?= $codeCount ?> <?= $codeCount === 1 ? 'code' : 'codes' ?></span>
                                </td>
                                <td style="text-align:center;"><span style="font-size:11px;color:#64748b;"><?= htmlspecialchars($s['owner_name'] ?? '—') ?></span></td>
                                  <td style="text-align:center;"><span class="badge badge-amber"><span class="badge-dot"></span>Cancelled</span></td>
                                <td style="text-align:center;">
                                    <?php if ($isOwner): ?>
                                    <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                        <button class="icon-btn action-btn-uncancel" data-id="<?= $s['saroId'] ?>" data-no="<?= $saroNoEsc ?>" title="Restore SARO" style="width:28px;height:28px;background:#f0fdf4;border-color:#bbf7d0;color:#16a34a;" onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg></button>
                                        <button class="action-btn action-btn-del" data-id="<?= $s['saroId'] ?>" data-no="<?= htmlspecialchars($s['saroNo'], ENT_QUOTES) ?>" title="Delete SARO"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                    </div>
                                    <?php else: ?>
                                    <span style="font-size:10px;font-weight:600;color:#94a3b8;background:#f8fafc;padding:4px 8px;border-radius:6px;border:1px dashed #cbd5e1;white-space:nowrap;" title="Only the owner can control this SARO">Owner Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="panel-footer">
                    <div class="show-rows-wrap">
                        Show
                        <select class="show-rows-select" id="cancelRows">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                        </select>
                        rows
                    </div>
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">
                        Displaying <strong id="row-count" style="color:#475569;"><?= $cancelledCount ?></strong> of <strong style="color:#475569;"><?= $cancelledCount ?></strong> cancelled SARO <?= $cancelledCount === 1 ? 'entry' : 'entries' ?>
                    </p>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
(function () {
    const allRows   = Array.from(document.querySelectorAll('#cancelledTbody .cancel-row'));
    const searchEl  = document.getElementById('cancelSearch');
    const rowsSel   = document.getElementById('cancelRows');
    const countEl   = document.getElementById('row-count');
    const totalEl   = countEl ? countEl.closest('p').querySelector('strong:nth-child(2)') : null;
    function apply() {
        const q     = searchEl ? searchEl.value.trim().toLowerCase() : '';
        const limit = rowsSel ? (parseInt(rowsSel.value, 10) || 10) : 10;
        let shown = 0;
        allRows.forEach(function (row) {
            const isMatch = !q || row.textContent.toLowerCase().includes(q);
            const show    = isMatch && shown < limit;
            row.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        if (countEl) countEl.textContent = shown;
    }
    if (searchEl) searchEl.addEventListener('input', apply);
    if (rowsSel)  rowsSel.addEventListener('change', apply);
    apply();
})();
</script>

<!-- Uncancel Modal -->
<div id="uncancelModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.4);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:#fff;border-radius:20px;width:100%;max-width:380px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1);overflow:hidden;animation:modalIn 0.2s ease-out;">
        <div style="padding:28px 28px 20px;">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" fill="none" stroke="#16a34a" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16px;font-weight:800;color:#0f172a;letter-spacing:-0.01em;">Restore SARO</h3>
                    <p style="font-size:12px;color:#94a3b8;font-weight:500;">Uncancel and restore to active</p>
                </div>
            </div>
            <p style="font-size:13px;color:#475569;line-height:1.5;margin-bottom:6px;">Are you sure you want to restore SARO <strong style="color:#0f172a;" id="uncancel-saro-label"></strong>?</p>
            <input type="hidden" id="uncancel-saro-id">
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="document.getElementById('uncancelModal').style.display='none'" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;">Cancel</button>
            <button id="confirmUncancelBtn" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;color:#fff;background:#16a34a;border:none;cursor:pointer;display:flex;align-items:center;gap:6px;">Restore</button>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.4);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:#fff;border-radius:20px;width:100%;max-width:380px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1);overflow:hidden;animation:modalIn 0.2s ease-out;">
        <div style="padding:28px 28px 20px;">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#fef2f2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16px;font-weight:800;color:#0f172a;letter-spacing:-0.01em;">Delete SARO</h3>
                    <p style="font-size:12px;color:#94a3b8;font-weight:500;">This action cannot be undone</p>
                </div>
            </div>
            <p style="font-size:13px;color:#475569;line-height:1.5;margin-bottom:6px;">Are you sure you want to permanently delete SARO <strong style="color:#0f172a;" id="delete-saro-label"></strong>?</p>
            <input type="hidden" id="delete-saro-id">
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="document.getElementById('deleteModal').style.display='none'" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;">Cancel</button>
            <button id="confirmDeleteBtn" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;color:#fff;background:#dc2626;border:none;cursor:pointer;display:flex;align-items:center;gap:6px;">Delete</button>
        </div>
    </div>
</div>
<style>@keyframes modalIn { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }</style>

<script>
    document.querySelectorAll('.action-btn-uncancel').forEach(btn => {
        btn.onclick = () => {
            document.getElementById('uncancel-saro-id').value = btn.dataset.id;
            document.getElementById('uncancel-saro-label').textContent = btn.dataset.no;
            document.getElementById('uncancelModal').style.display = 'flex';
        };
    });
    
    document.getElementById('confirmUncancelBtn').onclick = () => {
        const fd = new FormData();
        fd.append('action', 'uncancel');
        fd.append('saro_id', document.getElementById('uncancel-saro-id').value);
        fetch('cancelled_saro.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if(res.success) location.reload(); else alert('Error restoring SARO'); });
    };

    document.querySelectorAll('.action-btn-del').forEach(btn => {
        btn.onclick = () => {
            document.getElementById('delete-saro-id').value = btn.dataset.id;
            document.getElementById('delete-saro-label').textContent = btn.dataset.no;
            document.getElementById('deleteModal').style.display = 'flex';
        };
    });
    
    document.getElementById('confirmDeleteBtn').onclick = () => {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('saro_id', document.getElementById('delete-saro-id').value);
        fetch('cancelled_saro.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if(res.success) location.reload(); else alert('Error deleting SARO'); });
    };
</script>


<script>
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.querySelector('.mobile-menu-btn');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.overlay');

    if(btn && sidebar && overlay) {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.add('open');
            overlay.classList.add('show');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }
});
</script>
</body>
</html>
