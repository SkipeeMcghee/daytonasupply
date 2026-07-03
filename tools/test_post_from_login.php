<?php
// Simple POST to signup.php to simulate login->signup navigation and show response headers
$url = 'http://localhost/signup.php';
$data = http_build_query(['from_login' => '1']);
$options = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => $data,
        'ignore_errors' => true,
    ]
];
$context  = stream_context_create($options);
$result = @file_get_contents($url, false, $context);
if ($result === false) {
    echo "Request failed or local dev server not running.\n";
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $h) echo $h, "\n";
    }
    exit(1);
}
// Print response headers and a short snippet of the body
foreach ($http_response_header as $h) echo $h, "\n";
echo "\nBody snippet:\n";
echo substr($result, 0, 400);

?>
