<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';
$other_user_id = intval($_GET['user_id'] ?? 0);

if (empty($token) || !$other_user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$user_id = intval($payload['user_id']);

try {
    // Проверяем, существует ли уже чат
    $stmt = $pdo->prepare("
        SELECT id as chat_id FROM user_chats 
        WHERE (user1_id = ? AND user2_id = ?) 
           OR (user1_id = ? AND user2_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$user_id, $other_user_id, $other_user_id, $user_id]);
    $chat = $stmt->fetch();
    
    if ($chat) {
        // Чат существует - возвращаем его ID
        echo json_encode([
            'success' => true,
            'chat_id' => $chat['chat_id'],
            'exists' => true
        ]);
    } else {
        // Чат не существует - создаем новый
        $stmt = $pdo->prepare("
            INSERT INTO user_chats (user1_id, user2_id, created_at, updated_at) 
            VALUES (?, ?, NOW(), NOW())
        ");
        $stmt->execute([$user_id, $other_user_id]);
        $chat_id = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'chat_id' => $chat_id,
            'exists' => false,
            'message' => 'Chat created successfully'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Get chat id error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>