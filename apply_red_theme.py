import re
import os

file_path = r'c:\xampp\htdocs\saro\admin\settings_admin.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

replacements = {
    '#1e3a8a': '#7f1d1d',
    '#1e40af': '#991b1b',
    '#1d4ed8': '#b91c1c',
    '#2563eb': '#dc2626',
    '#3b82f6': '#ef4444',
    '#60a5fa': '#f87171',
    '#93c5fd': '#fca5a5',
    '#bfdbfe': '#fecaca',
    '#eff6ff': '#fef2f2',
    '#f5f8ff': '#fef2f2',
    'rgba(59,130,246,': 'rgba(239,68,68,',
    'rgba(29,78,216,': 'rgba(185,28,28,'
}

for old, new in replacements.items():
    content = content.replace(old, new)
    content = content.replace(old.upper(), new)

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Red theme applied successfully.")
