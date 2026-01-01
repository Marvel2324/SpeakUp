<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../includes/functions.php';

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? $_POST['token'] ?? '';
$name = $data['name'] ?? $_POST['name'] ?? '';
$username = $data['username'] ?? $_POST['username'] ?? '';
$description = $data['description'] ?? $_POST['description'] ?? '';
$is_channel = isset($data['is_channel']) ? (bool)$data['is_channel'] : false;
$members = $data['members'] ?? $_POST['members'] ?? [];

$user = verifyToken($token);
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен']);
    exit;
}

if (!$name || !$username) {
    echo json_encode(['success' => false, 'error' => 'Заполните название и юзернейм']);
    exit;
}

try {
    $db = getDatabaseConnection();
    $db->beginTransaction();
    
    // Проверяем уникальность юзернейма
    $stmt = $db->prepare("SELECT id FROM groups WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Юзернейм уже занят']);
        exit;
    }
    
    // Создаем группу
    $stmt = $db->prepare("
        INSERT INTO groups (name, username, description, avatar_url, is_channel, created_by, created_at) 
        VALUES (?, ?, ?, NULL, ?, ?, NOW())
    ");
    $stmt->execute([$name, $username, $description, $is_channel ? 1 : 0, $user['id']]);
    $group_id = $db->lastInsertId();
    
    // Добавляем создателя как владельца
    $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id, role, joined_at) VALUES (?, ?, 'owner', NOW())");
    $stmt->execute([$group_id, $user['id']]);
    
    // Добавляем участников
    if (is_array($members) && count($members) > 0) {
        $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
        foreach ($members as $member_id) {
            if ($member_id != $user['id']) {
                $stmt->execute([$group_id, $member_id]);
            }
        }
    }
    
    // Создаем системное сообщение
    $stmt = $db->prepare("INSERT INTO group_messages (group_id, sender_id, message, is_system, created_at) VALUES (?, ?, ?, 1, NOW())");
    $system_message = $is_channel ? "Канал создан" : "Группа создана";
    $stmt->execute([$group_id, $user['id'], $system_message]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => $is_channel ? 'Канал создан!' : 'Группа создана!',
        'group_id' => $group_id,
        'group' => [
            'id' => $group_id,
            'name' => $name,
            'username' => $username,
            'is_channel' => $is_channel,
            'member_count' => count($members) + 1
        ]
    ]);
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Ошибка создания: ' . $e->getMessage()]);
}
?>