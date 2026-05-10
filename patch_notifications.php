<?php
/**
 * patch_notifications2.php
 * Robust version using position-based div-depth counting.
 */

$files = [
    ['saro/dashboard.php',          false, "(int)\$_SESSION['user_id']"],
    ['saro/data_entry.php',         false, "(int)\$_SESSION['user_id']"],
    ['saro/audit_logs.php',         false, "(int)\$_SESSION['user_id']"],
    ['saro/cancelled_saro.php',     false, "(int)\$_SESSION['user_id']"],
    ['saro/settings.php',           false, "(int)\$_SESSION['user_id']"],
    ['saro/view_saro.php',          false, "(int)\$_SESSION['user_id']"],
    ['saro/view_procure_act.php',   false, "(int)\$_SESSION['user_id']"],
    ['saro/procurement_stat.php',   false, "(int)\$_SESSION['user_id']"],
    ['admin/dashboard.php',         true,  "\$adminId"],
    ['admin/activity_logs.php',     true,  "\$adminId"],
    ['admin/users.php',             true,  "\$adminId"],
    ['admin/password_requests.php', true,  "\$adminId"],
];

$root = __DIR__;

foreach ($files as [$relPath, $isAdmin, $uidVar]) {
    $path = $root . '/' . $relPath;
    if (!file_exists($path)) { echo "SKIP: $relPath\n"; continue; }

    $src = file_get_contents($path);
    $original = $src;

    // ── 1. Fix getRecentActivity() to pass $userId ──
    $src = preg_replace(
        '/(\$notifications\s*=\s*\$notifObj->getRecentActivity\()(\s*(?:10)?\s*)(\))/',
        "\$1{$uidVar}, 10\$3",
        $src
    );

    // ── 2. Find and extract the notifWrap div block ──
    $marker = 'id="notifWrap"';
    $pos = strpos($src, $marker);
    if ($pos === false) { echo "WARN (no notifWrap): $relPath\n"; goto write; }

    // Walk backward to find opening <div
    $before = substr($src, 0, $pos);
    $divStart = strrpos($before, '<div');
    if ($divStart === false) { echo "WARN (no <div before notifWrap): $relPath\n"; goto write; }

    // Walk forward counting <div> depth to find matching </div>
    $i = $divStart; $depth = 0; $len = strlen($src);
    while ($i < $len) {
        if (substr($src, $i, 4) === '<div') { $depth++; $i += 4; continue; }
        if (substr($src, $i, 6) === '</div>') {
            $depth--;
            if ($depth === 0) { $endPos = $i + 6; break; }
            $i += 6; continue;
        }
        $i++;
    }
    if ($depth !== 0) { echo "WARN (unbalanced divs): $relPath\n"; goto write; }

    $blockToReplace = substr($src, $divStart, $endPos - $divStart);

    // ── 3. Build the include line ──
    $isAdminStr = $isAdmin ? 'true' : 'false';
    $includeCode = "<?php \$isAdmin = {$isAdminStr}; \$pendingPwCount = \$pendingPwCount ?? 0; \$approvedPwReq = \$approvedPwReq ?? null; include __DIR__ . '/../includes/notif_dropdown.php'; ?>";

    $src = str_replace($blockToReplace, $includeCode, $src, $replaced);
    if (!$replaced) { echo "WARN (str_replace failed): $relPath\n"; goto write; }

    // ── 4. Remove old toggleNotif + click-outside JS ──
    // Remove function toggleNotif(...) { ... } block
    $src = preg_replace('/\s{4,}function\s+toggleNotif\s*\([^)]*\)\s*\{[^}]*\}\s*/s', "\n    ", $src);
    // Remove document.addEventListener('click', ...) used for notif close
    $src = preg_replace('/\s{4,}document\.addEventListener\(\'click\',\s*function\s*\(e\)\s*\{[^}]*notifWrap[^}]*\}\s*\);\s*/s', "\n    ", $src);

    write:
    if ($src !== $original) {
        file_put_contents($path, $src);
        echo "OK: $relPath\n";
    } else {
        echo "UNCHANGED: $relPath\n";
    }
}
echo "\nDone.\n";
