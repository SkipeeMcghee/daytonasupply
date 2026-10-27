<?php
// Optional: copy to includes/sync_config.php, UTF-8 without BOM.
// Block HTTP access to that file before provisioning the real token.
// Prefer setting SYNC_TOKEN in the server/PHP-FPM environment instead.
putenv('SYNC_TOKEN=PASTE_64_HEX_CHARS_HERE');
// Database settings come from the site's existing DB_* environment.

// Optional schema overrides:
// $CFG_TABLE = 'products'; $CFG_COL_ID = 'id';
// $CFG_COL_NAME = 'name'; $CFG_COL_PRICE = 'price'; $CFG_COL_DESC = 'description';
