<?php
/**
 * includes/notif_dropdown.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Unified notification bell + dropdown include.
 * Include this file inside the topbar of any admin/ or saro/ page.
 *
 * Expected variables already set by the calling page:
 *   $notifications   — array from $notifObj->getRecentActivity($userId, 10)
 *   $unreadCount     — int from $notifObj->countUnread($userId)
 *   $isAdmin         — bool  (true for admin/ pages, false for saro/ pages)
 *   $pendingPwCount  — int   (admin pages: pending password requests count; else 0)
 *   $approvedPwReq   — array|null (saro pages: approved-but-unapplied pw request)
 */
$isAdmin        = $isAdmin        ?? false;
$pendingPwCount = $pendingPwCount ?? 0;
$approvedPwReq  = $approvedPwReq  ?? null;

// Total badge count visible to this user
$specialCount = $isAdmin ? $pendingPwCount : ($approvedPwReq ? 1 : 0);
$totalBadge   = $unreadCount + $specialCount;

// Handler URL — absolute root-relative so it works from any page (saro/, admin/, etc.)
$handlerUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
// Go up one level from saro/ or admin/ to reach /saro root, then into includes/
$handlerUrl = preg_replace('#/(saro|admin)$#', '', $handlerUrl) . '/includes/notif_handler.php';
?>

