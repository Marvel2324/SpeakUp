// service-worker.js - минимальный
self.addEventListener('install', function(event) {
  console.log('Service Worker установлен');
});

self.addEventListener('fetch', function(event) {
  // Просто пропускаем запросы
});