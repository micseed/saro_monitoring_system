import re

# 1. cancelled_saro.php
with open('saro/cancelled_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Add $isOwner definition if not exists
if '$isOwner =' not in content:
    content = re.sub(
        r'(\$rowNum\s*=\s*str_pad\(\$i\+1,2,\'0\',STR_PAD_LEFT\);)',
        r"\1\n              $isOwner = (int)$s['userId'] === $userId;",
        content
    )

# Wrap buttons in if ($isOwner) if not already wrapped
if '<?php if ($isOwner): ?>\n                                    <div style="display:flex;align-items:center;justify-content:center;gap:6px;">' not in content:
    buttons = r"""(<div style="display:flex;align-items:center;justify-content:center;gap:6px;">\s*<button class="icon-btn action-btn-uncancel".*?</button>\s*<button class="icon-btn action-btn-del".*?</button>\s*</div>)"""
    content = re.sub(buttons, r'<?php if ($isOwner): ?>\n                                    \1\n                                    <?php endif; ?>', content, flags=re.DOTALL)

with open('saro/cancelled_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)


# 2. obligated_saro.php
with open('saro/obligated_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Add $isOwner definition if not exists
if '$isOwner =' not in content:
    content = re.sub(
        r'(\$rowNum\s*=\s*str_pad\(\$i\+1,2,\'0\',STR_PAD_LEFT\);)',
        r"\1\n              $isOwner = (int)$s['userId'] === $userId;",
        content
    )

# Wrap buttons in if ($isOwner) if not already wrapped
if '<?php if ($isOwner): ?>\n                                    <div style="display:flex;align-items:center;justify-content:center;gap:6px;">' not in content:
    buttons_oblig = r"""(<div style="display:flex;align-items:center;justify-content:center;gap:6px;">\s*<button class="icon-btn action-btn-del".*?</button>\s*</div>)"""
    content = re.sub(buttons_oblig, r'<?php if ($isOwner): ?>\n                                    \1\n                                    <?php endif; ?>', content, flags=re.DOTALL)

with open('saro/obligated_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)


# 3. lapsed_saro.php
with open('saro/lapsed_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Find the end of the extend button and move the endif down
# We need to swap:
#                     <?php endif; ?>
#                   <button class="action-btn action-btn-del" ... </button>
# to:
#                   <button class="action-btn action-btn-del" ... </button>
#                   <?php endif; ?>

swap_pattern = r"""(\s*<\?php endif; \?>)(\s*<button class="action-btn action-btn-del".*?</button>)"""
content = re.sub(swap_pattern, r"\2\1", content, flags=re.DOTALL)

with open('saro/lapsed_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Permissions fixed.")
