import re

owner_badge = """<?php else: ?>
                                    <span style="font-size:10px;font-weight:600;color:#94a3b8;background:#f8fafc;padding:4px 8px;border-radius:6px;border:1px dashed #cbd5e1;white-space:nowrap;" title="Only the owner can control this SARO">Owner Only</span>
"""

# 1. cancelled_saro.php
with open('saro/cancelled_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()
if 'Owner Only' not in content:
    content = content.replace('<?php endif; ?>', owner_badge + '                                    <?php endif; ?>')
    with open('saro/cancelled_saro.php', 'w', encoding='utf-8') as f:
        f.write(content)

# 2. obligated_saro.php
with open('saro/obligated_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()
if 'Owner Only' not in content:
    content = content.replace('<?php endif; ?>', owner_badge + '                                    <?php endif; ?>')
    with open('saro/obligated_saro.php', 'w', encoding='utf-8') as f:
        f.write(content)

# 3. lapsed_saro.php
with open('saro/lapsed_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()
if 'Owner Only' not in content:
    lapsed_badge = """<?php else: ?>
                  <span style="font-size:10px;font-weight:600;color:#94a3b8;background:#f8fafc;padding:4px 8px;border-radius:6px;border:1px dashed #cbd5e1;white-space:nowrap;margin-left:2px;" title="Only the owner can control this SARO">Owner Only</span>
"""
    content = content.replace('<?php endif; ?>', lapsed_badge + '                  <?php endif; ?>')
    with open('saro/lapsed_saro.php', 'w', encoding='utf-8') as f:
        f.write(content)

print("Added Owner Only badges")
