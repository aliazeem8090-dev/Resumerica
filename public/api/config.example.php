<?php
/**
 * Copy this file to config.php (same folder) and fill in real values.
 * config.php is gitignored — never commit real keys.
 */

// Required — from console.anthropic.com -> API Keys
define('ANTHROPIC_API_KEY', '');

// Optional — from resend.com -> API Keys. Without it, bookings/leads are only logged
// to the PHP error log (see hPanel -> Advanced -> PHP Configuration / error log).
define('RESEND_API_KEY', '');

// Where booking + scan-lead notifications go (your inbox).
define('NOTIFY_EMAIL', 'you@example.com');

// Optional — set only after verifying your domain in Resend, e.g.
// 'Resumerica <hello@yourdomain.com>'. When set, customers also get a booking
// confirmation and an emailed copy of their scan report.
define('FROM_EMAIL', '');

// Swap to 'claude-haiku-4-5-20251001' to cut scan costs.
define('MODEL', 'claude-sonnet-5');
