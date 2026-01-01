<?php
// api/init_database.php
require_once 'config.php';

try {
    // SQL для создания таблиц
    $sql = "
        -- Таблица пользователей
        CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100),
            avatar_url VARCHAR(500),
            bio TEXT,
            status ENUM('online', 'offline', 'idle') DEFAULT 'offline',
            last_seen DATETIME,
            role ENUM('user', 'admin', 'tester') DEFAULT 'user',
            is_blocked BOOLEAN DEFAULT FALSE,
            blocked_until DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        -- Таблица контактов
        CREATE TABLE IF NOT EXISTS user_contacts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            contact_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_contact (user_id, contact_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        -- Таблица приватных чатов
        CREATE TABLE IF NOT EXISTS user_chats (
            chat_id INT PRIMARY KEY AUTO_INCREMENT,
            user1_id INT NOT NULL,
            user2_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_message_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_chat_pair (user1_id, user2_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        -- Таблица сообщений
        CREATE TABLE IF NOT EXISTS messages (
            id INT PRIMARY KEY AUTO_INCREMENT,
            chat_id INT NOT NULL,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chat_id) REFERENCES user_chats(chat_id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_chat_created (chat_id, created_at),
            INDEX idx_sender_receiver (sender_id, receiver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    // Выполняем SQL
    $pdo->exec($sql);
    
    echo json_encode([
        'success' => true,
        'message' => 'Таблицы успешно созданы'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>