<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// Для CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config.php';

// Получаем данные из тела запроса
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Нет данных', 'input' => $input]);
    exit;
}

// Токен может быть в заголовках ИЛИ в теле запроса
$token = '';

// 1. Пробуем из заголовков
$headers = getallheaders();
if (isset($headers['Authorization'])) {
    $token = str_replace('Bearer ', '', $headers['Authorization']);
} 
// 2. Пробуем из тела запроса
else if (isset($data['token'])) {
    $token = $data['token'];
}

if (!$token) {
    echo json_encode([
        'success' => false, 
        'error' => 'Токен не предоставлен'
    ]);
    exit;
}

$userId = $data['user_id'] ?? 0;
$days = $data['days'] ?? null;
$reason = $data['reason'] ?? '';
$permanent = $data['permanent'] ?? false;

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

// ИСПРАВЛЕНО: проверяем что роль НАЧИНАЕТСЯ с 'admin'
if (strpos($admin['role'], 'admin') !== 0) {
    echo json_encode(['success' => false, 'error' => 'Требуются права администратора']);
    exit;
}

// Нельзя банить себя
if ($userId == $admin['id']) {
    echo json_encode(['success' => false, 'error' => 'Нельзя заблокировать себя']);
    exit;
}

// Проверяем существует ли пользователь
$stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
    exit;
}

// Дополнительная проверка: нельзя банить админа выше или равного уровня
if (strpos($user['role'], 'admin') === 0) {
    $adminLevel = (int) str_replace('admin', '', $admin['role']);
    $userLevel = (int) str_replace('admin', '', $user['role']);
    
    if ($userLevel >= $adminLevel) {
        echo json_encode(['success' => false, 'error' => 'Нельзя заблокировать админа вашего или выше уровня']);
        exit;
    }
}

// Рассчитываем дату истечения
$expiresAt = null;
if ($permanent || !$days || $days == '') {
    // Перманентный бан
    $expiresAt = null;
    $days = null;
    $permanent = true;
} else {
    // Временный бан
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    $permanent = false;
}

// Добавляем бан
$stmt = $pdo->prepare("INSERT INTO user_bans (user_id, banned_by, reason, days, is_permanent, expires_at) 
                       VALUES (?, ?, ?, ?, ?, ?)");
$success = $stmt->execute([
    $userId, 
    $admin['id'], 
    $reason, 
    $days, 
    $permanent ? 1 : 0, 
    $expiresAt
]);

if ($success) {
    echo json_encode(['success' => true, 'message' => 'Пользователь заблокирован']);
} else {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?>