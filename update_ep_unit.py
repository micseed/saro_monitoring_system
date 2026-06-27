with open('saro/view_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

target = """                    <select class="form-input" id="ep-unit">
                        <option value="">- None -</option>
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                        
                    </select>"""

replacement = """                    <select class="form-input" id="ep-unit">
                        <option value="">- None -</option>
                        <option>Unit</option><option>Lot</option><option>Set</option>
                        <option>Month</option><option>Year</option><option>Piece</option>
                        <option>Pack</option><option>Trip</option><option>Batch</option>
                        <option>Session</option><option>Pax</option><option>Day</option>
                        <option>Ream</option><option>Box</option>
                    </select>"""

content = content.replace(target, replacement)

with open('saro/view_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Updated ep-unit dropdown options")
