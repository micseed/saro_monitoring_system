<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/Database.php';
require_once __DIR__ . '/../class/notification.php';

$username = $_SESSION['full_name'];
$role     = $_SESSION['role'];
$initials = $_SESSION['initials'];
$userId   = (int)$_SESSION['user_id'];

// Handle new password-change request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_password') {
    $reason = trim($_POST['reason'] ?? '');
    $db   = new Database();
    $conn = $db->connect();
    $stmt = $conn->prepare("
        INSERT INTO password_requests (userId, reason, status)
        VALUES (?, ?, 'pending')
    ");
    $stmt->execute([$userId, $reason ?: null]);
    header('Location: settings.php?tab=password&req=sent');
    exit;
}

$notifObj    = new Notification();
$pwRequests  = $notifObj->getUserPasswordRequests($userId);
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
        .settings-nav-item:hover { background: #f5f8ff; color: #1d4ed8; }
        .settings-nav-item.active {
            background: #eff6ff; color: #1d4ed8; font-weight: 600;
            border-left: 3px solid #2563eb;
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
            border-color: #3b82f6; background: #fff;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
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
        .btn-primary { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
        .btn-primary:hover { background: #1e40af; border-color: #1e40af; box-shadow: 0 4px 12px rgba(29,78,216,0.25); }
        .btn-primary:disabled { background: #93c5fd; border-color: #93c5fd; cursor: not-allowed; box-shadow: none; }
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
        .alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
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
            <a href="audit_logs.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Activity Logs
            </a>
            <p class="nav-section-label">Account</p>
            <a href="settings.php" class="nav-item active">
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
                <span class="breadcrumb-active">Settings</span>
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
                            Manage your account preferences and submit a password change request to the administrator.
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
                    <a href="#" class="settings-nav-item" onclick="showSection('notif'); return false;">
                        <svg class="s-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        Notifications
                    </a>
                </div>

                <!-- Right: Content panels -->
                <div>

                    <!-- Profile Info section -->
                    <div id="section-profile" style="display:none;">
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-icon" style="background:#eff6ff;">
                                    <svg width="18" height="18" fill="none" stroke="#1d4ed8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                </div>
                                <div>
                                    <p style="font-size:14px;font-weight:800;color:#0f172a;">Profile Information</p>
                                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">View your account details</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <div style="display:flex;align-items:center;gap:16px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:24px;">
                                    <div style="width:56px;height:56px;border-radius:14px;
                                                background:linear-gradient(135deg,#2563eb,#1d4ed8);
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
                                    <p style="font-size:14px;font-weight:800;color:#0f172a;">Request Password Change</p>
                                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Submit a request for the administrator to reset your password</p>
                                </div>
                            </div>
                            <div class="card-body">

                                <!-- Info alert -->
                                <div class="alert alert-info">
                                    <svg class="alert-icon" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span>For security, password changes require administrator approval. Fill in the form below and submit your request. You will be notified once your new password is set.</span>
                                </div>

                                <!-- Success alert -->
                                <div class="alert alert-success" id="successAlert">
                                    <svg class="alert-icon" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span>Your password change request has been submitted. The administrator will process it shortly.</span>
                                </div>

                                <!-- Error alert -->
                                <div class="alert alert-error" id="errorAlert">
                                    <svg class="alert-icon" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span id="errorMsg">Please correct the errors and try again.</span>
                                </div>

                                <form id="pwRequestForm" novalidate>
                                    <div class="form-group">
                                        <label class="form-label">Current Password</label>
                                        <div class="input-wrap">
                                            <input type="password" class="form-input" id="currentPassword"
                                                   placeholder="Enter your current password">
                                            <button type="button" class="eye-btn" onclick="toggleEye('currentPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-currentPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                        <p class="form-hint">Required to verify your identity before submitting the request.</p>
                                    </div>

                                    <hr class="divider">

                                    <div class="form-group">
                                        <label class="form-label">Requested New Password</label>
                                        <div class="input-wrap">
                                            <input type="password" class="form-input" id="newPassword"
                                                   placeholder="Enter your desired new password"
                                                   oninput="checkStrength(this.value)">
                                            <button type="button" class="eye-btn" onclick="toggleEye('newPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-newPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                        <!-- Strength meter -->
                                        <div class="strength-bar" id="strengthBar">
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
                                            <input type="password" class="form-input" id="confirmPassword"
                                                   placeholder="Re-enter your desired new password">
                                            <button type="button" class="eye-btn" onclick="toggleEye('confirmPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-confirmPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                        <p class="form-hint">Both passwords must match.</p>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Reason for Request <span style="color:#94a3b8;font-weight:500;text-transform:none;">(optional)</span></label>
                                        <textarea class="form-input" id="requestReason" rows="3"
                                                  placeholder="e.g. Forgot current password, routine change, suspected compromise…"
                                                  style="resize:vertical;min-height:80px;"></textarea>
                                    </div>

                                    <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;padding-top:4px;">
                                        <button type="button" class="btn btn-ghost" onclick="resetForm()">Clear</button>
                                        <button type="submit" class="btn btn-primary" id="submitBtn">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                            Submit Request
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Pending requests card -->
                        <div class="card" style="margin-top:20px;">
                            <div class="card-header">
                                <div class="card-header-icon" style="background:#f5f3ff;">
                                    <svg width="18" height="18" fill="none" stroke="#7c3aed" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                </div>
                                <div>
                                    <p style="font-size:14px;font-weight:800;color:#0f172a;">Request History</p>
                                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Your recent password change requests</p>
                                </div>
                            </div>
                            <div class="card-body" style="padding:0;">
                                <table style="width:100%;border-collapse:collapse;">
                                    <thead>
                                        <tr>
                                            <th style="padding:10px 20px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#fff;background:#0f172a;text-align:left;">Date Submitted</th>
                                            <th style="padding:10px 20px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#fff;background:#0f172a;text-align:left;">Reason</th>
                                            <th style="padding:10px 20px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#fff;background:#0f172a;text-align:center;">Status</th>
                                            <th style="padding:10px 20px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#fff;background:#0f172a;text-align:left;">Processed By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pwRequests)): ?>
                                        <tr>
                                            <td colspan="4" style="padding:24px 20px;text-align:center;font-size:12px;color:#94a3b8;">No password requests submitted yet.</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($pwRequests as $req):
                                            $reqDate  = date('M d, Y — g:i A', strtotime($req['requested_at']));
                                            $reason   = $req['reason'] ? htmlspecialchars($req['reason']) : '<em style="color:#94a3b8;">No reason provided</em>';
                                            $badgeCls = match($req['status']) {
                                                'approved' => 'badge-approved',
                                                'rejected' => 'badge-rejected',
                                                default    => 'badge-pending',
                                            };
                                            $resolverName = '—';
                                            if ($req['resolver_fname']) {
                                                $resolverName = htmlspecialchars($req['resolver_fname'] . ' ' . $req['resolver_lname']);
                                                if ($req['resolved_at']) {
                                                    $resolverName .= ' — ' . date('M d, Y', strtotime($req['resolved_at']));
                                                }
                                            }
                                        ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <td style="padding:12px 20px;font-size:12px;color:#475569;"><?= $reqDate ?></td>
                                            <td style="padding:12px 20px;font-size:12px;color:#475569;"><?= $reason ?></td>
                                            <td style="padding:12px 20px;text-align:center;">
                                                <span class="request-badge <?= $badgeCls ?>">
                                                    <span class="badge-dot"></span>
                                                    <?= ucfirst(htmlspecialchars($req['status'])) ?>
                                                </span>
                                            </td>
                                            <td style="padding:12px 20px;font-size:12px;color:#475569;"><?= $resolverName ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Notifications section -->
                    <div id="section-notif" style="display:none;">
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-icon" style="background:#eff6ff;">
                                    <svg width="18" height="18" fill="none" stroke="#1d4ed8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                </div>
                                <div>
                                    <p style="font-size:14px;font-weight:800;color:#0f172a;">Notification Preferences</p>
                                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Configure how you receive alerts</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <p style="font-size:13px;color:#94a3b8;font-weight:500;text-align:center;padding:24px 0;">
                                    Notification settings will be available in a future update.
                                </p>
                            </div>
                        </div>
                    </div>

                </div><!-- end right col -->
            </div><!-- end settings-grid -->
        </div><!-- end content -->
    </main>
</div>

<script>
    /* ── Section switcher ── */
    function showSection(name) {
        ['profile','password','notif'].forEach(s => {
            document.getElementById('section-' + s).style.display = s === name ? '' : 'none';
        });
        document.querySelectorAll('.settings-nav-item').forEach((el, i) => {
            el.classList.toggle('active', i === ['profile','password','notif'].indexOf(name));
        });
    }

    /* ── Password visibility toggle ── */
    function toggleEye(fieldId, btn) {
        const inp = document.getElementById(fieldId);
        const isPass = inp.type === 'password';
        inp.type = isPass ? 'text' : 'password';
        const eyeIcon = document.getElementById('eye-' + fieldId);
        if (isPass) {
            eyeIcon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>`;
        } else {
            eyeIcon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;
        }
        btn.style.color = isPass ? '#2563eb' : '';
    }

    /* ── Password strength ── */
    function checkStrength(val) {
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        const colors = ['#ef4444','#f59e0b','#3b82f6','#16a34a'];
        const labels = ['Weak','Fair','Good','Strong'];
        const labelColors = ['#dc2626','#b45309','#1d4ed8','#15803d'];

        for (let i = 1; i <= 4; i++) {
            const seg = document.getElementById('seg' + i);
            seg.style.background = i <= score ? colors[score - 1] : '#e2e8f0';
        }

        const lbl = document.getElementById('strengthLabel');
        if (val.length === 0) {
            lbl.textContent = '';
        } else {
            lbl.textContent = labels[score - 1] || 'Weak';
            lbl.style.color = labelColors[score - 1] || '#dc2626';
        }
    }

    /* ── Form submission ── */
    document.getElementById('pwRequestForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const current = document.getElementById('currentPassword').value.trim();
        const newPw   = document.getElementById('newPassword').value;
        const confirm = document.getElementById('confirmPassword').value;

        document.getElementById('successAlert').style.display = 'none';
        document.getElementById('errorAlert').style.display   = 'none';

        if (!current) {
            showError('Please enter your current password.');
            return;
        }
        if (newPw.length < 6) {
            showError('New password must be at least 6 characters long.');
            return;
        }
        if (newPw !== confirm) {
            showError('New password and confirm password do not match.');
            return;
        }
        if (newPw === current) {
            showError('New password must be different from your current password.');
            return;
        }

        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="animation:spin 1s linear infinite;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Submitting…`;

        setTimeout(function() {
            document.getElementById('successAlert').style.display = 'flex';
            document.getElementById('pwRequestForm').reset();
            checkStrength('');
            btn.disabled = false;
            btn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg> Submit Request`;
        }, 1200);
    });

    function showError(msg) {
        document.getElementById('errorMsg').textContent = msg;
        document.getElementById('errorAlert').style.display = 'flex';
    }

    function resetForm() {
        document.getElementById('pwRequestForm').reset();
        document.getElementById('successAlert').style.display = 'none';
        document.getElementById('errorAlert').style.display   = 'none';
        checkStrength('');
    }

    /* ── Spinner keyframes ── */
    const style = document.createElement('style');
    style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
</script>
</body>
</html>
