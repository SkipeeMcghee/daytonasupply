<?php
// Local configuration for environment-specific settings.  This file is
// loaded automatically by includes/functions.php and should contain
// putenv() calls to define environment variables used by the
// application.  Do not commit sensitive credentials to version
// control; this file is meant to be managed outside of source control.

// Email address used for company notifications (e.g. new orders).  You
// can change this value to your company email in the future.
putenv('COMPANY_EMAIL=brianheise22@gmail.com');

// SMTP configuration for PHPMailer.  The username and password below
// correspond to your SMTP account (in this case a Gmail account with
// an App Password).  Replace these values with your own credentials
// when deploying to production.  Leaving these values blank will
// cause sendEmail() to fall back to PHP's built‑in mail() function.
putenv('SMTP_HOST=smtp.gmail.com');
putenv('SMTP_PORT=587');
putenv('SMTP_USER=brianheise22@gmail.com');
putenv('SMTP_PASS=bupr uclj johh nuab');
putenv('SMTP_SECURE=tls');