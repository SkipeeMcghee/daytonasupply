<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$p = getProductById(12);
echo "getProductById(12):\n";
var_export($p);
echo "\n\nAll products (first 10):\n";
$all = getAllProducts();
for ($i=0;$i<min(10,count($all));$i++){
    var_export($all[$i]);
    echo "\n";
}
