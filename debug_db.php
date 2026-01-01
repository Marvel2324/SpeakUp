<?php
require_once 'config.php';
echo "База: " . DB_NAME . "<br>";
echo "Пользователь: " . DB_USER . "<br>";
try {
    $pdo->query("SELECT 1");
    echo "✅ Подключение к БД работает";
} catch(Exception $e) {
    echo "❌ Ошибка БД: " . $e->getMessage();
}
?>