<?php /* ── Scoped CSS — injected once per page ── */ ?>
<style>
/* ── Notification Bell ── */
.nf-btn {
    width:36px;height:36px;border-radius:9px;
    background:#f8fafc;border:1px solid #e2e8f0;
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;color:#64748b;position:relative;
    transition:all .2s ease;
}
.nf-btn:hover { border-color:#3b82f6;color:#2563eb;background:#eff6ff; }
<?php if ($isAdmin): ?>
.nf-btn:hover { border-color:#ef4444;color:#dc2626;background:#fef2f2; }
<?php endif; ?>

/* Badge */
.nf-badge {
    position:absolute;top:-5px;right:-5px;
    min-width:18px;height:18px;
    background:#ef4444;border-radius:99px;border:2px solid #fff;
    font-size:9px;font-weight:800;color:#fff;
    display:flex;align-items:center;justify-content:center;padding:0 4px;
    transition:opacity .3s ease, transform .3s ease;
    pointer-events:none;
}
.nf-badge.fade-out { opacity:0; transform:scale(0.5); }

/* Dropdown container */
.nf-dropdown {
    display:none;position:absolute;top:calc(100% + 10px);right:0;
    width:340px;background:#fff;
    border:1px solid #e2e8f0;border-radius:14px;
    box-shadow:0 20px 50px rgba(0,0,0,0.13);
    z-index:9999;overflow:hidden;
    animation:nfSlideIn .18s ease;
}
@keyframes nfSlideIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }

/* Dropdown header */
.nf-header {
    padding:13px 16px;border-bottom:1px solid #f1f5f9;
    display:flex;align-items:center;justify-content:space-between;
}
.nf-header-title { font-size:13px;font-weight:800;color:#0f172a; }
.nf-mark-all {
    font-size:11px;font-weight:700;color:#3b82f6;
    background:none;border:none;cursor:pointer;padding:3px 6px;
    border-radius:5px;transition:background .15s,color .15s;
}
.nf-mark-all:hover { background:#eff6ff;color:#1d4ed8; }
<?php if ($isAdmin): ?>
.nf-mark-all:hover { background:#fef2f2;color:#dc2626; }
.nf-mark-all { color:#ef4444; }
<?php endif; ?>

/* List body */
.nf-list { max-height:310px;overflow-y:auto; }
.nf-list::-webkit-scrollbar { width:4px; }
.nf-list::-webkit-scrollbar-thumb { background:#e2e8f0;border-radius:4px; }

/* Empty state */
.nf-empty { padding:28px 16px;text-align:center;color:#94a3b8;font-size:12px;font-weight:500; }

/* Notification row */
.nf-row {
    display:flex;align-items:flex-start;gap:10px;
    padding:11px 14px;border-bottom:1px solid #f8fafc;
    text-decoration:none;position:relative;
    transition:background .15s;
    background:#fff;
}
.nf-row:hover { background:#f5f8ff; }
.nf-row.nf-unread {
    background:#f0f7ff;
    border-left:3px solid #3b82f6;
    padding-left:11px;
}
.nf-row.nf-unread:hover { background:#e6f0fd; }
.nf-row.nf-special {
    background:#f0fdf4;border-left:3px solid #16a34a;padding-left:11px;
}
.nf-row.nf-special:hover { background:#dcfce7; }
.nf-row.removing {
    transition:opacity .25s,transform .25s,max-height .3s,padding .3s;
    opacity:0;transform:translateX(16px);max-height:0;
    padding-top:0;padding-bottom:0;overflow:hidden;
}

/* Row icon */
.nf-icon {
    width:30px;height:30px;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    flex-shrink:0;margin-top:1px;
}

/* Row text */
.nf-text { flex:1;min-width:0; }
.nf-text-main {
    font-size:11px;font-weight:600;color:#0f172a;
    line-height:1.45;word-break:break-word;
}
.nf-text-sub { font-size:10px;color:#94a3b8;margin-top:2px;font-weight:500; }
.nf-unread .nf-text-main { font-weight:700; }

/* Dismiss button */
.nf-dismiss {
    width:20px;height:20px;border-radius:5px;
    border:none;background:none;cursor:pointer;
    color:#cbd5e1;display:flex;align-items:center;justify-content:center;
    flex-shrink:0;transition:background .15s,color .15s;
    margin-top:1px;padding:0;
}
.nf-dismiss:hover { background:#fee2e2;color:#dc2626; }

/* View all footer */
.nf-footer {
    display:block;padding:10px 16px;text-align:center;
    font-size:11px;font-weight:700;text-decoration:none;
    border-top:1px solid #f1f5f9;background:#fafbfe;
    color:#3b82f6;transition:background .15s;
}
.nf-footer:hover { background:#eff6ff; }
<?php if ($isAdmin): ?>
.nf-footer { color:#dc2626; }
.nf-footer:hover { background:#fef2f2; }
<?php endif; ?>

/* Unread dot (fallback when no count) */
.nf-dot {
    position:absolute;top:7px;right:7px;
    width:7px;height:7px;background:#ef4444;
    border-radius:50%;border:1.5px solid #fff;
}
</style>

<div style="position:relative;" id="notifWrap">
    <?php /* ── Bell button ── */ ?>
    <button class="nf-btn" id="notifBtn" type="button" aria-label="Notifications" onclick="nfToggle(event)">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <?php if ($totalBadge > 0): ?>
        <span class="nf-badge" id="nfBadge"><?= min($totalBadge, 99) ?></span>
        <?php else: ?>
        <span class="nf-dot" id="nfDot"></span>
        <?php endif; ?>
    </button>

    <?php /* ── Dropdown ── */ ?>
    <div class="nf-dropdown" id="notifDropdown">

        <?php /* Header */ ?>
        <div class="nf-header">
            <span class="nf-header-title">
                Notifications
                <?php if ($totalBadge > 0): ?>
                <span id="nfCountPill" style="display:inline-flex;align-items:center;justify-content:center;background:#ef4444;color:#fff;font-size:9px;font-weight:800;min-width:17px;height:17px;border-radius:99px;padding:0 4px;margin-left:5px;"><?= min($totalBadge,99) ?></span>
                <?php endif; ?>
            </span>
            <?php if (!empty($notifications) || $unreadCount > 0): ?>
            <button class="nf-mark-all" id="nfMarkAll" onclick="nfMarkAll()">✓ Mark all read</button>
            <?php endif; ?>
        </div>

        <?php /* List */ ?>
        <div class="nf-list" id="nfList">

            <?php /* ── Admin: pending password requests ── */ ?>
            <?php if ($isAdmin && $pendingPwCount > 0): ?>
            <a href="password_requests.php" class="nf-row nf-special">
                <div class="nf-icon" style="background:#dcfce7;">
                    <svg width="14" height="14" fill="none" stroke="#16a34a" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <div class="nf-text">
                    <p class="nf-text-main"><?= $pendingPwCount ?> Pending Password Request<?= $pendingPwCount !== 1 ? 's' : '' ?></p>
                    <p class="nf-text-sub">Requires admin approval</p>
                </div>
            </a>
            <?php endif; ?>

            <?php /* ── Saro: approved password notification ── */ ?>
            <?php if (!$isAdmin && $approvedPwReq): ?>
            <a href="settings.php?apply=1" class="nf-row nf-special">
                <div class="nf-icon" style="background:#dcfce7;">
                    <span style="font-size:14px;font-weight:900;color:#16a34a;">✓</span>
                </div>
                <div class="nf-text">
                    <p class="nf-text-main" style="color:#15803d;">Your password change has been approved</p>
                    <p class="nf-text-sub">Click to go to Settings and apply</p>
                </div>
            </a>
            <?php endif; ?>

            <?php /* ── Activity notifications ── */ ?>
            <?php if (empty($notifications) && !($isAdmin && $pendingPwCount > 0) && !(!$isAdmin && $approvedPwReq)): ?>
            <div class="nf-empty">
                <svg width="28" height="28" fill="none" stroke="#cbd5e1" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                No notifications yet
            </div>
            <?php else: ?>
            <?php foreach ($notifications as $n):
                $actor  = trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? '')) ?: 'System';
                $detail = htmlspecialchars($n['details'] ?? ucfirst($n['action']));
                $ts     = strtotime($n['created_at'] ?? 'now');
                $diff   = time() - $ts;
                $ago    = $diff < 60 ? 'just now' : ($diff < 3600 ? floor($diff/60).'m ago' : ($diff < 86400 ? floor($diff/3600).'h ago' : date('M d', $ts)));
                $isRead = (bool)(int)($n['is_read'] ?? 0);
                $logId  = (int)$n['logId'];

                // Link
                if (in_array($n['action'], ['delete','cancelled'])) {
                    $link = $isAdmin ? 'activity_logs.php' : 'audit_logs.php';
                } elseif ($n['affected_table'] === 'saro' && !empty($n['record_id'])) {
                    $link = ($isAdmin ? '' : '') . 'view_saro.php?id=' . $n['record_id'];
                } elseif ($n['affected_table'] === 'procurement') {
                    $link = 'procurement_stat.php';
                } else {
                    $link = $isAdmin ? 'activity_logs.php' : 'audit_logs.php';
                }

                // Icon colours per action
                $iconMap = [
                    'create'    => ['bg' => '#dcfce7', 'clr' => '#16a34a', 'lbl' => '+'],
                    'edit'      => ['bg' => '#fef9c3', 'clr' => '#b45309', 'lbl' => '✎'],
                    'delete'    => ['bg' => '#fee2e2', 'clr' => '#dc2626', 'lbl' => '✕'],
                    'cancelled' => ['bg' => '#fee2e2', 'clr' => '#dc2626', 'lbl' => '⊘'],
                ];
                $ic = $iconMap[$n['action']] ?? ['bg' => '#dbeafe', 'clr' => '#2563eb', 'lbl' => '·'];
            ?>
            <div class="nf-row <?= $isRead ? '' : 'nf-unread' ?>" id="nfRow-<?= $logId ?>" style="cursor:pointer;" onclick="window.location='<?= htmlspecialchars($link) ?>'">
                <div class="nf-icon" style="background:<?= $ic['bg'] ?>;">
                    <span style="font-size:13px;font-weight:900;color:<?= $ic['clr'] ?>;"><?= $ic['lbl'] ?></span>
                </div>
                <div class="nf-text">
                    <p class="nf-text-main"><?= $detail ?></p>
                    <p class="nf-text-sub"><?= htmlspecialchars($actor) ?> · <?= $ago ?></p>
                </div>
                <button class="nf-dismiss" title="Dismiss" onclick="event.stopPropagation();nfDismiss(<?= $logId ?>,this)">
                    <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php /* Footer */ ?>
        <a class="nf-footer" href="<?= $isAdmin ? 'activity_logs.php' : 'audit_logs.php' ?>">View all activity logs →</a>
    </div>
</div>

<?php /* ── Shared notification JS (injected once per page) ── */ ?>
<script>
(function(){
    const HANDLER = '<?= $handlerUrl ?>';
    let nfOpen = false;

    /* Toggle open/close */
    window.nfToggle = function(e) {
        e.stopPropagation();
        const dd = document.getElementById('notifDropdown');
        nfOpen = !nfOpen;
        dd.style.display = nfOpen ? 'block' : 'none';

        if (nfOpen) {
            /* Fade badge immediately when opening */
            const badge = document.getElementById('nfBadge');
            const pill  = document.getElementById('nfCountPill');
            const dot   = document.getElementById('nfDot');
            if (badge) {
                badge.classList.add('fade-out');
                setTimeout(() => badge.remove(), 320);
            }
            if (pill)  { setTimeout(() => pill.style.display='none', 300); }
            if (dot)   dot.style.opacity = '0.3';

            /* Persist "seen" to DB so badge stays gone after page navigation */
            fetch(HANDLER, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=mark_all_read'
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    /* Remove unread highlights silently */
                    document.querySelectorAll('.nf-row.nf-unread').forEach(r => r.classList.remove('nf-unread'));
                    const btn = document.getElementById('nfMarkAll');
                    if (btn) btn.style.opacity = '0.4';
                }
            }).catch(() => {/* fail silently */});
        }
    };

    /* Click outside closes */
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('notifWrap');
        if (wrap && !wrap.contains(e.target) && nfOpen) {
            document.getElementById('notifDropdown').style.display = 'none';
            nfOpen = false;
        }
    });

    /* Mark all as read */
    window.nfMarkAll = function() {
        fetch(HANDLER, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=mark_all_read'
        }).then(r => r.json()).then(d => {
            if (!d.success) return;
            /* Remove unread highlights */
            document.querySelectorAll('.nf-row.nf-unread').forEach(r => r.classList.remove('nf-unread'));
            /* Hide badge + mark-all button */
            const b = document.getElementById('nfBadge');
            if (b) { b.classList.add('fade-out'); setTimeout(() => b.remove(), 320); }
            const p = document.getElementById('nfCountPill');
            if (p) p.style.display = 'none';
            const btn = document.getElementById('nfMarkAll');
            if (btn) btn.style.opacity = '0.35';
        }).catch(console.error);
    };

    /* Dismiss single notification */
    window.nfDismiss = function(logId, btn) {
        fetch(HANDLER, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=dismiss&logId=' + logId
        }).then(r => r.json()).then(d => {
            if (!d.success) return;
            const row = document.getElementById('nfRow-' + logId);
            if (!row) return;
            /* Animate out */
            row.style.transition = 'opacity .22s ease, transform .22s ease, max-height .28s ease, padding .28s ease, margin .28s ease';
            row.style.maxHeight  = row.offsetHeight + 'px';
            row.style.overflow   = 'hidden';
            requestAnimationFrame(() => {
                row.style.opacity   = '0';
                row.style.transform = 'translateX(20px)';
                row.style.maxHeight = '0';
                row.style.paddingTop    = '0';
                row.style.paddingBottom = '0';
                row.style.marginTop     = '0';
                row.style.marginBottom  = '0';
            });
            setTimeout(() => {
                row.remove();
                /* Decrement badge count in DOM */
                nfDecrBadge();
                /* Show empty state if no rows left */
                const list = document.getElementById('nfList');
                const remaining = list ? list.querySelectorAll('.nf-row:not(.nf-special)') : [];
                if (remaining.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'nf-empty';
                    empty.innerHTML = '<svg width="28" height="28" fill="none" stroke="#cbd5e1" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>No notifications yet';
                    if (list) list.appendChild(empty);
                }
            }, 310);
        }).catch(console.error);
    };

    /* Decrement badge visually */
    function nfDecrBadge() {
        const badge = document.getElementById('nfBadge');
        const pill  = document.getElementById('nfCountPill');
        [badge, pill].forEach(el => {
            if (!el) return;
            const cur = parseInt(el.textContent) || 0;
            if (cur <= 1) { el.style.display = 'none'; }
            else { el.textContent = cur - 1; }
        });
    }
})();
</script>
