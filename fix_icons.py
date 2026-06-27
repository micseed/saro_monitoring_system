import re

def fix_buttons_and_css(filename, has_valid_until=True):
    with open(filename, 'r', encoding='utf-8') as f:
        content = f.read()

    # Add CSS for action-btn if not present
    if '.action-btn' not in content:
        css_to_add = """
.action-btn {
    width: 30px; height: 30px; border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid transparent; cursor: pointer;
    background: transparent; transition: all 0.2s ease;
}
.action-btn-del { color: #94a3b8; }
.action-btn-del:hover { background: #fee2e2; border-color: #fecaca; color: #dc2626; }
"""
        content = content.replace('</style>', f'{css_to_add}</style>')

    # Replace the old inline-styled button with the clean one
    # We use regex to match the button with class "icon-btn action-btn-del" or similar
    pattern = re.compile(r'<button class="icon-btn action-btn-del".*?><svg.*?></svg></button>')
    
    clean_button = """<button class="action-btn action-btn-del" data-id="<?= $s['saroId'] ?>" data-no="<?= htmlspecialchars($s['saroNo'], ENT_QUOTES) ?>" title="Delete SARO"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>"""
    
    content = pattern.sub(clean_button, content)
    
    with open(filename, 'w', encoding='utf-8') as f:
        f.write(content)

fix_buttons_and_css('saro/obligated_saro.php', True)
fix_buttons_and_css('saro/lapsed_saro.php', True)
fix_buttons_and_css('saro/cancelled_saro.php', False)

# For cancelled_saro.php, let's also add the Created By column
with open('saro/cancelled_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Headers
if '<th>Created By</th>' not in content and '<th style="text-align:center;">Created By</th>' not in content:
    content = content.replace('<th style="text-align:center;">Status</th>', '<th style="text-align:center;">Created By</th>\n                                  <th style="text-align:center;">Status</th>')

# Body
if '$s[\'owner_name\']' not in content:
    td_created = '<td style="text-align:center;"><span style="font-size:11px;color:#64748b;"><?= htmlspecialchars($s[\'owner_name\'] ?? \'—\') ?></span></td>\n                                  '
    content = re.sub(r'(<td style="text-align:center;">\s*<span class="badge)', td_created + r'\1', content)

# Colspan
content = content.replace('colspan="8"', 'colspan="9"')
content = content.replace('colspan="7"', 'colspan="8"') # In case it was 7

with open('saro/cancelled_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Fixed delete icons and added Created By to cancelled_saro.")
