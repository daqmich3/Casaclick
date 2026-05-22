<?php

// Lightweight probe for Railway (no Symfony bootstrap — avoids DB/JWT blocking healthcheck).
header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'ok' => true,
    'service' => 'casaclick',
    'time' => date('c'),
]);
