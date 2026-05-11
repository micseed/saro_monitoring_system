<?php
session_start();

// Already logged in — redirect to appropriate dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['role_id'] === 1 ? 'admin/dashboard.php' : 'saro/dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            require_once 'class/database.php';
            $db   = new Database();
            $conn = $db->connect();

            $stmt = $conn->prepare(
                "SELECT u.userId, u.username, u.first_name, u.last_name,
                        u.password, u.status, u.roleId, u.created_at, u.last_login, r.role,
                        NOW() as current_db_time
                 FROM `user` u
                 JOIN `user_role` r ON r.roleId = u.roleId
                 WHERE u.username = ?
                 LIMIT 1"
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Invalid username or password. Please try again.';
            } elseif ($user['status'] !== 'active') {
                $error = 'Your account is inactive. Contact the administrator.';
            } else {
                $now = strtotime($user['current_db_time']);
                $createdAt = strtotime($user['created_at']);
                $lastLogin = $user['last_login'] ? strtotime($user['last_login']) : null;

                // Time manipulation check
                if ($now < $createdAt) {
                    $error = 'System clock error: Current time is before account creation. Please correct your device date and time.';
                } elseif ($lastLogin && $now < $lastLogin) {
                    $error = 'System clock error: Current time is before your last login. Please correct your device date and time.';
                } else {
                    // Update last_login
                    $conn->prepare("UPDATE `user` SET last_login = NOW() WHERE userId = ?")
                         ->execute([$user['userId']]);

                // Set session
                $_SESSION['user_id']   = $user['userId'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                $_SESSION['role_id']   = (int)$user['roleId'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['initials']  = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));

                // Audit log — wrapped separately so a logging failure never blocks login
                try {
                    $conn->prepare("
                        INSERT INTO audit_logs (userId, action, details, affected_table, record_id, ip_address)
                        VALUES (?, 'login', ?, 'user', ?, ?)
                    ")->execute([
                        $user['userId'],
                        $user['role'] . ' logged in: ' . trim($user['first_name'] . ' ' . $user['last_name']),
                        $user['userId'],
                        $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                } catch (Exception $logEx) {
                    // Fail silently — login must always succeed
                }

                    // Redirect based on role
                    $dest = ($user['roleId'] == 1) ? 'admin/dashboard.php' : 'saro/dashboard.php';
                    header('Location: ' . $dest);
                    exit;
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | DICT SARO Monitoring</title>
    <link href="dist/output.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }

        body {
            display: flex;
            min-height: 100vh;
        }

        /* ── Left panel (form) ── */
        .left-panel {
            width: 50%;
            display: flex;
            flex-direction: column;
            background: #fff;
            position: relative;
            padding: 40px 64px;
            overflow-y: auto;
        }

        /* ── Right panel (image) ── */
        .right-panel {
            width: 50%;
            position: relative;
            background-image: url('assets/dict_bg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px;
        }
        .right-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(145deg, rgba(8,18,52,0.92) 0%, rgba(29,78,216,0.80) 100%);
        }
        .right-card {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 20px;
            padding: 48px 40px;
            max-width: 440px;
            width: 100%;
        }

        /* ── Form elements ── */
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 7px;
        }
        .form-input {
            width: 100%;
            padding: 11px 16px 11px 42px;
            border: 1.5px solid #e2e8f0;
            border-radius: 9px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            color: #0f172a;
            background: #f8fafc;
            outline: none;
            transition: all 0.2s ease;
        }
        .form-input:focus {
            border-color: #3b82f6;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(59,130,246,0.1);
        }
        .form-input.input-error { border-color: #ef4444; }
        .form-input.input-error:focus { box-shadow: 0 0 0 4px rgba(239,68,68,0.1); }

        .input-wrap { position: relative; }
        .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }
        .toggle-pw {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }
        .toggle-pw:hover { color: #3b82f6; }

        /* Submit */
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #2563eb;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            border: none;
            border-radius: 9px;
            cursor: pointer;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            transition: all 0.25s ease;
            box-shadow: 0 6px 20px rgba(37,99,235,0.3);
        }
        .btn-submit:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 10px 28px rgba(37,99,235,0.4);
        }
        .btn-submit:active { transform: translateY(0); }

        /* Error */
        .error-alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 9px;
            font-size: 13px;
            color: #b91c1c;
            font-weight: 500;
            margin-bottom: 20px;
        }

        /* Feature bullets */
        .feature-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .feature-dot {
            width: 8px; height: 8px;
            background: #60a5fa;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .left-panel { width: 100%; padding: 40px 32px; }
            .right-panel { display: none; }
        }
        @media (max-width: 480px) {
            .left-panel { padding: 32px 20px; }
        }
    </style>
