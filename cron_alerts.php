<?php
// cron_alerts.php
// This script checks for expiring SAROs and Procurement Activities
// and sends an email alert to all active Super Admins and Admins.
// It is designed to be run via a Cron Job or Windows Task Scheduler daily.

require_once __DIR__ . '/class/database.php';
$cfg = require __DIR__ . '/class/mail_config.php';

if (!empty($cfg['phpmailer_path'])) {
    $src = rtrim($cfg['phpmailer_path'], '/\\');
    require_once $src . '/Exception.php';
    require_once $src . '/PHPMailer.php';
    require_once $src . '/SMTP.php';
} else {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Find upcoming expiring SAROs (within 7 days)
    $saroStmt = $conn->query("
        SELECT saroId, saroNo, saro_title, valid_until 
        FROM saro 
        WHERE status != 'Obligated' AND status != 'Cancelled'
          AND valid_until IS NOT NULL 
          AND valid_until BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    ");
    $expiringSaros = $saroStmt->fetchAll(PDO::FETCH_ASSOC);

    // Find upcoming procurement activities (within 7 days)
    $procStmt = $conn->query("
        SELECT p.procurementId, p.pro_act as activity, p.proc_date, s.saroNo 
        FROM procurement p
        JOIN object_code o ON p.objectId = o.objectId
        JOIN saro s ON o.saroId = s.saroId
        WHERE p.status != 'obligated' AND p.status != 'cancelled'
          AND p.proc_date IS NOT NULL 
          AND p.proc_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    ");
    $expiringProcs = $procStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($expiringSaros) && empty($expiringProcs)) {
        echo "No upcoming deadlines within 7 days. Exiting.\n";
        exit;
    }

    // Get admins to notify
    $adminStmt = $conn->query("SELECT email, first_name, last_name FROM user WHERE roleId IN (1, 2) AND status = 'active' AND email != ''");
    $admins = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($admins)) {
        echo "No admins to notify. Exiting.\n";
        exit;
    }

    // Build Email Body
    $body = "<h2>SARO Monitoring System - Deadline Alerts</h2>";
    $body .= "<p>The following items are approaching their deadlines within the next 7 days:</p>";

    if (!empty($expiringSaros)) {
        $body .= "<h3>Expiring SAROs</h3><ul>";
        foreach ($expiringSaros as $s) {
            $body .= "<li><strong>{$s['saroNo']}</strong>: {$s['saro_title']} (Expires: " . date('M d, Y', strtotime($s['valid_until'])) . ")</li>";
        }
        $body .= "</ul>";
    }

    if (!empty($expiringProcs)) {
        $body .= "<h3>Upcoming Procurement Activities</h3><ul>";
        foreach ($expiringProcs as $p) {
            $body .= "<li><strong>{$p['saroNo']}</strong> - {$p['activity']} (Due: " . date('M d, Y', strtotime($p['proc_date'])) . ")</li>";
        }
        $body .= "</ul>";
    }

    $body .= "<p>Please log in to the DICT SARO Monitoring System to review these items.</p>";

    // Send emails
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = $cfg['encryption'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : ($cfg['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : '');
    $mail->Port       = $cfg['port'];
    $mail->setFrom($cfg['from_email'], $cfg['from_name']);
    $mail->isHTML(true);
    $mail->Subject = 'Deadline Alert: SAROs and Procurement Activities';
    $mail->Body    = $body;

    foreach ($admins as $admin) {
        $mail->addAddress($admin['email'], $admin['first_name'] . ' ' . $admin['last_name']);
    }

    $mail->send();
    echo "Deadline alerts sent successfully to " . count($admins) . " admins.\n";

} catch (Exception $e) {
    echo "Mailer Error: {$mail->ErrorInfo}\n";
} catch (\PDOException $e) {
    echo "Database Error: {$e->getMessage()}\n";
}
