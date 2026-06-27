import re

with open('saro/lapsed_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove the standalone Delete cell
delete_td_pattern = r'''\s*<td style="text-align:center;">\s*<div style="display:flex;align-items:center;justify-content:center;gap:6px;">\s*<button class="icon-btn action-btn-del".*?</button>\s*</div>\s*</td>'''
content = re.sub(delete_td_pattern, '', content, flags=re.DOTALL)

# 2. Add Delete button into the existing Actions cell
delete_btn_html = """<button class="action-btn action-btn-del" data-id="<?= $s['saroId'] ?>" data-no="<?= $saroNoEsc ?>" title="Delete SARO" style="color:#dc2626;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>"""

target = """<?php endif; ?>\n                </div>"""
replacement = f"<?php endif; ?>\n                  {delete_btn_html}\n                </div>"
content = content.replace(target, replacement)

# 3. Update colspan
content = re.sub(r'colspan="\d+"', 'colspan="9"', content)

with open('saro/lapsed_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)
print("Done laps")
