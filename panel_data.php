<?php
// api/admin/panel_data.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once '/var/www/whg95531/data/www/speakup.hgweb.ru/api/config.php';

// Проверяем токен из GET параметра
$token = $_GET['token'] ?? '';

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'Токен не указан']);
    exit();
}

// Декодируем простой токен
$token_data = json_decode(base64_decode($token), true);
if (!$token_data) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен']);
    exit();
}

// Получаем пользователя по ID из токена
try {
    $stmt = $pdo->prepare("SELECT id, username, display_name, avatar_url, role FROM users WHERE id = ?");
    $stmt->execute([$token_data['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
        exit();
    }
    
    // Проверяем права администратора
    if ($user['role'] !== 'admin' && $user['role'] !== 'tester') {
        echo json_encode(['success' => false, 'error' => 'Нет прав доступа']);
        exit();
    }
    
    // Получаем статистику
    $stats = [];
    
    // Всего пользователей
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $stats['total_users'] = (int)$stmt->fetchColumn();
    
    // Активные пользователи (были онлайн в последние 24 часа)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE last_seen > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $stats['active_users'] = (int)$stmt->fetchColumn();
    
    // Всего групп
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM groups");
    $stats['total_groups'] = (int)$stmt->fetchColumn();
    
    // Всего сообщений
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM messages");
    $stats['total_messages'] = (int)$stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'message' => 'Админ-панель загружена'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}
?>