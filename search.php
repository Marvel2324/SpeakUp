<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ПРОСТОЙ СПОСОБ ПОЛУЧЕНИЯ ТОКЕНА
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'No token']);
    exit;
}

// ДЕКОДИРУЕМ ТОКЕН
$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$current_user_id = $payload['user_id'];

// ПОЛУЧАЕМ ПОИСКОВЫЙ ЗАПРОС
$query = trim($_GET['q'] ?? '');
if (empty($query) || strlen($query) < 2) {
    echo json_encode([
        'success' => false,
        'error' => 'Введите минимум 2 символа для поиска'
    ]);
    exit();
}

try {
    // ИЩЕМ ПОЛЬЗОВАТЕЛЕЙ
    $search_term = "%" . $query . "%";
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            username,
            display_name,
            avatar_url,
            status,
            last_seen,
            created_at,
            bio
        FROM users 
        WHERE id != ?
          AND (
            username LIKE ? 
            OR display_name LIKE ? 
            OR email LIKE ?
          )
        ORDER BY 
            CASE 
                WHEN username LIKE ? THEN 1
                WHEN display_name LIKE ? THEN 2
                WHEN email LIKE ? THEN 3
                ELSE 4
            END,
            username ASC
        LIMIT 20
    ");
    
    $stmt->execute([
        $current_user_id,
        $search_term, 
        $search_term,
        $search_term,
        $search_term,
        $search_term,
        $search_term
    ]);
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ФОРМАТИРУЕМ ДАННЫЕ
    foreach ($users as &$user) {
        // ФОРМАТИРУЕМ LAST_SEEN
        if ($user['status'] === 'online') {
            $user['last_seen_text'] = 'онлайн';
        } else if ($user['last_seen']) {
            $lastSeen = new DateTime($user['last_seen']);
            $now = new DateTime();
            $diff = $now->diff($lastSeen);
            
            if ($diff->days > 0) {
                $user['last_seen_text'] = $diff->days . ' дн. назад';
            } else if ($diff->h > 0) {
                $user['last_seen_text'] = $diff->h . ' ч. назад';
            } else if ($diff->i > 5) {
                $user['last_seen_text'] = $diff->i . ' мин. назад';
            } else {
                $user['last_seen_text'] = 'только что';
            }
        } else {
            $user['last_seen_text'] = 'давно';
        }
        
        // ЕСЛИ НЕТ DISPLAY_NAME, ИСПОЛЬЗУЕМ USERNAME
        if (empty($user['display_name'])) {
            $user['display_name'] = $user['username'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'query' => $query,
        'users' => $users,
        'count' => count($users)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Search error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка поиска',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>