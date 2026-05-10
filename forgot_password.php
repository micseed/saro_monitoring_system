<?php
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role_id'] === 1 ? 'admin/dashboard.php' : 'saro/dashboard.php'));
    exit;
}

require_once 'class/database.php';
require_once 'class/mailer.php';

$step      = 'search';  // search | confirm | email_sent | success
$error     = '';
$foundUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Step 1: find the account ──────────────────────────────────────────
    if (isset($_POST['find_user'])) {
        $identifier = trim($_POST['identifier'] ?? '');
        if ($identifier === '') {
            $error = 'Please enter your username or email address.';
        } else {
            try {
                $db   = new Database();
                $conn = $db->connect();
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $conn->prepare(
                    "SELECT userId, first_name, last_name, email, username, status, roleId
                     FROM user
                     WHERE (username = ? OR email = ?)
                     LIMIT 1"
                );
                $stmt->execute([$identifier, $identifier]);
                $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$foundUser) {
                    $error = 'No account found with that username or email.';
                } elseif ($foundUser['status'] !== 'active') {
                    $error = 'This account is inactive. Contact your administrator directly.';
                    $foundUser = null;
                } elseif ((int)$foundUser['roleId'] === 1) {
                    // Check for 30-minute rate limit
                    $limitCheck = $conn->prepare("SELECT 1 FROM password_resets WHERE userId = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1");
                    $limitCheck->execute([$foundUser['userId']]);
                    if ($limitCheck->fetch()) {
                        $error = 'You have recently requested a password reset. Please wait 30 minutes before trying again.';
                        $foundUser = null;
                    } else {
                        // ── Super Admin: send email token reset ───────────────
                        // Remove any existing unused tokens for this user
                    $conn->prepare("DELETE FROM password_resets WHERE userId = ? AND used_at IS NULL")
                         ->execute([$foundUser['userId']]);

                    $token = bin2hex(random_bytes(32));
                    $conn->prepare("INSERT INTO password_resets (userId, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
                         ->execute([$foundUser['userId'], $token]);

                    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $baseUrl  = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                    $resetUrl = $baseUrl . '/reset_password.php?token=' . $token;
                    $fullName = trim($foundUser['first_name'] . ' ' . $foundUser['last_name']);

                    $sent = Mailer::sendPasswordReset($foundUser['email'], $fullName, $resetUrl);

                    if ($sent) {
                        $step = 'email_sent';
                    } else {
                        // Clean up the token so it can be retried
                        $conn->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
                        $error = 'Failed to send the reset email. Please check the mail configuration in class/mail_config.php and try again.';
                    }
                    $foundUser = null; // don't expose user data in HTML
                    }
                } else {
                    // ── Regular user: admin-approval flow ────────────────
                    // Check for 30-minute rate limit before allowing them to proceed to step 2
                    $limitCheck = $conn->prepare("SELECT 1 FROM password_requests WHERE userId = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1");
                    $limitCheck->execute([$foundUser['userId']]);
                    if ($limitCheck->fetch()) {
                        $error = 'You have recently requested a password reset. Please wait 30 minutes before trying again.';
                        $foundUser = null;
                    } else {
                        $step = 'confirm';
                    }
                }
            } catch (PDOException $e) {
                $error = 'Database error. Please try again later.';
            }
        }
    }

    // ── Step 2 (regular users only): submit the approval request ─────────
    if (isset($_POST['submit_request'])) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($userId <= 0) {
            $error = 'Invalid request. Please start over.';
            $step  = 'search';
        } elseif ($reason === '') {
            $error = 'Please describe why you need a password reset.';
            $step  = 'confirm';
            try {
                $db   = new Database();
                $conn = $db->connect();
                $stmt = $conn->prepare("SELECT userId, first_name, last_name, email, username FROM user WHERE userId = ? LIMIT 1");
                $stmt->execute([$userId]);
                $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { /* fall through */ }
        } else {
            try {
                $db   = new Database();
                $conn = $db->connect();

                $timeCheck = $conn->prepare("SELECT 1 FROM password_requests WHERE userId = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1");
                $timeCheck->execute([$userId]);

                $dup = $conn->prepare("SELECT requestId FROM password_requests WHERE userId = ? AND status = 'pending' LIMIT 1");
                $dup->execute([$userId]);

                if ($timeCheck->fetch()) {
                    $error = 'You have recently requested a password reset. Please wait 30 minutes before trying again.';
                    $step  = 'confirm';
                    $stmt  = $conn->prepare("SELECT userId, first_name, last_name, email, username FROM user WHERE userId = ? LIMIT 1");
                    $stmt->execute([$userId]);
                    $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);
                } elseif ($dup->fetch()) {
                    $error = 'You already have a pending password reset request. Please wait for administrator approval.';
                    $step  = 'confirm';
                    $stmt  = $conn->prepare("SELECT userId, first_name, last_name, email, username FROM user WHERE userId = ? LIMIT 1");
                    $stmt->execute([$userId]);
                    $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $conn->prepare("INSERT INTO password_requests (userId, reason) VALUES (?, ?)")
                         ->execute([$userId, $reason]);
                    $step = 'success';
                }
            } catch (PDOException $e) {
                $error = 'Database error. Please try again later.';
                $step  = 'confirm';
            }
        }
    }
}

