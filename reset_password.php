<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role_id'] === 1 ? 'admin/dashboard.php' : 'saro/dashboard.php'));
    exit;
}

require_once 'class/database.php';

$token    = trim($_GET['token'] ?? '');
$step     = 'form';   // form | success | invalid
$error    = '';
$userId   = null;
$fullName = '';
$resetId  = null;

if ($token === '') {
    $step = 'invalid';
} else {
    try {
        $db   = new Database();
        $conn = $db->connect();
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $conn->prepare("
            SELECT pr.id, pr.userId, u.first_name, u.last_name
            FROM   password_resets pr
            JOIN   user u ON u.userId = pr.userId
            WHERE  pr.token      = ?
              AND  pr.used_at    IS NULL
              AND  pr.expires_at >  NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $step = 'invalid';
        } else {
            $userId   = (int)$row['userId'];
            $resetId  = (int)$row['id'];
            $fullName = trim($row['first_name'] . ' ' . $row['last_name']);

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
                $pw  = $_POST['new_password']      ?? '';
                $pw2 = $_POST['confirm_password']  ?? '';

                if (strlen($pw) < 8) {
                    $error = 'Password must be at least 8 characters.';
                } elseif ($pw !== $pw2) {
                    $error = 'Passwords do not match.';
                } else {
                    $hash = password_hash($pw, PASSWORD_DEFAULT);

                    $conn->prepare("UPDATE user SET password = ?, updated_at = NOW() WHERE userId = ?")
                         ->execute([$hash, $userId]);

                    $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
                         ->execute([$resetId]);

                    try {
                        $conn->prepare("
                            INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address)
                            VALUES (?, 'password_reset', 'Super Admin password reset via email token', 'user', ?, ?)
                        ")->execute([$userId, $userId, $_SERVER['REMOTE_ADDR'] ?? null]);
                    } catch (Exception $logEx) { /* non-fatal */ }

                    $step = 'success';
                }
            }
        }
    } catch (PDOException $e) {
        $step  = 'invalid';
        $error = 'Database error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | DICT SARO Monitoring</title>
    <link href="dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { display: flex; min-height: 100vh; }

        .left-panel {
            width: 50%; display: flex; flex-direction: column;
            background: #fff; padding: 40px 64px; overflow-y: auto;
        }
        .right-panel {
            width: 50%; position: relative;
            background-image: url('assets/dict_bg.jpg');
            background-size: cover; background-position: center;
            display: flex; align-items: center; justify-content: center; padding: 48px;
        }
        .right-overlay { position: absolute; inset: 0; background: linear-gradient(145deg, rgba(8,18,52,0.92) 0%, rgba(29,78,216,0.80) 100%); }
        .right-card {
            position: relative; z-index: 1;
            background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-radius: 20px; padding: 48px 40px; max-width: 440px; width: 100%;
        }

        .form-label { display: block; font-size: 12px; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 7px; }
        .form-input {
            width: 100%; padding: 11px 44px 11px 42px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 14px; font-family: 'Poppins', sans-serif; font-weight: 500;
            color: #0f172a; background: #f8fafc; outline: none; transition: all 0.2s ease;
        }
        .form-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,0.1); }
        .form-input.input-error { border-color: #ef4444; }
        .input-wrap { position: relative; }
        .input-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
        .toggle-pw {
            position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #94a3b8;
            display: flex; align-items: center; transition: color 0.2s;
        }
        .toggle-pw:hover { color: #3b82f6; }

        .strength-bar { height: 4px; border-radius: 99px; background: #e2e8f0; margin-top: 8px; overflow: hidden; }
        .strength-fill { height: 100%; border-radius: 99px; transition: width 0.3s, background 0.3s; width: 0; }

        .btn-submit {
            width: 100%; padding: 12px; background: #2563eb; color: #fff;
            font-size: 14px; font-weight: 700; font-family: 'Poppins', sans-serif;
            border: none; border-radius: 9px; cursor: pointer; letter-spacing: 0.04em;
            text-transform: uppercase; transition: all 0.25s ease;
            box-shadow: 0 6px 20px rgba(37,99,235,0.3);
        }
        .btn-submit:hover { background: #1d4ed8; transform: translateY(-1px); box-shadow: 0 10px 28px rgba(37,99,235,0.4); }
        .btn-submit:active { transform: translateY(0); }

        .error-alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; background: #fef2f2; border: 1px solid #fecaca;
            border-radius: 9px; font-size: 13px; color: #b91c1c; font-weight: 500;
            margin-bottom: 20px;
        }

        @media (max-width: 900px) { .left-panel { width: 100%; padding: 40px 32px; } .right-panel { display: none; } }
        @media (max-width: 480px) { .left-panel { padding: 32px 20px; } }
    </style>
</head>
<body>

<div class="left-panel">

    <div style="margin-bottom:auto;">
        <a href="login.php"
           style="display:inline-flex;align-items:center;gap:6px;font-size:13px;
                  font-weight:600;color:#64748b;text-decoration:none;transition:color 0.2s;"
           onmouseover="this.style.color='#2563eb'"
           onmouseout="this.style.color='#64748b'">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to Sign In
        </a>
    </div>

    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;max-width:380px;width:100%;margin:0 auto;padding:40px 0;">

        <?php if ($step === 'invalid'): ?>
        <!-- ── Invalid / expired token ── -->
        <div style="text-align:center;">
            <div style="width:72px;height:72px;border-radius:50%;background:#fef2f2;border:2px solid #fecaca;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                <svg width="32" height="32" fill="none" stroke="#dc2626" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h2 style="font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:10px;">Link Expired or Invalid</h2>
            <p style="font-size:14px;color:#64748b;line-height:1.7;margin-bottom:<?= $error ? '12px' : '28px' ?>;">
                This password reset link has already been used, expired, or is invalid. Reset links are valid for <strong style="color:#0f172a;">1 hour</strong> and can only be used once.
            </p>
            <?php if ($error): ?>
            <p style="font-size:12px;color:#ef4444;background:#fef2f2;border:1px solid #fecaca;
                      border-radius:8px;padding:10px 14px;margin-bottom:20px;text-align:left;word-break:break-all;">
                <?= htmlspecialchars($error) ?>
            </p>
            <?php endif; ?>
            <a href="forgot_password.php"
               style="display:block;padding:12px;background:#2563eb;color:#fff;font-size:14px;
                      font-weight:700;border-radius:9px;text-decoration:none;text-align:center;
                      box-shadow:0 6px 20px rgba(37,99,235,0.3);">
                Request a New Link
            </a>
        </div>

        <?php elseif ($step === 'success'): ?>
        <!-- ── Success ── -->
        <div style="text-align:center;">
            <div style="width:72px;height:72px;border-radius:50%;background:#f0fdf4;border:2px solid #bbf7d0;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                <svg width="32" height="32" fill="none" stroke="#16a34a" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h2 style="font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:10px;">Password Updated</h2>
            <p style="font-size:14px;color:#64748b;line-height:1.7;margin-bottom:28px;">
                Your Super Admin password has been reset successfully. You can now sign in with your new password.
            </p>
            <a href="login.php"
               style="display:block;padding:12px;background:#2563eb;color:#fff;font-size:14px;
                      font-weight:700;border-radius:9px;text-decoration:none;text-align:center;
                      box-shadow:0 6px 20px rgba(37,99,235,0.3);">
                Sign In Now
            </a>
        </div>

        <?php else: ?>
        <!-- ── Set new password form ── -->
        <div style="text-align:center;margin-bottom:32px;">
            <div style="width:80px;height:80px;background:#eff6ff;border:2px solid #bfdbfe;
                        border-radius:50%;padding:10px;margin:0 auto 20px;
                        display:flex;align-items:center;justify-content:center;">
                <img src="assets/dict_logo.png" alt="DICT Logo" style="width:100%;height:100%;object-fit:contain;">
            </div>
            <h2 style="font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:6px;">Set New Password</h2>
            <p style="font-size:14px;color:#64748b;font-weight:400;">
                Hi <strong style="color:#0f172a;"><?= htmlspecialchars($fullName) ?></strong>. Choose a strong password.
            </p>
        </div>

        <?php if ($error): ?>
        <div class="error-alert">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="reset_password.php?token=<?= urlencode($token) ?>" autocomplete="off">

            <!-- New password -->
            <div style="margin-bottom:18px;">
                <label class="form-label" for="new_password">New Password</label>
                <div class="input-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </span>
                    <input type="password" id="new_password" name="new_password"
                           class="form-input <?= $error ? 'input-error' : '' ?>"
                           placeholder="Min. 8 characters"
                           required autofocus
                           oninput="updateStrength(this.value)">
                    <button type="button" class="toggle-pw" onclick="togglePw('new_password', this)" aria-label="Toggle">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye1-open">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye1-closed" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                        </svg>
                    </button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                <p id="strength-label" style="font-size:11px;color:#94a3b8;margin-top:4px;font-weight:500;"></p>
            </div>

            <!-- Confirm password -->
            <div style="margin-bottom:24px;">
                <label class="form-label" for="confirm_password">Confirm Password</label>
                <div class="input-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </span>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="form-input <?= $error ? 'input-error' : '' ?>"
                           placeholder="Re-enter your new password" required>
                    <button type="button" class="toggle-pw" onclick="togglePw('confirm_password', this)" aria-label="Toggle">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye2-open">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye2-closed" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit">Set New Password</button>
        </form>

        <p style="text-align:center;font-size:12px;color:#94a3b8;margin-top:24px;line-height:1.6;font-weight:500;">
            For authorized DICT personnel only.<br>Unauthorized access is strictly prohibited.
        </p>
        <?php endif; ?>

    </div>
</div>

<!-- ══ Right Panel ══ -->
<div class="right-panel">
    <div class="right-overlay"></div>
    <div class="right-card">
        <div style="width:72px;height:72px;border-radius:50%;border:2px solid rgba(255,255,255,0.5);
                    background:#fff;padding:6px;margin-bottom:24px;
                    display:flex;align-items:center;justify-content:center;">
            <img src="assets/dict_logo.png" alt="DICT Logo" style="width:100%;height:100%;object-fit:contain;">
        </div>
        <p style="font-size:10px;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:0.18em;margin-bottom:10px;">
            DICT &mdash; Region IX &amp; BASULTA
        </p>
        <h3 style="font-size:clamp(22px,2.8vw,32px);font-weight:900;color:#fff;
                    text-transform:uppercase;line-height:1.15;letter-spacing:-0.02em;margin-bottom:16px;">
            SARO<br>Monitoring<br>
            <span style="background:linear-gradient(90deg,#60a5fa,#bfdbfe);
                         -webkit-background-clip:text;-webkit-text-fill-color:transparent;
                         background-clip:text;">System</span>
        </h3>
        <p style="font-size:14px;color:rgba(255,255,255,0.6);line-height:1.8;
                  font-weight:400;border-bottom:1px solid rgba(255,255,255,0.1);padding-bottom:28px;">
            Centralized fund monitoring for Special Allotment Release Orders across the Zamboanga Peninsula and BASULTA cluster.
        </p>
    </div>
</div>

<script>
    function togglePw(id, btn) {
        const inp = document.getElementById(id);
        const isText = inp.type === 'text';
        inp.type = isText ? 'password' : 'text';
        // swap eye icons: find the open/closed pair inside this button
        const svgs = btn.querySelectorAll('svg');
        svgs[0].style.display = isText ? '' : 'none';
        svgs[1].style.display = isText ? 'none' : '';
    }

    function updateStrength(val) {
        const fill  = document.getElementById('strength-fill');
        const label = document.getElementById('strength-label');
        let score = 0;
        if (val.length >= 8)  score++;
        if (val.length >= 12) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/\d/.test(val))   score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        const levels = [
            { pct: '0%',   color: '#e2e8f0', text: '' },
            { pct: '25%',  color: '#ef4444', text: 'Weak' },
            { pct: '50%',  color: '#f59e0b', text: 'Fair' },
            { pct: '75%',  color: '#3b82f6', text: 'Good' },
            { pct: '100%', color: '#22c55e', text: 'Strong' },
        ];
        const lvl = val.length === 0 ? levels[0] : levels[Math.min(score, 4)];
        fill.style.width      = lvl.pct;
        fill.style.background = lvl.color;
        label.textContent     = lvl.text;
        label.style.color     = lvl.color;
    }
</script>
</body>
</html>
