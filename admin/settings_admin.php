<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/notification.php';

$username = $_SESSION['full_name'];
$role     = $_SESSION['role'];
$initials = $_SESSION['initials'];
$userId   = (int)$_SESSION['user_id'];

$db   = new Database();
$conn = $db->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'change_password') {
        $currPass = $_POST['current_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        if ($newPass !== $confPass) {
            header('Location: settings_admin.php?tab=password&err=mismatch');
            exit;
        }

        $stmt = $conn->prepare("SELECT password FROM user WHERE userId = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currPass, $user['password'])) {
            header('Location: settings_admin.php?tab=password&err=wrong_password');
            exit;
        }

        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $conn->prepare("UPDATE user SET password = ? WHERE userId = ?")->execute([$hash, $userId]);

        header('Location: settings_admin.php?tab=password&success=1');
        exit;
    }
}

$notifObj      = new Notification();
$pendingPwCount = $notifObj->countPendingPasswordRequests();
$pwRequests    = $notifObj->getUserPasswordRequests($userId);
$notifications = $notifObj->getRecentActivity((int)$_SESSION['user_id'], 10);
$unreadCount   = $notifObj->countUnread($userId);
$approvedPwReq = $notifObj->getApprovedPasswordNotification($userId);
$cancelledCount = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='cancelled'")->fetchColumn();
$obligatedCount = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='obligated'")->fetchColumn();
$lapsedCount    = (int)$conn->query("SELECT COUNT(*) FROM saro WHERE status='lapsed'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | DICT SARO Monitoring</title>
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
        ::-webkit-scrollbar-thumb:hover { background: #fca5a5; }

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

        /* -- Main -- */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 0; min-width: 0; }
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
        .icon-btn:hover { border-color: #ef4444; color: #dc2626; background: #fef2f2; }
        .content { flex: 1; overflow-y: auto; padding: 28px 32px; }

        /* Hero */
        .hero-banner {
            background: linear-gradient(135deg, #7f1d1d 0%, #dc2626 100%);
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

        /* Settings layout */
        .settings-grid {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 20px;
            align-items: start;
        }

        /* Settings nav panel */
        .settings-nav-panel {
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 14px;
            overflow: hidden;
        }
        .settings-nav-header {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        .settings-nav-item {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 16px;
            font-size: 12px; font-weight: 500; color: #475569;
            text-decoration: none; cursor: pointer;
            border: none; background: none; width: 100%; text-align: left;
            transition: all 0.2s ease;
            border-bottom: 1px solid #f8fafc;
        }
        .settings-nav-item:last-child { border-bottom: none; }
        .settings-nav-item:hover { background: #fef2f2; color: #b91c1c; }
        .settings-nav-item.active {
            background: #fef2f2; color: #b91c1c; font-weight: 600;
            border-left: 3px solid #dc2626;
            padding-left: 13px;
        }
        .settings-nav-item .s-icon { width: 15px; height: 15px; flex-shrink: 0; }

        /* Card */
        .card {
            background: #fff;
            border: 1px solid #e8edf5;
            border-radius: 14px;
            overflow: hidden;
        }
        .card-header {
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; gap: 12px;
        }
        .card-header-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .card-body { padding: 24px; }

        /* Form */
        .form-group { margin-bottom: 20px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label {
            display: block;
            font-size: 11px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: 0.06em;
            margin-bottom: 7px;
        }
        .form-input {
            width: 100%; padding: 10px 14px;
            border: 1px solid #e2e8f0; border-radius: 9px;
            font-size: 13px; font-family: 'Poppins', sans-serif; color: #0f172a;
            background: #f8fafc; outline: none;
            transition: all 0.2s ease;
        }
        .form-input:focus {
            border-color: #ef4444; background: #fff;
            box-shadow: 0 0 0 3px rgba(239,68,68,0.1);
        }
        .form-input:disabled {
            background: #f1f5f9; color: #94a3b8; cursor: not-allowed;
        }
        .form-hint {
            font-size: 11px; color: #94a3b8; font-weight: 500;
            margin-top: 5px; line-height: 1.5;
        }

        /* Password strength */
        .strength-bar {
            display: flex; gap: 4px; margin-top: 8px;
        }
        .strength-seg {
            flex: 1; height: 4px; border-radius: 99px;
            background: #e2e8f0; transition: background 0.3s ease;
        }
        .strength-seg.weak { background: #ef4444; }
        .strength-seg.fair { background: #f59e0b; }
        .strength-seg.good { background: #3b82f6; }
        .strength-seg.strong { background: #10b981; }
        .strength-label {
            font-size: 10px; font-weight: 700; margin-top: 5px;
            letter-spacing: 0.05em; text-transform: uppercase;
        }

        /* Password eye toggle */
        .input-wrap { position: relative; }
        .input-wrap .form-input { padding-right: 40px; }
        .eye-btn {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #94a3b8; padding: 0;
            display: flex; align-items: center;
            transition: color 0.2s ease;
        }
        .eye-btn:hover { color: #475569; }

        /* Divider */
        .divider { border: none; border-top: 1px solid #f1f5f9; margin: 24px 0; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 18px; border-radius: 9px;
            font-size: 12px; font-weight: 600; font-family: 'Poppins', sans-serif;
            cursor: pointer; text-decoration: none;
            transition: all 0.2s ease; border: 1px solid transparent;
        }
        .btn-primary { background: #b91c1c; color: #fff; border-color: #b91c1c; }
        .btn-primary:hover { background: #991b1b; border-color: #991b1b; box-shadow: 0 4px 12px rgba(185,28,28,0.25); }
        .btn-primary:disabled { background: #fca5a5; border-color: #fca5a5; cursor: not-allowed; box-shadow: none; }
        .btn-ghost { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .btn-ghost:hover { border-color: #94a3b8; color: #0f172a; background: #f1f5f9; }

        /* Alert */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 16px; border-radius: 10px;
            font-size: 12px; font-weight: 500; line-height: 1.5;
            margin-bottom: 20px;
        }
        .alert-icon { flex-shrink: 0; margin-top: 1px; }
        .alert-info { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; display: none; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; display: none; }

        /* Profile info row */
        .profile-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
        }

        /* Request badge */
        .request-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 99px;
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .badge-pending { background: #fef9c3; color: #b45309; border: 1px solid #fde68a; }
        .badge-approved { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

        /* Modal */
        .modal-overlay { position: fixed; inset: 0; z-index: 300; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; padding: 24px; }
        .modal-overlay.open { display: flex; }
        .modal-card { background: #fff; border-radius: 18px; width: 100%; max-width: 500px; box-shadow: 0 24px 64px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh; }
        .modal-header { padding: 22px 28px; background: linear-gradient(135deg, #7f1d1d, #dc2626); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .modal-body { padding: 24px 28px; display: flex; flex-direction: column; gap: 16px; overflow-y: auto; }
        .modal-footer { padding: 16px 28px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-shrink: 0; }
        .modal-close-btn { width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,0.12); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #fff; transition: background 0.2s ease; }
        .modal-close-btn:hover { background: rgba(255,255,255,0.22); }
    
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
                <?php if($pendingPwCount > 0): ?>
                    <span class="nav-badge"><?= $pendingPwCount ?></span>
                <?php endif; ?>
            </a>
            <a href="activity_logs.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Activity Logs
            </a>
            <p class="nav-section-label">Reports</p>
            <a href="export_records.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Export Records
            </a>
        
            <p class="nav-section-label">Configuration</p>
            <a href="settings_admin.php" class="nav-item active">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
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

        <!-- Topbar -->
        <header class="topbar">
            <div class="breadcrumb">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m0 0l-7 7-7-7M19 10v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Home</span>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <a href="dashboard.php" style="text-decoration:none;color:inherit;">Dashboard</a>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="breadcrumb-active">Settings</span>
            </div>
            <div class="topbar-right">
                <!-- Notification -->
                <?php $isAdmin = true; $pendingPwCount ??= 0; $approvedPwReq ??= null; include __DIR__ . '/../includes/notif_dropdown.php'; ?>
                <div style="display:flex;align-items:center;gap:10px;padding:6px 12px;
                            background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                    <div style="width:28px;height:28px;border-radius:7px;
                                background:linear-gradient(135deg,#dc2626,#b91c1c);
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

        <!-- -- Content -- -->
        <div class="content">

            <!-- Hero -->
            <div class="hero-banner">
                <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);
                                   text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">
                            Account
                        </p>
                        <h2 style="font-size:22px;font-weight:900;color:#fff;
                                   text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">
                            Settings
                        </h2>
                        <p style="font-size:13px;color:rgba(255,255,255,0.6);font-weight:400;max-width:480px;line-height:1.6;">
                            Manage your account preferences and password change.
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:center;
                                width:64px;height:64px;border-radius:18px;
                                background:rgba(255,255,255,0.1);flex-shrink:0;">
                        <svg width="30" height="30" fill="none" stroke="rgba(255,255,255,0.85)" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Settings grid -->
            <div class="settings-grid">

                <!-- Left: Settings nav -->
                <div class="settings-nav-panel">
                    <div class="settings-nav-header">
                        <p style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;">Preferences</p>
                    </div>
                    <a href="#profile-section" class="settings-nav-item" onclick="showSection('profile')">
                        <svg class="s-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Profile Info
                    </a>
                    <a href="#password-section" class="settings-nav-item active" onclick="showSection('password')">
                        <svg class="s-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        Change Password
                    </a>
                </div>

                <!-- Right: Content panels -->
                <div>

                    <!-- Profile Info section -->
                    <div id="section-profile" style="display:none;">
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-icon" style="background:#fef2f2;">
                                    <svg width="18" height="18" fill="none" stroke="#b91c1c" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                </div>
                                <div>
                                    <p style="font-size:14px;font-weight:800;color:#0f172a;">Profile Information</p>
                                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">View your account details</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div style="display:flex;align-items:center;gap:16px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:24px;">
                                    <div style="width:56px;height:56px;border-radius:14px;
                                                background:linear-gradient(135deg,#dc2626,#b91c1c);
                                                display:flex;align-items:center;justify-content:center;
                                                font-size:18px;font-weight:800;color:#fff;flex-shrink:0;">
                                        <?= htmlspecialchars($initials) ?>
                                    </div>
                                    <div>
                                        <p style="font-size:15px;font-weight:800;color:#0f172a;"><?= htmlspecialchars($username) ?></p>
                                        <p style="font-size:11px;color:#64748b;font-weight:500;margin-top:2px;"><?= htmlspecialchars($role) ?></p>
                                    </div>
                                    <div style="margin-left:auto;">
                                        <span class="request-badge badge-approved">
                                            <span class="badge-dot"></span>
                                            Active
                                        </span>
                                    </div>
                                </div>
                                <div class="profile-row">
                                    <div class="form-group">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" class="form-input" value="<?= htmlspecialchars($username) ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Role</label>
                                        <input type="text" class="form-input" value="<?= htmlspecialchars($role) ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Department</label>
                                        <input type="text" class="form-input" value="DICT — Region IX &amp; BASULTA" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Account Status</label>
                                        <input type="text" class="form-input" value="Active" disabled>
                                    </div>
                                </div>
                                <p class="form-hint" style="margin-top:4px;">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:3px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Profile details are managed by your administrator. Contact them to request changes.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Change Password section -->
                    <div id="section-password">
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-icon" style="background:#fef9c3;">
                                    <svg width="18" height="18" fill="none" stroke="#b45309" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                </div>
                                <div>
                                    <p style="font-size:14px;font-weight:800;color:#0f172a;">Change Password</p>
                                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Update your administrator account password</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Error modal handled at the bottom -->
                                

                                <form method="post" action="settings_admin.php" onsubmit="return validatePwForm()">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label class="form-label">Current Password</label>
                                        <div class="input-wrap">
                                            <input type="password" class="form-input" id="currentPassword" name="current_password" placeholder="Enter your current password" required>
                                            <button type="button" class="eye-btn" onclick="toggleEye('currentPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-currentPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <hr class="divider">
                                    <div class="form-group">
                                        <label class="form-label">New Password</label>
                                        <div class="input-wrap">
                                            <input type="password" class="form-input" id="newPassword" name="new_password" placeholder="Enter new password" required oninput="checkStrength(this.value)">
                                            <button type="button" class="eye-btn" onclick="toggleEye('newPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-newPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                        <div class="strength-bar" id="strengthBar" style="margin-top:10px;">
                                            <div class="strength-seg" id="seg1"></div>
                                            <div class="strength-seg" id="seg2"></div>
                                            <div class="strength-seg" id="seg3"></div>
                                            <div class="strength-seg" id="seg4"></div>
                                        </div>
                                        <p class="strength-label" id="strengthLabel" style="color:#94a3b8;"></p>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Confirm New Password</label>
                                        <div class="input-wrap">
                                            <input type="password" class="form-input" id="confirmPassword" name="confirm_password" placeholder="Confirm new password" required>
                                            <button type="button" class="eye-btn" onclick="toggleEye('confirmPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-confirmPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div style="display:flex;justify-content:flex-end;">
                                        <button type="submit" class="btn btn-primary">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div><!-- end right col -->
            </div><!-- end settings-grid -->
        </div><!-- end content -->
    
<?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
<div class="modal-overlay open" style="display: flex; position: fixed; inset: 0; z-index: 1000; background: rgba(15,23,42,0.7); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 24px;">
    <div class="modal-card" style="background: #fff; border-radius: 20px; width: 100%; max-width: 400px; box-shadow: 0 24px 64px rgba(0,0,0,0.2); overflow: hidden; text-align: center; padding: 32px 24px;">
        <div style="width: 64px; height: 64px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
            <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h3 style="font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Password Changed</h3>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 24px; line-height: 1.6;">Your password has been successfully updated. For security reasons, please log in again using your new password.</p>
        <a href="../logout.php" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; font-size: 14px;">Log In Again</a>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['err'])): ?>
<div class="modal-overlay open" style="display: flex; position: fixed; inset: 0; z-index: 1000; background: rgba(15,23,42,0.7); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 24px;">
    <div class="modal-card" style="background: #fff; border-radius: 20px; width: 100%; max-width: 400px; box-shadow: 0 24px 64px rgba(0,0,0,0.2); overflow: hidden; text-align: center; padding: 32px 24px;">
        <div style="width: 64px; height: 64px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
            <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
        <h3 style="font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Update Failed</h3>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 24px; line-height: 1.6;">
            <?php 
                if ($_GET['err'] === 'wrong_password') echo 'Incorrect current password. Please try again.';
                elseif ($_GET['err'] === 'mismatch') echo 'New passwords do not match.';
                else echo 'An error occurred. Please try again.';
            ?>
        </p>
        <a href="settings_admin.php?tab=password" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; font-size: 14px;">Try Again</a>
    </div>
</div>
<?php endif; ?>

</main>

</div>


<script>
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('tab') === 'password') {
        showSection('password');
    } else {
        showSection('profile');
    }

    function showSection(sec) {
        document.getElementById('section-profile').style.display = 'none';
        document.getElementById('section-password').style.display = 'none';
        
        document.querySelectorAll('.settings-nav-item').forEach(el => el.classList.remove('active'));
        
        document.getElementById('section-' + sec).style.display = 'block';
        event.currentTarget.classList.add('active');
        
        const url = new URL(window.location);
        url.searchParams.set('tab', sec);
        window.history.pushState({}, '', url);
    }

    function toggleEye(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById('eye-' + inputId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
        } else {
            input.type = 'password';
            icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
        }
    }

    function checkStrength(val) {
        const seg1 = document.getElementById('seg1');
        const seg2 = document.getElementById('seg2');
        const seg3 = document.getElementById('seg3');
        const seg4 = document.getElementById('seg4');
        const label = document.getElementById('strengthLabel');
        
        let score = 0;
        if(val.length >= 8) score++;
        if(/[A-Z]/.test(val)) score++;
        if(/[0-9]/.test(val)) score++;
        if(/[\W_]/.test(val)) score++;

        [seg1,seg2,seg3,seg4].forEach(s => s.className = 'strength-seg');
        
        if (val.length === 0) {
            label.textContent = '';
            label.style.color = '#94a3b8';
        } else if (score <= 1) {
            seg1.classList.add('weak');
            label.textContent = 'Weak password';
            label.style.color = '#ef4444';
        } else if (score === 2) {
            seg1.classList.add('fair'); seg2.classList.add('fair');
            label.textContent = 'Fair password';
            label.style.color = '#f59e0b';
        } else if (score === 3) {
            seg1.classList.add('good'); seg2.classList.add('good'); seg3.classList.add('good');
            label.textContent = 'Good password';
            label.style.color = '#3b82f6';
        } else {
            seg1.classList.add('strong'); seg2.classList.add('strong'); seg3.classList.add('strong'); seg4.classList.add('strong');
            label.textContent = 'Strong password';
            label.style.color = '#10b981';
        }
    }

    function validatePwForm() {
        const p1 = document.getElementById('newPassword').value;
        const p2 = document.getElementById('confirmPassword').value;
        if(p1 !== p2) {
            alert('New passwords do not match.');
            return false;
        }
        return true;
    }
</script>

</body>
</html>
