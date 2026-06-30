import re

file_path = r'c:\xampp\htdocs\saro\admin\settings.php'
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Replace the POST action PHP logic
old_php_logic = """if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'request_password') {
        $reason   = trim($_POST['reason'] ?? '');
        $newPass  = trim($_POST['new_password'] ?? '');
        $currPass = $_POST['current_password'] ?? '';

        $stmt = $conn->prepare("SELECT password FROM user WHERE userId = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currPass, $user['password'])) {
            header('Location: settings.php?tab=password&err=wrong_password');
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO password_requests (userId, reason, requested_new_password, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$userId, $reason ?: '', $newPass ?: null]);
        header('Location: settings.php?tab=password&req=sent');
        exit;
    }

    if ($action === 'apply_password') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT requestId, requested_new_password
            FROM password_requests
            WHERE requestId = ? AND userId = ? AND status = 'approved' AND applied_at IS NULL AND requested_new_password IS NOT NULL
        ");
        $stmt->execute([$requestId, $userId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($req) {
            $hash = password_hash($req['requested_new_password'], PASSWORD_DEFAULT);
            $conn->prepare("UPDATE user SET password = ? WHERE userId = ?")->execute([$hash, $userId]);
            $conn->prepare("UPDATE password_requests SET applied_at = NOW() WHERE requestId = ?")->execute([$requestId]);
        }
        header('Location: settings.php?tab=password&applied=1');
        exit;
    }
}"""

new_php_logic = """if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'change_password') {
        $currPass = $_POST['current_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        if ($newPass !== $confPass) {
            header('Location: settings.php?tab=password&err=mismatch');
            exit;
        }

        $stmt = $conn->prepare("SELECT password FROM user WHERE userId = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currPass, $user['password'])) {
            header('Location: settings.php?tab=password&err=wrong_password');
            exit;
        }

        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $conn->prepare("UPDATE user SET password = ? WHERE userId = ?")->execute([$hash, $userId]);
        $conn->prepare("INSERT INTO activity_logs (userId, action, details) VALUES (?, 'password_change', 'Admin changed their own password')")->execute([$userId]);

        header('Location: settings.php?tab=password&success=1');
        exit;
    }
}"""

if old_php_logic in content:
    content = content.replace(old_php_logic, new_php_logic)
else:
    print("Could not find the PHP logic block to replace.")

# 2. Replace the NotifObj fetching block
old_notif_logic = """$notifObj      = new Notification();
$pwRequests    = $notifObj->getUserPasswordRequests($userId);
$notifications = $notifObj->getRecentActivity((int)$_SESSION['user_id'], 10);
$unreadCount   = $notifObj->countUnread($userId);
$approvedPwReq = $notifObj->getApprovedPasswordNotification($userId);"""

new_notif_logic = """$notifObj      = new Notification();
$notifications = $notifObj->getRecentActivity((int)$_SESSION['user_id'], 10);
$unreadCount   = $notifObj->countUnread($userId);
$pendingPwCount = (int)$conn->query("SELECT COUNT(*) FROM password_requests WHERE status='pending'")->fetchColumn();"""

if old_notif_logic in content:
    content = content.replace(old_notif_logic, new_notif_logic)

# 3. Replace the Hero text
old_hero = "Manage your account preferences and submit a password change request to the administrator."
new_hero = "Manage your account preferences and update your administrator password securely."
content = content.replace(old_hero, new_hero)

# 4. Replace the entire section-password HTML and the modal HTML
# We can use regex to replace everything from <div id="section-password"> up to <!-- -- Password Request Modal -- -->
# and then the modal itself.

import re

# Match <div id="section-password"> up to the end of the content wrapper which is </div><!-- end right col -->
pattern_password_section = r'<div id="section-password">.*?</div><!-- end right col -->'

