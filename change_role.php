<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Получаем данные
$input = json_decode(file_get_contents('php://input'), true);
$token = '';

// Ищем токен
$headers = getallheaders();
if (isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
    } else {
        $token = $authHeader;
    }
} else if (isset($input['token'])) {
    $token = $input['token'];
}

// Отладочный вывод
error_log("Token received: " . substr($token, 0, 20) . "...");

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Токен не предоставлен']);
    exit;
}

// Проверяем токен
$stmt = $pdo->prepare("SELECT u.id, u.role, u.username FROM user_sessions us 
                       JOIN users u ON us.user_id = u.id 
                       WHERE us.access_token = ? AND us.expires_at > NOW()");
$stmt->execute([$token]);
$admin = $stmt->fetch();

if (!$admin) {
    error_log("Invalid token or expired");
    echo json_encode(['success' => false, 'error' => 'Токен недействителен или истек']);
    exit;
}

error_log("Admin found: " . $admin['username'] . " with role: " . $admin['role']);

// Функция для получения уровня роли
function getRoleLevel($role) {
    $roleLevels = [
        'user' => 0,
        'tester1' => 1,
        'tester2' => 2,
        'tester3' => 3,
        'admin1' => 1,
        'admin2' => 2,
        'admin3' => 3,
        'admin4' => 4
    ];
    return $roleLevels[$role] ?? 0;
}

// Функция проверки прав
function canAdminChangeRole($adminRole, $targetCurrentRole, $newRole, $isSelf = false) {
    $adminLevel = getRoleLevel($adminRole);
    $targetLevel = getRoleLevel($targetCurrentRole);
    $newLevel = getRoleLevel($newRole);
    
    // 1. Никто не может менять роль admin4 (кроме самому себе для admin4)
    if ($targetCurrentRole === 'admin4') {
        if (!$isSelf) {
            return false;
        }
        // Admin4 может понизить себя
        if ($newRole !== 'admin4' && $isSelf) {
            return true;
        }
    }
    
    // 2. Admin1 и admin2 не могут менять роли вообще
    if (in_array($adminRole, ['admin1', 'admin2'])) {
        return false;
    }
    
    // 3. Admin3 может менять только тестеров и admin1-2
    if ($adminRole === 'admin3') {
        // Проверяем текущую роль цели
        if (strpos($targetCurrentRole, 'admin') === 0) {
            $targetAdminLevel = (int)substr($targetCurrentRole, -1);
            if ($targetAdminLevel >= 3) {
                return false; // Не может менять admin3/admin4
            }
        }
        
        // Проверяем новую роль
        if (strpos($newRole, 'admin') === 0) {
            $newAdminLevel = (int)substr($newRole, -1);
            if ($newAdminLevel >= 3) {
                return false; // Не может назначать admin3/admin4
            }
        }
        
        return true;
    }
    
    // 4. Admin4 может менять всех, кроме admin4 другим
    if ($adminRole === 'admin4') {
        if ($newRole === 'admin4' && !$isSelf) {
            return false; // Не может назначить admin4 другому
        }
        return true;
    }
    
    return false;
}

// ПРОВЕРКА: Только админы могут менять роли
if (strpos($admin['role'], 'admin') !== 0) {
    echo json_encode(['success' => false, 'error' => 'Требуются права администратора']);
    exit;
}

$userId = intval($input['user_id'] ?? 0);
$newRole = $input['role'] ?? 'user';

error_log("Changing role for user $userId to $newRole");

if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Не указан user_id']);
    exit;
}

// Разрешенные роли (admin4 нельзя назначать через интерфейс)
$allowedRoles = ['user', 'tester1', 'tester2', 'tester3', 'admin1', 'admin2', 'admin3'];
if (!in_array($newRole, $allowedRoles)) {
    echo json_encode(['success' => false, 'error' => 'Недопустимая роль: ' . $newRole]);
    exit;
}

// Получаем данные цели
$stmt = $pdo->prepare("SELECT id, role, username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
    exit;
}

error_log("Target user found: " . $targetUser['username'] . " with current role: " . $targetUser['role']);

// Проверяем права
$isSelf = ($userId == $admin['id']);
if (!canAdminChangeRole($admin['role'], $targetUser['role'], $newRole, $isSelf)) {
    error_log("Permission denied for admin " . $admin['role'] . " to change role from " . $targetUser['role'] . " to " . $newRole);
    echo json_encode([
        'success' => false, 
        'error' => 'У вас недостаточно прав для этого действия',
        'debug' => [
            'admin_role' => $admin['role'],
            'admin_level' => getRoleLevel($admin['role']),
            'target_role' => $targetUser['role'],
            'new_role' => $newRole,
            'is_self' => $isSelf
        ]
    ]);
    exit;
}

// Всё ок - меняем роль
$stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
$success = $stmt->execute([$newRole, $userId]);

if ($success) {
    error_log("Role changed successfully for user $userId to $newRole");
    
    // Логируем действие
    try {
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, target_user_id, details) VALUES (?, ?, ?, ?)");
        $logStmt->execute([
            $admin['id'],
            'change_role',
            $userId,
            json_encode([
                'from_role' => $targetUser['role'],
                'to_role' => $newRole,
                'admin_username' => $admin['username'],
                'target_username' => $targetUser['username']
            ], JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $e) {
        error_log("Failed to log action: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Роль успешно изменена',
        'user' => [
            'id' => $userId,
            'role' => $newRole,
            'username' => $targetUser['username']
        ]
    ]);
} else {
    error_log("Database error: " . implode(", ", $stmt->errorInfo()));
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?>