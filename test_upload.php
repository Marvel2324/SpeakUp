<?php
// test_upload.php - тестирование загрузки файлов
require_once '../config.php';

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'message' => 'Test endpoint works',
    'post_data' => $_POST,
    'files' => $_FILES,
    'request_method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'
]);
?>