import re

with open('saro/cancelled_saro.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Add POST Handler
post_handler = """
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['saro_id'])) {
    $action = $_POST['action'];
    $sId    = (int)$_POST['saro_id'];
    if ($action === 'delete') {
        $saroObj->deleteSaro($sId, $userId);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'uncancel') {
        $saroObj->uncancelSaro($sId, $userId);
        $saroObj->checkAndAutoUpdateStatus($userId);
        echo json_encode(['success' => true]);
        exit;
    }
}
"""
content = content.replace('$allSaros       = $saroObj->getAllSaros();', post_handler + '\n$allSaros       = $saroObj->getAllSaros();')

# 2. Add Actions Header
content = content.replace('<th style="text-align:center;">Status</th>', '<th style="text-align:center;">Status</th>\n                                <th style="text-align:center;">Actions</th>')

# 3. Increase colspan
content = content.replace('colspan="6"', 'colspan="7"')

# 4. Add Actions Column
actions_td = """<td style="text-align:center;">
                                    <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                        <button class="icon-btn action-btn-uncancel" data-id="<?= $s['saroId'] ?>" data-no="<?= $saroNoEsc ?>" title="Restore SARO" style="width:28px;height:28px;background:#f0fdf4;border-color:#bbf7d0;color:#16a34a;" onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg></button>
                                        <button class="icon-btn action-btn-del" data-id="<?= $s['saroId'] ?>" data-no="<?= $saroNoEsc ?>" title="Delete SARO" style="width:28px;height:28px;background:#fef2f2;border-color:#fecaca;color:#dc2626;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
                                    </div>
                                </td>"""
content = content.replace('<td style="text-align:center;"><span class="badge badge-amber"><span class="badge-dot"></span>Cancelled</span></td>', '<td style="text-align:center;"><span class="badge badge-amber"><span class="badge-dot"></span>Cancelled</span></td>\n                                ' + actions_td)

# 5. Add Modals and Javascript
modals_and_js = """
<!-- Uncancel Modal -->
<div id="uncancelModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.4);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:#fff;border-radius:20px;width:100%;max-width:380px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1);overflow:hidden;animation:modalIn 0.2s ease-out;">
        <div style="padding:28px 28px 20px;">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" fill="none" stroke="#16a34a" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16px;font-weight:800;color:#0f172a;letter-spacing:-0.01em;">Restore SARO</h3>
                    <p style="font-size:12px;color:#94a3b8;font-weight:500;">Uncancel and restore to active</p>
                </div>
            </div>
            <p style="font-size:13px;color:#475569;line-height:1.5;margin-bottom:6px;">Are you sure you want to restore SARO <strong style="color:#0f172a;" id="uncancel-saro-label"></strong>?</p>
            <input type="hidden" id="uncancel-saro-id">
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="document.getElementById('uncancelModal').style.display='none'" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;">Cancel</button>
            <button id="confirmUncancelBtn" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;color:#fff;background:#16a34a;border:none;cursor:pointer;display:flex;align-items:center;gap:6px;">Restore</button>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.4);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:#fff;border-radius:20px;width:100%;max-width:380px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1),0 8px 10px -6px rgba(0,0,0,0.1);overflow:hidden;animation:modalIn 0.2s ease-out;">
        <div style="padding:28px 28px 20px;">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">
                <div style="width:42px;height:42px;border-radius:12px;background:#fef2f2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="20" height="20" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </div>
                <div>
                    <h3 style="font-size:16px;font-weight:800;color:#0f172a;letter-spacing:-0.01em;">Delete SARO</h3>
                    <p style="font-size:12px;color:#94a3b8;font-weight:500;">This action cannot be undone</p>
                </div>
            </div>
            <p style="font-size:13px;color:#475569;line-height:1.5;margin-bottom:6px;">Are you sure you want to permanently delete SARO <strong style="color:#0f172a;" id="delete-saro-label"></strong>?</p>
            <input type="hidden" id="delete-saro-id">
        </div>
        <div style="padding:16px 28px;border-top:1px solid #f1f5f9;background:#fafbfe;display:flex;align-items:center;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="document.getElementById('deleteModal').style.display='none'" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:600;color:#64748b;border:none;background:none;cursor:pointer;">Cancel</button>
            <button id="confirmDeleteBtn" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;color:#fff;background:#dc2626;border:none;cursor:pointer;display:flex;align-items:center;gap:6px;">Delete</button>
        </div>
    </div>
</div>
<style>@keyframes modalIn { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }</style>

<script>
    document.querySelectorAll('.action-btn-uncancel').forEach(btn => {
        btn.onclick = () => {
            document.getElementById('uncancel-saro-id').value = btn.dataset.id;
            document.getElementById('uncancel-saro-label').textContent = btn.dataset.no;
            document.getElementById('uncancelModal').style.display = 'flex';
        };
    });
    
    document.getElementById('confirmUncancelBtn').onclick = () => {
        const fd = new FormData();
        fd.append('action', 'uncancel');
        fd.append('saro_id', document.getElementById('uncancel-saro-id').value);
        fetch('cancelled_saro.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if(res.success) location.reload(); else alert('Error restoring SARO'); });
    };

    document.querySelectorAll('.action-btn-del').forEach(btn => {
        btn.onclick = () => {
            document.getElementById('delete-saro-id').value = btn.dataset.id;
            document.getElementById('delete-saro-label').textContent = btn.dataset.no;
            document.getElementById('deleteModal').style.display = 'flex';
        };
    });
    
    document.getElementById('confirmDeleteBtn').onclick = () => {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('saro_id', document.getElementById('delete-saro-id').value);
        fetch('cancelled_saro.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { if(res.success) location.reload(); else alert('Error deleting SARO'); });
    };
</script>
"""

content = content.replace('</body>', modals_and_js + '\n</body>')

with open('saro/cancelled_saro.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Done")
