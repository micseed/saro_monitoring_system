<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/database.php';

$db  = new Database();
$pdo = $db->connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$currentUserId = (int)$_SESSION['user_id'];
$username  = $_SESSION['full_name'];
$role      = $_SESSION['role'];
$initials  = $_SESSION['initials'];
$adminId   = $currentUserId;

// ── Handle Create Account POST ─────────────────────────────
$createSuccess     = false;
$createError       = '';
$createdUserName   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_account') {
    $firstName   = trim($_POST['first_name']   ?? '');
    $lastName    = trim($_POST['last_name']     ?? '');
    $middleName  = trim($_POST['middle_name']   ?? '') ?: null;
    $phoneNumber = trim($_POST['phone_number']  ?? '') ?: null;
    $uname       = trim($_POST['username']      ?? '');
    $email       = trim($_POST['email']         ?? '');
    $roleId      = (int)($_POST['roleId']       ?? 0);
    $password    = $_POST['password']           ?? '';
    $confirmPw   = $_POST['confirm_password']   ?? '';
    $status      = 'active';

    if (!$firstName || !$lastName || !$uname || !$email || !$roleId || !$password) {
        $createError = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $createError = 'Invalid email address.';
    } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[\W_]/', $password)) {
        $createError = 'Password must be at least 8 characters with one uppercase letter, one number, and one special character.';
    } elseif ($password !== $confirmPw) {
        $createError = 'Passwords do not match.';
    } else {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM user WHERE username = ? OR email = ?");
        $chk->execute([$uname, $email]);
        if ($chk->fetchColumn() > 0) {
            $createError = 'Username or email already exists.';
        } else {
            $hashed    = password_hash($password, PASSWORD_BCRYPT);
            $createdBy = $currentUserId ?: null;

            $ins = $pdo->prepare("
                INSERT INTO user (roleId, last_name, first_name, middle_name, phone_number, username, email, password, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$roleId, $lastName, $firstName, $middleName, $phoneNumber, $uname, $email, $hashed, $status, $createdBy]);

            $createSuccess   = true;
            $createdUserName = $firstName . ' ' . $lastName;
            $newId = $pdo->lastInsertId();

            $logStmt = $pdo->prepare("
                INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address)
                VALUES (?, 'create', ?, 'user', ?, ?)
            ");
            $logStmt->execute([
                $createdBy,
                "Created account for {$createdUserName}",
                $newId,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }
    }
}

// ── Handle Edit User POST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    $editId     = (int)($_POST['edit_user_id'] ?? 0);
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $editRoleId = (int)($_POST['roleId']    ?? 0);
    $editStatus = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

    if ($editId && $firstName && $lastName && filter_var($email, FILTER_VALIDATE_EMAIL) && $editRoleId) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM user WHERE email = ? AND userId != ?");
        $chk->execute([$email, $editId]);
        if ($chk->fetchColumn() == 0) {
            $pdo->prepare("UPDATE user SET first_name=?, last_name=?, email=?, roleId=?, status=? WHERE userId=?")
                ->execute([$firstName, $lastName, $email, $editRoleId, $editStatus, $editId]);
            $pdo->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'edit', ?, 'user', ?, ?)")
                ->execute([$currentUserId, "Updated account for $firstName $lastName", $editId, $_SERVER['REMOTE_ADDR'] ?? null]);
        }
    }
    header('Location: users.php'); exit;
}

// ── Handle Delete User POST ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    $delId = (int)($_POST['delete_user_id'] ?? 0);
    if ($delId && $delId !== $currentUserId) {
        try {
            $pdo->prepare("DELETE FROM user WHERE userId = ?")->execute([$delId]);
            $pdo->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'delete', 'Deleted user account', 'user', ?, ?)")
                ->execute([$currentUserId, $delId, $_SERVER['REMOTE_ADDR'] ?? null]);
            header('Location: users.php?deleted=1'); exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                // User has linked records — deactivate instead of hard delete
                $pdo->prepare("UPDATE user SET status='inactive' WHERE userId=?")->execute([$delId]);
                $pdo->prepare("INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address) VALUES (?, 'edit', 'Deactivated user account (linked records exist)', 'user', ?, ?)")
                    ->execute([$currentUserId, $delId, $_SERVER['REMOTE_ADDR'] ?? null]);
                header('Location: users.php?deactivated=1'); exit;
            }
            throw $e;
        }
    }
    header('Location: users.php'); exit;
}

