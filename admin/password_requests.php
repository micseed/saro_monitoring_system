<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/database.php';

$username = $_SESSION['full_name'];
$role     = $_SESSION['role'];
$initials = $_SESSION['initials'];
$adminId  = (int)$_SESSION['user_id'];

$db  = new Database();
$pdo = $db->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Handle approve / reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $reqId = (int)$_POST['request_id'];
    $act   = $_POST['action'];
    $note  = trim($_POST['admin_note'] ?? '');

    if ($act === 'approved') {
        $newPw = $_POST['new_password'] ?? '';
        if (strlen($newPw) >= 8) {
            $pr = $pdo->prepare("SELECT userId FROM password_requests WHERE requestId = ?");
            $pr->execute([$reqId]);
            $target = $pr->fetch();
            if ($target) {
                $hash = password_hash($newPw, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE user SET password = ?, updated_at = NOW() WHERE userId = ?")
                    ->execute([$hash, $target['userId']]);
                $pdo->prepare("
                    UPDATE password_requests
                    SET status = 'approved', admin_note = ?, resolved_by = ?, resolved_at = NOW(), applied_at = NOW()
                    WHERE requestId = ?
                ")->execute([$note ?: null, $adminId, $reqId]);
            }
        }
    } elseif ($act === 'rejected') {
        $pdo->prepare("
            UPDATE password_requests
            SET status = 'rejected', admin_note = ?, resolved_by = ?, resolved_at = NOW()
            WHERE requestId = ?
        ")->execute([$note ?: null, $adminId, $reqId]);
    }

    header('Location: password_requests.php');
    exit;
}

// Fetch all password requests with user details
$requests = $pdo->query("
    SELECT pr.requestId, pr.reason, pr.status, pr.admin_note, pr.requested_at, pr.resolved_at,
           u.first_name, u.last_name, u.email, ur.role AS user_role,
           r.first_name AS resolver_fname, r.last_name AS resolver_lname
    FROM password_requests pr
    JOIN user u ON pr.userId = u.userId
    JOIN user_role ur ON ur.roleId = u.roleId
    LEFT JOIN user r ON pr.resolved_by = r.userId
    ORDER BY pr.requested_at ASC
")->fetchAll();

$totalReq    = count($requests);
$pendingReq  = count(array_filter($requests, fn($r) => $r['status'] === 'pending'));
$approvedReq = count(array_filter($requests, fn($r) => $r['status'] === 'approved'));
$rejectedReq = count(array_filter($requests, fn($r) => $r['status'] === 'rejected'));

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
    <title>Password Requests | DICT SARO Monitoring</title>
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

        /* ── Stat cards ── */
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border: 1px solid #e8edf5; border-radius: 14px; padding: 20px 22px; position: relative; overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.07); }
        .stat-card-accent { position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: 14px 14px 0 0; }
        .stat-icon-wrap { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .stat-label { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 900; color: #0f172a; letter-spacing: -0.03em; line-height: 1; }
        .stat-meta { margin-top: 12px; padding-top: 12px; border-top: 1px solid #f1f5f9; }

        /* ── Panel ── */
        .panel { background: #fff; border: 1px solid #e8edf5; border-radius: 16px; overflow: hidden; }
        .panel-header { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
        .panel-footer { padding: 12px 24px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: space-between; }

        /* ── Table ── */
        table { width: 100%; border-collapse: collapse; }
        thead th { padding: 11px 20px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.12em; color: #94a3b8; background: #fafbfe; border-bottom: 1px solid #f1f5f9; white-space: nowrap; text-align: left; }
        tbody tr { border-bottom: 1px solid #f8fafc; transition: background 0.15s ease; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 16px 20px; font-size: 12px; color: #475569; vertical-align: middle; }

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
        .btn-primary:hover { background: #b91c1c; border-color: #b91c1c; }
        .btn-ghost { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
        .btn-ghost:hover { border-color: #94a3b8; color: #0f172a; }
        .btn-approve { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; font-family: 'Poppins', sans-serif; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .btn-approve:hover { background: #dcfce7; border-color: #86efac; box-shadow: 0 2px 8px rgba(22,163,74,0.2); }
        .btn-reject { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; font-family: 'Poppins', sans-serif; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .btn-reject:hover { background: #fee2e2; border-color: #f87171; box-shadow: 0 2px 8px rgba(220,38,38,0.15); }
        .btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

        /* ── User avatar ── */
        .u-avatar { width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; color: #fff; }

        /* ── Search ── */
        .search-wrap { position: relative; }
        .search-input { padding: 8px 12px 8px 34px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; font-size: 12px; font-family: 'Poppins', sans-serif; width: 200px; outline: none; transition: all 0.2s ease; }
        .search-input:focus { border-color: #ef4444; background: #fff; box-shadow: 0 0 0 3px rgba(239,68,68,0.1); }
        .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }

        /* ── Filter tabs ── */
        .filter-tabs { display: flex; align-items: center; gap: 4px; background: #f1f5f9; border-radius: 10px; padding: 4px; }
        .filter-tab { padding: 6px 16px; border-radius: 7px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.2s; color: #64748b; border: none; background: transparent; font-family: 'Poppins', sans-serif; }
        .filter-tab.active { background: #fff; color: #0f172a; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        .filter-tab:hover:not(.active) { color: #0f172a; }

        /* ── Show rows ── */
        .show-rows-wrap { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #64748b; font-weight: 500; }
        .show-rows-select { padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 11px; font-family: 'Poppins', sans-serif; color: #0f172a; background: #f8fafc; outline: none; cursor: pointer; }

        /* ── Reason card ── */
        .reason-chip { display: inline-block; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 3px 10px; font-size: 11px; color: #475569; font-weight: 500; }

        /* ── Modal ── */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.65); backdrop-filter: blur(4px); z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
        .modal-overlay.open { opacity: 1; pointer-events: all; }
        .modal-card { background: #fff; border-radius: 20px; width: 100%; max-width: 440px; box-shadow: 0 24px 60px rgba(0,0,0,0.2); transform: translateY(16px); transition: transform 0.25s ease; overflow: hidden; }
        .modal-overlay.open .modal-card { transform: translateY(0); }
        .modal-header { padding: 22px 28px 18px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
        .modal-body { padding: 22px 28px; display: flex; flex-direction: column; gap: 14px; }
        .modal-footer { padding: 16px 28px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: flex-end; gap: 10px; }

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
            <a href="password_requests.php" class="nav-item active">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                Password Requests
                <?php if ($pendingReq > 0): ?><span class="nav-badge"><?= $pendingReq ?></span><?php endif; ?>
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
        <header class="topbar">
            <div class="breadcrumb">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m0 0l-7 7-7-7M19 10v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Home</span>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <a href="dashboard.php" style="text-decoration:none;color:inherit;">Admin Panel</a>
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="breadcrumb-active">Password Requests</span>
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

        <div class="content">

            <!-- Hero -->
            <div class="hero-banner">
                <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:24px;">
                    <div>
                        <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">Admin Management</p>
                        <h2 style="font-size:22px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">Password Requests</h2>
                        <p style="font-size:13px;color:rgba(255,255,255,0.65);font-weight:400;max-width:480px;line-height:1.6;">
                            Review and process password change requests submitted by system users. Approve or reject requests to maintain account security.
                        </p>
                    </div>
                    <div style="display:flex;gap:12px;flex-shrink:0;">
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fff;line-height:1;"><?= $totalReq ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Total</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fde68a;line-height:1;"><?= $pendingReq ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Pending</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#86efac;line-height:1;"><?= $approvedReq ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Approved</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fca5a5;line-height:1;"><?= $rejectedReq ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Rejected</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#64748b,#94a3b8);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;"><p class="stat-label">Total Requests</p><p class="stat-value"><?= str_pad($totalReq, 2, '0', STR_PAD_LEFT) ?></p><div class="stat-meta"><span class="badge" style="background:#f1f5f9;color:#475569;"><span class="badge-dot"></span>All Time</span></div></div>
                        <div class="stat-icon-wrap" style="background:#f8fafc;"><svg width="26" height="26" fill="none" stroke="#64748b" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#f59e0b,#fcd34d);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;"><p class="stat-label">Pending</p><p class="stat-value" style="color:#b45309;"><?= str_pad($pendingReq, 2, '0', STR_PAD_LEFT) ?></p><div class="stat-meta"><span class="badge badge-amber"><span class="badge-dot"></span>Needs Action</span></div></div>
                        <div class="stat-icon-wrap" style="background:#fffbeb;"><svg width="26" height="26" fill="none" stroke="#d97706" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#16a34a,#4ade80);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;"><p class="stat-label">Approved</p><p class="stat-value" style="color:#16a34a;"><?= str_pad($approvedReq, 2, '0', STR_PAD_LEFT) ?></p><div class="stat-meta"><span class="badge badge-green"><span class="badge-dot"></span>Resolved</span></div></div>
                        <div class="stat-icon-wrap" style="background:#f0fdf4;"><svg width="26" height="26" fill="none" stroke="#16a34a" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#ef4444,#f87171);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;"><p class="stat-label">Rejected</p><p class="stat-value" style="color:#dc2626;"><?= str_pad($rejectedReq, 2, '0', STR_PAD_LEFT) ?></p><div class="stat-meta"><span class="badge badge-red"><span class="badge-dot"></span>Declined</span></div></div>
                        <div class="stat-icon-wrap" style="background:#fef2f2;"><svg width="26" height="26" fill="none" stroke="#dc2626" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                    </div>
                </div>
            </div>

            <!-- Requests Panel -->
            <div class="panel">
                <div class="panel-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#fef2f2;display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">All Password Requests</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">Manage change requests from system users</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="filter-tabs">
                            <button class="filter-tab active" onclick="filterTab(this)">All</button>
                            <button class="filter-tab" onclick="filterTab(this)">Pending</button>
                            <button class="filter-tab" onclick="filterTab(this)">Approved</button>
                            <button class="filter-tab" onclick="filterTab(this)">Rejected</button>
                        </div>
                        <div class="search-wrap">
                            <svg class="search-icon" width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" class="search-input" placeholder="Search requests…">
                        </div>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th style="min-width:180px;">User</th>
                                <th style="text-align:center;">Role</th>
                                <th style="min-width:120px;">Request Date</th>
                                <th style="min-width:160px;">Reason</th>
                                <th style="text-align:center;">Status</th>
                                <th style="min-width:120px;">Resolved By</th>
                                <th style="text-align:center;min-width:160px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requests)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:24px;color:#94a3b8;font-size:13px;">No password requests found.</td></tr>
                            <?php else: ?>
                            <?php foreach ($requests as $idx => $req):
                                $fI = strtoupper(substr($req['first_name'], 0, 1));
                                $lI = strtoupper(substr($req['last_name'],  0, 1));
                                $reqDateLine1 = date('M d, Y', strtotime($req['requested_at']));
                                $reqDateLine2 = date('g:i A',  strtotime($req['requested_at']));
                                $reasonHtml   = $req['reason'] ? '<span class="reason-chip">' . htmlspecialchars($req['reason']) . '</span>' : '<span style="font-size:11px;color:#94a3b8;font-style:italic;">No reason provided</span>';
                                $badgeCls     = match($req['status']) {
                                    'approved' => 'badge-green',
                                    'rejected' => 'badge-red',
                                    default    => 'badge-amber',
                                };
                                $roleClass = strtolower(str_replace(' ', '-', preg_replace('/[^a-zA-Z ]/', '', $req['user_role'])));
                                $resolverHtml = '—';
                                if ($req['resolver_fname']) {
                                    $resolverHtml = '<p style="font-size:11px;font-weight:600;color:#334155;">' . htmlspecialchars($req['resolver_fname'] . ' ' . $req['resolver_lname']) . '</p>';
                                    if ($req['resolved_at']) {
                                        $resolverHtml .= '<p style="font-size:10px;color:#94a3b8;">' . date('M d · g:i A', strtotime($req['resolved_at'])) . '</p>';
                                    }
                                }
                            ?>
                            <tr>
                                <td style="color:#cbd5e1;font-weight:700;font-size:12px;"><?= str_pad($idx + 1, 2, '0', STR_PAD_LEFT) ?></td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <span class="u-avatar" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><?= htmlspecialchars($fI . $lI) ?></span>
                                        <div>
                                            <p style="font-weight:700;color:#0f172a;font-size:13px;line-height:1.2;"><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?></p>
                                            <p style="font-size:10px;color:#94a3b8;"><?= htmlspecialchars($req['email']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align:center;"><span class="role-pill role-admin"><?= htmlspecialchars($req['user_role']) ?></span></td>
                                <td>
                                    <p style="font-size:12px;font-weight:700;color:#0f172a;"><?= $reqDateLine1 ?></p>
                                    <p style="font-size:10px;color:#94a3b8;"><?= $reqDateLine2 ?></p>
                                </td>
                                <td><?= $reasonHtml ?></td>
                                <td style="text-align:center;"><span class="badge <?= $badgeCls ?>"><span class="badge-dot"></span><?= ucfirst(htmlspecialchars($req['status'])) ?></span></td>
                                <td><?= $resolverHtml ?></td>
                                <td style="text-align:center;">
                                    <?php if ($req['status'] === 'pending'): ?>
                                    <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                        <button class="btn-approve" onclick="openResolve('approve', '<?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name'], ENT_QUOTES) ?>', <?= $req['requestId'] ?>)">
                                            <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            Approve
                                        </button>
                                        <button class="btn-reject" onclick="openResolve('reject', '<?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name'], ENT_QUOTES) ?>', <?= $req['requestId'] ?>)">
                                            <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                            Reject
                                        </button>
                                    </div>
                                    <?php else: ?>
                                    <span style="font-size:11px;color:#94a3b8;font-weight:500;">Resolved</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel-footer">
                    <div class="show-rows-wrap"><span>Show</span><select class="show-rows-select"><option>10 rows</option><option selected>20 rows</option><option>50 rows</option></select></div>
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Displaying <strong style="color:#475569;"><?= $totalReq ?></strong> request<?= $totalReq !== 1 ? 's' : '' ?></p>
                </div>
            </div>

        </div><!-- /content -->
    </main>
</div>

<!-- ══ Resolve Confirmation Modal ══ -->
<form method="POST" action="password_requests.php" id="resolve-form">
    <input type="hidden" name="request_id" id="form-request-id">
    <input type="hidden" name="action"     id="form-action">
    <div class="modal-overlay" id="modal-resolve" onclick="handleOverlayClick(event)">
        <div class="modal-card">
            <div class="modal-header">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div id="modal-icon" style="width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;"></div>
                    <div>
                        <p id="modal-title" style="font-size:14px;font-weight:800;color:#0f172a;line-height:1.2;"></p>
                        <p id="modal-subtitle" style="font-size:11px;color:#94a3b8;font-weight:500;"></p>
                    </div>
                </div>
                <button type="button" onclick="closeModal()" style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="modal-alert" style="border-radius:9px;padding:12px 16px;display:flex;align-items:flex-start;gap:10px;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;" id="modal-alert-icon"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p id="modal-alert-text" style="font-size:12px;font-weight:500;line-height:1.6;"></p>
                </div>

                <!-- New password field — only shown when approving -->
                <div id="new-password-wrap" style="display:none;">
                    <p style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">
                        New Password <span style="color:#ef4444;">*</span>
                    </p>
                    <div style="position:relative;">
                        <input type="password" id="modal-new-password" name="new_password"
                               style="width:100%;padding:10px 40px 10px 14px;border:1px solid #e2e8f0;border-radius:9px;
                                      font-size:13px;font-family:'Poppins',sans-serif;color:#0f172a;background:#f8fafc;
                                      outline:none;transition:all 0.2s;box-sizing:border-box;"
                               placeholder="Min. 8 characters"
                               onfocus="this.style.borderColor='#16a34a';this.style.boxShadow='0 0 0 3px rgba(22,163,74,0.1)';"
                               onblur="this.style.borderColor='#e2e8f0';this.style.boxShadow='none';">
                        <button type="button" onclick="toggleModalPw()"
                                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                                       background:none;border:none;cursor:pointer;color:#94a3b8;display:flex;align-items:center;">
                            <svg id="modal-eye-open" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg id="modal-eye-closed" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                            </svg>
                        </button>
                    </div>
                    <p style="font-size:10px;color:#94a3b8;margin-top:5px;">Tell the user their new password after approving.</p>
                </div>

                <div>
                    <p style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">Admin Note (Optional)</p>
                    <textarea name="admin_note" style="width:100%;padding:10px 14px;border:1px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:'Poppins',sans-serif;color:#0f172a;background:#f8fafc;outline:none;resize:none;height:80px;transition:all 0.2s;" placeholder="Add a note for the user…" onfocus="this.style.borderColor='#ef4444';this.style.boxShadow='0 0 0 3px rgba(239,68,68,0.1)';" onblur="this.style.borderColor='#e2e8f0';this.style.boxShadow='none';"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                <button type="submit" id="modal-confirm-btn" class="btn btn-primary"></button>
            </div>
        </div>
    </div>
</form>

<script>
    function filterTab(el) {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
    }

    function openResolve(type, name, requestId) {
        const modal      = document.getElementById('modal-resolve');
        const icon       = document.getElementById('modal-icon');
        const title      = document.getElementById('modal-title');
        const subtitle   = document.getElementById('modal-subtitle');
        const alert      = document.getElementById('modal-alert');
        const alertIcon  = document.getElementById('modal-alert-icon');
        const alertText  = document.getElementById('modal-alert-text');
        const confirmBtn = document.getElementById('modal-confirm-btn');
        const pwWrap     = document.getElementById('new-password-wrap');
        const pwInput    = document.getElementById('modal-new-password');

        document.getElementById('form-request-id').value = requestId;
        document.getElementById('form-action').value     = type === 'approve' ? 'approved' : 'rejected';

        // Reset password field
        pwInput.value = '';
        pwInput.type  = 'password';
        document.getElementById('modal-eye-open').style.display   = '';
        document.getElementById('modal-eye-closed').style.display = 'none';

        if (type === 'approve') {
            icon.style.background = '#f0fdf4';
            icon.innerHTML = '<svg width="16" height="16" fill="none" stroke="#16a34a" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            title.textContent    = 'Approve Password Request';
            subtitle.textContent = name + ' · Set a new password below';
            alert.style.background = '#f0fdf4';
            alert.style.border     = '1px solid #bbf7d0';
            alertIcon.style.stroke = '#16a34a';
            alertText.style.color  = '#166534';
            alertText.textContent  = 'Set a new password for ' + name + '. Their password will be updated immediately upon approval.';
            confirmBtn.textContent = '✓ Approve & Reset Password';
            confirmBtn.style.background  = '#16a34a';
            confirmBtn.style.borderColor = '#16a34a';
            pwWrap.style.display = '';
            pwInput.required     = true;
        } else {
            icon.style.background = '#fef2f2';
            icon.innerHTML = '<svg width="16" height="16" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            title.textContent    = 'Reject Password Request';
            subtitle.textContent = name + ' · Password change';
            alert.style.background = '#fef2f2';
            alert.style.border     = '1px solid #fecaca';
            alertIcon.style.stroke = '#dc2626';
            alertText.style.color  = '#991b1b';
            alertText.textContent  = 'Rejecting this request will deny ' + name + '\'s password change.';
            confirmBtn.textContent = '✕ Confirm Rejection';
            confirmBtn.style.background  = '#dc2626';
            confirmBtn.style.borderColor = '#dc2626';
            pwWrap.style.display = 'none';
            pwInput.required     = false;
        }

        modal.classList.add('open');
    }

    function toggleModalPw() {
        const inp    = document.getElementById('modal-new-password');
        const isText = inp.type === 'text';
        inp.type = isText ? 'password' : 'text';
        document.getElementById('modal-eye-open').style.display   = isText ? '' : 'none';
        document.getElementById('modal-eye-closed').style.display = isText ? 'none' : '';
    }

    function closeModal() {
        document.getElementById('modal-resolve').classList.remove('open');
    }

    function handleOverlayClick(e) {
        if (e.target === document.getElementById('modal-resolve')) closeModal();
    }
</script>
</body>
</html>
