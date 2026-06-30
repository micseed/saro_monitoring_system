import os
import re

base_dir = r'c:\xampp\htdocs\saro\admin'
old_settings = os.path.join(base_dir, 'settings.php')
new_settings = os.path.join(base_dir, 'settings_admin.php')

# 1. Rename the file
if os.path.exists(old_settings):
    os.rename(old_settings, new_settings)
    print(f"Renamed {old_settings} to {new_settings}")

# 2. Update references in all admin PHP files
admin_files = ['activity_logs.php', 'dashboard.php', 'export_records.php', 'password_requests.php', 'users.php', 'settings_admin.php']

for f in admin_files:
    path = os.path.join(base_dir, f)
    if os.path.exists(path):
        with open(path, 'r', encoding='utf-8') as file:
            content = file.read()
        
        # Replace href="settings.php" with href="settings_admin.php"
        content = content.replace('href="settings.php"', 'href="settings_admin.php"')
        
        # For settings_admin.php specifically, replace internal action references
        if f == 'settings_admin.php':
            content = content.replace('action="settings.php"', 'action="settings_admin.php"')
            content = content.replace("Location: settings.php", "Location: settings_admin.php")
            
        with open(path, 'w', encoding='utf-8') as file:
            file.write(content)
        print(f"Updated references in {f}")
