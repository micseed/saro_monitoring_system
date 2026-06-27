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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
        h1, h2, h3, h4, h5, h6, .brand-font { font-family: 'Outfit', sans-serif; }
        html, body { height: 100%; }

        body {
            display: flex;
            flex-direction: row-reverse;
            min-height: 100vh;
            background: #ffffff;
        }

        /* Animations */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .fade-up { opacity: 0; animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }

        /* ── Left panel (form) ── */
        .left-panel {
            width: 50%;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            position: relative;
            padding: 24px 64px;
            overflow-y: auto;
            scrollbar-width: none; /* Firefox */
        }
        .left-panel::-webkit-scrollbar {
            display: none; /* Chrome/Safari/Edge */
        }

        /* ── Right panel (image) ── */
        .right-panel {
            width: 50%;
            position: relative;
            background: url('assets/dict_bg.jpg') center/cover no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px;
        }
        .right-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(8,18,52,0.9) 0%, rgba(14,40,100,0.8) 100%);
        }

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
            padding: 14px 16px 14px 44px;
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
        .form-input.input-error { border-color: #ef4444; }
        .form-input.input-error:focus { box-shadow: 0 0 0 4px rgba(239,68,68,0.1); }

        .input-wrap { position: relative; }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }
        .toggle-pw {
            position: absolute;
            right: 14px;
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
        }
        .btn-submit:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37,99,235,0.4);
        }
        .btn-submit:active { transform: translateY(0); }

        /* Error */
        .error-alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            font-size: 14px;
            color: #b91c1c;
            font-weight: 500;
            margin-bottom: 24px;
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
        <div class="fade-up" style="margin-bottom:auto;">
            <a href="index.php"
               style="display:inline-flex;align-items:center;gap:6px;font-size:14px;
                      font-weight:600;color:#64748b;text-decoration:none;transition:color 0.2s;"
               onmouseover="this.style.color='#2563eb'"
               onmouseout="this.style.color='#64748b'">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                Back to Home
            </a>
        </div>

        <!-- Form area -->
        <div style="flex:1; display:flex; flex-direction:column; justify-content:center; max-width:400px; width:100%; margin:0 auto; padding:10px 0;">

            <!-- Logo + heading -->
            <div class="fade-up delay-100" style="text-align:center; margin-bottom:24px;">
                <div style="width:80px; height:80px; background:#ffffff; border:1px solid #e2e8f0;
                            border-radius:24px; padding:12px; margin:0 auto 16px;
                            display:flex; align-items:center; justify-content:center; box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
                    <img src="assets/dict_logo.png" alt="DICT Logo" style="width:100%; height:100%; object-fit:contain;">
                </div>
                <h2 class="brand-font" style="font-size:32px; font-weight:800; color:#0f172a; margin-bottom:8px;">Welcome Back</h2>
                <p style="font-size:15px; color:#64748b; font-weight:400;">
                    Sign in to the DICT SARO Monitoring System.
                </p>
            </div>

            <!-- Error alert -->
            <?php if ($error): ?>
            <div class="error-alert fade-up delay-200">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" style="flex-shrink:0;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="login.php" autocomplete="off" class="fade-up delay-200">

                <!-- Username -->
                <div style="margin-bottom:20px;">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
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
                <div style="margin-bottom:12px;">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </span>
                        <input type="password" id="password" name="password"
                               class="form-input <?= $error ? 'input-error' : '' ?>"
                               placeholder="Enter your password"
                               required>
                        <button type="button" class="toggle-pw" id="togglePw" aria-label="Toggle password">
                            <svg id="eye-open" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg id="eye-closed" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Forgot password -->
                <div style="text-align:right; margin-bottom:30px;">
                    <a href="forgot_password.php" style="font-size:13px; color:#3b82f6; font-weight:500; text-decoration:none; transition: color 0.2s;"
                       onmouseover="this.style.color='#1d4ed8'"
                       onmouseout="this.style.color='#3b82f6'">Forgot password?</a>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-submit">
                    Sign In
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>

            </form>

            <!-- Footer note -->
            <div class="fade-up delay-300" style="text-align:center; font-size:13px; color:#94a3b8; margin-top:32px; line-height:1.6; font-weight:400;">
                For authorized DICT personnel only.<br>Unauthorized access is strictly prohibited.
            </div>

        </div>

    </div>

    <!-- ══ Right Panel: Image ══ -->
    <div class="right-panel">
        <div class="right-overlay"></div>
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
