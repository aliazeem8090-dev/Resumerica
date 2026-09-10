<?php
/**
 * Copy this file to config.php (same folder) and fill in real values.
 * config.php is gitignored — never commit real keys.
 */

// From resend.com -> API Keys. Without it, booking/review notifications are
// only logged to the PHP error log (see hPanel -> Advanced -> PHP Configuration
// / error log), not actually emailed.
define('RESEND_API_KEY', '');

// Where booking + CV review notifications go (your inbox).
define('NOTIFY_EMAIL', 'you@example.com');

// Optional — set only after verifying your domain in Resend, e.g.
// 'Resumerica <hello@yourdomain.com>'. When set, visitors also get their own
// confirmation emails (booking confirmation / "your CV is being reviewed").
define('FROM_EMAIL', '');
