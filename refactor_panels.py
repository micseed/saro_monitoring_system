import re

file_path = r'c:\xampp\htdocs\saro\admin\password_requests.php'
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Add PHP variables for $pendingRequests and $resolvedRequests after the query
query_end_index = content.find(')->fetchAll();') + len(')->fetchAll();')
php_add = """
$pendingRequests = array_filter($requests, fn($r) => $r['status'] === 'pending');
$resolvedRequests = array_filter($requests, fn($r) => $r['status'] !== 'pending');
"""
content = content[:query_end_index] + php_add + content[query_end_index:]

# 2. Extract the table row template
row_template_match = re.search(r'<\?php foreach \(\$requests as \$idx => \$req\):(.*?)(?:<\?php endforeach; \?>)', content, flags=re.DOTALL)
if not row_template_match:
    print("Could not find row template")
    exit(1)

row_template = row_template_match.group(0)

# Replace `$requests` with `$pendingRequests` for the first table
pending_row_template = row_template.replace('foreach ($requests as', 'foreach ($pendingRequests as')

# Replace `$requests` with `$resolvedRequests` for the second table
resolved_row_template = row_template.replace('foreach ($requests as', 'foreach ($resolvedRequests as')


# 3. Build new HTML structure
panel_start = content.find('<!-- Requests Panel -->')
panel_end = content.find('</div><!-- /content -->')

new_html = f"""<!-- Requests Panel: Pending -->
            <div class="panel" id="pending-panel">
                <div class="panel-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#fffbeb;display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#d97706" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">Pending Password Requests</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">Action required on these requests</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="search-wrap">
                            <svg class="search-icon" width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" class="search-input" id="pendingSearch" placeholder="Search pending...">
                        </div>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th style="min-width:180px;">User</th>
                                <th style="text-align:center;">Role</th>
                                <th style="min-width:120px;">Request Date</th>
                                <th style="min-width:160px;">Reason</th>
                                <th style="text-align:center;">Status</th>
                                <th style="min-width:120px;">Resolved By</th>
                                <th style="text-align:center;min-width:160px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingTbody">
                            <?php if (empty($pendingRequests)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:24px;color:#94a3b8;font-size:13px;">No pending password requests.</td></tr>
                            <?php else: ?>
                            {pending_row_template}
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel-footer">
                    <div class="show-rows-wrap">
                        <span>Show</span>
                        <select class="show-rows-select" id="pendingRows">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                        rows
                    </div>
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Displaying <strong id="pending-count" style="color:#475569;"><?= count($pendingRequests) ?></strong> request<?= count($pendingRequests) !== 1 ? 's' : '' ?></p>
                </div>
            </div>

            <!-- Requests Panel: Resolved -->
            <div class="panel" id="resolved-panel" style="margin-top:24px;">
                <div class="panel-header">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;">
                            <svg width="15" height="15" fill="none" stroke="#16a34a" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p style="font-size:13px;font-weight:800;color:#0f172a;">Resolved Password Requests</p>
                            <p style="font-size:10px;color:#94a3b8;font-weight:500;">History of approved and rejected requests</p>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="filter-tabs" id="resolvedFilterTabs">
                            <button type="button" class="filter-tab active" data-filter="all">All</button>
                            <button type="button" class="filter-tab" data-filter="approved">Approved</button>
                            <button type="button" class="filter-tab" data-filter="rejected">Rejected</button>
                        </div>
                        <div class="search-wrap">
                            <svg class="search-icon" width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" class="search-input" id="resolvedSearch" placeholder="Search history...">
                        </div>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th style="min-width:180px;">User</th>
                                <th style="text-align:center;">Role</th>
                                <th style="min-width:120px;">Request Date</th>
                                <th style="min-width:160px;">Reason</th>
                                <th style="text-align:center;">Status</th>
                                <th style="min-width:120px;">Resolved By</th>
                                <th style="text-align:center;min-width:160px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="resolvedTbody">
                            <?php if (empty($resolvedRequests)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:24px;color:#94a3b8;font-size:13px;">No resolved password requests found.</td></tr>
                            <?php else: ?>
                            {resolved_row_template}
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel-footer">
                    <div class="show-rows-wrap">
                        <span>Show</span>
                        <select class="show-rows-select" id="resolvedRows">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                        rows
                    </div>
                    <p style="font-size:11px;color:#94a3b8;font-weight:500;">Displaying <strong id="resolved-count" style="color:#475569;"><?= count($resolvedRequests) ?></strong> request<?= count($resolvedRequests) !== 1 ? 's' : '' ?></p>
                </div>
            </div>
"""

