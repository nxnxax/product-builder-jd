/**
 * YOUNGMAN WebView Bridge
 *
 * 웹 ↔ 네이티브 앱 (React Native WebView) 메시지 브리지.
 * 일반적인 WebView 앱 기본세트:
 *   - 인증 토큰 전달 (login/logout)
 *   - FCM 토큰 수신
 *   - 외부 링크 시스템 브라우저 열기
 *   - Android 뒤로가기 가로채기
 *   - 앱 정보 / 포어그라운드 복귀 / 푸시 탭 진입
 *
 * 전송 (web → app):
 *   window.ReactNativeWebView.postMessage(JSON.stringify({ type, payload, ts }))
 *
 * 수신 (app → web):
 *   app 이 injectJavaScript 로 window.YoungmanBridge.<handler>(...) 호출.
 *
 * 브라우저(앱 외부)에서는 모든 호출이 no-op 이라 안전합니다.
 *
 * 전체 메시지 스펙은 BRIDGE_API.md 참고.
 */

const BRIDGE_VERSION = '1.1.0';

function isInApp() {
    if (typeof window === 'undefined') return false;
    // ① RN WebView 표준 — ReactNativeWebView.postMessage 존재 시 즉시 true.
    if (window.ReactNativeWebView && typeof window.ReactNativeWebView.postMessage === 'function') {
        return true;
    }
    // ② user-agent fallback — 앱 빌드가 UA 끝에 'YoungmanApp' 키워드 부착.
    //    RN WebView 의 ReactNativeWebView.postMessage inject 가 timing/플랫폼 차이로
    //    nav 첫 렌더 시점에 아직 안 들어와있을 수 있음 → UA 가 안전한 보조 신호.
    try {
        if (/YoungmanApp/.test(navigator.userAgent || '')) return true;
    } catch {}
    return false;
}

function postToApp(type, payload) {
    if (!isInApp()) return false;
    try {
        const msg = JSON.stringify({
            type: String(type),
            payload: payload === undefined ? null : payload,
            ts: Date.now(),
        });
        window.ReactNativeWebView.postMessage(msg);
        return true;
    } catch (e) {
        try { console.warn('[bridge] postToApp failed', type, e); } catch {}
        return false;
    }
}

// ─── 핸들러 레지스트리 (app → web) ────────────────────────────────────
// 앱이 inject 한 스크립트에서 window.YoungmanBridge.<name>(...) 형태로 호출.
// 같은 이름의 외부 핸들러를 한 번 더 등록하면 마지막 등록이 우선.
const _handlers = new Map();

function setHandler(name, fn) {
    if (typeof fn !== 'function') return;
    _handlers.set(String(name), fn);
}

function _invoke(name, ...args) {
    const fn = _handlers.get(name);
    if (!fn) return undefined;
    try { return fn(...args); }
    catch (e) { try { console.warn('[bridge] handler error', name, e); } catch {} }
}

// ─── 외부 링크 인터셉트 ────────────────────────────────────────────────
// 앱 안에서 <a target="_blank"> 또는 외부 호스트 링크 클릭 시 시스템 브라우저로.
// (WebView 내부에서 열리면 돌아올 길이 없어 보이는 UX 문제 회피.)
function _isExternalUrl(href) {
    if (!href) return false;
    try {
        const u = new URL(href, window.location.href);
        if (u.protocol !== 'http:' && u.protocol !== 'https:') return false;
        return u.host !== window.location.host;
    } catch { return false; }
}

function _installLinkInterceptor() {
    if (!isInApp()) return;
    document.addEventListener('click', (e) => {
        const a = e.target?.closest?.('a[href]');
        if (!a) return;
        const href = a.getAttribute('href');
        const target = (a.getAttribute('target') || '').toLowerCase();
        const isBlank = target === '_blank';
        const isExternal = _isExternalUrl(href);
        if (!isBlank && !isExternal) return;
        // mailto:, tel: 등은 앱도 시스템 핸들러로 위임하길 원함
        e.preventDefault();
        postToApp('nav.openExternal', { url: a.href });
    }, { capture: true });
}

