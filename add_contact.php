<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

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

$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$user_id = intval($payload['user_id']);
$contact_id = intval($_GET['contact_id'] ?? $_POST['contact_id'] ?? 0);

if (!$contact_id) {
    echo json_encode(['success' => false, 'error' => 'Need contact_id']);
    exit;
}

if ($user_id == $contact_id) {
    echo json_encode(['success' => false, 'error' => 'Cannot add yourself']);
    exit;
}

try {
    // Проверяем, не добавлен ли уже
    $stmt = $pdo->prepare("SELECT id FROM user_contacts WHERE user_id = ? AND contact_id = ?");
    $stmt->execute([$user_id, $contact_id]);
    
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false, 
            'error' => 'Уже в контактах',
            'already' => true
        ]);
        exit;
    }
    
    // Добавляем в контакты
    $stmt = $pdo->prepare("INSERT INTO user_contacts (user_id, contact_id) VALUES (?, ?)");
    $stmt->execute([$user_id, $contact_id]);
    
    // Создаем чат автоматически (если его еще нет)
    $stmt = $pdo->prepare("
        SELECT id FROM user_chats 
        WHERE (user1_id = ? AND user2_id = ?) 
           OR (user1_id = ? AND user2_id = ?)
    ");
    $stmt->execute([$user_id, $contact_id, $contact_id, $user_id]);
    
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO user_chats (user1_id, user2_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $contact_id]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Контакт добавлен'
    ]);
    
} catch (Exception $e) {
    error_log("Add contact error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка добавления в контакты'
    ]);
}
?>