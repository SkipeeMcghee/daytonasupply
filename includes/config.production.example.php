<?php
/**
 * Production configuration overrides for Daytona Supply website.
 *
 * Copy this file to includes/config.local.php and replace the
 * placeholder values with your actual database credentials and
 * company email.  This file defines environment variables that
 * override the defaults used by the application.  For shared
 * hosting you should also configure these values via your hosting
 * provider's environment variable settings if available.
 */

// Database driver: set to 'mysql' for production
putenv('DB_DRIVER=mysql');
// Hostname of your MySQL server (e.g. 'localhost' or your host's IP)
putenv('DB_HOST=localhost');
// Name of the MySQL database that contains the Daytona tables
putenv('DB_NAME=daytona_supply');
// Username and password for connecting to the database
putenv('DB_USER=username_here');
putenv('DB_PASS=password_here');

// Company email address used as the sender for notifications
putenv('COMPANY_EMAIL=orders@daytonasupply.com');

// SMTP settings for sending email via PHPMailer.  You can leave
// SMTP_AUTH blank if your provider does not require authentication.
putenv('SMTP_HOST=smtp.yourprovider.com');
putenv('SMTP_PORT=587');
putenv('SMTP_USER=smtp_username_here');
putenv('SMTP_PASS=smtp_password_here');
putenv('SMTP_SECURE=tls');