$flashMsg  = '';
$flashType = '';
if (isset($_GET['deleted']))     { $flashMsg = 'User account has been permanently deleted.'; $flashType = 'red'; }
if (isset($_GET['deactivated'])) { $flashMsg = 'User has linked records and cannot be fully deleted. The account has been deactivated instead.'; $flashType = 'amber'; }

// ── Fetch dynamic data ───────────────────────────────
$roles = $pdo->query("SELECT roleId, role FROM user_role ORDER BY roleId")->fetchAll();
$pendingReqCount = (int)$pdo->query("SELECT COUNT(*) FROM password_requests WHERE status = 'pending'")->fetchColumn();

// Fetch Users with their Creator's Name
$usersQuery = $pdo->query("
    SELECT u.userId, u.roleId, u.first_name, u.last_name, u.email, u.status, u.last_login, u.created_at, ur.role,
           c.first_name as creator_fname, c.last_name as creator_lname
    FROM user u
    JOIN user_role ur ON u.roleId = ur.roleId
    LEFT JOIN user c ON u.created_by = c.userId
    ORDER BY u.created_at DESC
");
$allUsers = $usersQuery->fetchAll();

// Fetch Audit Logs with the Actor's Name (the FIX)
$logsQuery = $pdo->query("
    SELECT a.*, u.first_name, u.last_name 
    FROM audit_logs a
    LEFT JOIN user u ON a.userId = u.userId
    ORDER BY a.created_at DESC
    LIMIT 50
");
$logs = $logsQuery->fetchAll();

// Dynamic counters
$totalUsers = count($allUsers);
$totalLogs  = $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$activeCount = 0;
$inactiveCount = 0;

foreach($allUsers as $u) {
    if ($u['status'] === 'active') $activeCount++;
    else $inactiveCount++;
}

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
    <title>User Management | DICT SARO Monitoring</title>
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
        .panel { background: #fff; border: 1px solid #e8edf5; border-radius: 16px; overflow: hidden; margin-bottom: 24px; }
        .panel-header { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
        .panel-footer { padding: 12px 24px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: space-between; }

        /* ── Table ── */
        table { width: 100%; border-collapse: collapse; }
        thead th { padding: 11px 20px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.12em; color: #94a3b8; background: #fafbfe; border-bottom: 1px solid #f1f5f9; white-space: nowrap; text-align: left; }
        tbody tr { border-bottom: 1px solid #f8fafc; transition: background 0.15s ease; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        tbody td { padding: 14px 20px; font-size: 12px; color: #475569; vertical-align: middle; }

        /* ── Badges ── */
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge-green  { background: #dcfce7; color: #16a34a; }
        .badge-red    { background: #fee2e2; color: #dc2626; }
        .badge-amber  { background: #fef9c3; color: #b45309; }
        .badge-blue   { background: #dbeafe; color: #1d4ed8; }
        .badge-purple { background: #f3e8ff; color: #7c3aed; }

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
        .btn-danger-sm { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 5px 10px; border-radius: 7px; font-size: 11px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .btn-danger-sm:hover { background: #fee2e2; border-color: #f87171; }
        .btn-edit-sm { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 5px 10px; border-radius: 7px; font-size: 11px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .btn-edit-sm:hover { background: #dbeafe; border-color: #93c5fd; }
        .btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 7px; }

        /* ── User avatar ── */
        .u-avatar { width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; color: #fff; }

        /* ── Search ── */
        .search-wrap { position: relative; }
        .search-input { padding: 8px 12px 8px 34px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; font-size: 12px; font-family: 'Poppins', sans-serif; width: 200px; outline: none; transition: all 0.2s ease; }
        .search-input:focus { border-color: #ef4444; background: #fff; box-shadow: 0 0 0 3px rgba(239,68,68,0.1); }
        .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }

        /* ── Password cell ── */
        .pw-cell { display: flex; align-items: center; gap: 8px; }
        .pw-text { font-family: monospace; font-size: 13px; font-weight: 600; color: #94a3b8; letter-spacing: 2px; }
        .pw-toggle { width: 26px; height: 26px; border-radius: 6px; border: 1px solid #e2e8f0; background: #f8fafc; color: #94a3b8; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; flex-shrink: 0; }
        .pw-toggle:hover { border-color: #93c5fd; color: #2563eb; background: #eff6ff; }

        /* ── Action pills (logs) ── */
        .action-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 6px; font-size: 10px; font-weight: 700; white-space: nowrap; text-transform: capitalize; }
        .action-login   { background: #eff6ff; color: #1d4ed8; }
        .action-create  { background: #f0fdf4; color: #15803d; }
        .action-edit    { background: #fef9c3; color: #b45309; }
        .action-view    { background: #f5f3ff; color: #6d28d9; }
        .action-logout  { background: #f1f5f9; color: #475569; }

        /* ── Show rows ── */
        .show-rows-wrap { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #64748b; font-weight: 500; }
        .show-rows-select { padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 11px; font-family: 'Poppins', sans-serif; color: #0f172a; background: #f8fafc; outline: none; cursor: pointer; }

        /* ── Modal ── */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.65); backdrop-filter: blur(4px); z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s ease; }
        .modal-overlay.open { opacity: 1; pointer-events: all; }
        .modal-card { background: #fff; border-radius: 20px; width: 100%; max-width: 480px; box-shadow: 0 24px 60px rgba(0,0,0,0.2); transform: translateY(16px); transition: transform 0.25s ease; overflow: hidden; }
        .modal-overlay.open .modal-card { transform: translateY(0); }
        .modal-header { padding: 22px 28px 18px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
        .modal-body { padding: 22px 28px; display: flex; flex-direction: column; gap: 16px; }
        .modal-footer { padding: 16px 28px; border-top: 1px solid #f1f5f9; background: #fafbfe; display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-label { font-size: 11px; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.08em; }
        .form-input { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 9px; font-size: 13px; font-family: 'Poppins', sans-serif; color: #0f172a; background: #f8fafc; outline: none; transition: all 0.2s ease; width: 100%; }
        .form-input:focus { border-color: #ef4444; background: #fff; box-shadow: 0 0 0 3px rgba(239,68,68,0.1); }
        .form-select { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 9px; font-size: 13px; font-family: 'Poppins', sans-serif; color: #0f172a; background: #f8fafc; outline: none; cursor: pointer; width: 100%; transition: all 0.2s; }
        .form-select:focus { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.1); }
        .pw-input-wrap { position: relative; }
        .pw-input-wrap .form-input { padding-right: 40px; }
        .pw-show-btn { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #94a3b8; display: flex; align-items: center; padding: 0; transition: color 0.2s; }
        .pw-show-btn:hover { color: #475569; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        /* ── Success modal ── */
        .success-modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.65); backdrop-filter: blur(4px); z-index: 1100; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; }
        .success-modal-overlay.open { opacity: 1; pointer-events: all; }
        .success-modal-card { background: #fff; border-radius: 20px; width: 100%; max-width: 400px; box-shadow: 0 24px 60px rgba(0,0,0,0.2); transform: translateY(20px) scale(0.97); transition: transform 0.3s ease; overflow: hidden; text-align: center; padding: 40px 32px 32px; }
        .success-modal-overlay.open .success-modal-card { transform: translateY(0) scale(1); }
        .success-checkmark { width: 68px; height: 68px; border-radius: 50%; background: linear-gradient(135deg,#16a34a,#15803d); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 8px 24px rgba(22,163,74,0.3); animation: popIn 0.4s ease; }
        @keyframes popIn { 0% { transform: scale(0.5); opacity: 0; } 70% { transform: scale(1.1); } 100% { transform: scale(1); opacity: 1; } }

        /* ── Form error ── */
        .form-error { background: #fef2f2; border: 1px solid #fecaca; border-radius: 9px; padding: 10px 14px; display: flex; align-items: flex-start; gap: 8px; }
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
            <a href="users.php" class="nav-item active">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                Users
            </a>
            <a href="password_requests.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                Password Requests
                <?php if ($pendingReqCount > 0): ?><span class="nav-badge"><?= $pendingReqCount ?></span><?php endif; ?>
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
                <span class="breadcrumb-active">User Management</span>
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

            <?php if ($flashMsg): ?>
            <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:12px;margin-bottom:16px;
                background:<?= $flashType==='red' ? '#fef2f2' : '#fffbeb' ?>;
                border:1px solid <?= $flashType==='red' ? '#fecaca' : '#fde68a' ?>;">
                <svg width="16" height="16" fill="none" stroke="<?= $flashType==='red' ? '#dc2626' : '#d97706' ?>" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <p style="font-size:12px;font-weight:600;color:<?= $flashType==='red' ? '#991b1b' : '#92400e' ?>;line-height:1.5;"><?= htmlspecialchars($flashMsg) ?></p>
            </div>
            <?php endif; ?>

            <!-- Hero -->
            <div class="hero-banner">
                <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:24px;">
                    <div>
                        <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">Admin Management</p>
                        <h2 style="font-size:22px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">User Management</h2>
                        <p style="font-size:13px;color:rgba(255,255,255,0.65);font-weight:400;max-width:480px;line-height:1.6;">
                            Create and manage user accounts, assign roles, and monitor login activity across the SARO Monitoring System.
                        </p>
                    </div>
                    <div style="display:flex;gap:12px;flex-shrink:0;">
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fff;line-height:1;"><?= $totalUsers ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Total Users</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#86efac;line-height:1;"><?= $activeCount ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Active</p>
                        </div>
                        <div style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.15);border-radius:12px;padding:14px 20px;text-align:center;min-width:82px;">
                            <p style="font-size:22px;font-weight:900;color:#fca5a5;line-height:1;"><?= $inactiveCount ?></p>
                            <p style="font-size:9px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.1em;margin-top:4px;">Inactive</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#2563eb,#60a5fa);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;"><p class="stat-label">Total Accounts</p><p class="stat-value"><?= str_pad($totalUsers, 2, '0', STR_PAD_LEFT) ?></p><div class="stat-meta"><span class="badge badge-blue"><span class="badge-dot"></span>Registered</span></div></div>
                        <div class="stat-icon-wrap" style="background:#eff6ff;"><svg width="26" height="26" fill="none" stroke="#2563eb" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#16a34a,#4ade80);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;"><p class="stat-label">Active Users</p><p class="stat-value" style="color:#16a34a;"><?= str_pad($activeCount, 2, '0', STR_PAD_LEFT) ?></p><div class="stat-meta"><span class="badge badge-green"><span class="badge-dot" style="animation:pulse 2s infinite;"></span>Online Now</span></div></div>
                        <div class="stat-icon-wrap" style="background:#f0fdf4;"><svg width="26" height="26" fill="none" stroke="#16a34a" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#94a3b8,#cbd5e1);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;"><p class="stat-label">Inactive Users</p><p class="stat-value" style="color:#94a3b8;"><?= str_pad($inactiveCount, 2, '0', STR_PAD_LEFT) ?></p><div class="stat-meta"><span class="badge" style="background:#f1f5f9;color:#64748b;"><span class="badge-dot"></span>No Access</span></div></div>
                        <div class="stat-icon-wrap" style="background:#f8fafc;"><svg width="26" height="26" fill="none" stroke="#94a3b8" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-accent" style="background:linear-gradient(90deg,#7c3aed,#a78bfa);"></div>
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                        <div style="flex:1;min-width:0;"><p class="stat-label">Roles Assigned</p><p class="stat-value"><?= str_pad(count($roles), 2, '0', STR_PAD_LEFT) ?></p><div class="stat-meta"><span class="badge badge-purple"><span class="badge-dot"></span>Distinct Roles</span></div></div>
                        <div class="stat-icon-wrap" style="background:#f5f3ff;"><svg width="26" height="26" fill="none" stroke="#7c3aed" viewBox="0 0 24 24" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg></div>
                    </div>
                </div>
            </div>

            <!-- User Profiles Panel -->
            <div class="panel">
                <div class="panel-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#fef2f2;display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">User Profiles</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">Manage accounts and access roles</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="search-wrap">
                            <svg class="search-icon" width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" class="search-input" placeholder="Search users…">
                        </div>
                        <select class="show-rows-select">
                            <option>All Roles</option>
                            <?php foreach ($roles as $r): ?>
                                <option><?= htmlspecialchars($r['role']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary" onclick="openModal()">
                            <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Create Account
                        </button>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Name &amp; Profile</th>
                                <th>Email</th>
                                <th style="text-align:center;">Role</th>
                                <th style="text-align:center;">Created By</th>
                                <th>Last Active</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($allUsers)): ?>
                                <tr><td colspan="8" style="text-align:center;padding:20px;color:#94a3b8;">No users found.</td></tr>
                            <?php else: ?>
                                <?php foreach($allUsers as $index => $u): 
                                    $fInitial = strtoupper(substr($u['first_name'], 0, 1));
                                    $lInitial = strtoupper(substr($u['last_name'], 0, 1));
                                    $joinedDate = date('M j, Y', strtotime($u['created_at']));
                                    
                                    $lastActiveHtml = '<p style="font-size:12px;font-weight:600;color:#94a3b8;">Never logged in</p>';
                                    if ($u['last_login']) {
                                        $lastActiveHtml = '<p style="font-size:12px;font-weight:600;color:#0f172a;">'.date('M d, h:i A', strtotime($u['last_login'])).'</p>';
                                    }

                                    $roleClass = 'role-viewer';
                                    if (strpos(strtolower($u['role']), 'super') !== false || strpos(strtolower($u['role']), 'admin') !== false) {
                                        $roleClass = 'role-admin';
                                    } elseif (strpos(strtolower($u['role']), 'encoder') !== false) {
                                        $roleClass = 'role-encoder';
                                    }
                                    
                                    $statusClass = $u['status'] === 'active' ? 'badge-green' : 'badge-red';
                                    
                                    $creatorName = !empty($u['creator_fname']) ? htmlspecialchars($u['creator_fname'] . ' ' . $u['creator_lname']) : 'System Setup';
                                ?>
                                <tr>
                                    <td style="color:#cbd5e1;font-weight:700;font-size:12px;"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <span class="u-avatar" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);">
                                                <?= htmlspecialchars($fInitial . $lInitial) ?>
                                            </span>
                                            <div>
                                                <p style="font-weight:700;color:#0f172a;font-size:13px;line-height:1.2;"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></p>
                                                <p style="font-size:10px;color:#94a3b8;font-weight:500;">Joined <?= $joinedDate ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($u['email']) ?></td>
                                    <td style="text-align:center;"><span class="role-pill <?= $roleClass ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                                    <td style="text-align:center;">
                                        <span style="font-size:11px;font-weight:600;color:#475569;"><?= $creatorName ?></span>
                                    </td>
                                    <td>
                                        <?= $lastActiveHtml ?>
                                    </td>
                                    <td style="text-align:center;"><span class="badge <?= $statusClass ?>"><span class="badge-dot"></span><?= ucfirst(htmlspecialchars($u['status'])) ?></span></td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                            <button class="btn-edit-sm" onclick="openEditModal(this)"
                                                data-id="<?= $u['userId'] ?>"
                                                data-first="<?= htmlspecialchars($u['first_name'], ENT_QUOTES) ?>"
                                                data-last="<?= htmlspecialchars($u['last_name'], ENT_QUOTES) ?>"
                                                data-email="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>"
                                                data-roleid="<?= $u['roleId'] ?>"
                                                data-status="<?= $u['status'] ?>">
                                                <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>Edit
                                            </button>
                                            <button class="btn-danger-sm" onclick="openDeleteModal(this)"
                                                data-id="<?= $u['userId'] ?>"
                                                data-name="<?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES) ?>">
                                                <svg width="11" height="11" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel-footer">
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Displaying <strong style="color:#475569;"><?= count($allUsers) ?></strong> users</p>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div class="show-rows-wrap"><span>Show</span><select class="show-rows-select"><option>10 rows</option><option selected>20 rows</option><option>50 rows</option></select></div>
                    </div>
                </div>
            </div>

        </div><!-- /content -->
    </main>
</div>

<!-- ══ Create Account Modal ══ -->
<div class="modal-overlay" id="modal-create" onclick="handleOverlayClick(event)">
    <div class="modal-card" style="max-width:560px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:36px;height:36px;border-radius:10px;background:#fef2f2;display:flex;align-items:center;justify-content:center;">
                    <svg width="16" height="16" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                </div>
                <div>
                    <p style="font-size:14px;font-weight:800;color:#0f172a;line-height:1.2;">Create New Account</p>
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Fill in the user details below</p>
                </div>
            </div>
            <button onclick="closeModal()" style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;transition:all 0.2s;" onmouseover="this.style.background='#fee2e2';this.style.color='#dc2626';this.style.borderColor='#fecaca';" onmouseout="this.style.background='#f8fafc';this.style.color='#64748b';this.style.borderColor='#e2e8f0';">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="create_account">
            <div class="modal-body" style="max-height:70vh;overflow-y:auto;">

                <?php if ($createError): ?>
                <div class="form-error">
                    <svg width="14" height="14" fill="none" stroke="#dc2626" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px;color:#991b1b;font-weight:500;line-height:1.5;"><?= htmlspecialchars($createError) ?></p>
                </div>
                <?php endif; ?>

                <!-- Name row -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" class="form-input" placeholder="e.g. Juan" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" class="form-input" placeholder="e.g. dela Cruz" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Middle Name <span style="color:#94a3b8;font-weight:500;text-transform:none;font-size:10px;">(optional)</span></label>
                    <input type="text" name="middle_name" class="form-input" placeholder="e.g. Santos" value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                </div>

                <!-- Phone + Username row -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone Number <span style="color:#94a3b8;font-weight:500;text-transform:none;font-size:10px;">(optional)</span></label>
                        <input type="text" name="phone_number" class="form-input" placeholder="e.g. 09171234567" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Username <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="username" class="form-input" placeholder="e.g. jdelacruz" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                    </div>
                </div>

                <!-- Email + Role row -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email Address <span style="color:#dc2626;">*</span></label>
                        <input type="email" name="email" class="form-input" placeholder="user@dict.gov.ph" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role <span style="color:#dc2626;">*</span></label>
                        <select name="roleId" class="form-select" required>
                            <option value="">Select role…</option>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['roleId'] ?>" <?= (($_POST['roleId'] ?? '') == $r['roleId']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['role']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Passwords -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Password <span style="color:#dc2626;">*</span></label>
                        <div class="pw-input-wrap">
                            <input type="password" name="password" class="form-input" id="modal-pw" placeholder="Enter password" required>
                            <button type="button" class="pw-show-btn" onclick="toggleModalPw('modal-pw')">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm Password <span style="color:#dc2626;">*</span></label>
                        <div class="pw-input-wrap">
                            <input type="password" name="confirm_password" class="form-input" id="modal-pw-confirm" placeholder="Repeat password" required>
                            <button type="button" class="pw-show-btn" onclick="toggleModalPw('modal-pw-confirm')">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:10px 14px;display:flex;align-items:flex-start;gap:8px;">
                    <svg width="14" height="14" fill="none" stroke="#d97706" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px;color:#92400e;font-weight:500;line-height:1.5;">Password must be at least 8 characters with one uppercase letter, one number, and one special character.</p>
                </div>

            </div><!-- /modal-body -->
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Success Modal ══ -->
<div class="success-modal-overlay" id="modal-success">
    <div class="success-modal-card">
        <div class="success-checkmark">
            <svg width="32" height="32" fill="none" stroke="#fff" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        <p style="font-size:20px;font-weight:900;color:#0f172a;margin-bottom:8px;">Account Created!</p>
        <p style="font-size:13px;color:#64748b;font-weight:500;line-height:1.6;margin-bottom:24px;">
            The account for <strong style="color:#0f172a;" id="success-name"></strong> has been successfully created and is now active.
        </p>
        <button onclick="closeSuccessModal()" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px;">
            Done
        </button>
    </div>
</div>

<!-- ══ Edit User Modal ══ -->
<div class="modal-overlay" id="modal-edit" onclick="if(event.target===this)closeEditModal()">
    <div class="modal-card" style="max-width:480px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:36px;height:36px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;">
                    <svg width="16" height="16" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </div>
                <div>
                    <p style="font-size:14px;font-weight:800;color:#0f172a;line-height:1.2;">Edit User Account</p>
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Update account details below</p>
                </div>
            </div>
            <button onclick="closeEditModal()" style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;transition:all 0.2s;" onmouseover="this.style.background='#fee2e2';this.style.color='#dc2626';this.style.borderColor='#fecaca';" onmouseout="this.style.background='#f8fafc';this.style.color='#64748b';this.style.borderColor='#e2e8f0';">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="edit_user_id" id="edit-user-id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" id="edit-first-name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" id="edit-last-name" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address <span style="color:#dc2626;">*</span></label>
                    <input type="email" name="email" id="edit-email" class="form-input" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role <span style="color:#dc2626;">*</span></label>
                        <select name="roleId" id="edit-roleId" class="form-select" required>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['roleId'] ?>"><?= htmlspecialchars($r['role']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status <span style="color:#dc2626;">*</span></label>
                        <select name="status" id="edit-status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Delete User Modal ══ -->
<div class="modal-overlay" id="modal-delete" onclick="if(event.target===this)closeDeleteModal()">
    <div class="modal-card" style="max-width:420px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:36px;height:36px;border-radius:10px;background:#fef2f2;display:flex;align-items:center;justify-content:center;">
                    <svg width="16" height="16" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </div>
                <div>
                    <p style="font-size:14px;font-weight:800;color:#0f172a;line-height:1.2;">Delete Account</p>
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">This action cannot be undone</p>
                </div>
            </div>
            <button onclick="closeDeleteModal()" style="width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;transition:all 0.2s;" onmouseover="this.style.background='#fee2e2';this.style.color='#dc2626';this.style.borderColor='#fecaca';" onmouseout="this.style.background='#f8fafc';this.style.color='#64748b';this.style.borderColor='#e2e8f0';">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="delete_user_id" id="delete-user-id">
            <div class="modal-body">
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:16px;display:flex;align-items:flex-start;gap:12px;">
                    <svg width="18" height="18" fill="none" stroke="#dc2626" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p style="font-size:12px;color:#991b1b;font-weight:500;line-height:1.6;">
                        Are you sure you want to delete the account for <strong id="delete-user-name"></strong>? If the user has linked records, the account will be deactivated instead of permanently removed.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete Account
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleModalPw(id) {
        const el = document.getElementById(id);
        el.type = el.type === 'password' ? 'text' : 'password';
    }

    function openModal() {
        document.getElementById('modal-create').classList.add('open');
    }
    function closeModal() {
        document.getElementById('modal-create').classList.remove('open');
    }
    function handleOverlayClick(e) {
        if (e.target === document.getElementById('modal-create')) closeModal();
    }

    function closeSuccessModal() {
        document.getElementById('modal-success').classList.remove('open');
    }

    function openEditModal(btn) {
        document.getElementById('edit-user-id').value    = btn.dataset.id;
        document.getElementById('edit-first-name').value = btn.dataset.first;
        document.getElementById('edit-last-name').value  = btn.dataset.last;
        document.getElementById('edit-email').value      = btn.dataset.email;
        document.getElementById('edit-roleId').value     = btn.dataset.roleid;
        document.getElementById('edit-status').value     = btn.dataset.status;
        document.getElementById('modal-edit').classList.add('open');
    }
    function closeEditModal() {
        document.getElementById('modal-edit').classList.remove('open');
    }

    function openDeleteModal(btn) {
        document.getElementById('delete-user-id').value              = btn.dataset.id;
        document.getElementById('delete-user-name').textContent      = btn.dataset.name;
        document.getElementById('modal-delete').classList.add('open');
    }
    function closeDeleteModal() {
        document.getElementById('modal-delete').classList.remove('open');
    }

    // Auto-trigger modals based on PHP result
    <?php if ($createSuccess): ?>
    window.addEventListener('DOMContentLoaded', function () {
        document.getElementById('success-name').textContent = <?= json_encode($createdUserName) ?>;
        document.getElementById('modal-success').classList.add('open');
    });
    <?php elseif ($createError): ?>
    window.addEventListener('DOMContentLoaded', function () {
        document.getElementById('modal-create').classList.add('open');
    });
    <?php endif; ?>
</script>
</body>
</html>