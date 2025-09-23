<?php
// Diagnostic: check getProductById() output for a product id
require_once __DIR__ . '/../includes/functions.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 1429;
$prod = getProductById($id);

header('Content-Type: application/json');
echo json_encode([
    'checked_id' => $id,
    'result' => $prod === null ? null : $prod,
    'note' => 'If result is null, getProductById did not find the product in DB or cache.'
], JSON_PRETTY_PRINT);
