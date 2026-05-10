<?php
/**
 * SMTP Mail Configuration
 * Fill in your credentials before deploying.
 *
 * PHPMailer installation (choose one):
 *   A) Composer  — run: composer require phpmailer/phpmailer
 *                  set phpmailer_path = null  (uses vendor/autoload.php automatically)
 *
 *   B) Manual    — download PHPMailer from github.com/PHPMailer/PHPMailer/releases
 *                  extract and upload the src/ folder to your project, e.g.:
 *                    yourproject/phpmailer/src/PHPMailer.php
 *                    yourproject/phpmailer/src/SMTP.php
 *                    yourproject/phpmailer/src/Exception.php
 *                  then set phpmailer_path to that src/ directory (see below)
 *
 * Gmail setup:
 *   1. Enable 2-Step Verification on your Google account.
 *   2. Go to myaccount.google.com > Security > App Passwords.
 *   3. Generate a password for "Mail" and paste it below.
 *
 * Hostinger email setup:
 *   host       = smtp.hostinger.com
 *   port       = 465
 *   encryption = ssl
 *   username   = your full email (e.g. noreply@yourdomain.com)
 *   password   = that email account's password
 */
return [
    // ── PHPMailer path ────────────────────────────────────────────────────
    // null  = use Composer's vendor/autoload.php (Composer installation)
    // path  = absolute path to PHPMailer's src/ folder (manual installation)
    //         Example: __DIR__ . '/../phpmailer/src'
    'phpmailer_path' => null,                               

    // ── SMTP settings ─────────────────────────────────────────────────────
    'host'       => 'smtp.gmail.com',          // smtp.gmail.com OR smtp.hostinger.com
    'port'       => 587,                        // Gmail TLS: 587  | Hostinger SSL: 465
    'encryption' => 'tls',                      // 'tls' or 'ssl'
    'username'   => 'micahsedigo200615@gmail.com',     // SMTP login
    'password'   => 'uahg vkuo jtwz akst',   // App password (NOT your Google login password)
    'from_email' => 'micahsedigo200615@gmail.com',     // "From" address
    'from_name'  => 'DICT SARO Monitoring',     // "From" display name
];
