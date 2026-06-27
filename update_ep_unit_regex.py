import re

with open('saro/view_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

pattern = r'(<select class="form-input" id="ep-unit">).*?(</select>)'
replacement = r"""\1
                        <option value="">- None -</option>
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                        <option>Pack</option><option>Trip</option><option>Batch</option>
                        <option>Session</option><option>Pax</option><option>Day</option>
                        <option>Ream</option><option>Box</option>
                    \2"""

content = re.sub(pattern, replacement, content, flags=re.DOTALL)

with open('saro/view_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Updated ep-unit dropdown with regex")
