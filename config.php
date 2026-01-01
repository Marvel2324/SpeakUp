<?php
// api/config.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Настройки базы данных
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'speakup');
define('DB_USER', 'speakup');
define('DB_PASS', 'SpeakUp121202_');
define('DEBUG_MODE', true);

// Секретный ключ для JWT
define('JWT_SECRET', 'your-secret-key-change-this');

// Подключение к базе данных
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}
?>