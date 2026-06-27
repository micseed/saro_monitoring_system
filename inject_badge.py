import re

# 1. cancelled_saro.php
with open('saro/cancelled_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

target_cancelled = """                                    </div>\n                                    <?php endif; ?>\n                                </td>"""
replacement_cancelled = """                                    </div>\n                                    <?php else: ?>\n                                    <span style="font-size:10px;font-weight:600;color:#94a3b8;background:#f8fafc;padding:4px 8px;border-radius:6px;border:1px dashed #cbd5e1;white-space:nowrap;" title="Only the owner can control this SARO">Owner Only</span>\n                                    <?php endif; ?>\n                                </td>"""
content = content.replace(target_cancelled, replacement_cancelled)

with open('saro/cancelled_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

# 2. obligated_saro.php
with open('saro/obligated_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()
    
target_obligated = """                                    </div>\n                                    <?php endif; ?>\n                                </td>"""
content = content.replace(target_obligated, replacement_cancelled) # Same replacement text

with open('saro/obligated_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

# 3. lapsed_saro.php
with open('saro/lapsed_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

target_lapsed = """                  </button>\n                  <?php endif; ?>\n                </div>"""
replacement_lapsed = """                  </button>\n                  <?php else: ?>\n                  <span style="font-size:10px;font-weight:600;color:#94a3b8;background:#f8fafc;padding:4px 8px;border-radius:6px;border:1px dashed #cbd5e1;white-space:nowrap;margin-left:2px;" title="Only the owner can control this SARO">Owner Only</span>\n                  <?php endif; ?>\n                </div>"""
content = content.replace(target_lapsed, replacement_lapsed)

with open('saro/lapsed_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Successfully injected Owner Only badges carefully!")