</head>
<body>

    <!-- ══ Left Panel: Form ══ -->
    <div class="left-panel">

        <!-- Back link -->
        <div style="margin-bottom:auto;">
            <a href="index.php"
               style="display:inline-flex;align-items:center;gap:6px;font-size:13px;
                      font-weight:600;color:#64748b;text-decoration:none;transition:color 0.2s;"
               onmouseover="this.style.color='#2563eb'"
               onmouseout="this.style.color='#64748b'">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                Back to Home
            </a>
        </div>

        <!-- Form area -->
        <div style="flex:1; display:flex; flex-direction:column; justify-content:center; max-width:380px; width:100%; margin:0 auto; padding:40px 0;">

            <!-- Logo + heading -->
            <div style="text-align:center; margin-bottom:36px;">
                <div style="width:80px; height:80px; background:#eff6ff; border:2px solid #bfdbfe;
                            border-radius:50%; padding:10px; margin:0 auto 20px;
                            display:flex; align-items:center; justify-content:center;">
                    <img src="assets/dict_logo.png" alt="DICT Logo" style="width:100%; height:100%; object-fit:contain;">
                </div>
                <h2 style="font-size:26px; font-weight:900; color:#0f172a; letter-spacing:-0.02em; margin-bottom:6px;">Sign In</h2>
                <p style="font-size:14px; color:#64748b; font-weight:400;">
                    Access the DICT SARO Monitoring System.
                </p>
            </div>

            <!-- Error alert -->
            <?php if ($error): ?>
            <div class="error-alert">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="login.php" autocomplete="off">

                <!-- Username -->
                <div style="margin-bottom:18px;">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </span>
                        <input type="text" id="username" name="username"
                               class="form-input <?= $error ? 'input-error' : '' ?>"
                               placeholder="Enter your username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               required autofocus>
                    </div>
                </div>

                <!-- Password -->
                <div style="margin-bottom:10px;">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </span>
                        <input type="password" id="password" name="password"
                               class="form-input <?= $error ? 'input-error' : '' ?>"
                               placeholder="Enter your password"
                               required>
                        <button type="button" class="toggle-pw" id="togglePw" aria-label="Toggle password">
                            <svg id="eye-open" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg id="eye-closed" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Forgot password -->
                <div style="text-align:right; margin-bottom:24px;">
                    <a href="forgot_password.php" style="font-size:12px; color:#3b82f6; font-weight:600; text-decoration:none;"
                       onmouseover="this.style.color='#1d4ed8'"
                       onmouseout="this.style.color='#3b82f6'">Forgot your password?</a>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-submit">Sign In</button>

            </form>

            <!-- Footer note -->
            <p style="text-align:center; font-size:12px; color:#94a3b8; margin-top:28px; line-height:1.6; font-weight:500;">
                For authorized DICT personnel only.<br>Unauthorized access is strictly prohibited.
            </p>

        </div>

    </div>

    <!-- ══ Right Panel: Image + Card ══ -->
    <div class="right-panel">
        <div class="right-overlay"></div>

        <div class="right-card">

            <!-- Logo -->
            <div style="width:72px; height:72px; border-radius:50%; border:2px solid rgba(255,255,255,0.5);
                        background:#fff; padding:6px; margin-bottom:24px;
                        display:flex; align-items:center; justify-content:center;">
                <img src="assets/dict_logo.png" alt="DICT Logo" style="width:100%; height:100%; object-fit:contain;">
            </div>

            <p style="font-size:10px; font-weight:700; color:#93c5fd; text-transform:uppercase;
                      letter-spacing:0.18em; margin-bottom:10px;">
                DICT &mdash; Region IX &amp; BASULTA
            </p>

            <h3 style="font-size:clamp(22px,2.8vw,32px); font-weight:900; color:#fff;
                        text-transform:uppercase; line-height:1.15;
                       letter-spacing:-0.02em; margin-bottom:16px;">
                SARO<br>Monitoring<br>
                <span style="background:linear-gradient(90deg,#60a5fa,#bfdbfe);
                             -webkit-background-clip:text; -webkit-text-fill-color:transparent;
                             background-clip:text;">System</span>
            </h3>

            <p style="font-size:14px; color:rgba(255,255,255,0.6); line-height:1.8;
                      font-weight:400; margin-bottom:32px; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:28px;">
                Centralized fund monitoring for Special Allotment Release Orders in DRRM - DICT.
            </p>
        </div>
    </div>

    <script>
        const toggleBtn = document.getElementById('togglePw');
        const pwInput   = document.getElementById('password');
        const eyeOpen   = document.getElementById('eye-open');
        const eyeClosed = document.getElementById('eye-closed');

        toggleBtn.addEventListener('click', () => {
            const isHidden = pwInput.type === 'password';
            pwInput.type            = isHidden ? 'text'    : 'password';
            eyeOpen.style.display   = isHidden ? 'none'    : '';
            eyeClosed.style.display = isHidden ? ''        : 'none';
        });
    </script>
</body>
</html>
