<?php
// Регистрация нового пользователя
// Метод: POST
// Поля: username, email, password, display_name (опционально)

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config.php';

// Разрешаем только POST-запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Метод не разрешен. Используйте POST.']);
    exit();
}

// Получаем и проверяем входные данные
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    $input = $_POST; // На случай, если данные пришли как form-data
}

// Проверяем обязательные поля
$required_fields = ['username', 'email', 'password'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => "Обязательное поле '$field' отсутствует."]);
        exit();
    }
}

// Очищаем и валидируем данные
$username = trim($input['username']);
$email = trim($input['email']);
$password = $input['password'];
$display_name = !empty($input['display_name']) ? trim($input['display_name']) : $username;

// Валидация имени пользователя (3-32 символа, только буквы, цифры, _)
if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
    echo json_encode(['error' => 'Имя пользователя должно содержать от 3 до 32 символов (буквы, цифры, подчёркивание).']);
    exit();
}

// Валидация email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'Некорректный email адрес.']);
    exit();
}

// Валидация пароля (минимум 6 символов)
if (strlen($password) < 6) {
    echo json_encode(['error' => 'Пароль должен содержать минимум 6 символов.']);
    exit();
}

try {
    // Проверяем, не занят ли username
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Имя пользователя уже занято.']);
        exit();
    }
    
    // Проверяем, не занят ли email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Email уже используется.']);
        exit();
    }
    
    // Хэшируем пароль
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Генерируем токен для верификации email (пока просто сохраняем, отправку писем добавим позже)
    $verification_token = bin2hex(random_bytes(32));
    
    // Вставляем пользователя в базу
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, display_name, verification_token) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $username,
        $email,
        $password_hash,
        $display_name,
        $verification_token
    ]);
    
    $user_id = $pdo->lastInsertId();
    
    // Получаем созданного пользователя (без пароля)
    $stmt = $pdo->prepare("
        SELECT id, username, email, display_name, avatar_url, status, created_at 
        FROM users WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    // Успешный ответ
    http_response_code(201); // Created
    echo json_encode([
        'success' => true,
        'message' => 'Пользователь успешно зарегистрирован.',
        'user' => $user,
        'note' => 'На данный момент подтверждение email не требуется. Функция будет добавлена позже.'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка базы данных.',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>