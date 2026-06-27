import re

with open('saro/view_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove required asterisk from HTML
# Look for: <label class="form-label">Procurement Date <span style="color:#dc2626;">*</span></label>
# Followed by ep-date
pattern_label = r'(<label class="form-label">Procurement Date) <span style="color:#dc2626;">\*</span>(</label>\s*<input type="date"[^>]*id="ep-date")'
content = re.sub(pattern_label, r'\1\2', content, flags=re.DOTALL)

# Remove oninput validation from ep-date
# oninput="if(this.value.trim()===''){ this.classList.add('input-error'); document.getElementById('err-ep-date').style.display='block'; } else { this.classList.remove('input-error'); document.getElementById('err-ep-date').style.display='none'; }"
pattern_oninput = r' oninput="if\(this\.value\.trim\(\)===''.*?style\.display=''none''; \}"'
content = re.sub(pattern_oninput, '', content)

# 2. Remove JS validation in saveEditProc()
pattern_js_validation = r"if \(\!getVal\('ep-date'\)\)\s*\{\s*setFieldError\('ep-date',\s*'err-ep-date',\s*'Procurement date is required!'\);\s*ok = false;\s*\}"
content = re.sub(pattern_js_validation, '', content)

# Write back
with open('saro/view_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Made ep-date optional in edit modal")
