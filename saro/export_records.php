<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../class/database.php';
require_once __DIR__ . '/../class/notification.php';

$username = $_SESSION['full_name'];
$role     = $_SESSION['role'];
$initials = $_SESSION['initials'];
$userId   = (int)$_SESSION['user_id'];

$db  = new Database();
$pdo = $db->connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$notifObj      = new Notification();
$notifications = $notifObj->getRecentActivity($userId, 10);
$unreadCount   = $notifObj->countUnread($userId);
$approvedPwReq = $notifObj->getApprovedPasswordNotification($userId);

// Sidebar counts
$cancelledCount = (int)$pdo->query("SELECT COUNT(*) FROM saro WHERE status='cancelled'")->fetchColumn();
$obligatedCount = (int)$pdo->query("SELECT COUNT(*) FROM saro WHERE status='obligated'")->fetchColumn();
$lapsedCount    = (int)$pdo->query("SELECT COUNT(*) FROM saro WHERE status='lapsed'")->fetchColumn();

// Available fiscal years with obligated SAROs
$years = $pdo->query("
    SELECT DISTINCT fiscal_year
    FROM saro
    WHERE status = 'obligated'
    ORDER BY fiscal_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

$selectedYear = isset($_GET['year']) && in_array((int)$_GET['year'], array_map('intval', $years))
    ? (int)$_GET['year']
    : (empty($years) ? (int)date('Y') : (int)$years[0]);

// Obligated SAROs for selected year
$saroStmt = $pdo->prepare("
    SELECT s.saroId, s.saroNo, s.saro_title, s.fiscal_year, s.total_budget,
           s.date_released, s.valid_until,
           COUNT(DISTINCT o.objectId) AS obj_count,
           COALESCE(SUM(CASE WHEN p.status='obligated' THEN p.obligated_amount ELSE 0 END), 0) AS total_obligated
    FROM saro s
    LEFT JOIN object_code o ON o.saroId = s.saroId
    LEFT JOIN procurement p ON p.objectId = o.objectId
    WHERE s.status = 'obligated' AND s.fiscal_year = :yr
    GROUP BY s.saroId
    ORDER BY s.saroNo ASC
");
$saroStmt->execute([':yr' => $selectedYear]);
$saros = $saroStmt->fetchAll();

// Procurement activities per SARO
$procMap = [];
if (!empty($saros)) {
    $ids = implode(',', array_map(fn($s) => (int)$s['saroId'], $saros));
    $procRows = $pdo->query("
        SELECT p.procurementId, o.saroId, o.code AS object_code,
               p.pro_act, p.quantity, p.unit, p.unit_cost,
               p.obligated_amount, p.period_start, p.period_end, p.proc_date, p.remarks
        FROM procurement p
        JOIN object_code o ON p.objectId = o.objectId
        WHERE o.saroId IN ($ids) AND p.status = 'obligated'
        ORDER BY p.proc_date ASC, p.procurementId ASC
    ")->fetchAll();
    foreach ($procRows as $pr) {
        $procMap[$pr['saroId']][] = $pr;
    }
}

// Year totals
$yearBudget    = array_sum(array_column($saros, 'total_budget'));
$yearObligated = array_sum(array_column($saros, 'total_obligated'));
$yearRate      = $yearBudget > 0 ? round($yearObligated / $yearBudget * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Export Records | DICT SARO Monitoring</title>
<link href="../dist/output.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
* { font-family: 'Poppins', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; background: #f0f4ff; }
::-webkit-scrollbar { width: 5px; } ::-webkit-scrollbar-thumb { background: #c7d7fe; border-radius: 99px; }
.layout { display: flex; height: 100vh; }

/* Sidebar */
.sidebar { width: 256px; flex-shrink: 0; display: flex; flex-direction: column; background: #0f172a; position: relative; overflow: hidden; }
.sidebar::before { content: ''; position: absolute; top: -80px; right: -80px; width: 220px; height: 220px; background: #1e3a8a; border-radius: 50%; opacity: 0.4; pointer-events: none; }
.sidebar::after  { content: ''; position: absolute; bottom: -60px; left: -60px; width: 180px; height: 180px; background: #1d4ed8; border-radius: 50%; opacity: 0.2; pointer-events: none; }
.sidebar-brand { display: flex; align-items: center; gap: 12px; padding: 28px 24px 24px; border-bottom: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
.brand-logo { width: 40px; height: 40px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; padding: 6px; }
.sidebar-nav { flex: 1; padding: 20px 16px; overflow-y: auto; position: relative; z-index: 1; }
.nav-section-label { font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.25); text-transform: uppercase; letter-spacing: 0.16em; padding: 0 8px; margin-bottom: 8px; margin-top: 20px; }
.nav-section-label:first-child { margin-top: 0; }
.nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px; font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.45); text-decoration: none; transition: all 0.2s ease; margin-bottom: 2px; }
.nav-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.85); }
.nav-item.active { background: #1e3a8a; color: #fff; box-shadow: 0 0 0 1px rgba(59,130,246,0.3); }
.nav-item.active .nav-icon { color: #60a5fa; }
.nav-icon { width: 16px; height: 16px; flex-shrink: 0; }
.sidebar-footer { padding: 16px; border-top: 1px solid rgba(255,255,255,0.06); position: relative; z-index: 1; }
.user-card { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; background: rgba(255,255,255,0.05); margin-bottom: 8px; }
.user-avatar { width: 34px; height: 34px; border-radius: 8px; background: linear-gradient(135deg,#2563eb,#1d4ed8); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; color: #fff; }
.signout-btn { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.4); background: none; border: none; cursor: pointer; text-decoration: none; transition: all 0.2s ease; }
.signout-btn:hover { background: rgba(239,68,68,0.12); color: #fca5a5; }

/* Main */
.main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.topbar { height: 64px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; padding: 0 32px; background: #fff; border-bottom: 1px solid #e8edf5; }
.breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #64748b; }
.breadcrumb-active { color: #0f172a; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.content { flex: 1; overflow-y: auto; padding: 28px 32px; }

/* Hero */
.hero-banner { background: linear-gradient(135deg,#312e81 0%,#4f46e5 100%); border-radius: 16px; padding: 28px 32px; margin-bottom: 24px; position: relative; overflow: hidden; }
.hero-banner::before { content: ''; position: absolute; top: -60px; right: -40px; width: 220px; height: 220px; background: rgba(255,255,255,0.07); border-radius: 50%; }
.hero-banner::after  { content: ''; position: absolute; bottom: -40px; right: 120px; width: 140px; height: 140px; background: rgba(255,255,255,0.05); border-radius: 50%; }

/* Year tabs */
.year-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
.year-tab { padding: 8px 20px; border-radius: 99px; font-size: 12px; font-weight: 700; text-decoration: none; border: 1.5px solid #e2e8f0; color: #64748b; background: #fff; transition: all 0.2s ease; }
.year-tab:hover { border-color: #6366f1; color: #4f46e5; }
.year-tab.active { background: #4f46e5; border-color: #4f46e5; color: #fff; box-shadow: 0 4px 12px rgba(79,70,229,0.3); }

/* Summary cards */
.summary-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; margin-bottom: 20px; }
.summary-card { background: #fff; border: 1px solid #e8edf5; border-radius: 12px; padding: 18px 20px; }
.summary-label { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 4px; }
.summary-value { font-size: 22px; font-weight: 900; color: #0f172a; letter-spacing: -0.02em; }

/* Table panel */
.table-panel { background: #fff; border: 1px solid #e8edf5; border-radius: 16px; overflow: hidden; }
.panel-header { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
table { width: 100%; border-collapse: collapse; }
thead th { padding: 11px 16px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.12em; color: #94a3b8; background: #fafbfe; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
tbody td { padding: 12px 16px; font-size: 12px; color: #475569; border-bottom: 1px solid #f8fafc; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: #f5f8ff; }

/* SARO row toggle */
.saro-row { cursor: pointer; user-select: none; }
.saro-row:hover { background: #f0f4ff !important; }
.saro-row td { font-weight: 600; }
.proc-section { background: #fafbfe; }
.proc-section td { padding: 0; }
.proc-inner { padding: 0 16px 12px 40px; }
.proc-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.proc-table th { padding: 8px 10px; font-size: 8.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; background: #f1f5f9; border-radius: 6px; }
.proc-table td { padding: 8px 10px; font-size: 11px; color: #475569; border-bottom: 1px solid #f1f5f9; }
.proc-table tr:last-child td { border-bottom: none; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 9px; font-size: 12px; font-weight: 700; font-family: 'Poppins',sans-serif; border: none; cursor: pointer; transition: all 0.2s ease; text-decoration: none; }
.btn-print { background: #4f46e5; color: #fff; }
.btn-print:hover { background: #4338ca; box-shadow: 0 4px 14px rgba(79,70,229,0.35); }

/* Empty state */
.empty-state { text-align: center; padding: 52px 20px; color: #94a3b8; }

/* Print */
@media print {
    @page { size: landscape; margin: 15mm 12mm; }
    body * { visibility: hidden; }
    #print-area, #print-area * { visibility: visible; }
    #print-area { position: fixed; inset: 0; background: #fff; padding: 0; }
}
</style>
</head>
<body>
<div class="layout">

<!-- Sidebar -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-logo"><img src="../assets/dict_logo.png" alt="DICT" style="width:100%;height:100%;object-fit:contain;"></div>
    <div>
      <p style="font-size:13px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:0.05em;line-height:1.1;">DICT Portal</p>
      <p style="font-size:8px;color:rgba(255,255,255,0.3);font-weight:700;text-transform:uppercase;letter-spacing:0.2em;">Region IX &amp; BASULTA</p>
    </div>
  </div>
  <nav class="sidebar-nav">
    <p class="nav-section-label">Main</p>
    <a href="dashboard.php" class="nav-item"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>Dashboard</a>
    <a href="data_entry.php" class="nav-item"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Data Entry</a>
    <a href="procurement_stat.php" class="nav-item"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Procurement Status</a>
    <p class="nav-section-label">Reports</p>
    <a href="export_records.php" class="nav-item active"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Export Records</a>
    <a href="audit_logs.php" class="nav-item"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Activity Logs</a>
    <p class="nav-section-label">History</p>
    <a href="cancelled_saro.php" class="nav-item"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>Cancelled SAROs<?php if ($cancelledCount > 0): ?><span style="margin-left:auto;min-width:18px;height:18px;border-radius:99px;background:#b45309;color:#fff;font-size:9px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;"><?= $cancelledCount ?></span><?php endif; ?></a>
    <a href="obligated_saro.php" class="nav-item"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Obligated SAROs<?php if ($obligatedCount > 0): ?><span style="margin-left:auto;min-width:18px;height:18px;border-radius:99px;background:#16a34a;color:#fff;font-size:9px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;"><?= $obligatedCount ?></span><?php endif; ?></a>
    <a href="lapsed_saro.php" class="nav-item"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Lapsed SAROs<?php if ($lapsedCount > 0): ?><span style="margin-left:auto;min-width:18px;height:18px;border-radius:99px;background:#dc2626;color:#fff;font-size:9px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;"><?= $lapsedCount ?></span><?php endif; ?></a>
    <p class="nav-section-label">Account</p>
    <a href="settings.php" class="nav-item"><svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>Settings</a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
      <div style="min-width:0;">
        <p style="font-size:12px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($username) ?></p>
        <p style="font-size:10px;color:rgba(255,255,255,0.3);font-weight:500;"><?= htmlspecialchars($role) ?></p>
      </div>
    </div>
    <a href="../logout.php" class="signout-btn"><svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Sign Out</a>
  </div>
</aside>

<!-- Main -->
<main class="main">
  <header class="topbar">
    <div class="breadcrumb">
      <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m0 0l-7 7-7-7M19 10v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
      <span>Home</span>
      <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <a href="dashboard.php" style="text-decoration:none;color:inherit;">Dashboard</a>
      <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span class="breadcrumb-active">Export Records</span>
    </div>
    <div class="topbar-right">
      <?php $isAdmin = false; $pendingPwCount = $pendingPwCount ?? 0; include __DIR__ . '/../includes/notif_dropdown.php'; ?>
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
      <div style="position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
          <p style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.16em;margin-bottom:6px;">Reports</p>
          <h2 style="font-size:22px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:-0.01em;margin-bottom:6px;">Export Records</h2>
          <p style="font-size:13px;color:rgba(255,255,255,0.6);font-weight:400;max-width:480px;line-height:1.6;">
            Annual report of obligated SARO records and their obligated procurement activities.
          </p>
        </div>
        <?php if (!empty($saros)): ?>
        <button class="btn btn-print" onclick="printReport()" style="flex-shrink:0;margin-top:4px;">
          <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
          Print Report
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Year tabs -->
    <?php if (!empty($years)): ?>
    <div class="year-tabs">
      <?php foreach ($years as $yr): ?>
      <a href="?year=<?= (int)$yr ?>" class="year-tab <?= (int)$yr === $selectedYear ? 'active' : '' ?>">
        FY <?= (int)$yr ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($years)): ?>
    <!-- No data at all -->
    <div class="table-panel">
      <div class="empty-state">
        <svg width="48" height="48" fill="none" stroke="#cbd5e1" viewBox="0 0 24 24" style="margin:0 auto 12px;display:block;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <p style="font-size:14px;font-weight:700;color:#94a3b8;margin-bottom:4px;">No obligated records yet</p>
        <p style="font-size:12px;color:#cbd5e1;">Reports will appear here once SAROs are fully obligated.</p>
      </div>
    </div>

    <?php else: ?>

    <!-- Summary cards -->
    <div class="summary-grid">
      <div class="summary-card">
        <p class="summary-label">Obligated SAROs</p>
        <p class="summary-value"><?= count($saros) ?></p>
        <p style="font-size:10px;color:#94a3b8;font-weight:500;margin-top:4px;">FY <?= $selectedYear ?></p>
      </div>
      <div class="summary-card">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#4f46e5,#818cf8);border-radius:12px 12px 0 0;"></div>
        <p class="summary-label">Total Budget</p>
        <p class="summary-value" style="font-size:18px;">₱<?= number_format($yearBudget, 2) ?></p>
        <p style="font-size:10px;color:#94a3b8;font-weight:500;margin-top:4px;">Combined SARO allocation</p>
      </div>
      <div class="summary-card" style="position:relative;">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#16a34a,#4ade80);border-radius:12px 12px 0 0;"></div>
        <p class="summary-label">Total Obligated</p>
        <p class="summary-value" style="font-size:18px;color:#16a34a;">₱<?= number_format($yearObligated, 2) ?></p>
        <p style="font-size:10px;color:#94a3b8;font-weight:500;margin-top:4px;"><?= $yearRate ?>% utilization rate</p>
      </div>
    </div>

    <!-- Records table -->
    <div class="table-panel">
      <div class="panel-header">
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="width:32px;height:32px;border-radius:8px;background:#ede9fe;display:flex;align-items:center;justify-content:center;">
            <svg width="15" height="15" fill="none" stroke="#4f46e5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          </div>
          <div>
            <p style="font-size:13px;font-weight:800;color:#0f172a;">FY <?= $selectedYear ?> Obligated SARO Records</p>
            <p style="font-size:10px;color:#94a3b8;font-weight:500;">Click a SARO row to expand its procurement activities</p>
          </div>
        </div>
        <button class="btn btn-print" onclick="printReport()">
          <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
          Print Report
        </button>
      </div>

      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th style="width:44px;text-align:left;">No.</th>
              <th style="text-align:left;">SARO No.</th>
              <th style="text-align:left;">SARO Title</th>
              <th style="text-align:right;">Total Budget</th>
              <th style="text-align:right;">Total Obligated</th>
              <th style="text-align:center;">Valid Until</th>
              <th style="text-align:center;width:32px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($saros as $i => $s):
              $procs    = $procMap[$s['saroId']] ?? [];
              $unoblig  = (float)$s['total_budget'] - (float)$s['total_obligated'];
              $bur      = $s['total_budget'] > 0 ? round($s['total_obligated'] / $s['total_budget'] * 100, 1) : 0;
              $validFmt = $s['valid_until'] ? date('M d, Y', strtotime($s['valid_until'])) : '—';
              $rowId    = 'proc-' . $s['saroId'];
            ?>
            <tr class="saro-row" onclick="toggleProc('<?= $rowId ?>', this)">
              <td style="color:#cbd5e1;font-size:12px;"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></td>
              <td><span style="font-weight:800;color:#0f172a;font-size:13px;"><?= htmlspecialchars($s['saroNo']) ?></span></td>
              <td style="max-width:240px;"><p style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#334155;"><?= htmlspecialchars($s['saro_title']) ?></p></td>
              <td style="text-align:right;font-weight:700;color:#334155;">₱<?= number_format((float)$s['total_budget'],2) ?></td>
              <td style="text-align:right;">
                <p style="font-weight:800;color:#16a34a;font-size:12px;margin-bottom:1px;">₱<?= number_format((float)$s['total_obligated'],2) ?></p>
                <p style="font-size:10px;color:#4ade80;font-weight:600;"><?= $bur ?>% of budget</p>
              </td>
              <td style="text-align:center;font-size:11px;color:#64748b;"><?= $validFmt ?></td>
              <td style="text-align:center;">
                <svg class="chevron-<?= $rowId ?>" width="14" height="14" fill="none" stroke="#94a3b8" viewBox="0 0 24 24" style="transition:transform 0.2s ease;">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </td>
            </tr>
            <tr id="<?= $rowId ?>" class="proc-section" style="display:none;">
              <td colspan="7">
                <div class="proc-inner">
                  <?php if (empty($procs)): ?>
                  <p style="font-size:12px;color:#94a3b8;padding:10px 0;">No obligated procurement activities found for this SARO.</p>
                  <?php else: ?>
                  <table class="proc-table">
                    <thead>
                      <tr>
                        <th style="text-align:left;">Object Code</th>
                        <th style="text-align:left;">Procurement Activity</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:center;">Unit</th>
                        <th style="text-align:right;">Unit Cost</th>
                        <th style="text-align:right;">Obligated Amount</th>
                        <th style="text-align:center;">Period</th>
                        <th style="text-align:center;">Proc. Date</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($procs as $p):
                        $period = '';
                        if ($p['period_start'] && $p['period_end']) {
                            $period = date('M d', strtotime($p['period_start'])) . ' – ' . date('M d, Y', strtotime($p['period_end']));
                        } elseif ($p['period_start']) {
                            $period = date('M d, Y', strtotime($p['period_start']));
                        }
                        $procDate = $p['proc_date'] ? date('M d, Y', strtotime($p['proc_date'])) : '—';
                      ?>
                      <tr>
                        <td><span style="font-weight:700;color:#4f46e5;"><?= htmlspecialchars($p['object_code']) ?></span></td>
                        <td style="max-width:200px;"><p style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($p['pro_act'] ?? '—') ?></p></td>
                        <td style="text-align:center;"><?= $p['quantity'] ?? '—' ?></td>
                        <td style="text-align:center;"><?= htmlspecialchars($p['unit'] ?? '—') ?></td>
                        <td style="text-align:right;"><?= $p['unit_cost'] ? '₱'.number_format((float)$p['unit_cost'],2) : '—' ?></td>
                        <td style="text-align:right;font-weight:700;color:#16a34a;">₱<?= number_format((float)$p['obligated_amount'],2) ?></td>
                        <td style="text-align:center;font-size:11px;"><?= $period ?: '—' ?></td>
                        <td style="text-align:center;font-size:11px;"><?= $procDate ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="padding:14px 24px;border-top:1px solid #f1f5f9;background:#fafbfe;display:flex;align-items:center;justify-content:space-between;">
        <p style="font-size:11px;color:#94a3b8;font-weight:500;"><strong style="color:#475569;"><?= count($saros) ?></strong> obligated SARO <?= count($saros) === 1 ? 'record' : 'records' ?> for FY <?= $selectedYear ?></p>
        <div style="display:flex;align-items:center;gap:20px;">
          <div style="text-align:right;">
            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Total Budget</p>
            <p style="font-size:15px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;">₱<?= number_format($yearBudget,2) ?></p>
          </div>
          <div style="width:1px;height:32px;background:#e2e8f0;"></div>
          <div style="text-align:right;">
            <p style="font-size:9px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:2px;">Total Obligated</p>
            <p style="font-size:15px;font-weight:900;color:#16a34a;letter-spacing:-0.02em;">₱<?= number_format($yearObligated,2) ?></p>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</main>
</div>

<!-- Hidden print area -->
<div id="print-area" style="display:none;"></div>

<script>
function toggleProc(id, row) {
    const section  = document.getElementById(id);
    const chevron  = document.querySelector('.chevron-' + id);
    const isOpen   = section.style.display !== 'none';
    section.style.display = isOpen ? 'none' : '';
    if (chevron) chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
}

// ── Print ──
const reportData = <?= json_encode([
    'year'      => $selectedYear,
    'saros'     => array_map(fn($s) => [
        'saroId'          => $s['saroId'],
        'saroNo'          => $s['saroNo'],
        'saro_title'      => $s['saro_title'],
        'total_budget'    => (float)$s['total_budget'],
        'total_obligated' => (float)$s['total_obligated'],
        'valid_until'     => $s['valid_until'],
        'procurements'    => $procMap[$s['saroId']] ?? [],
    ], $saros),
    'yearBudget'    => $yearBudget,
    'yearObligated' => $yearObligated,
    'yearRate'      => $yearRate,
]) ?>;

function printReport() {
    const fmt = n => '₱' + parseFloat(n).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    const fmtDate = d => d ? new Date(d).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : '—';
    const fmtPeriod = (s, e) => {
        if (!s && !e) return '—';
        const ds = s ? new Date(s).toLocaleDateString('en-PH',{month:'short',day:'numeric'}) : '';
        const de = e ? new Date(e).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '';
        return ds && de ? ds + ' – ' + de : (ds || de);
    };

    let saroPrint = '';
    reportData.saros.forEach((s, si) => {
        const bur = s.total_budget > 0 ? (s.total_obligated / s.total_budget * 100).toFixed(1) : '0.0';
        let procRows = '';
        if (s.procurements.length) {
            s.procurements.forEach(p => {
                procRows += `<tr>
                    <td>${p.object_code}</td>
                    <td>${p.pro_act || '—'}</td>
                    <td class="c">${p.quantity || '—'}</td>
                    <td class="c">${p.unit || '—'}</td>
                    <td class="r">${p.unit_cost ? fmt(p.unit_cost) : '—'}</td>
                    <td class="r"><strong>${fmt(p.obligated_amount)}</strong></td>
                    <td class="c">${fmtPeriod(p.period_start, p.period_end)}</td>
                    <td class="c">${fmtDate(p.proc_date)}</td>
                </tr>`;
            });
        } else {
            procRows = '<tr><td colspan="8" class="c" style="color:#888;font-style:italic;">No procurement activities</td></tr>';
        }

        saroPrint += `
        <div class="saro-block">
            <div class="saro-head">
                <div>
                    <span class="saro-num">${String(si+1).padStart(2,'0')}.</span>
                    <strong>${s.saroNo}</strong>
                    <span class="saro-title">${s.saro_title}</span>
                </div>
                <div class="saro-summary">
                    <span>Budget: <strong>${fmt(s.total_budget)}</strong></span>
                    <span>Obligated: <strong style="color:#16a34a;">${fmt(s.total_obligated)}</strong></span>
                    <span>BUR: <strong>${bur}%</strong></span>
                    <span>Valid Until: ${fmtDate(s.valid_until)}</span>
                </div>
            </div>
            <table class="pt">
                <thead><tr>
                    <th>Object Code</th><th>Procurement Activity</th>
                    <th class="c">Qty</th><th class="c">Unit</th>
                    <th class="r">Unit Cost</th><th class="r">Obligated Amt</th>
                    <th class="c">Period</th><th class="c">Proc. Date</th>
                </tr></thead>
                <tbody>${procRows}</tbody>
            </table>
        </div>`;
    });

    const now = new Date().toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'});
    document.getElementById('print-area').innerHTML = `
    <style>
        #print-area { font-family: Arial, sans-serif; font-size: 10.5px; color: #111; padding: 24px 28px; }
        #print-area .rpt-header { margin-bottom: 18px; border-bottom: 2px solid #312e81; padding-bottom: 12px; }
        #print-area .rpt-header h1 { font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #312e81; }
        #print-area .rpt-header p { font-size: 10px; color: #555; margin-top: 3px; }
        #print-area .summary-bar { display: flex; gap: 28px; margin-bottom: 16px; padding: 10px 14px; background: #f0f0fa; border-radius: 6px; }
        #print-area .summary-bar span { font-size: 11px; }
        #print-area .saro-block { margin-bottom: 18px; page-break-inside: avoid; }
        #print-area .saro-head { background: #312e81; color: #fff; padding: 7px 10px; border-radius: 5px 5px 0 0; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        #print-area .saro-num  { font-weight: 700; margin-right: 5px; }
        #print-area .saro-title { color: rgba(255,255,255,0.7); font-size: 10px; margin-left: 6px; }
        #print-area .saro-summary { display: flex; gap: 14px; font-size: 10px; color: rgba(255,255,255,0.85); flex-wrap: wrap; }
        #print-area .pt { width: 100%; border-collapse: collapse; }
        #print-area .pt thead th { background: #e8e8f8; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.08em; text-align: left; }
        #print-area .pt tbody td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
        #print-area .pt tbody tr:nth-child(even) { background: #f9f9fd; }
        #print-area .pt .c { text-align: center; }
        #print-area .pt .r { text-align: right; }
        #print-area .rpt-footer { margin-top: 18px; font-size: 10px; color: #555; text-align: right; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
    <div class="rpt-header">
        <h1>Obligated SARO Records &amp; Procurement Activities — FY ${reportData.year}</h1>
        <p>DICT — Zamboanga-BASULTA Cluster &nbsp;|&nbsp; Printed: ${now}</p>
    </div>
    <div class="summary-bar">
        <span>Total SAROs: <strong>${reportData.saros.length}</strong></span>
        <span>Total Budget: <strong>${fmt(reportData.yearBudget)}</strong></span>
        <span>Total Obligated: <strong>${fmt(reportData.yearObligated)}</strong></span>
        <span>Utilization Rate: <strong>${reportData.yearRate}%</strong></span>
    </div>
    ${saroPrint || '<p style="text-align:center;color:#888;padding:20px;">No records for this year.</p>'}
    <div class="rpt-footer">Total records: ${reportData.saros.length} &nbsp;|&nbsp; Report generated on ${now}</div>`;

    const area = document.getElementById('print-area');
    area.style.display = 'block';
    window.print();
    window.onafterprint = () => { area.style.display = 'none'; };
}
</script>
</body>
</html>
