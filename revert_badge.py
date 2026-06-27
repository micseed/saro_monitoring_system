import re

owner_badge = """<?php else: ?>
                                    <span style="font-size:10px;font-weight:600;color:#94a3b8;background:#f8fafc;padding:4px 8px;border-radius:6px;border:1px dashed #cbd5e1;white-space:nowrap;" title="Only the owner can control this SARO">Owner Only</span>
"""

lapsed_badge = """<?php else: ?>
                  <span style="font-size:10px;font-weight:600;color:#94a3b8;background:#f8fafc;padding:4px 8px;border-radius:6px;border:1px dashed #cbd5e1;white-space:nowrap;margin-left:2px;" title="Only the owner can control this SARO">Owner Only</span>
"""

for path in ['saro/cancelled_saro.php', 'saro/obligated_saro.php']:
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace(owner_badge + '                                    <?php endif; ?>', '<?php endif; ?>')
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

with open('saro/lapsed_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()
content = content.replace(lapsed_badge + '                  <?php endif; ?>', '<?php endif; ?>')
with open('saro/lapsed_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Reverted accidental replacements")