// ─── 고수준 헬퍼 ──────────────────────────────────────────────────────
// notifyLogout race guard (ChatGPT 진단 2026-05-20 반영):
// 최근 notifyLogin 시각이 N초 안이면 notifyLogout 무시. stale logout 이 새 토큰 덮어쓰기 방지.
let _lastNotifyLoginAt = 0;
const NOTIFY_LOGOUT_COOLDOWN_MS = 30_000;  // 30초

function notifyLogin(session) {
    if (!session || !session.access_token) return;
    _lastNotifyLoginAt = Date.now();
    postToApp('auth.login', {
        accessToken: session.access_token,
        refreshToken: session.refresh_token || null,
        userId: session.user?.id || null,
        email: session.user?.email || null,
        expiresAt: session.expires_at || null,
        authEpoch: _lastNotifyLoginAt,  // RN 측 stale logout 판별용
    });
}

function notifyLogout() {
    // race guard — 최근 30초 안에 notifyLogin 보낸 적 있으면 stale logout 으로 간주.
    if (_lastNotifyLoginAt > 0 && (Date.now() - _lastNotifyLoginAt) < NOTIFY_LOGOUT_COOLDOWN_MS) {
        try { console.warn('[bridge] notifyLogout skipped — recent login within 30s'); } catch {}
        return;
    }
    postToApp('auth.logout', null);
}

function openExternal(url) {
    if (!url) return;
    if (isInApp()) postToApp('nav.openExternal', { url });
    else { try { window.open(url, '_blank', 'noopener'); } catch {} }
}

function share(payload) {
    if (isInApp()) { postToApp('nav.share', payload || {}); return true; }
    if (navigator.share) { try { navigator.share(payload || {}); return true; } catch {} }
    return false;
}

function requestAppInfo() { postToApp('app.info.request', null); }
function requestFcmToken() { postToApp('app.fcm.request', null); }
function setStatusBar(style, color) { postToApp('app.statusBar', { style, color }); }
function haptic(type) { postToApp('app.haptic', { type: type || 'light' }); }

// ─── Heartbeat (앱팀 2026-05-20 요청) ────────────────────────────────
// WebView 가 살아있음을 RN 에 주기적으로 알림. RN 의 Native Fallback Refresh 분기 기준.
//   - 페이지 로드 직후 1회
//   - 30초 setInterval
//   - 세션 변경 / refresh 시작·종료 시 즉시
// 60s 이상 안 오면 RN 은 WebView 사망으로 간주 → Native fallback 우선.
let _sessionSnapshot = { hasSession: false, expiresAt: null };
let _refreshInflightFlag = false;
const HEARTBEAT_INTERVAL_MS = 30_000;

function sendHeartbeat() {
    if (!isInApp()) return;
    try {
        postToApp('bridge.heartbeat', {
            bridgeReady: true,
            hasSession: !!_sessionSnapshot.hasSession,
            expiresAt: _sessionSnapshot.expiresAt || null,
            refreshInflight: !!_refreshInflightFlag,
            timestamp: Date.now(),
        });
    } catch {}
}

// auth-shared.js 가 호출 — 세션 변경 시 RN 에 즉시 통보.
function setSessionSnapshot(session) {
    _sessionSnapshot = session && session.access_token ? {
        hasSession: true,
        expiresAt: session.expires_at || null,
    } : { hasSession: false, expiresAt: null };
    sendHeartbeat();
}

// auth-shared.js 가 호출 — _refreshInflight 진입/해제 시 RN 에 즉시 통보.
function setRefreshInflight(flag) {
    const next = !!flag;
    if (_refreshInflightFlag === next) return;
    _refreshInflightFlag = next;
    sendHeartbeat();
}

