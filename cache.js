// cache.js - НОВАЯ СИСТЕМА КЭШИРОВАНИЯ СООБЩЕНИЙ
// ===============================================

// Объект для хранения кэша в памяти
let messageCache = {
    private: {}, // Формат: { "myId_userId": [массив сообщений] }
    groups: {}   // Формат: { "group_groupId": [массив сообщений] }
};

// Генерация уникального ключа для кэша
function getCacheKey(type, targetId) {
    if (!window.myId || !targetId) return null;
    
    if (type === 'private') {
        // Для приватных чатов: мой ID + ID собеседника
        return `${window.myId}_${targetId}`;
    } else if (type === 'group') {
        // Для групп: префикс + ID группы
        return `group_${targetId}`;
    }
    return null;
}

// Сохранить сообщения в кэш
function saveToCache(type, targetId, messages) {
    try {
        if (!messages || !Array.isArray(messages)) return;
        
        const key = getCacheKey(type, targetId);
        if (!key) return;
        
        // Сохраняем в памяти
        if (type === 'private') {
            messageCache.private[key] = messages;
        } else {
            messageCache.groups[key] = messages;
        }
        
        // Сохраняем в localStorage как резервную копию
        localStorage.setItem(`cache_v2_${key}`, JSON.stringify({
            messages: messages,
            timestamp: Date.now(),
            type: type
        }));
        
        console.log(`✅ Сохранено в кэш: ${type} ${targetId}, сообщений: ${messages.length}`);
        
    } catch (e) {
        console.error('❌ Ошибка сохранения в кэш:', e);
    }
}

// Загрузить сообщения из кэша
function loadFromCache(type, targetId) {
    try {
        const key = getCacheKey(type, targetId);
        if (!key) return [];
        
        // 1. Пробуем загрузить из памяти
        let messages = [];
        if (type === 'private' && messageCache.private[key]) {
            messages = messageCache.private[key];
        } else if (type === 'group' && messageCache.groups[key]) {
            messages = messageCache.groups[key];
        }
        
        // 2. Если в памяти нет, пробуем localStorage
        if (messages.length === 0) {
            const cached = localStorage.getItem(`cache_v2_${key}`);
            if (cached) {
                const data = JSON.parse(cached);
                messages = data.messages || [];
                
                // Восстанавливаем в память
                if (type === 'private') {
                    messageCache.private[key] = messages;
                } else {
                    messageCache.groups[key] = messages;
                }
                
                console.log(`📂 Загружено из localStorage: ${type} ${targetId}, сообщений: ${messages.length}`);
            }
        } else {
            console.log(`💾 Загружено из памяти: ${type} ${targetId}, сообщений: ${messages.length}`);
        }
        
        return messages;
        
    } catch (e) {
        console.error('❌ Ошибка загрузки из кэша:', e);
        return [];
    }
}

// Очистить кэш для конкретного чата
function clearCache(type, targetId) {
    try {
        const key = getCacheKey(type, targetId);
        if (!key) return;
        
        if (type === 'private') {
            delete messageCache.private[key];
        } else {
            delete messageCache.groups[key];
        }
        
        localStorage.removeItem(`cache_v2_${key}`);
        
        console.log(`🧹 Очищен кэш: ${type} ${targetId}`);
        
    } catch (e) {
        console.error('❌ Ошибка очистки кэша:', e);
    }
}

// Очистить ВЕСЬ старый кэш (вызвать один раз при загрузке)
function clearAllOldCache() {
    try {
        console.log('🧹 Начинаю очистку старого кэша...');
        
        // Удаляем ВСЕ старые ключи кэша
        const keysToRemove = [];
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            // Удаляем все ключи старой системы кэширования
            if (key.startsWith('speakup_messages_') || 
                key.startsWith('speakup_msgs_') ||
                key.startsWith('chat_') ||
                key.startsWith('messages_') ||
                key.startsWith('user_') && key.includes('_messages') ||
                (key.startsWith('cache_') && !key.startsWith('cache_v2_'))) {
                keysToRemove.push(key);
            }
        }
        
        let removedCount = 0;
        keysToRemove.forEach(key => {
            localStorage.removeItem(key);
            removedCount++;
            console.log(`🗑️ Удален старый ключ: ${key}`);
        });
        
        console.log(`✅ Очистка завершена. Удалено ключей: ${removedCount}`);
        
        // Также очищаем кэш в памяти
        messageCache.private = {};
        messageCache.groups = {};
        
    } catch (e) {
        console.error('❌ Ошибка очистки старого кэша:', e);
    }
}

// Добавить одно сообщение в кэш
function addMessageToCache(type, targetId, message) {
    try {
        if (!message) return;
        
        const existing = loadFromCache(type, targetId);
        
        // Проверяем, нет ли дубликата
        const exists = existing.some(msg => 
            msg.id === message.id || 
            (msg.message === message.message && 
             msg.sender_id === message.sender_id &&
             Math.abs(new Date(msg.created_at) - new Date(message.created_at)) < 1000)
        );
        
        if (!exists) {
            existing.push(message);
            
            // Ограничиваем количество сообщений (последние 100)
            if (existing.length > 100) {
                existing.splice(0, existing.length - 100);
            }
            
            // Сортируем по времени
            existing.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
            
            // Сохраняем обновленный кэш
            saveToCache(type, targetId, existing);
            
            console.log(`➕ Добавлено сообщение в кэш: ${type} ${targetId}`);
        }
        
    } catch (e) {
        console.error('❌ Ошибка добавления сообщения в кэш:', e);
    }
}