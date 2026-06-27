<?php
// ── Guard: redirect if already installed ──
$installed = false;
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=saro_db", "root", "", [
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
            $tmp->exec("CREATE DATABASE IF NOT EXISTS `saro_db`
                        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            unset($tmp);

            $conn = new PDO("mysql:host=127.0.0.1;dbname=saro_db", "root", "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // ── Insert System Admin ──
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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
        h1, h2, h3, h4, h5, h6, .brand-font { font-family: 'Outfit', sans-serif; }
        html, body { height: 100%; }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: url('assets/dict_bg.jpg') center/cover no-repeat;
            padding: 24px;
        }
        .body-overlay {
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, rgba(8,18,52,0.9) 0%, rgba(14,40,100,0.8) 100%);
            z-index: 0;
        }

        /* Animations */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-up { opacity: 0; animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }

        /* ── Center panel (form) ── */
        .left-panel {
            position: relative;
            z-index: 1;
            background: #ffffff;
            border-radius: 24px;
            padding: 40px 48px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 48px);
            overflow-y: auto;
            scrollbar-width: none;
        }
        .left-panel::-webkit-scrollbar {
            display: none;
        }

        /* Step indicator */
        .step-bar { display: flex; align-items: center; gap: 0; margin-bottom: 32px; }
        .step-item { display: flex; align-items: center; gap: 8px; }
        .step-dot {
            width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; font-family: 'Inter', sans-serif;
        }
        .step-dot.done { background: #22c55e; color: #fff; }
        .step-dot.active { background: #2563eb; color: #fff; box-shadow: 0 0 0 4px rgba(37,99,235,0.2); }
        .step-dot.idle { background: #f8fafc; color: #94a3b8; border: 1.5px solid #e2e8f0; }
        .step-label { font-size: 12px; font-weight: 600; color: #64748b; white-space: nowrap; font-family: 'Inter', sans-serif; }
        .step-line { flex: 1; height: 2px; background: #e2e8f0; margin: 0 8px; min-width: 20px; }
        .step-line.done { background: #22c55e; }

        /* ── Form elements ── */
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            color: #0f172a;
            background: #f8fafc;
            outline: none;
            transition: all 0.3s ease;
        }
        .form-input:focus {
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(59,130,246,0.1);
        }
        .form-input.err { border-color: #ef4444; }
        .form-input.err:focus { box-shadow: 0 0 0 4px rgba(239,68,68,0.1); }
        
        .input-wrap { position: relative; }
        .input-wrap .form-input { padding-right: 40px; }
        .eye-btn {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #94a3b8;
            display: flex; align-items: center; transition: color 0.2s;
        }
        .eye-btn:hover { color: #3b82f6; }
        
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;}
        .form-group { margin-bottom: 16px; }
        .hint { font-size: 12px; color: #94a3b8; font-weight: 400; margin-top: 6px; }
        .required { color: #ef4444; }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 16px; border-radius: 12px;
            font-size: 14px; font-weight: 500; line-height: 1.5; margin-bottom: 24px;
        }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .alert-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .alert-warn    { background: #fefce8; border: 1px solid #fde68a; color: #92400e; }

        /* Button */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #2563eb;
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(37,99,235,0.3);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
        }
        .btn-submit:hover:not(:disabled) {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37,99,235,0.4);
        }
        .btn-submit:active:not(:disabled) { transform: translateY(0); }
        .btn-submit:disabled { background: #93c5fd; cursor: not-allowed; box-shadow: none; }

        .btn-secondary {
            display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px;
            background: #0f172a; color: #fff; font-size: 15px; font-weight: 600; border-radius: 12px; text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 15px rgba(15,23,42,0.3);
        }
        .btn-secondary:hover { background: #1e293b; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(15,23,42,0.4); }

        @media (max-width: 640px) { .left-panel { padding: 32px 24px; max-height: calc(100vh - 32px); } .two-col { grid-template-columns: 1fr; gap: 0; } }
    </style>
</head>
<body>

<div class="body-overlay"></div>
<div class="left-panel">

    <div class="fade-up" style="margin-bottom:auto;">
        <div style="display:inline-flex;align-items:center;gap:10px;">
            <div style="width:40px;height:40px;border-radius:12px;background:#ffffff;border:1px solid #e2e8f0;
                        display:flex;align-items:center;justify-content:center;padding:6px; box-shadow:0 4px 6px rgba(0,0,0,0.05);">
                <img src="assets/dict_logo.png" alt="DICT" style="width:100%;height:100%;object-fit:contain;">
            </div>
            <div>
                <p class="brand-font" style="font-size:15px;font-weight:700;color:#0f172a;line-height:1.1;">DICT Portal</p>
                <p style="font-size:10px;color:#64748b;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;">Region IX &amp; BASULTA</p>
            </div>
        </div>
    </div>

    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;max-width:480px;width:100%;margin:0 auto;padding:20px 0;">

        <?php if ($installed && !$success): ?>
        <!-- ── Already installed ── -->
        <div class="fade-up delay-100" style="text-align:center;margin-bottom:32px;">
            <div style="width:80px;height:80px;background:#f0fdf4;border:2px solid #bbf7d0;border-radius:24px;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 24px;">
                <svg width="36" height="36" fill="none" stroke="#22c55e" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h2 class="brand-font" style="font-size:28px;font-weight:800;color:#0f172a;margin-bottom:8px;">Already Installed</h2>
            <p style="font-size:15px;color:#64748b;">The system is already set up. A System Admin account exists.</p>
        </div>
        <div class="alert alert-warn fade-up delay-200">
            <svg style="flex-shrink:0;margin-top:1px;" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>For security, delete or rename <strong>setup.php</strong> after the initial installation.</span>
        </div>
        <div class="fade-up delay-300">
            <a href="login.php" class="btn-secondary">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                Go to Sign In
            </a>
        </div>

        <?php elseif ($success): ?>
        <!-- ── Success ── -->
        <div class="fade-up delay-100" style="text-align:center;margin-bottom:32px;">
            <div style="width:80px;height:80px;background:#f0fdf4;border:2px solid #bbf7d0;border-radius:24px;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 24px;">
                <svg width="36" height="36" fill="none" stroke="#22c55e" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h2 class="brand-font" style="font-size:28px;font-weight:800;color:#0f172a;margin-bottom:8px;">Installation Complete!</h2>
            <p style="font-size:15px;color:#64748b;">The database and System Admin account have been created.</p>
        </div>

        <!-- Step bar done -->
        <div class="step-bar fade-up delay-200" style="margin-bottom:32px;">
            <div class="step-item">
                <div class="step-dot done">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                </div>
                <span class="step-label" style="color:#15803d;">Database</span>
            </div>
            <div class="step-line done"></div>
            <div class="step-item">
                <div class="step-dot done">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                </div>
                <span class="step-label" style="color:#15803d;">System Admin</span>
            </div>
            <div class="step-line done"></div>
            <div class="step-item">
                <div class="step-dot done">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                </div>
                <span class="step-label" style="color:#15803d;">Done</span>
            </div>
        </div>

        <div class="alert alert-success fade-up delay-300" style="margin-bottom:16px;">
            <svg style="flex-shrink:0;margin-top:1px;" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>System Admin account created successfully.</span>
        </div>
        <div class="alert alert-warn fade-up delay-300" style="margin-bottom:24px;">
            <svg style="flex-shrink:0;margin-top:1px;" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span><strong>Security:</strong> Delete or rename <strong>setup.php</strong> now to prevent re-access.</span>
        </div>
        <div class="fade-up delay-400">
            <a href="login.php" class="btn-submit">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                Sign In Now
            </a>
        </div>

        <?php else: ?>
        <!-- ── Install Form ── -->
        <div class="fade-up delay-100" style="margin-bottom:32px;">
            <p style="font-size:12px;font-weight:700;color:#2563eb;text-transform:uppercase;
                      letter-spacing:2px;margin-bottom:8px;">One-Time Setup</p>
            <h2 class="brand-font" style="font-size:32px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;margin-bottom:8px;">
                System Installation
            </h2>
            <p style="font-size:15px;color:#64748b;line-height:1.6;">
                Create the database and your System Admin account to get started.
            </p>
        </div>

        <!-- Step bar -->
        <div class="step-bar fade-up delay-100" style="margin-bottom:32px;">
            <div class="step-item">
                <div class="step-dot active">1</div>
                <span class="step-label" style="color:#1d4ed8;">Database</span>
            </div>
            <div class="step-line"></div>
            <div class="step-item">
                <div class="step-dot active">2</div>
                <span class="step-label" style="color:#1d4ed8;">System Admin</span>
            </div>
            <div class="step-line"></div>
            <div class="step-item">
                <div class="step-dot idle">3</div>
                <span class="step-label">Done</span>
            </div>
        </div>

        <!-- Error -->
        <?php if ($error): ?>
        <div class="alert alert-error fade-up delay-200">
            <svg style="flex-shrink:0;margin-top:1px;" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="setup.php" autocomplete="off" class="fade-up delay-200">

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
                       placeholder="systemadmin" required autocomplete="new-password">
                <p class="hint">Used to sign in to the system.</p>
            </div>

            <div class="two-col">
                <div class="form-group">
                    <label class="form-label">Password <span class="required">*</span></label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="pw1"
                               class="form-input <?= $error ? 'err' : '' ?>"
                               placeholder="Min. 8 chars" required autocomplete="new-password">
                        <button type="button" class="eye-btn" onclick="togglePw('pw1',this)">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
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
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="installBtn">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                Install System
            </button>

        </form>

        <?php endif; ?>

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
        btn.innerHTML = `<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="animation:spin 1s linear infinite;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Installing…`;
    });

    const style = document.createElement('style');
    style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
</script>
</body>
</html>
