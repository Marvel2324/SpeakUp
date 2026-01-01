<?php
// РАБОЧИЙ API ВХОДА - ПРОСТОЙ ТОКЕН
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '/var/www/whg95531/data/www/speakupmess.hgweb.ru/api/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Только POST']);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) $data = $_POST;

if (empty($data['username']) || empty($data['password'])) {
    echo json_encode(['success' => false, 'error' => 'Укажите имя и пароль']);
    exit();
}

$username = trim($data['username']);
$password = $data['password'];

try {
    // 1. Ищем пользователя (ДОБАВЛЕНО role в SELECT!)
    $stmt = $pdo->prepare("SELECT id, username, email, password_hash, display_name, avatar_url, role FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
        exit();
    }
    
    // 2. Проверяем пароль
    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Неверный пароль']);
        exit();
    }
    
    // 3. Обновляем last_seen
    $updateStmt = $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
    $updateStmt->execute([$user['id']]);
    
    // 4. ПРОСТОЙ ТОКЕН (как раньше работало)
    $token_payload = [
        'user_id' => $user['id'],
        'username' => $user['username'],
        'exp' => time() + (30 * 24 * 60 * 60)
    ];
    $token = base64_encode(json_encode($token_payload));
    
    // 5. Пытаемся сохранить в новую таблицу (если есть)
    try {
        @$pdo->prepare("INSERT INTO user_sessions (user_id, access_token, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))")
            ->execute([$user['id'], $token, $token_payload['exp']]);
    } catch (Exception $e) {
        // Игнорируем ошибку если таблицы нет
    }
    
    // 6. Успешный ответ
    unset($user['password_hash']);
    
    // Гарантируем что роль всегда есть (по умолчанию 'user')
    $user['role'] = $user['role'] ?? 'user';
    
    echo json_encode([
        'success' => true,
        'message' => 'Вход выполнен',
        'token' => $token,
        'user' => $user,  // Теперь в $user есть поле 'role'
        'expires_in' => '30 дней'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()]);
}
?>