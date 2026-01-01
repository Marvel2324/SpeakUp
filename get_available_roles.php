<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Получаем токен
$token = $_GET['token'] ?? '';
$target_id = intval($_GET['target_id'] ?? 0);

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Токен не предоставлен']);
    exit;
}

// Проверяем токен
$stmt = $pdo->prepare("SELECT u.id, u.role FROM user_sessions us 
                       JOIN users u ON us.user_id = u.id 
                       WHERE us.access_token = ? AND us.expires_at > NOW()");
$stmt->execute([$token]);
$admin = $stmt->fetch();

if (!$admin) {
    echo json_encode(['success' => false, 'error' => 'Токен недействителен или истек']);
    exit;
}

// Проверяем, что это админ
if (strpos($admin['role'], 'admin') !== 0) {
    echo json_encode(['success' => false, 'error' => 'Требуются права администратора']);
    exit;
}

// Получаем роль цели
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$target_id]);
$target = $stmt->fetch();

if (!$target) {
    echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
    exit;
}

$admin_role = $admin['role'];
$target_role = $target['role'];
$is_self = ($target_id == $admin['id']);

// Определяем доступные роли в зависимости от прав админа
$available_roles = ['user']; // Всегда доступна роль пользователя

// Проверяем уровень админа
$admin_level = (int)substr($admin_role, -1);

// Правила доступности ролей:
if ($admin_role === 'admin3') {
    // Admin3 может назначать:
    // 1. Тестеров (1-3)
    $available_roles = array_merge($available_roles, ['tester1', 'tester2', 'tester3']);
    // 2. Admin1-2
    $available_roles = array_merge($available_roles, ['admin1', 'admin2']);
    
    // Не может назначать admin3, admin4
    
} elseif ($admin_role === 'admin4') {
    // Admin4 может назначать:
    // 1. Тестеров (1-3)
    $available_roles = array_merge($available_roles, ['tester1', 'tester2', 'tester3']);
    // 2. Admin1-3 (admin4 только себе)
    $available_roles = array_merge($available_roles, ['admin1', 'admin2', 'admin3']);
    
    // Admin4 может назначать себе
    if ($is_self) {
        $available_roles[] = 'admin4';
    }
} else {
    // Admin1, admin2 не могут назначать роли
    $available_roles = ['user'];
}

// Фильтруем дубликаты
$available_roles = array_unique($available_roles);

// Сортируем для удобства
usort($available_roles, function($a, $b) {
    $order = ['user', 'tester1', 'tester2', 'tester3', 'admin1', 'admin2', 'admin3', 'admin4'];
    return array_search($a, $order) - array_search($b, $order);
});

echo json_encode([
    'success' => true,
    'available_roles' => $available_roles,
    'debug' => [
        'admin_role' => $admin_role,
        'admin_level' => $admin_level,
        'target_role' => $target_role,
        'is_self' => $is_self
    ]
]);
?>