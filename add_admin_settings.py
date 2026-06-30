import re
import glob

# 1. Update the sidebar in all original admin files
nav_addition = """
            <p class="nav-section-label">Configuration</p>
            <a href="settings.php" class="nav-item">
                <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Settings
            </a>
        </nav>"""

admin_files = ['activity_logs.php', 'dashboard.php', 'export_records.php', 'password_requests.php', 'users.php']

for f in admin_files:
    path = r'c:\xampp\htdocs\saro\admin\\' + f
    with open(path, 'r', encoding='utf-8') as file:
        content = file.read()
    
    if '<p class="nav-section-label">Configuration</p>' not in content:
        content = content.replace('</nav>', nav_addition)
        with open(path, 'w', encoding='utf-8') as file:
            file.write(content)
        print(f"Updated {f}")

# 2. Fix admin/settings.php
settings_path = r'c:\xampp\htdocs\saro\admin\settings.php'
with open(settings_path, 'r', encoding='utf-8') as f:
    s_content = f.read()

# Replace the saro admin tag with the proper admin tag
saro_admin_tag = """        <div class="admin-tag">
            <div class="admin-tag-dot"></div>
            <p style="font-size:9px;font-weight:800;color:#fca5a5;text-transform:uppercase;letter-spacing:0.15em;">SARO Admin</p>
        </div>"""
new_admin_tag = """        <div class="admin-tag">
            <div class="admin-tag-dot"></div>
            <p style="font-size:9px;font-weight:800;color:#fca5a5;text-transform:uppercase;letter-spacing:0.15em;">Admin Control Panel</p>
        </div>"""
s_content = s_content.replace(saro_admin_tag, new_admin_tag)

# Extract the nav block from dashboard.php to use as a template for settings.php
with open(r'c:\xampp\htdocs\saro\admin\dashboard.php', 'r', encoding='utf-8') as f:
    d_content = f.read()
nav_match = re.search(r'<nav class="sidebar-nav">.*?</nav>', d_content, flags=re.DOTALL)
if nav_match:
    d_nav = nav_match.group(0)
    # Remove active class from dashboard and add to settings
    d_nav = d_nav.replace('class="nav-item active"', 'class="nav-item"')
    d_nav = d_nav.replace('<a href="settings.php" class="nav-item">', '<a href="settings.php" class="nav-item active">')
    
    # Now replace the nav in settings.php
    s_content = re.sub(r'<nav class="sidebar-nav">.*?</nav>', d_nav, s_content, flags=re.DOTALL)

with open(settings_path, 'w', encoding='utf-8') as f:
    f.write(s_content)
print("Updated settings.php")

