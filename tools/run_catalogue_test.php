<?php
// Test runner to simulate a GET request to catalogue.php
chdir(__DIR__ . '/..');
// Simulate a search term
$_GET['search'] = 'BAT';
// Ensure display of errors for debugging
ini_set('display_errors', '1');
error_reporting(E_ALL);

include __DIR__ . '/../catalogue.php';
