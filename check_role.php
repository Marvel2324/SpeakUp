<?php
header('Content-Type: application/json');
require_once '../config.php';

// Получаем токен
$token = $_GET['token'] ?? '';

// Проверяем пользователя
$stmt = $pdo->prepare("SELECT u.id, u.username, u.role FROM users u 
                      INNER JOIN tokens t ON u.id = t.user_id 
                      WHERE t.token = ? AND t.expires_at > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

// Возвращаем роль пользователя
echo json_encode([
    'success' => true,
    'user_id' => $user['id'],
    'username' => $user['username'],
    'role' => $user['role'],
    'level' => getLevelFromRole($user['role'])
]);

function getLevelFromRole($role) {
    if ($role === 'user') return 0;
    if (strpos($role, 'tester') === 0) return 10;
    if (strpos($role, 'admin') === 0) {
        return (int) str_replace('admin', '', $role);
    }
    return 0;
}
?>