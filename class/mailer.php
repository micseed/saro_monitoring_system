<?php
class Mailer
{
    private static function loadPHPMailer(array $cfg): void
    {
        if (!empty($cfg['phpmailer_path'])) {
            // Manual installation: load the three source files directly
            $src = rtrim($cfg['phpmailer_path'], '/\\');
            require_once $src . '/Exception.php';
            require_once $src . '/PHPMailer.php';
            require_once $src . '/SMTP.php';
        } else {
            // Composer installation: use the autoloader
            require_once __DIR__ . '/../vendor/autoload.php';
        }
    }

    public static function sendPasswordReset(
        string $toEmail,
        string $toName,
        string $resetUrl
    ): bool {
        $cfg = require __DIR__ . '/mail_config.php';
        self::loadPHPMailer($cfg);

        // Import after loading so the classes exist
        $phpmailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
        $exceptionClass = 'PHPMailer\\PHPMailer\\Exception';

        $mail = new $phpmailerClass(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['username'];
            $mail->Password   = $cfg['password'];
            $mail->SMTPSecure = $cfg['encryption'] === 'ssl'
                ? $phpmailerClass::ENCRYPTION_SMTPS
                : $phpmailerClass::ENCRYPTION_STARTTLS;
            $mail->Port = (int) $cfg['port'];

            $mail->setFrom($cfg['from_email'], $cfg['from_name']);
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = 'Password Reset — DICT SARO Monitoring';
            $mail->Body    = self::buildHtml($toName, $resetUrl);
            $mail->AltBody =
                "Hi $toName,\n\n" .
                "Reset your Super Admin password here:\n$resetUrl\n\n" .
                "This link expires in 1 hour.\n" .
                "If you did not request this, ignore this email.";

            $mail->send();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function buildHtml(string $name, string $resetUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f0f4ff;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4ff;padding:40px 20px;">
<tr><td align="center">
  <table width="560" cellpadding="0" cellspacing="0"
         style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
    <tr>
      <td style="background:linear-gradient(135deg,#081234,#1d4ed8);padding:32px 40px;text-align:center;">
        <p style="margin:0;font-size:11px;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:0.2em;">
          DICT — Region IX &amp; BASULTA
        </p>
        <h1 style="margin:8px 0 0;font-size:22px;font-weight:900;color:#fff;text-transform:uppercase;letter-spacing:-0.01em;">
          SARO Monitoring System
        </h1>
      </td>
    </tr>
    <tr>
      <td style="padding:36px 40px;">
        <p style="margin:0 0 16px;font-size:15px;font-weight:600;color:#0f172a;">Hi {$name},</p>
        <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.7;">
          We received a request to reset the password for your <strong>Super Admin</strong> account.
          Click the button below to set a new password.
        </p>
        <div style="text-align:center;margin:28px 0;">
          <a href="{$resetUrl}"
             style="display:inline-block;padding:14px 36px;background:#2563eb;color:#fff;
                    font-size:14px;font-weight:700;border-radius:10px;text-decoration:none;
                    letter-spacing:0.04em;text-transform:uppercase;">
            Reset My Password
          </a>
        </div>
        <p style="margin:0 0 6px;font-size:12px;color:#94a3b8;text-align:center;">
          This link expires in <strong style="color:#475569;">1 hour</strong>.
        </p>
        <p style="margin:0;font-size:12px;color:#94a3b8;text-align:center;">
          If you didn't request a password reset, you can safely ignore this email.
        </p>
        <hr style="margin:28px 0;border:none;border-top:1px solid #f1f5f9;">
        <p style="margin:0;font-size:11px;color:#cbd5e1;line-height:1.7;">
          If the button doesn't work, copy and paste this link into your browser:<br>
          <a href="{$resetUrl}" style="color:#3b82f6;word-break:break-all;">{$resetUrl}</a>
        </p>
      </td>
    </tr>
    <tr>
      <td style="padding:18px 40px;background:#f8fafc;border-top:1px solid #f1f5f9;text-align:center;">
        <p style="margin:0;font-size:11px;color:#94a3b8;">
          For authorized DICT personnel only. Unauthorized access is strictly prohibited.
        </p>
      </td>
    </tr>
  </table>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
