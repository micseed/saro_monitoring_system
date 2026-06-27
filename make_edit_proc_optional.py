import re

with open('saro/view_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove required asterisk from HTML
# Look for: <label class="form-label">Procurement Date <span style="color:#dc2626;">*</span></label>
# Followed by edit-proc-date
pattern_label = r'(<label class="form-label">Procurement Date) <span style="color:#dc2626;">\*</span>(</label>\s*<input type="date"[^>]*id="edit-proc-date")'
content = re.sub(pattern_label, r'\1\2', content, flags=re.DOTALL)

# Also remove oninput validation from edit-proc-date
# oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-edit-proc-date').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-edit-proc-date').style.display='none'; }"
pattern_oninput = r' oninput="if\(this\.value\.trim\(\)===''.*?style\.display=''none''; \}"'
content = re.sub(pattern_oninput, '', content)

# 2. Remove JS validation in saveEditProc()
pattern_js_validation = r"if \(\!getVal\('edit-proc-date'\)\) \{ setFieldError\('edit-proc-date', 'err-edit-proc-date', 'Procurement date is required!'\); ok = false; \}\s*"
content = re.sub(pattern_js_validation, '', content)

# Write back
with open('saro/view_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Made procurement date optional in edit modal")