// ─── 기본 핸들러 (override 가능) ──────────────────────────────────────
// 앱이 onBack 호출 시 — 모달/드로어가 열려있으면 닫고 true 반환,
// 아니면 false 반환해서 앱이 라우터 pop 또는 종료를 처리하게 함.
function _defaultOnBack() {
    // 흔한 닫기 셀렉터 우선
    const closeables = [
        '.modal.is-open', '.modal[open]', '.drawer.is-open',
        '.sheet.is-open', '[data-modal-open="true"]',
    ];
    for (const sel of closeables) {
        const el = document.querySelector(sel);
        if (el) {
            const closeBtn = el.querySelector('[data-close], .close, .modal-close');
            if (closeBtn) { closeBtn.click(); return true; }
            el.classList.remove('is-open');
            el.removeAttribute('open');
            return true;
        }
    }
    return false;
}

setHandler('onBack', _defaultOnBack);

// ─── 앱 정보 캐시 ────────────────────────────────────────────────────
let _appInfo = null;
let _fcmToken = null;

setHandler('onAppInfo', (info) => { _appInfo = info || null; });
setHandler('onFcmToken', (token) => { _fcmToken = token || null; });

function getAppInfo() { return _appInfo; }
function getFcmToken() { return _fcmToken; }

// ─── window 노출 ─────────────────────────────────────────────────────
// 앱은 injectJavaScript 로 window.YoungmanBridge.<name>(...) 를 호출.
// DevTools / non-module 스크립트에서도 동일 객체로 web→app 헬퍼 호출 가능.
const YoungmanBridge = {
    version: BRIDGE_VERSION,
    isInApp,
    // ── app → web (앱이 호출) ──
    // 등록형 핸들러 dispatch — 앱은 이 한 군데만 호출하면 됨.
    handle(name, ...args) { return _invoke(name, ...args); },
    // 자주 쓰는 핸들러는 직접 호출도 허용 (편의)
    onReady(info) { _appInfo = info || null; _invoke('onReady', info); },
    onAppInfo(info) { _appInfo = info || null; _invoke('onAppInfo', info); },
    onFcmToken(token) { _fcmToken = token || null; _invoke('onFcmToken', token); },
    onPushOpen(data) { _invoke('onPushOpen', data); },
    onAppResume() { _invoke('onAppResume'); },
    onBack() { return _invoke('onBack') === true; },
    // 외부 핸들러 등록
    setHandler,
    // ── web → app (웹이 호출) ──
    postToApp,
    notifyLogin,
    notifyLogout,
    openExternal,
    share,
    requestAppInfo,
    requestFcmToken,
    setStatusBar,
    haptic,
    // 디버깅용 — 현재 캐시된 앱 정보
    getAppInfo,
    getFcmToken,
    // Heartbeat (앱팀 2026-05-20)
    sendHeartbeat,
    setSessionSnapshot,
    setRefreshInflight,
};

if (typeof window !== 'undefined') {
    window.YoungmanBridge = YoungmanBridge;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _installLinkInterceptor, { once: true });
    } else {
        _installLinkInterceptor();
    }
    // 앱에 페이지 ready 통보 — 앱이 이걸 받으면 onReady(info) 로 응답할 수 있음.
    postToApp('bridge.ready', {
        version: BRIDGE_VERSION,
        page: location.pathname,
        userAgent: navigator.userAgent,
    });
    // Heartbeat — 페이지 로드 직후 + 30초 interval (앱팀 2026-05-20).
    if (isInApp()) {
        setTimeout(sendHeartbeat, 200);
        setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);
    }
}

export {
    isInApp,
    postToApp,
    setHandler,
    notifyLogin,
    notifyLogout,
    openExternal,
    share,
    requestAppInfo,
    requestFcmToken,
    setStatusBar,
    haptic,
    getAppInfo,
    getFcmToken,
    sendHeartbeat,
    setSessionSnapshot,
    setRefreshInflight,
};

export default YoungmanBridge;
