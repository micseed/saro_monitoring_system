import re

file_path = r'c:\xampp\htdocs\saro\admin\settings_admin.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Replace Hero Text
old_hero = "Manage your account preferences and submit a password change request to the administrator."
new_hero = "Manage your account preferences and password change."
content = content.replace(old_hero, new_hero)

# 2. Remove inline success alert
alert_block_pattern = r"<\?php if \(isset\(\$_GET\['success'\]\) && \$_GET\['success'\] === '1'\): \?>\s*<div class=\"alert alert-success\".*?</div>\s*<\?php elseif \(isset\(\$_GET\['err'\]\)\): \?>"
new_alert_block = r"<?php if (isset($_GET['err'])): ?>"
content = re.sub(alert_block_pattern, new_alert_block, content, flags=re.DOTALL)

# 3. Inject Modal before </main>
modal_html = """
<?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
<div class="modal-overlay open" style="display: flex; position: fixed; inset: 0; z-index: 1000; background: rgba(15,23,42,0.7); backdrop-filter: blur(4px); align-items: center; justify-content: center; padding: 24px;">
    <div class="modal-card" style="background: #fff; border-radius: 20px; width: 100%; max-width: 400px; box-shadow: 0 24px 64px rgba(0,0,0,0.2); overflow: hidden; text-align: center; padding: 32px 24px;">
        <div style="width: 64px; height: 64px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
            <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h3 style="font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Password Changed</h3>
        <p style="font-size: 13px; color: #64748b; margin-bottom: 24px; line-height: 1.6;">Your password has been successfully updated. For security reasons, please log in again using your new password.</p>
        <a href="../logout.php" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; font-size: 14px;">Log In Again</a>
    </div>
</div>
<?php endif; ?>
</main>
"""

content = content.replace("</main>", modal_html)

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Settings admin updated with success modal and hero text.")
