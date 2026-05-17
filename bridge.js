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
    return typeof window !== 'undefined'
        && !!window.ReactNativeWebView
        && typeof window.ReactNativeWebView.postMessage === 'function';
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
function notifyLogin(session) {
    if (!session || !session.access_token) return;
    postToApp('auth.login', {
        accessToken: session.access_token,
        refreshToken: session.refresh_token || null,
        userId: session.user?.id || null,
        email: session.user?.email || null,
        expiresAt: session.expires_at || null,
    });
}

function notifyLogout() {
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
};

export default YoungmanBridge;
