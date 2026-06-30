import re

dashboard_path = r'c:\xampp\htdocs\saro\admin\dashboard.php'
settings_path = r'c:\xampp\htdocs\saro\admin\settings_admin.php'

with open(dashboard_path, 'r', encoding='utf-8') as f:
    dashboard_content = f.read()

with open(settings_path, 'r', encoding='utf-8') as f:
    settings_content = f.read()

# 1. Extract and replace sidebar CSS
dash_css_pattern = r'/\* ── Sidebar ── \*/.*?(?=/\* ── Main ── \*/)'
dash_css_match = re.search(dash_css_pattern, dashboard_content, re.DOTALL)
if dash_css_match:
    dash_css = dash_css_match.group(0)
    
    settings_css_pattern = r'/\* -- Sidebar -- \*/.*?(?=/\* -- Main -- \*/)'
    settings_content = re.sub(settings_css_pattern, dash_css, settings_content, flags=re.DOTALL)
else:
    print("Dashboard sidebar CSS not found.")

# 2. Extract and replace sidebar HTML
dash_html_pattern = r'<aside class="sidebar">.*?</aside>'
dash_html_match = re.search(dash_html_pattern, dashboard_content, re.DOTALL)
if dash_html_match:
    dash_html = dash_html_match.group(0)
    
    settings_html_pattern = r'<aside class="sidebar">.*?</aside>'
    settings_content = re.sub(settings_html_pattern, dash_html, settings_content, flags=re.DOTALL)
else:
    print("Dashboard sidebar HTML not found.")

# 3. Adjust active classes and variables in settings_content
# Remove active from Dashboard
settings_content = settings_content.replace(
    '<a href="dashboard.php" class="nav-item active">', 
    '<a href="dashboard.php" class="nav-item">'
)

# Add active to Settings
settings_content = settings_content.replace(
    '<a href="settings_admin.php" class="nav-item">', 
    '<a href="settings_admin.php" class="nav-item active">'
)

# Update variable
settings_content = settings_content.replace(
    "$stats['pending_requests']", 
    "$pendingPwCount"
)

with open(settings_path, 'w', encoding='utf-8') as f:
    f.write(settings_content)

print("Successfully copied dashboard sidebar design to settings_admin.")