function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . str_repeat('*', max(3, strlen($local) - 1)) . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | DICT SARO Monitoring</title>
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
        .right-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(145deg, rgba(8,18,52,0.92) 0%, rgba(29,78,216,0.80) 100%);
        }
        .right-card {
            position: relative; z-index: 1;
            background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-radius: 20px; padding: 48px 40px; max-width: 440px; width: 100%;
        }

        .form-label {
            display: block; font-size: 12px; font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 7px;
        }
        .form-input {
            width: 100%; padding: 11px 16px 11px 42px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 14px; font-family: 'Poppins', sans-serif; font-weight: 500;
            color: #0f172a; background: #f8fafc; outline: none; transition: all 0.2s ease;
        }
        .form-input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,0.1); }
        .form-input.input-error { border-color: #ef4444; }
        .form-textarea {
            width: 100%; padding: 11px 16px; border: 1.5px solid #e2e8f0; border-radius: 9px;
            font-size: 14px; font-family: 'Poppins', sans-serif; font-weight: 500;
            color: #0f172a; background: #f8fafc; outline: none; resize: none;
            height: 96px; transition: all 0.2s ease;
        }
        .form-textarea:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,0.1); }

        .input-wrap { position: relative; }
        .input-icon {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; pointer-events: none;
        }

        .btn-submit {
            width: 100%; padding: 12px; background: #2563eb; color: #fff;
            font-size: 14px; font-weight: 700; font-family: 'Poppins', sans-serif;
            border: none; border-radius: 9px; cursor: pointer; letter-spacing: 0.04em;
            text-transform: uppercase; transition: all 0.25s ease;
            box-shadow: 0 6px 20px rgba(37,99,235,0.3);
        }
        .btn-submit:hover { background: #1d4ed8; transform: translateY(-1px); box-shadow: 0 10px 28px rgba(37,99,235,0.4); }
        .btn-submit:active { transform: translateY(0); }
        .btn-secondary {
            width: 100%; padding: 11px; background: #fff; color: #475569;
            font-size: 14px; font-weight: 600; font-family: 'Poppins', sans-serif;
            border: 1.5px solid #e2e8f0; border-radius: 9px; cursor: pointer;
            transition: all 0.2s ease; text-align: center; text-decoration: none; display: block;
        }
        .btn-secondary:hover { border-color: #94a3b8; color: #0f172a; }

        .error-alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; background: #fef2f2; border: 1px solid #fecaca;
            border-radius: 9px; font-size: 13px; color: #b91c1c; font-weight: 500;
            margin-bottom: 20px;
        }
        .info-card {
            background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px;
            padding: 14px 16px; margin-bottom: 20px;
        }
        .success-icon {
            width: 72px; height: 72px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;
        }
        .feature-dot { width: 8px; height: 8px; background: #60a5fa; border-radius: 50%; flex-shrink: 0; margin-top: 6px; }

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

        <?php if ($step === 'email_sent'): ?>
        <!-- ── Email sent (super admin) ── -->
        <div style="text-align:center;">
            <div class="success-icon" style="background:#eff6ff;border:2px solid #bfdbfe;">
                <svg width="32" height="32" fill="none" stroke="#2563eb" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <h2 style="font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:10px;">Check Your Email</h2>
            <p style="font-size:14px;color:#64748b;font-weight:400;line-height:1.7;margin-bottom:28px;">
                A password reset link has been sent to your registered email address. The link expires in <strong style="color:#0f172a;">1 hour</strong>.
            </p>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:left;margin-bottom:28px;">
                <p style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:10px;">Next steps</p>
                <div style="display:flex;gap:10px;margin-bottom:8px;"><div class="feature-dot"></div>
                    <p style="font-size:13px;color:#475569;">Open the email from <strong>DICT SARO Monitoring</strong>.</p></div>
                <div style="display:flex;gap:10px;margin-bottom:8px;"><div class="feature-dot"></div>
                    <p style="font-size:13px;color:#475569;">Click <strong>Reset My Password</strong>.</p></div>
                <div style="display:flex;gap:10px;"><div class="feature-dot"></div>
                    <p style="font-size:13px;color:#475569;">Set your new password — the link works only once.</p></div>
            </div>
            <a href="forgot_password.php" style="display:block;text-align:center;font-size:13px;color:#3b82f6;font-weight:600;text-decoration:none;margin-bottom:12px;"
               onmouseover="this.style.color='#1d4ed8'" onmouseout="this.style.color='#3b82f6'">
                Didn't receive it? Try again
            </a>
            <a href="login.php" class="btn-secondary">Back to Sign In</a>
        </div>

        <?php elseif ($step === 'success'): ?>
        <!-- ── Admin-approval submitted (regular users) ── -->
        <div style="text-align:center;">
            <div class="success-icon" style="background:#f0fdf4;border:2px solid #bbf7d0;">
                <svg width="32" height="32" fill="none" stroke="#16a34a" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h2 style="font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:10px;">Request Submitted</h2>
            <p style="font-size:14px;color:#64748b;font-weight:400;line-height:1.7;margin-bottom:28px;">
                Your password reset request has been received. Please contact your <strong style="color:#0f172a;">system administrator</strong> to follow up.
            </p>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:left;margin-bottom:28px;">
                <p style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:10px;">What happens next?</p>
                <div style="display:flex;gap:10px;margin-bottom:8px;"><div class="feature-dot"></div>
                    <p style="font-size:13px;color:#475569;">Admin reviews your request in the password requests panel.</p></div>
                <div style="display:flex;gap:10px;margin-bottom:8px;"><div class="feature-dot"></div>
                    <p style="font-size:13px;color:#475569;">Once approved, your password will be reset.</p></div>
                <div style="display:flex;gap:10px;"><div class="feature-dot"></div>
                    <p style="font-size:13px;color:#475569;">Contact your administrator directly for faster resolution.</p></div>
            </div>
            <a href="login.php" class="btn-submit" style="display:block;text-decoration:none;text-align:center;">Back to Sign In</a>
        </div>

        <?php else: ?>
        <!-- ── Header (search & confirm steps) ── -->
        <div style="text-align:center;margin-bottom:32px;">
            <div style="width:80px;height:80px;background:#eff6ff;border:2px solid #bfdbfe;
                        border-radius:50%;padding:10px;margin:0 auto 20px;
                        display:flex;align-items:center;justify-content:center;">
                <img src="assets/dict_logo.png" alt="DICT Logo" style="width:100%;height:100%;object-fit:contain;">
            </div>
            <h2 style="font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;margin-bottom:6px;">
                <?= $step === 'confirm' ? 'Submit Reset Request' : 'Forgot Password?' ?>
            </h2>
            <p style="font-size:14px;color:#64748b;font-weight:400;">
                <?= $step === 'confirm'
                    ? 'Confirm your identity and provide a reason.'
                    : 'Enter your username or email to find your account.' ?>
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

        <?php if ($step === 'search'): ?>
        <!-- Step 1: search form -->
        <form method="POST" action="forgot_password.php" autocomplete="off">
            <div style="margin-bottom:20px;">
                <label class="form-label" for="identifier">Username or Email</label>
                <div class="input-wrap">
                    <span class="input-icon">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </span>
                    <input type="text" id="identifier" name="identifier"
                           class="form-input <?= $error ? 'input-error' : '' ?>"
                           placeholder="Enter your username or email"
                           value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
                           required autofocus>
                </div>
            </div>
            <button type="submit" name="find_user" class="btn-submit" style="margin-bottom:12px;">Find My Account</button>
            <a href="login.php" class="btn-secondary">Cancel</a>
        </form>

        <?php elseif ($step === 'confirm' && $foundUser): ?>
        <!-- Step 2: reason form (regular users) -->
        <div class="info-card">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:40px;height:40px;border-radius:10px;background:#2563eb;
                            display:flex;align-items:center;justify-content:center;
                            font-size:14px;font-weight:800;color:#fff;flex-shrink:0;">
                    <?= htmlspecialchars(strtoupper(substr($foundUser['first_name'], 0, 1) . substr($foundUser['last_name'], 0, 1))) ?>
                </div>
                <div>
                    <p style="font-size:13px;font-weight:700;color:#0f172a;line-height:1.2;">
                        <?= htmlspecialchars($foundUser['first_name'] . ' ' . $foundUser['last_name']) ?>
                    </p>
                    <p style="font-size:11px;color:#64748b;font-weight:500;">
                        <?= htmlspecialchars(maskEmail($foundUser['email'])) ?>
                    </p>
                </div>
                <div style="margin-left:auto;">
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;
                                 background:#f0fdf4;border:1px solid #bbf7d0;border-radius:99px;
                                 font-size:10px;font-weight:700;color:#16a34a;">
                        <span style="width:5px;height:5px;border-radius:50%;background:#16a34a;"></span>
                        Active
                    </span>
                </div>
            </div>
        </div>

        <form method="POST" action="forgot_password.php" autocomplete="off">
            <input type="hidden" name="user_id" value="<?= (int)$foundUser['userId'] ?>">
            <div style="margin-bottom:20px;">
                <label class="form-label" for="reason">Reason for Reset</label>
                <textarea id="reason" name="reason"
                          class="form-textarea <?= $error ? 'input-error' : '' ?>"
                          placeholder="Briefly describe why you need a password reset…"
                          required><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
            </div>
            <button type="submit" name="submit_request" class="btn-submit" style="margin-bottom:12px;">Submit Reset Request</button>
            <a href="forgot_password.php" class="btn-secondary">Search Again</a>
        </form>
        <?php endif; ?>

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

</body>
</html>