new_password_section = """<div id="section-password">
                        <div class="card">
                            <div class="card-header">
                                <div class="card-header-icon" style="background:#fef9c3;">
                                    <svg width="18" height="18" fill="none" stroke="#b45309" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                </div>
                                <div>
                                    <p style="font-size:14px;font-weight:800;color:#0f172a;">Change Password</p>
                                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Update your administrator account password</p>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
                                <div class="alert alert-success" style="display:flex;margin-bottom:16px;">
                                    <svg class="alert-icon" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span>Your password has been changed successfully.</span>
                                </div>
                                <?php elseif (isset($_GET['err'])): ?>
                                <div class="alert alert-error" style="display:flex;margin-bottom:16px;">
                                    <svg class="alert-icon" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span>
                                        <?php 
                                            if ($_GET['err'] === 'wrong_password') echo 'Incorrect current password. Please try again.';
                                            elseif ($_GET['err'] === 'mismatch') echo 'New passwords do not match.';
                                            else echo 'An error occurred. Please try again.';
                                        ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <form method="post" action="settings.php" onsubmit="return validatePwForm()">
                                    <input type="hidden" name="action" value="change_password">
                                    <div class="form-group">
                                        <label class="form-label">Current Password</label>
                                        <div class="input-wrap">
                                            <input type="password" class="form-input" id="currentPassword" name="current_password" placeholder="Enter your current password" required>
                                            <button type="button" class="eye-btn" onclick="toggleEye('currentPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-currentPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <hr class="divider">
                                    <div class="form-group">
                                        <label class="form-label">New Password</label>
                                        <div class="input-wrap">
                                            <input type="password" class="form-input" id="newPassword" name="new_password" placeholder="Enter new password" required oninput="checkStrength(this.value)">
                                            <button type="button" class="eye-btn" onclick="toggleEye('newPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-newPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                        <div class="strength-bar" id="strengthBar" style="margin-top:10px;">
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
                                            <input type="password" class="form-input" id="confirmPassword" name="confirm_password" placeholder="Confirm new password" required>
                                            <button type="button" class="eye-btn" onclick="toggleEye('confirmPassword', this)">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" id="eye-confirmPassword"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div style="display:flex;justify-content:flex-end;">
                                        <button type="submit" class="btn btn-primary">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            Change Password
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div><!-- end right col -->"""

content = re.sub(pattern_password_section, new_password_section, content, flags=re.DOTALL)

# 5. Remove the Modal block and script modifications
# Remove everything from <!-- -- Password Request Modal -- --> down to </script> including the modal functions.
# The `pwModal` related code is all the way down.
# Let's just find and replace the whole modal markup and logic.
modal_pattern = r'<!-- -- Password Request Modal -- -->.*?</script>'

# We still need the script block for toggleEye, checkStrength, etc.
new_script_block = """<script>
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('tab') === 'password') {
        showSection('password');
    } else {
        showSection('profile');
    }

    function showSection(sec) {
        document.getElementById('section-profile').style.display = 'none';
        document.getElementById('section-password').style.display = 'none';
        
        document.querySelectorAll('.settings-nav-item').forEach(el => el.classList.remove('active'));
        
        document.getElementById('section-' + sec).style.display = 'block';
        event.currentTarget.classList.add('active');
        
        const url = new URL(window.location);
        url.searchParams.set('tab', sec);
        window.history.pushState({}, '', url);
    }

    function toggleEye(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById('eye-' + inputId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
        } else {
            input.type = 'password';
            icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
        }
    }

    function checkStrength(val) {
        const seg1 = document.getElementById('seg1');
        const seg2 = document.getElementById('seg2');
        const seg3 = document.getElementById('seg3');
        const seg4 = document.getElementById('seg4');
        const label = document.getElementById('strengthLabel');
        
        let score = 0;
        if(val.length >= 8) score++;
        if(/[A-Z]/.test(val)) score++;
        if(/[0-9]/.test(val)) score++;
        if(/[\W_]/.test(val)) score++;

        [seg1,seg2,seg3,seg4].forEach(s => s.className = 'strength-seg');
        
        if (val.length === 0) {
            label.textContent = '';
            label.style.color = '#94a3b8';
        } else if (score <= 1) {
            seg1.classList.add('weak');
            label.textContent = 'Weak password';
            label.style.color = '#ef4444';
        } else if (score === 2) {
            seg1.classList.add('fair'); seg2.classList.add('fair');
            label.textContent = 'Fair password';
            label.style.color = '#f59e0b';
        } else if (score === 3) {
            seg1.classList.add('good'); seg2.classList.add('good'); seg3.classList.add('good');
            label.textContent = 'Good password';
            label.style.color = '#3b82f6';
        } else {
            seg1.classList.add('strong'); seg2.classList.add('strong'); seg3.classList.add('strong'); seg4.classList.add('strong');
            label.textContent = 'Strong password';
            label.style.color = '#10b981';
        }
    }

    function validatePwForm() {
        const p1 = document.getElementById('newPassword').value;
        const p2 = document.getElementById('confirmPassword').value;
        if(p1 !== p2) {
            alert('New passwords do not match.');
            return false;
        }
        return true;
    }
</script>"""

content = re.sub(modal_pattern, new_script_block, content, flags=re.DOTALL)

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)
print("Updated admin settings to be fully independent.")
