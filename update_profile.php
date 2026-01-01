<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ПРОСТОЙ МЕТОД ПОЛУЧЕНИЯ ТОКЕНА
$token = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    } else {
        $token = $auth_header;
    }
}

if (empty($token) && isset($_GET['token'])) {
    $token = $_GET['token'];
}

if (empty($token)) {
    $input = file_get_contents('php://input');
    if (!empty($input)) {
        $data = json_decode($input, true);
        if ($data && isset($data['token'])) {
            $token = $data['token'];
        }
    }
}

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'No token provided']);
    exit();
}

// Декодируем токен
try {
    $decoded = base64_decode($token);
    if ($decoded === false) {
        $payload = json_decode($token, true);
        if (!$payload || !isset($payload['user_id'])) {
            throw new Exception('Cannot decode token');
        }
        $user_id = intval($payload['user_id']);
    } else {
        $payload = json_decode($decoded, true);
        if (!$payload || !isset($payload['user_id'])) {
            throw new Exception('Invalid token payload');
        }
        $user_id = intval($payload['user_id']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit();
}

// Получаем данные
$input = file_get_contents('php://input');
$data = [];
if (!empty($input)) {
    $data = json_decode($input, true);
}

if (empty($data)) {
    echo json_encode(['success' => false, 'error' => 'No data provided']);
    exit();
}

if (empty($data['username'])) {
    echo json_encode(['success' => false, 'error' => 'Username is required']);
    exit();
}

try {
    // ПРОВЕРЯЕМ УНИКАЛЬНОСТЬ USERNAME
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_user = $stmt->fetch();
    
    if ($current_user && $current_user['username'] !== $data['username']) {
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check_stmt->execute([$data['username'], $user_id]);
        if ($check_stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Username already taken']);
            exit();
        }
    }
    
    // ПРОВЕРЯЕМ EMAIL
    if (!empty($data['email'])) {
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Invalid email format']);
            exit();
        }
        
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_stmt->execute([$data['email'], $user_id]);
        if ($check_stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Email already registered']);
            exit();
        }
    }
    
    // ВРЕМЕННОЕ РЕШЕНИЕ: ИСПОЛЬЗУЕМ ТОЛЬКО ДОПУСТИМЫЕ СТАТУСЫ
    // Пробуем получить допустимые значения из базы
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
        $status_info = $stmt->fetch();
        
        $allowed_statuses = ['online', 'offline']; // значения по умолчанию
        
        if ($status_info && isset($status_info['Type'])) {
            $type = $status_info['Type'];
            // Если это ENUM, извлекаем значения
            if (strpos($type, 'enum') === 0) {
                preg_match_all("/'([^']+)'/", $type, $matches);
                if (!empty($matches[1])) {
                    $allowed_statuses = $matches[1];
                }
            }
            // Если это VARCHAR, ограничиваем длину
            elseif (preg_match('/varchar\((\d+)\)/', $type, $matches)) {
                $max_length = $matches[1];
                $status = substr($data['status'] ?? 'online', 0, $max_length);
            }
        }
        
        // Определяем статус
        $status = $data['status'] ?? 'online';
        if (!in_array($status, $allowed_statuses)) {
            $status = 'online'; // значение по умолчанию
        }
        
    } catch (Exception $e) {
        // Если не удалось определить, используем безопасные значения
        $status = 'online';
    }
    
    // ОБНОВЛЯЕМ ПРОФИЛЬ
    $stmt = $pdo->prepare("
        UPDATE users 
        SET 
            display_name = ?,
            username = ?,
            email = ?,
            bio = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    // Сначала без статуса
    $result = $stmt->execute([
        $data['display_name'] ?? '',
        $data['username'] ?? '',
        $data['email'] ?? '',
        $data['bio'] ?? '',
        $user_id
    ]);
    
    if ($result) {
        // Если нужно обновить статус отдельно
        if (isset($status)) {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$status, $user_id]);
        }
        
        // Получаем обновленного пользователя
        $stmt = $pdo->prepare("
            SELECT id, username, display_name, email, status, bio, avatar_url 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $updated_user = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $updated_user
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'error' => 'Update failed']);
    }
    
} catch (Exception $e) {
    error_log("Update profile error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'details' => $e->getMessage()
    ]);
}
?>