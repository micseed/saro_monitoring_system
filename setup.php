<?php
// ── Guard: redirect if already installed ──
$installed = false;
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=monitoring_db", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $n = $pdo->query("SELECT COUNT(*) FROM `user` WHERE roleId = 1")->fetchColumn();
    if ((int)$n > 0) $installed = true;
} catch (Exception $e) { /* DB or table absent — proceed with install */ }

// ── Handle POST install ──
$success = false;
$error   = '';

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $first    = trim($_POST['first_name']  ?? '');
    $last     = trim($_POST['last_name']   ?? '');
    $mid      = trim($_POST['middle_name'] ?? '');
    $phone    = trim($_POST['phone']       ?? '');
    $email    = trim($_POST['email']       ?? '');
    $uname    = trim($_POST['username']    ?? '');
    $pw       = $_POST['password']         ?? '';
    $pw2      = $_POST['confirm']          ?? '';

    if (!$first || !$last || !$email || !$uname || !$pw) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $tmp = new PDO("mysql:host=127.0.0.1", "root", "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $tmp->exec("CREATE DATABASE IF NOT EXISTS `monitoring_db`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            unset($tmp);

            $conn = new PDO("mysql:host=127.0.0.1;dbname=monitoring_db", "root", "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $conn->exec("SET FOREIGN_KEY_CHECKS = 0");

            // ── 1. user_role ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `user_role` (
                roleId INT         PRIMARY KEY AUTO_INCREMENT,
                role   VARCHAR(50) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $conn->exec("INSERT IGNORE INTO `user_role` (roleId, role) VALUES
                (1, 'Super Admin'),
                (2, 'Admin')");

            // ── 2. user ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `user` (
                userId       INT          PRIMARY KEY AUTO_INCREMENT,
                roleId       INT          NOT NULL,
                last_name    VARCHAR(50)  NOT NULL,
                first_name   VARCHAR(50)  NOT NULL,
                middle_name  VARCHAR(50),
                phone_number VARCHAR(20),
                username     VARCHAR(50)  NOT NULL UNIQUE,
                email        VARCHAR(100) NOT NULL UNIQUE,
                password     VARCHAR(255) NOT NULL,
                status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
                last_login   DATETIME,
                created_by   INT,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (roleId)     REFERENCES user_role(roleId),
                FOREIGN KEY (created_by) REFERENCES `user`(userId) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── 3. password_requests ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `password_requests` (
                requestId    INT  PRIMARY KEY AUTO_INCREMENT,
                userId       INT  NOT NULL,
                reason       TEXT NOT NULL,
                status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                admin_note   TEXT,
                resolved_by  INT,
                requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                resolved_at  DATETIME,
                FOREIGN KEY (userId)      REFERENCES `user`(userId)  ON DELETE CASCADE,
                FOREIGN KEY (resolved_by) REFERENCES `user`(userId)  ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── 4. audit_logs ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `audit_logs` (
                logId          INT PRIMARY KEY AUTO_INCREMENT,
                userId         INT,
                action         ENUM('login','logout','create','edit','delete','view','approve','reject') NOT NULL,
                details        TEXT,
                affected_table VARCHAR(50),
                record_id      INT,
                ip_address     VARCHAR(45),
                timestamp      DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (userId) REFERENCES `user`(userId) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── 5. saro ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `saro` (
                saroId       INT           PRIMARY KEY AUTO_INCREMENT,
                userId       INT           NOT NULL,
                saroNo       VARCHAR(50)   NOT NULL UNIQUE,
                saro_title   VARCHAR(150)  NOT NULL,
                fiscal_year  YEAR          NOT NULL,
                total_budget DECIMAL(15,2) NOT NULL,
                status       ENUM('active','cancelled') NOT NULL DEFAULT 'active',
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (userId) REFERENCES `user`(userId)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── 6. object_code ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `object_code` (
                objectId       INT           PRIMARY KEY AUTO_INCREMENT,
                saroId         INT           NOT NULL,
                code           VARCHAR(50)   NOT NULL,
                projected_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                FOREIGN KEY (saroId) REFERENCES saro(saroId) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── 7. expense_items ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `expense_items` (
                itemId    INT          PRIMARY KEY AUTO_INCREMENT,
                objectId  INT          NOT NULL,
                item_name VARCHAR(150) NOT NULL,
                FOREIGN KEY (objectId) REFERENCES object_code(objectId) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── 8. procurement ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `procurement` (
                procurementId    INT           PRIMARY KEY AUTO_INCREMENT,
                objectId         INT           NOT NULL,
                pro_act          VARCHAR(150),
                is_travelExpense BOOLEAN       NOT NULL DEFAULT FALSE,
                quantity         INT,
                unit             VARCHAR(50),
                unit_cost        DECIMAL(15,2),
                obligated_amount DECIMAL(15,2),
                period_start     DATE,
                period_end       DATE,
                proc_date        DATE,
                remarks          TEXT,
                created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (objectId) REFERENCES object_code(objectId) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── 9. required_documents ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `required_documents` (
                documentId         INT          PRIMARY KEY AUTO_INCREMENT,
                document_name      VARCHAR(150) NOT NULL,
                applies_to_regular BOOLEAN      NOT NULL DEFAULT TRUE,
                applies_to_travel  BOOLEAN      NOT NULL DEFAULT FALSE,
                sort_order         INT          NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $conn->exec("INSERT IGNORE INTO `required_documents`
                (documentId, document_name, applies_to_regular, applies_to_travel, sort_order) VALUES
                (1,  'Purchase Request',          1, 0, 1),
                (2,  'Quotation Sheet',           1, 0, 2),
                (3,  'Mayor''s Permit',           1, 0, 3),
                (4,  'BIR 2303',                  1, 0, 4),
                (5,  'Supplemental APP',          1, 0, 5),
                (6,  'Notice of Award',           1, 0, 6),
                (7,  'Notice to Proceed',         1, 0, 7),
                (8,  'Inspection and Acceptance', 1, 0, 8),
                (9,  'Travel Order',              0, 1, 1),
                (10, 'Itinerary',                 0, 1, 2),
                (11, 'Certificate of Travel',     0, 1, 3),
                (12, 'Reimbursement Report',      0, 1, 4),
                (13, 'CENRR',                     0, 1, 5),
                (14, 'Travel Report',             0, 1, 6),
                (15, 'Travel Summary',            0, 1, 7)");

            // ── 10. procurement_status ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `procurement_status` (
                statusId      INT  PRIMARY KEY AUTO_INCREMENT,
                procurementId INT  NOT NULL,
                documentId    INT  NOT NULL,
                status        ENUM('pending','received','not_required') NOT NULL DEFAULT 'pending',
                updated_by    INT,
                updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_proc_doc (procurementId, documentId),
                FOREIGN KEY (procurementId) REFERENCES procurement(procurementId) ON DELETE CASCADE,
                FOREIGN KEY (documentId)    REFERENCES required_documents(documentId),
                FOREIGN KEY (updated_by)    REFERENCES `user`(userId) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ── 11. signatory_role ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `signatory_role` (
                signId      INT          PRIMARY KEY AUTO_INCREMENT,
                sign_name   VARCHAR(100) NOT NULL,
                sign_order  INT          NOT NULL,
                is_required BOOLEAN      NOT NULL DEFAULT TRUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $conn->exec("INSERT IGNORE INTO `signatory_role`
                (signId, sign_name, sign_order, is_required) VALUES
                (1, 'Budget Officer Signature', 1, 1),
                (2, 'End User Signature',       2, 1),
                (3, 'BAC Chair Signature',      3, 1),
                (4, 'RD Signature',             4, 1),
                (5, 'PO Creation',              5, 1),
                (6, 'Finance Signature',        6, 1),
                (7, 'Conforme Signature',       7, 1)");

            // ── 12. proc_approval ──
            $conn->exec("CREATE TABLE IF NOT EXISTS `proc_approval` (
                approvId      INT  PRIMARY KEY AUTO_INCREMENT,
                procurementId INT  NOT NULL,
                signId        INT  NOT NULL,
                status        ENUM('approved','rejected') NOT NULL,
                approved_by   INT,
                remarks       TEXT,
                approval_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_proc_sign (procurementId, signId),
                FOREIGN KEY (procurementId) REFERENCES procurement(procurementId)  ON DELETE CASCADE,
                FOREIGN KEY (signId)        REFERENCES signatory_role(signId),
                FOREIGN KEY (approved_by)   REFERENCES `user`(userId)              ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $conn->exec("SET FOREIGN_KEY_CHECKS = 1");

            // ── Insert Super Admin ──
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO `user`
                 (roleId, last_name, first_name, middle_name, phone_number, username, email, password, created_by)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?, NULL)"
            );
            $stmt->execute([$last, $first, $mid ?: null, $phone ?: null, $uname, $email, $hash]);

            $success   = true;
            $installed = true;

        } catch (PDOException $e) {
            $error = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Setup | DICT SARO Monitoring</title>
    <link href="dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }

        body { display: flex; min-height: 100vh; }

        .left-panel {
            width: 55%; display: flex; flex-direction: column;
            background: #fff; padding: 40px 64px; overflow-y: auto;
        }
        .right-panel {
            width: 45%; position: relative;
            background-image: url('assets/dict_bg.jpg');
            background-size: cover; background-position: center;
            display: flex; align-items: center; justify-content: center; padding: 48px;
        }
        .right-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(145deg, rgba(8,18,52,0.92) 0%, rgba(29,78,216,0.80) 100%);
        }
        .right-card {
            position: relative; z-index: 1;
            background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-radius: 20px; padding: 40px 36px; max-width: 400px; width: 100%;
        }

        /* Step indicator */
        .step-bar { display: flex; align-items: center; gap: 0; margin-bottom: 32px; }
        .step-item { display: flex; align-items: center; gap: 8px; }
        .step-dot {
            width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800;
        }
        .step-dot.done { background: #22c55e; color: #fff; }
        .step-dot.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 4px rgba(37,99,235,0.2); }
        .step-dot.idle { background: #f1f5f9; color: #94a3b8; border: 1.5px solid #e2e8f0; }
        .step-label { font-size: 11px; font-weight: 600; color: #64748b; white-space: nowrap; }
        .step-line { flex: 1; height: 2px; background: #e2e8f0; margin: 0 8px; min-width: 20px; }
        .step-line.done { background: #22c55e; }

        /* Form */
        .form-label {
            display: block; font-size: 11px; font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 6px;
        }
        .form-input {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 13px; font-family: 'Poppins', sans-serif; font-weight: 500;
            color: #0f172a; background: #f8fafc; outline: none;
            transition: all 0.2s ease;
        }
        .form-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .form-input.err { border-color: #ef4444; }
        .input-wrap { position: relative; }
        .input-wrap .form-input { padding-right: 40px; }
        .eye-btn {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #94a3b8;
            display: flex; align-items: center; transition: color 0.2s;
        }
        .eye-btn:hover { color: #3b82f6; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-group { margin-bottom: 16px; }
        .hint { font-size: 10px; color: #94a3b8; font-weight: 500; margin-top: 4px; }
        .required { color: #ef4444; }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; border-radius: 9px;
            font-size: 12px; font-weight: 500; line-height: 1.5; margin-bottom: 20px;
        }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .alert-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .alert-warn    { background: #fefce8; border: 1px solid #fde68a; color: #92400e; }

        /* Button */
        .btn-install {
            width: 100%; padding: 12px;
            background: #2563eb; color: #fff;
            font-size: 13px; font-weight: 700; font-family: 'Poppins', sans-serif;
            border: none; border-radius: 9px; cursor: pointer;
            letter-spacing: 0.04em; text-transform: uppercase;
            transition: all 0.25s ease;
            box-shadow: 0 6px 20px rgba(37,99,235,0.3);
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-install:hover { background: #1d4ed8; transform: translateY(-1px); box-shadow: 0 10px 28px rgba(37,99,235,0.4); }
        .btn-install:disabled { background: #93c5fd; cursor: not-allowed; transform: none; box-shadow: none; }

        @media (max-width: 900px) { .left-panel { width: 100%; } .right-panel { display: none; } }
        @media (max-width: 640px) { .left-panel { padding: 32px 24px; } .two-col { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="left-panel">

    <div style="margin-bottom:auto;">
        <div style="display:inline-flex;align-items:center;gap:10px;">
            <div style="width:36px;height:36px;border-radius:50%;background:#eff6ff;border:1.5px solid #bfdbfe;
                        display:flex;align-items:center;justify-content:center;padding:5px;">
                <img src="assets/dict_logo.png" alt="DICT" style="width:100%;height:100%;object-fit:contain;">
            </div>
            <div>
                <p style="font-size:12px;font-weight:800;color:#0f172a;line-height:1.1;">DICT Portal</p>
                <p style="font-size:9px;color:#94a3b8;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;">Region IX &amp; BASULTA</p>
            </div>
        </div>
    </div>

    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;max-width:480px;width:100%;margin:0 auto;padding:40px 0;">

        <?php if ($installed && !$success): ?>
        <!-- ── Already installed ── -->
        <div style="text-align:center;margin-bottom:28px;">
            <div style="width:72px;height:72px;background:#f0fdf4;border:2px solid #bbf7d0;border-radius:50%;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                <svg width="32" height="32" fill="none" stroke="#22c55e" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h2 style="font-size:22px;font-weight:900;color:#0f172a;margin-bottom:8px;">Already Installed</h2>
            <p style="font-size:14px;color:#64748b;">The system is already set up. A Super Admin account exists.</p>
        </div>
        <div class="alert alert-warn">
            <svg style="flex-shrink:0;margin-top:1px;" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>For security, delete or rename <strong>setup.php</strong> after the initial installation.</span>
        </div>
        <a href="login.php"
           style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;
                  background:#0f172a;color:#fff;font-size:13px;font-weight:700;border-radius:9px;text-decoration:none;
                  transition:background 0.2s;"
           onmouseover="this.style.background='#1e293b'"
           onmouseout="this.style.background='#0f172a'">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
            Go to Sign In
        </a>

        <?php elseif ($success): ?>
        <!-- ── Success ── -->
        <div style="text-align:center;margin-bottom:28px;">
            <div style="width:72px;height:72px;background:#f0fdf4;border:2px solid #bbf7d0;border-radius:50%;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                <svg width="32" height="32" fill="none" stroke="#22c55e" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h2 style="font-size:22px;font-weight:900;color:#0f172a;margin-bottom:8px;">Installation Complete!</h2>
            <p style="font-size:14px;color:#64748b;">The database and Super Admin account have been created.</p>
        </div>

        <!-- Step bar done -->
        <div class="step-bar" style="margin-bottom:24px;">
            <div class="step-item">
                <div class="step-dot done">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                </div>
                <span class="step-label" style="color:#15803d;">Database</span>
            </div>
            <div class="step-line done"></div>
            <div class="step-item">
                <div class="step-dot done">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                </div>
                <span class="step-label" style="color:#15803d;">Super Admin</span>
            </div>
            <div class="step-line done"></div>
            <div class="step-item">
                <div class="step-dot done">
                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                </div>
                <span class="step-label" style="color:#15803d;">Done</span>
            </div>
        </div>

        <div class="alert alert-success" style="margin-bottom:16px;">
            <svg style="flex-shrink:0;margin-top:1px;" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>All database tables created and seeded. Your Super Admin account is ready.</span>
        </div>
        <div class="alert alert-warn" style="margin-bottom:20px;">
            <svg style="flex-shrink:0;margin-top:1px;" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><strong>Security:</strong> Delete or rename <strong>setup.php</strong> now to prevent re-access.</span>
        </div>
        <a href="login.php"
           style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;
                  background:#2563eb;color:#fff;font-size:13px;font-weight:700;border-radius:9px;text-decoration:none;
                  box-shadow:0 6px 20px rgba(37,99,235,0.3);transition:all 0.25s;"
           onmouseover="this.style.background='#1d4ed8'"
           onmouseout="this.style.background='#2563eb'">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
            Sign In Now
        </a>

        <?php else: ?>
        <!-- ── Install Form ── -->
        <div style="margin-bottom:28px;">
            <p style="font-size:10px;font-weight:700;color:#2563eb;text-transform:uppercase;
                      letter-spacing:0.16em;margin-bottom:6px;">One-Time Setup</p>
            <h2 style="font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:6px;">
                System Installation
            </h2>
            <p style="font-size:13px;color:#64748b;line-height:1.6;">
                Create the database and your Super Admin account to get started.
            </p>
        </div>

        <!-- Step bar -->
        <div class="step-bar" style="margin-bottom:24px;">
            <div class="step-item">
                <div class="step-dot active">1</div>
                <span class="step-label" style="color:#1d4ed8;">Database</span>
            </div>
            <div class="step-line"></div>
            <div class="step-item">
                <div class="step-dot active">2</div>
                <span class="step-label" style="color:#1d4ed8;">Super Admin</span>
            </div>
            <div class="step-line"></div>
            <div class="step-item">
                <div class="step-dot idle">3</div>
                <span class="step-label">Done</span>
            </div>
        </div>

        <!-- DB info notice -->
        <div class="alert alert-info" style="margin-bottom:20px;">
            <svg style="flex-shrink:0;margin-top:1px;" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Database: <strong>monitoring_db</strong> on <strong>127.0.0.1</strong> (root / no password). All tables will be created automatically.</span>
        </div>

        <!-- Error -->
        <?php if ($error): ?>
        <div class="alert alert-error">
            <svg style="flex-shrink:0;margin-top:1px;" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="setup.php" autocomplete="off">

            <!-- Name row -->
            <div class="two-col">
                <div class="form-group">
                    <label class="form-label">First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" class="form-input <?= $error ? 'err' : '' ?>"
                           value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                           placeholder="Juan" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name <span class="required">*</span></label>
                    <input type="text" name="last_name" class="form-input <?= $error ? 'err' : '' ?>"
                           value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
                           placeholder="Dela Cruz" required>
                </div>
            </div>

            <div class="two-col">
                <div class="form-group">
                    <label class="form-label">Middle Name</label>
                    <input type="text" name="middle_name" class="form-input"
                           value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>"
                           placeholder="Santos">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-input"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                           placeholder="+63 9XX XXX XXXX">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address <span class="required">*</span></label>
                <input type="email" name="email" class="form-input <?= $error ? 'err' : '' ?>"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="admin@dict.gov.ph" required>
            </div>

            <div class="form-group">
                <label class="form-label">Username <span class="required">*</span></label>
                <input type="text" name="username" class="form-input <?= $error ? 'err' : '' ?>"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       placeholder="superadmin" required autocomplete="new-password">
                <p class="hint">Used to sign in to the system.</p>
            </div>

            <div class="two-col">
                <div class="form-group">
                    <label class="form-label">Password <span class="required">*</span></label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="pw1"
                               class="form-input <?= $error ? 'err' : '' ?>"
                               placeholder="Min. 8 characters" required autocomplete="new-password">
                        <button type="button" class="eye-btn" onclick="togglePw('pw1',this)">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm <span class="required">*</span></label>
                    <div class="input-wrap">
                        <input type="password" name="confirm" id="pw2"
                               class="form-input <?= $error ? 'err' : '' ?>"
                               placeholder="Re-enter password" required>
                        <button type="button" class="eye-btn" onclick="togglePw('pw2',this)">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-install" id="installBtn">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                Install System
            </button>

        </form>

        <?php endif; ?>

    </div>
</div>

<!-- Right panel -->
<div class="right-panel">
    <div class="right-overlay"></div>
    <div class="right-card">
        <div style="width:64px;height:64px;border-radius:50%;border:2px solid rgba(255,255,255,0.5);
                    background:#fff;padding:6px;margin-bottom:20px;
                    display:flex;align-items:center;justify-content:center;">
            <img src="assets/dict_logo.png" alt="DICT Logo" style="width:100%;height:100%;object-fit:contain;">
        </div>
        <p style="font-size:10px;font-weight:700;color:#93c5fd;text-transform:uppercase;
                  letter-spacing:0.18em;margin-bottom:8px;">
            DICT &mdash; Region IX &amp; BASULTA
        </p>
        <h3 style="font-size:clamp(20px,2.4vw,28px);font-weight:900;color:#fff;
                   text-transform:uppercase;line-height:1.2;letter-spacing:-0.02em;margin-bottom:14px;">
            SARO<br>Monitoring<br>
            <span style="background:linear-gradient(90deg,#60a5fa,#bfdbfe);
                         -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">
                System
            </span>
        </h3>
        <p style="font-size:13px;color:rgba(255,255,255,0.55);line-height:1.75;font-weight:400;
                  border-top:1px solid rgba(255,255,255,0.1);padding-top:20px;margin-top:8px;">
            This setup wizard will initialize the database and create your administrator account. Run it only once.
        </p>
        <div style="margin-top:20px;display:flex;flex-direction:column;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:28px;height:28px;border-radius:8px;background:rgba(255,255,255,0.1);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="14" height="14" fill="none" stroke="#60a5fa" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4"/></svg>
                </div>
                <span style="font-size:12px;color:rgba(255,255,255,0.7);font-weight:500;">Creates all 12 database tables</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:28px;height:28px;border-radius:8px;background:rgba(255,255,255,0.1);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="14" height="14" fill="none" stroke="#60a5fa" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <span style="font-size:12px;color:rgba(255,255,255,0.7);font-weight:500;">Bootstraps the Super Admin account</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:28px;height:28px;border-radius:8px;background:rgba(255,255,255,0.1);
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="14" height="14" fill="none" stroke="#60a5fa" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <span style="font-size:12px;color:rgba(255,255,255,0.7);font-weight:500;">Passwords hashed with bcrypt</span>
            </div>
        </div>
    </div>
</div>

<script>
    function togglePw(id, btn) {
        const inp = document.getElementById(id);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        btn.style.color = inp.type === 'text' ? '#2563eb' : '';
    }

    document.querySelector('form') && document.querySelector('form').addEventListener('submit', function() {
        const btn = document.getElementById('installBtn');
        btn.disabled = true;
        btn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="animation:spin 1s linear infinite;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Installing…`;
    });

    const style = document.createElement('style');
    style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
</script>
</body>
</html>
