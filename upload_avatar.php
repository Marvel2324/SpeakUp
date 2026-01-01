<?php
require_once '../config.php';

// Включаем вывод ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Устанавливаем заголовки
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Обрабатываем OPTIONS запросы
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ФУНКЦИЯ ДЛЯ ПОЛУЧЕНИЯ ТОКЕНА ИЗ РАЗНЫХ ИСТОЧНИКОВ
function getTokenFromRequest() {
    $token = '';
    
    // 1. Из заголовка Authorization (основной способ)
    $headers = getallheaders();
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            if (strpos($value, 'Bearer ') === 0) {
                $token = substr($value, 7);
                break;
            } else {
                $token = $value;
                break;
            }
        }
    }
    
    // 2. Из GET параметра (для тестов)
    if (empty($token) && isset($_GET['token'])) {
        $token = $_GET['token'];
    }
    
    return trim($token);
}

// ФУНКЦИЯ ДЛЯ ПОЛУЧЕНИЯ USER_ID ИЗ ТОКЕНА
function getUserIdFromToken($token) {
    if (empty($token)) return null;
    
    try {
        // Пробуем декодировать base64
        $decoded = base64_decode($token);
        if ($decoded) {
            $payload = json_decode($decoded, true);
            if ($payload && isset($payload['user_id'])) {
                return intval($payload['user_id']);
            }
        }
        
        // Пробуем как чистый JSON
        $payload = json_decode($token, true);
        if ($payload && isset($payload['user_id'])) {
            return intval($payload['user_id']);
        }
        
    } catch (Exception $e) {
        error_log("Token decode error: " . $e->getMessage());
    }
    
    return null;
}

// ПОЛУЧАЕМ ТОКЕН
$token = getTokenFromRequest();

// ЛОГИРУЕМ ВХОДНЫЕ ДАННЫЕ (для отладки)
error_log("=== AVATAR UPLOAD DEBUG ===");
error_log("Token: " . ($token ? substr($token, 0, 30) . "..." : "EMPTY"));
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log("POST data: " . json_encode($_POST));
error_log("FILES data: " . json_encode($_FILES));

if (empty($token)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Токен не предоставлен',
        'debug' => [
            'method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'
        ]
    ]);
    exit;
}

// ПОЛУЧАЕМ USER_ID
$user_id = getUserIdFromToken($token);

if (!$user_id) {
    echo json_encode([
        'success' => false, 
        'error' => 'Неверный токен. Не удалось извлечь user_id'
    ]);
    exit;
}

// ПРОВЕРЯЕМ МЕТОД ЗАПРОСА
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false, 
        'error' => 'Только POST запросы разрешены'
    ]);
    exit;
}

// ПРОВЕРЯЕМ, ЧТО ФАЙЛ ЗАГРУЖЕН
if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $error_msg = 'Файл не загружен';
    
    if (isset($_FILES['avatar'])) {
        switch ($_FILES['avatar']['error']) {
            case UPLOAD_ERR_INI_SIZE: $error_msg = 'Файл слишком большой (настройки сервера)'; break;
            case UPLOAD_ERR_FORM_SIZE: $error_msg = 'Файл слишком большой (форма)'; break;
            case UPLOAD_ERR_PARTIAL: $error_msg = 'Файл загружен частично'; break;
            case UPLOAD_ERR_NO_FILE: $error_msg = 'Файл не выбран'; break;
            case UPLOAD_ERR_NO_TMP_DIR: $error_msg = 'Отсутствует временная папка'; break;
            case UPLOAD_ERR_CANT_WRITE: $error_msg = 'Ошибка записи на диск'; break;
            case UPLOAD_ERR_EXTENSION: $error_msg = 'Расширение PHP остановило загрузку'; break;
        }
    }
    
    echo json_encode([
        'success' => false, 
        'error' => $error_msg,
        'files_info' => $_FILES
    ]);
    exit;
}

$file = $_FILES['avatar'];

