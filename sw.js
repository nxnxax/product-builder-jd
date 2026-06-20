// 영맨 PWA 설치 가능 조건 충족용 최소 service worker.
//   fetch 핸들러만 존재(설치 criteria) — respondWith 없음 = 네트워크 그대로 통과, 캐시/오프라인 동작 없음(안전).
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* pass-through */ });
