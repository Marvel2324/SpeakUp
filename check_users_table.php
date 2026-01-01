<?php
require_once 'config.php';

try {
    // Получаем структуру таблицы
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll();
    
    echo "<h3>Структура таблицы users:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Поле</th><th>Тип</th><th>Null</th><th>Key</th><th>По умолчанию</th><th>Дополнительно</th></tr>";
    
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td><strong>{$col['Field']}</strong></td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "<td>{$col['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Проверяем статус отдельно
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'");
    $status_col = $stmt->fetch();
    
    echo "<h3>Информация о поле 'status':</h3>";
    echo "<pre>";
    print_r($status_col);
    echo "</pre>";
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage();
}
?>