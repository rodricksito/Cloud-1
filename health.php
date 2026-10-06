<?php
require_once __DIR__ . '/includes/app.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $backend = api('health');
    echo json_encode(['status' => 'ok', 'instance' => nombreInstancia(), 'backend' => $backend], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable', 'instance' => nombreInstancia(), 'backend' => ['status' => 'unavailable']], JSON_THROW_ON_ERROR);
}
