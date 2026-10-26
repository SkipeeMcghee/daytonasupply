<?php
// Copy OUTSIDE the webroot to ../config/catalog_sync.php, UTF-8 without BOM.
$SYNC_TOKEN = 'PASTE_64_HEX_CHARS_HERE';
$DB_DSN = 'mysql:host=localhost;dbname=DBNAME;charset=utf8mb4';
$DB_USER = 'DBUSER';
$DB_PASS = 'DBPASS';

// Optional schema overrides:
// $CFG_TABLE = 'products'; $CFG_COL_ID = 'id';
// $CFG_COL_NAME = 'name'; $CFG_COL_PRICE = 'price'; $CFG_COL_DESC = 'description';
