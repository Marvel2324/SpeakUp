<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config.php';

// Получаем токен
$token = $_GET['token'] ?? '';
if (!$token) {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
}

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Токен не указан']);
    exit;
}

// Декодируем токен
$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен']);
    exit;
}

$user_id = $payload['user_id'];
$other_user_id = intval($_GET['other_user_id'] ?? 0);

if (!$other_user_id) {
    echo json_encode(['success' => false, 'error' => 'ID пользователя не указан']);
    exit;
}

try {
    // Определяем порядок ID для уникальности чата
    $user1_id = min($user_id, $other_user_id);
    $user2_id = max($user_id, $other_user_id);
    
    // Проверяем существование чата в таблице user_chats
    $stmt = $pdo->prepare("
        SELECT id, user1_id, user2_id 
        FROM user_chats 
        WHERE user1_id = ? AND user2_id = ?
    ");
    $stmt->execute([$user1_id, $user2_id]);
    $chat = $stmt->fetch();
    
    if ($chat) {
        // Чат существует
        echo json_encode([
            'success' => true,
            'chat_id' => $chat['id'],
            'exists' => true,
            'user1_id' => $chat['user1_id'],
            'user2_id' => $chat['user2_id']
        ]);
    } else {
        // Чат не найден
        echo json_encode([
            'success' => true,
            'chat_id' => null,
            'exists' => false,
            'message' => 'Чат не найден'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Get or create chat error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка базы данных',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>