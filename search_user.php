<?php
// api/admin/search_users.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once '/var/www/whg95531/data/www/speakup.hgweb.ru/api/config.php';

$token = $_GET['token'] ?? '';
$query = $_GET['q'] ?? '';

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'Токен не указан']);
    exit();
}

// Декодируем токен
$token_data = json_decode(base64_decode($token), true);
if (!$token_data) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен']);
    exit();
}

// Получаем пользователя
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$token_data['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || ($user['role'] !== 'admin' && $user['role'] !== 'tester')) {
        echo json_encode(['success' => false, 'error' => 'Нет прав доступа']);
        exit();
    }
    
    // Ищем пользователей
    if (empty($query)) {
        $stmt = $pdo->query("SELECT id, username, display_name, avatar_url, role, created_at FROM users ORDER BY id DESC LIMIT 20");
    } else {
        $search = "%$query%";
        $stmt = $pdo->prepare("SELECT id, username, display_name, avatar_url, role, created_at FROM users 
                              WHERE username LIKE ? OR display_name LIKE ? 
                              ORDER BY username LIMIT 20");
        $stmt->execute([$search, $search]);
    }
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}
?>