with open('saro/view_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update backend SQL
old_sql = """$conn->prepare("UPDATE object_code SET code=?,projected_cost=? WHERE objectId=?")->execute([$newCode, (float)($_POST['projected_cost']??0), $oid]);"""
new_sql = """$isTravel = (int)($_POST['is_travelExpense'] ?? 0) === 1 ? 1 : 0;
            $conn->prepare("UPDATE object_code SET code=?,projected_cost=?,is_travelExpense=? WHERE objectId=?")->execute([$newCode, (float)($_POST['projected_cost']??0), $isTravel, $oid]);"""
content = content.replace(old_sql, new_sql)

# 2. Add isTravel parameter to openEditObjModal function call
old_call = """onclick="openEditObjModal(<?= $obj['objectId'] ?>,'<?= htmlspecialchars($obj['code'],ENT_QUOTES) ?>','<?= $obj['projected_cost'] ?>','<?= htmlspecialchars($obj['expense_items']??'',ENT_QUOTES) ?>')">"""
new_call = """onclick="openEditObjModal(<?= $obj['objectId'] ?>,'<?= htmlspecialchars($obj['code'],ENT_QUOTES) ?>','<?= $obj['projected_cost'] ?>','<?= htmlspecialchars($obj['expense_items']??'',ENT_QUOTES) ?>', <?= (int)$obj['is_travelExpense'] ?>)">"""
content = content.replace(old_call, new_call)

# 3. Update openEditObjModal javascript
old_func_def = "function openEditObjModal(objectId, code, cost, item) {"
new_func_def = "function openEditObjModal(objectId, code, cost, item, isTravel) {"
content = content.replace(old_func_def, new_func_def)

old_set_item = "setVal('edit-obj-expense-item', item || '');"
new_set_item = "setVal('edit-obj-expense-item', item || '');\n        document.getElementById('edit-obj-travel').checked = (isTravel === 1);"
content = content.replace(old_set_item, new_set_item)

# 4. Update saveEditObj javascript
old_post_ajax = """        postAjax({
            ajax_action: 'edit_object_code', saroId: currentSaroId, objectId,
            code: sanitize(getVal('edit-obj-code')), projected_cost: getVal('edit-obj-cost'),
            expense_item: sanitize(getVal('edit-obj-expense-item')),
        })"""
new_post_ajax = """        postAjax({
            ajax_action: 'edit_object_code', saroId: currentSaroId, objectId,
            code: sanitize(getVal('edit-obj-code')), projected_cost: getVal('edit-obj-cost'),
            expense_item: sanitize(getVal('edit-obj-expense-item')),
            is_travelExpense: document.getElementById('edit-obj-travel').checked ? 1 : 0
        })"""
content = content.replace(old_post_ajax, new_post_ajax)

# 5. Add checkbox HTML into editObjModal
old_html = """            <div>
                <label class="form-label">Expense Item</label>
                <input type="text" class="form-input" id="edit-obj-expense-item" placeholder="Enter expense item description...">
            </div>
        </div>"""
new_html = """            <div>
                <label class="form-label">Expense Item</label>
                <input type="text" class="form-input" id="edit-obj-expense-item" placeholder="Enter expense item description...">
            </div>
            <div style="margin-top:12px;">
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;">
                    <input type="checkbox" id="edit-obj-travel" style="width:14px;height:14px;accent-color:#3b82f6;cursor:pointer;"> 
                    Travel Expense
                </label>
            </div>
        </div>"""
content = content.replace(old_html, new_html)

with open('saro/view_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Updated edit object code with travel checkbox")
