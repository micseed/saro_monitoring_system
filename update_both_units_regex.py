import re

with open('saro/view_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

pattern_proc = r'(<select class="form-input" id="proc-unit">).*?(</select>)'
pattern_ep = r'(<select class="form-input" id="ep-unit">).*?(</select>)'

replacement_proc = r"""\1
                        <option value="">- None -</option>
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                        <option>Pack</option><option>Trip</option><option>Batch</option>
                        <option>Session</option><option>Pax</option><option>Day</option>
                        <option>Ream</option><option>Box</option>
                    \2"""

replacement_ep = r"""\1
                        <option value="">- None -</option>
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                        <option>Pack</option><option>Trip</option><option>Batch</option>
                        <option>Session</option><option>Pax</option><option>Day</option>
                        <option>Ream</option><option>Box</option>
                    \2"""

content = re.sub(pattern_proc, replacement_proc, content, flags=re.DOTALL)
content = re.sub(pattern_ep, replacement_ep, content, flags=re.DOTALL)

with open('saro/view_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Updated both proc-unit and ep-unit dropdowns with regex")
