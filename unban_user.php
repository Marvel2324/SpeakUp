<?php
header('Content-Type: application/json');
require_once '../api/config.php';
require_once '../includes/functions.php';

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? $_POST['token'] ?? '';
$target_id = $data['user_id'] ?? $_POST['user_id'] ?? 0;

$admin = verifyToken($token);
if (!$admin || $admin['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Нет прав доступа']);
    exit;
}

try {
    $db = getDatabaseConnection();
    
    // Снимаем блокировку
    $stmt = $db->prepare("UPDATE users SET is_blocked = 0, blocked_until = NULL WHERE id = ?");
    $stmt->execute([$target_id]);
    
    // Отмечаем блокировку как неактивную
    $stmt = $db->prepare("UPDATE user_bans SET expires_at = NOW() WHERE user_id = ? AND (expires_at > NOW() OR expires_at IS NULL)");
    $stmt->execute([$target_id]);
    
    echo json_encode(['success' => true, 'message' => 'Пользователь разблокирован']);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}
?>