// ПРОВЕРЯЕМ ТИП ФАЙЛА
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];
if (!in_array($file['type'], $allowed_types)) {
    echo json_encode([
        'success' => false, 
        'error' => 'Неподдерживаемый формат изображения. Допустимые: JPEG, PNG, GIF, WebP',
        'file_type' => $file['type']
    ]);
    exit;
}

// ПРОВЕРЯЕМ РАЗМЕР ФАЙЛА (макс 5MB)
$max_size = 5 * 1024 * 1024;
if ($file['size'] > $max_size) {
    echo json_encode([
        'success' => false, 
        'error' => 'Файл слишком большой. Максимальный размер: 5MB',
        'file_size' => $file['size']
    ]);
    exit;
}

// ПРОВЕРЯЕМ, ЯВЛЯЕТСЯ ЛИ ФАЙЛ ИЗОБРАЖЕНИЕМ
$image_info = @getimagesize($file['tmp_name']);
if (!$image_info) {
    echo json_encode(['success' => false, 'error' => 'Файл не является изображением']);
    exit;
}

// ПУТИ ДЛЯ СОХРАНЕНИЯ
$base_dir = '/var/www/whg94605/data/www/speakup.hgweb.ru/';
$upload_dir = $base_dir . 'uploads/avatars/';

// СОЗДАЕМ ПАПКИ ЕСЛИ ИХ НЕТ
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// СОЗДАЕМ ПАПКУ ДЛЯ ПОЛЬЗОВАТЕЛЯ
$user_dir = $upload_dir . 'user_' . $user_id . '/';
if (!file_exists($user_dir)) {
    mkdir($user_dir, 0755, true);
}

// ГЕНЕРИРУЕМ ИМЯ ФАЙЛА
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (empty($extension)) {
    // Определяем расширение по MIME-типу
    $mime_map = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    $extension = $mime_map[$file['type']] ?? 'jpg';
}

$filename = 'avatar_' . $user_id . '_' . time() . '_' . uniqid() . '.' . $extension;
$filepath = $user_dir . $filename;

// ПЫТАЕМСЯ СОХРАНИТЬ ФАЙЛ
if (move_uploaded_file($file['tmp_name'], $filepath)) {
    // Формируем URL для доступа
    $avatar_url = 'https://speakup.hgweb.ru/uploads/avatars/user_' . $user_id . '/' . $filename;
    
    try {
        // ОБНОВЛЯЕМ БАЗУ ДАННЫХ
        $stmt = $pdo->prepare("UPDATE users SET avatar_url = ?, avatar_updated_at = NOW() WHERE id = ?");
        $stmt->execute([$avatar_url, $user_id]);
        
        // ОЧИЩАЕМ СТАРЫЕ АВАТАРКИ (оставляем последние 3)
        $old_files = glob($user_dir . 'avatar_*');
        if (count($old_files) > 3) {
            // Сортируем по времени изменения
            usort($old_files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Удаляем старые
            for ($i = 0; $i < count($old_files) - 3; $i++) {
                @unlink($old_files[$i]);
            }
        }
        
        // УСПЕШНЫЙ ОТВЕТ
        echo json_encode([
            'success' => true,
            'avatar_url' => $avatar_url,
            'message' => 'Аватар успешно обновлен',
            'debug' => [
                'user_id' => $user_id,
                'file_saved' => $filepath,
                'url' => $avatar_url
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        // Удаляем файл если ошибка БД
        @unlink($filepath);
        error_log("Database error: " . $e->getMessage());
        
        echo json_encode([
            'success' => false,
            'error' => 'Ошибка базы данных: ' . $e->getMessage()
        ]);
    }
    
} else {
    // ОШИБКА СОХРАНЕНИЯ ФАЙЛА
    $error_msg = 'Ошибка при сохранении файла';
    if (!is_writable($user_dir)) {
        $error_msg .= '. Папка недоступна для записи: ' . $user_dir;
    }
    
    echo json_encode([
        'success' => false,
        'error' => $error_msg,
        'debug' => [
            'upload_dir' => $upload_dir,
            'user_dir' => $user_dir,
            'is_writable' => is_writable($user_dir)
        ]
    ]);
}
?>