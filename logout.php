<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

// Получаем токен
$token = $_GET['token'] ?? '';
if (empty($token)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'No token']);
    exit;
}

try {
    // Удаляем токен из БД
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE access_token = ?");
    $stmt->execute([$token]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Выход выполнен успешно'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>