content = content[:panel_start] + new_html + "\n        " + content[panel_end:]

# 4. Update the Javascript block for filtering
js_start = content.find('(function () {\\n    const allRows  = Array.from(document.querySelectorAll(\\\'.req-row\\\'));')
if js_start == -1:
    js_start = content.find('(function () {\\n    const allRows  = Array.from(document.querySelectorAll')
    if js_start == -1:
        # Fallback using regex to find the start of the IIFE handling filter
        m = re.search(r'\(function \(\) \{\s*const allRows\s*=\s*Array\.from\(document\.querySelectorAll', content)
        if m:
            js_start = m.start()

if js_start != -1:
    js_end = content.find('})();', js_start) + 5
    new_js = """(function () {
    // Pending
    const pendingRows  = Array.from(document.querySelectorAll('#pendingTbody .req-row'));
    const pendingSearch = document.getElementById('pendingSearch');
    const pendingRowsSel  = document.getElementById('pendingRows');
    const pendingCount  = document.getElementById('pending-count');

    function applyPending() {
        const q     = pendingSearch ? pendingSearch.value.trim().toLowerCase() : '';
        const limit = pendingRowsSel ? (parseInt(pendingRowsSel.value, 10) || 10) : 10;
        let shown = 0;
        pendingRows.forEach(function (row) {
            const searchMatch = !q || row.textContent.toLowerCase().includes(q);
            const show = searchMatch && shown < limit;
            row.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        if (pendingCount) pendingCount.textContent = shown;
    }
    if (pendingSearch) pendingSearch.addEventListener('input', applyPending);
    if (pendingRowsSel)  pendingRowsSel.addEventListener('change', applyPending);
    applyPending();

    // Resolved
    const resolvedRows  = Array.from(document.querySelectorAll('#resolvedTbody .req-row'));
    const resolvedSearch = document.getElementById('resolvedSearch');
    const resolvedRowsSel  = document.getElementById('resolvedRows');
    const resolvedCount  = document.getElementById('resolved-count');
    const resolvedTabs     = document.querySelectorAll('#resolvedFilterTabs .filter-tab');
    let resolvedActiveFilter = 'all';

    function applyResolved() {
        const q     = resolvedSearch ? resolvedSearch.value.trim().toLowerCase() : '';
        const limit = resolvedRowsSel ? (parseInt(resolvedRowsSel.value, 10) || 10) : 10;
        let shown = 0;
        resolvedRows.forEach(function (row) {
            const statusMatch = resolvedActiveFilter === 'all' || row.dataset.status === resolvedActiveFilter;
            const searchMatch = !q || row.textContent.toLowerCase().includes(q);
            const show = statusMatch && searchMatch && shown < limit;
            row.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        if (resolvedCount) resolvedCount.textContent = shown;
    }
    resolvedTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            resolvedTabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            resolvedActiveFilter = tab.dataset.filter;
            applyResolved();
        });
    });
    if (resolvedSearch) resolvedSearch.addEventListener('input', applyResolved);
    if (resolvedRowsSel)  resolvedRowsSel.addEventListener('change', applyResolved);
    applyResolved();
})();"""
    content = content[:js_start] + new_js + content[js_end:]
else:
    print("Could not find JS block to update.")

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)
print("Done!")
