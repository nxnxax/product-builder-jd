/**
 * Shared auth + API helpers used by index, profile, and admin pages.
 * Keeps Supabase client initialization in one place.
 */

// 네이티브 앱 (React Native WebView) 브리지 — 브라우저에서는 모든 호출 no-op.
import { notifyLogin as _bridgeLogin, notifyLogout as _bridgeLogout } from './bridge.js?v=20260517-bridge-v1';

const API_URL = 'records.php';

let supabaseClient = null;
let currentSession = null;
let initPromise = null;

async function loadConfig() {
    try {
        const config = await import(`./supabase_config.js?v=${Date.now()}`);
        const url = String(config.SUPABASE_URL || '').trim().replace(/\/+$/, '');
        const anonKey = String(config.SUPABASE_ANON_KEY || '').trim();
        if (!url || !anonKey) return null;
        return { url, anonKey };
    } catch {
        return null;
    }
}

export async function initSupabase() {
    if (initPromise) return initPromise;
    initPromise = (async () => {
        const config = await loadConfig();
        if (!config) return { client: null, session: null };
        const { createClient } = await import('https://esm.sh/@supabase/supabase-js@2');
        supabaseClient = createClient(config.url, config.anonKey);
        const { data } = await supabaseClient.auth.getSession();
        currentSession = data?.session || null;
        cacheUserEmail(currentSession?.user?.email);
        // 이미 로그인된 상태로 페이지 진입 시 onAuthStateChange 가 INITIAL_SESSION
        // 을 발화 안 할 수도 있어 명시적으로 한 번 호출 — 슬롯 dropdown 양식 표시 보장.
        if (currentSession?.user) {
            try { refreshNavFormsCache(); } catch {}
            // 고아 user (supabase auth 만 있고 members 없음) 자동 복구 — 매 페이지 boot 시.
            try { ensureMemberRowOnce(); } catch {}
        }
        // 초기 진입 시 이미 로그인 상태면 앱에 토큰 전달 (FCM 매핑/푸시 알림용)
        if (currentSession?.access_token) { try { _bridgeLogin(currentSession); } catch {} }
        supabaseClient.auth.onAuthStateChange((event, session) => {
            const had = !!currentSession?.user;
            currentSession = session || null;
            cacheUserEmail(currentSession?.user?.email);
            // 로그인 전환 시점에 사용자 정의 양식 목록을 서버에서 새로 가져와
            // 슬롯 dropdown 에 즉시 반영. 같은 디바이스에서 로그아웃→재로그인
            // 케이스의 양식 누락 문제 fix.
            if (currentSession?.user && (event === 'SIGNED_IN' || event === 'INITIAL_SESSION' || event === 'TOKEN_REFRESHED' || !had)) {
                try { refreshNavFormsCache(); } catch {}
                try { ensureMemberRowOnce(); } catch {}
                try { _bridgeLogin(currentSession); } catch {}
            }
            if (event === 'SIGNED_OUT') { try { _bridgeLogout(); } catch {} }
        });
        return { client: supabaseClient, session: currentSession };
    })();
    return initPromise;
}

// 고아 user 자동 복구 — auth.users 엔 있고 public.members 엔 없는 사용자.
// session 당 한 번만 호출 (sessionStorage flag). 매 페이지마다 호출 안 해도 됨.
let _ensureInflight = null;
async function ensureMemberRowOnce() {
    try { if (sessionStorage.getItem('erp.memberEnsured') === '1') return; } catch {}
    if (_ensureInflight) return _ensureInflight;
    if (!currentSession?.access_token) return;

    _ensureInflight = (async () => {
        try {
            const meta = currentSession.user?.user_metadata || {};
            const resp = await fetch('records.php?resource=auth-member&ensure=1', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + currentSession.access_token,
                },
                body: JSON.stringify({
                    resource: 'auth-member',
                    ensure: true,
                    email: currentSession.user?.email || '',
                    fullName: meta.full_name || meta.name || '',
                    phone: meta.phone || '',
                    nickname: meta.nickname || '',
                }),
            });
            const data = await resp.json().catch(() => ({}));
            // 명시 string 으로 출력 — Object 펼쳐야 보이는 문제 차단
            console.log('[ensure member auto] STATUS=' + resp.status + ' ERROR=' + (data?.error || '(none)') + ' COLUMNS=' + JSON.stringify(data?.columns || []) + ' TABLE=' + (data?.table || '(none)'));
            console.log('[ensure member auto] FULL', data);
            if (resp.ok && (data?.ok || data?.already)) {
                try { sessionStorage.setItem('erp.memberEnsured', '1'); } catch {}
            } else {
                try {
                    sessionStorage.setItem('erp.ensureError', JSON.stringify({
                        status: resp.status,
                        error: data?.error || 'unknown',
                        columns: data?.columns,
                        table: data?.table,
                        sqlState: data?.sql_state,
                        at: new Date().toISOString(),
                    }));
                } catch {}
                // 사용자가 즉시 확인 가능하도록 console.error 로 강조
                console.error('[ensure member auto] 멤버 행 생성 실패: ' + (data?.error || 'HTTP ' + resp.status));
            }
        } catch (e) {
            console.error('[ensure member auto] fetch error', e);
        } finally {
            _ensureInflight = null;
        }
    })();
    return _ensureInflight;
}

export function getSession() { return currentSession; }
export function getClient() { return supabaseClient; }

function cacheUserEmail(email) {
    try {
        const prev = sessionStorage.getItem('erp.userEmail') || '';
        if (email) {
            const v = String(email).toLowerCase();
            // 사용자 전환 감지 시 이전 사용자 양식 캐시 정리 (다른 사용자의 양식이
            // 잠시 보이거나 새 사용자가 stale 캐시를 그대로 쓰는 버그 방지).
            if (prev && prev !== v) {
                try { sessionStorage.removeItem('erp.customForms'); } catch {}
            }
            sessionStorage.setItem('erp.userEmail', v);
            localStorage.setItem('erp.userEmail.last', v);
        } else {
            sessionStorage.removeItem('erp.userEmail');
        }
    } catch {}
}

export async function getAccessToken({ forceRefresh = false } = {}) {
    if (!supabaseClient) return null;
    if (forceRefresh) {
        const { data } = await supabaseClient.auth.getSession();
        currentSession = data?.session || currentSession;
    }
    return currentSession?.access_token || null;
}

/* 만료 임박/만료된 access_token 을 refresh_token 으로 갱신. 모바일 백그라운드 탭에서
   setInterval 기반 auto-refresh 가 멈춰 토큰이 만료되는 케이스 대응. */
async function ensureFreshAccessToken() {
    if (!supabaseClient) return null;
    try {
        const nowSec = Math.floor(Date.now() / 1000);
        const exp = Number(currentSession?.expires_at || 0);
        // 만료 60초 이내 또는 이미 만료 → 강제 refresh
        if (!exp || exp - nowSec < 60) {
            const { data, error } = await supabaseClient.auth.refreshSession();
            if (!error && data?.session) currentSession = data.session;
        } else {
            const { data } = await supabaseClient.auth.getSession();
            currentSession = data?.session || currentSession;
        }
    } catch {}
    return currentSession?.access_token || null;
}

export async function apiRequest(resource, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
    };
    let token = await ensureFreshAccessToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    const url = options.query
        ? `${API_URL}?resource=${encodeURIComponent(resource)}&${options.query}`
        : `${API_URL}?resource=${encodeURIComponent(resource)}`;

    let response = await fetch(url, {
        method: options.method || 'GET',
        headers,
        body: options.body,
    });

    // 401 → refresh_token 으로 한 번 더 갱신 후 재시도. (서버 stale clock / 비정상 만료 케이스 보호.)
    if (response.status === 401 && supabaseClient) {
        try {
            const { data, error } = await supabaseClient.auth.refreshSession();
            if (!error && data?.session?.access_token) {
                currentSession = data.session;
                const retryHeaders = { ...headers, Authorization: `Bearer ${data.session.access_token}` };
                response = await fetch(url, {
                    method: options.method || 'GET',
                    headers: retryHeaders,
                    body: options.body,
                });
            }
        } catch {}
    }

    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok === false) {
        const err = new Error(payload?.error || `요청 실패 (${response.status})`);
        err.status = response.status;
        throw err;
    }
    return payload;
}

export function isAdmin(session) {
    if (!session?.user) return false;
    const meta = session.user.app_metadata || {};
    const userMeta = session.user.user_metadata || {};
    const role = String(meta.role || userMeta.role || '').toLowerCase();
    if (role === 'admin' || role === 'owner') return true;
    return Boolean(meta.is_admin || userMeta.is_admin);
}

export function getInitial(name, email) {
    const candidate = String(name || email || '').trim();
    if (!candidate) return '·';
    return candidate.charAt(0).toUpperCase();
}

/* =========================================================================
   통일된 헤더 — 모든 페이지 공통 구조 + 사용자 표시 일관화
   ========================================================================= */

const DISPLAY_NAME_KEY = 'yman_display_name';
const ADMIN_FLAG_KEY   = 'yman_is_admin';

/** 보여줄 이름을 안전하게 결정 — members.name → user_metadata.full_name → 이메일 prefix → 이메일 */
export function getDisplayName(profile, user) {
    // 우선순위: 가입 시 입력한 닉네임 > supabase metadata.nickname > 실명 > 이메일
    const candidates = [
        profile?.nickname,
        user?.user_metadata?.nickname,
        profile?.name,
        profile?.full_name,
        user?.user_metadata?.full_name,
        user?.user_metadata?.name,
    ];
    for (const c of candidates) {
        const t = String(c ?? '').trim();
        if (t) return t;
    }
    const email = String(user?.email ?? '').trim();
    if (email) {
        const at = email.indexOf('@');
        return at > 0 ? email.slice(0, at) : email;
    }
    return '';
}

function cacheDisplayName(name) { try { sessionStorage.setItem(DISPLAY_NAME_KEY, name || ''); } catch {} }
function readCachedDisplayName() { try { return sessionStorage.getItem(DISPLAY_NAME_KEY) || ''; } catch { return ''; } }
function cacheAdminFlag(b) { try { sessionStorage.setItem(ADMIN_FLAG_KEY, b ? '1' : '0'); } catch {} }
function readCachedAdminFlag() { try { return sessionStorage.getItem(ADMIN_FLAG_KEY) === '1'; } catch { return false; } }

// 단색 라인 아이콘 (Lucide 스타일) — currentColor 따라감.
const SVG = (path) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
const ICON = {
    chart:     SVG('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>'),
    sparkles:  SVG('<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/>'),
    card:      SVG('<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>'),
    upload:    SVG('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>'),
    home:      SVG('<path d="M3 10.5 12 3l9 7.5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>'),
    building:  SVG('<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-4h6v4"/><path d="M8 7h.01"/><path d="M12 7h.01"/><path d="M16 7h.01"/><path d="M8 11h.01"/><path d="M12 11h.01"/><path d="M16 11h.01"/>'),
    fileText:  SVG('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>'),
    users:     SVG('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
    user:      SVG('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'),
    megaphone: SVG('<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>'),
    chat:      SVG('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'),
    help:      SVG('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'),
};

// 모바일 하단 nav — 헤더 / 외부 다운로드 페이지 양쪽에서 공유.
// 4탭: 홈 / 고객관리대장(메인) / 슬롯1 / 슬롯2 — 슬롯은 헤더와 동기화됨.
const SLOT_KEY_LABELS = {
    'org.html': '조직도',
    'contracts.html': '계약자',
    'forms.html': '신규 양식',
};
function resolveSlotLabel(key) {
    if (SLOT_KEY_LABELS[key]) return SLOT_KEY_LABELS[key];
    // 사용자 정의 양식 — forms.html?form=<id> 형식
    const m = String(key).match(/forms\.html\?form=(\d+)/);
    if (m) {
        const id = parseInt(m[1], 10);
        try {
            const list = JSON.parse(sessionStorage.getItem('erp.customForms') || '[]');
            const f = list.find(x => x.id === id);
            if (f) return f.name;
        } catch {}
    }
    return '양식';
}
function renderBottomNav(activeKey) {
    const path = (activeKey || (location.pathname.split('/').pop() || 'index.html')).toLowerCase();
    // 비로그인 시 본인 잔재 키 무시 — anon 키만 사용 → '+ 신규 양식' 표시
    const isAnonymous = !readCachedDisplayName();
    const email = (() => {
        if (isAnonymous) return '';
        try {
            return (sessionStorage.getItem('erp.userEmail')
                 || localStorage.getItem('erp.userEmail.last')
                 || '').toLowerCase();
        } catch { return ''; }
    })();
    const slotKey = (s) => `yman_nav_${s}:${email || 'anon'}`;
    // 사용자가 한 번도 슬롯 선택 안 했으면 '+ 신규 양식 신청' 으로 표시 (빈 fallback).
    const slot1Key = (() => { try { return localStorage.getItem(slotKey('slot1')) || ''; } catch { return ''; } })();
    const slot2Key = (() => { try { return localStorage.getItem(slotKey('slot2')) || ''; } catch { return ''; } })();
    const slot1Item = slot1Key
        ? { key: slot1Key, label: resolveSlotLabel(slot1Key), href: slot1Key, icon: ICON.building }
        : { key: 'forms.html?new=1&slot=slot1', label: '+ 신규 양식', href: 'forms.html?new=1&slot=slot1', icon: ICON.building };
    const slot2Item = slot2Key
        ? { key: slot2Key, label: resolveSlotLabel(slot2Key), href: slot2Key, icon: ICON.fileText }
        : { key: 'forms.html?new=1&slot=slot2', label: '+ 신규 양식', href: 'forms.html?new=1&slot=slot2', icon: ICON.fileText };
    const items = [
        { key: 'index.html',     label: '홈',             href: 'index.html',     icon: ICON.home },
        { key: 'customers.html', label: '고객관리대장',   href: 'customers.html', icon: ICON.users, main: true },
        slot1Item,
        slot2Item,
    ];
    const html = items.map(item => {
        const isHome = item.key === 'index.html' && (path === '' || path === 'index.html');
        const isActive = isHome || path === item.key;
        const cls = `mobile-bottom-nav-item${isActive ? ' active' : ''}${item.main ? ' main' : ''}`;
        return `
            <a class="${cls}" href="${item.href}">
                <span class="mobile-bottom-nav-icon">${item.icon}</span>
                <span class="mobile-bottom-nav-label">${escapeHtmlSafe(item.label)}</span>
            </a>
        `;
    }).join('');
    document.querySelectorAll('[data-yman-bottom-nav]').forEach(el => el.remove());
    const nav = document.createElement('nav');
    nav.className = 'mobile-bottom-nav';
    nav.setAttribute('aria-label', '주요 메뉴');
    nav.setAttribute('data-yman-bottom-nav', '');
    nav.innerHTML = html;
    document.body.appendChild(nav);
}

// 헤더 없이 bottom nav 만 그리는 경량 export — 외부 다운로드 등 비로그인 페이지용.
export function mountBottomNav(opts) {
    renderBottomNav(opts && opts.activeKey);
}

/* 사용자 정의 양식(page_type=custom) 목록을 sessionStorage 에 캐시.
   현재 캐시와 다르면 nav 를 즉시 재렌더 — 같은 페이지에서도 dropdown 갱신됨. */
async function refreshNavFormsCache() {
    if (!currentSession?.user) return;
    let payload = null;
    try {
        payload = await apiRequest('ledger-groups', { query: 'page_type=custom' });
    } catch (e) {
        // silent fail → 디버그 가능하도록 console 출력 (사용자 양식 사라짐 진단용)
        console.warn('[refreshNavFormsCache] fetch 실패:', e?.message || e);
        return;
    }
    try {
        const items = payload?.items || [];
        const list = items.map(f => ({
            id: f.id,
            name: f.name,
            navSlot: f.settings?.customSettings?.__navSlot || 'slot1',
        }));
        const newJson = JSON.stringify(list);
        let oldJson = '';
        try { oldJson = sessionStorage.getItem('erp.customForms') || ''; } catch {}
        try { sessionStorage.setItem('erp.customForms', newJson); } catch {}
        // 캐시 내용이 같아도 첫 mount 가 빈 캐시로 그려졌을 수 있어 항상 한 번은 재렌더 보장.
        const root = document.getElementById('app-header');
        if (root && !root.dataset.navRefreshing && (newJson !== oldJson || !root.dataset.navOnceRefreshed)) {
            root.dataset.navRefreshing = '1';
            root.dataset.navOnceRefreshed = '1';
            try { mountAppHeader(); } finally { delete root.dataset.navRefreshing; }
        }
    } catch (e) {
        console.warn('[refreshNavFormsCache] 처리 실패:', e?.message || e);
    }
}

// forms.js 가 양식 생성/삭제 후 명시적으로 호출 가능 — drawer/bottom-nav 즉시 동기화
export function refreshNavForms() { return refreshNavFormsCache(); }

/* =========================================================================
   로그인/회원가입 성공 후 navigation — 단일 transition 페이지(login-complete.html)로.

   이전: 모달 안에서 곧장 현재 페이지 reload/replace 했더니 supabase 의 비동기
        storage write 와 race 해서 "로그인이 가끔 안 됨" 증상 반복.

   현재: 어떤 페이지에서 로그인하든 무조건 login-complete.html 로 한 번 navigate.
        그 페이지가 getSession 으로 session 확정 + 원래 페이지(next)로 redirect.

   효과 — 로그아웃 흐름(logout.html)과 대칭적:
     - 모든 로그인 흐름이 동일 → 재발 없음.
     - storage write 가 끝날 때까지 transition 페이지가 양보 (120ms + 한번 더 확인).
     - cache-bust replace 로 bfcache 회피.
   ========================================================================= */
async function navigateAfterAuth() {
    // 원래 로그인 시도한 페이지를 그대로 next 로 — login-complete 가 같은 페이지로 돌려보냄.
    // 메인(index.html)에서 로그인하면 메인에, 서브 페이지에서 로그인하면 그 서브 페이지로.
    let next = 'index.html';
    try {
        const path = window.location.pathname.replace(/^\//, '');
        next = (path || 'index.html') + window.location.search;
    } catch {}

    const target = 'login-complete.html?next=' + encodeURIComponent(next);
    try { window.location.replace(target); }
    catch { try { window.location.href = target; } catch {} }
}

/* =========================================================================
   로그아웃 — 단일 cleanup 페이지(logout.html)로 navigate.

   이전: storage 정리 + signOut + index.html 이동을 한 번에 하다가
        같은 URL 일 때 bfcache hit, supabase 비동기 storage write race,
        분산된 로그아웃 핸들러(profile.js / main.js) 가 서로 다른 흐름을
        가져 "로그아웃이 가끔 안 됨" 증상이 반복됐다.

   현재: 단 두 단계만.
     1) 본 함수: location.replace('logout.html?_t=ts') — 한 번의 단순 navigation.
        같은 URL/bfcache 가능성 없음 (다른 path, query 다름).
     2) logout.html: 모든 storage cleanup + supabase signOut + index.html 로 redirect.
        실패 시 강제 reload fallback.

   효과:
     - 어느 페이지에서 로그아웃하든 동일 흐름.
     - replace 가 무조건 작동 (다른 path 이므로).
     - cleanup 은 새 페이지 컨텍스트에서 진행 → race condition 원천 차단.
   ========================================================================= */
export function performLogout(e) {
    // preventDefault/stopPropagation 호출하지 않음 — <a> click 의 native navigation 까지 보존.
    // body class 변경도 X — 클릭 target 의 부모 컨테이너가 display:none 되면 일부 브라우저가 navigation cancel.
    // 오직 navigation 만 시도. cleanup 은 logout.html 에서 책임.
    const target = 'logout.html?_t=' + Date.now();
    try {
        window.location.replace(target);
    } catch {
        try { window.location.href = target; } catch {
            try { window.location.assign(target); } catch {}
        }
    }
}

// =========================================================================
// bfcache 복원 시 강제 reload — auth 상태 stale 차단.
//
// 브라우저 (특히 Safari, Chrome 모바일) 는 페이지를 메모리에 보관해서 back/forward
// 시 즉시 복원함. JS module 재실행 없이 옛 DOM + 옛 state 그대로. logout 후
// 옛 페이지로 돌아가면 옛 데이터 보이는 분리 현상 (헤더는 anon 인식, 본문은 옛
// fetched 결과) 발생.
//
// pageshow event 의 persisted=true 가 bfcache 복원 신호. 이때 강제 reload 하면
// module 재실행 + fresh fetch → 현재 auth 상태와 일관된 화면.
// =========================================================================
if (typeof window !== 'undefined' && !window.__ymanPageshowBound) {
    window.__ymanPageshowBound = true;
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            try { window.location.reload(); } catch {}
        }
    });
}

// "로그인 유지" 체크박스 해제 케이스 — 브라우저/탭 종료 시 supabase 토큰 제거.
// pagehide 가 모바일에서도 안정적. beforeunload 는 데스크탑 fallback.
if (typeof window !== 'undefined' && !window.__ymanEndSessionBound) {
    window.__ymanEndSessionBound = true;
    const handleEndSession = () => {
        try {
            if (sessionStorage.getItem('erp.endSessionOnClose') !== '1') return;
            const keys = [];
            for (let i = 0; i < localStorage.length; i++) keys.push(localStorage.key(i));
            keys.forEach(k => {
                if (!k) return;
                const low = k.toLowerCase();
                if (low.startsWith('sb-') || low.includes('supabase')) {
                    try { localStorage.removeItem(k); } catch {}
                }
            });
        } catch {}
    };
    window.addEventListener('pagehide', handleEndSession);
    window.addEventListener('beforeunload', handleEndSession);
}

// document 레벨 backup 클릭 위임 — 브라우저 navigation 을 절대 막지 않음 (가장 reliable).
//
// 중요: click 처리 중에 body.is-anon 같은 클래스를 즉시 추가하면 안 된다.
// 클릭된 <a> 가 #user-menu 안에 있고 body.is-anon 이 #user-menu 를 display:none
// 으로 만들면, click target 이 click 처리 중 detach 되어 일부 모바일 브라우저
// (iOS Safari, 안드로이드 webview 등) 가 native <a> navigation 을 cancel 한다.
// → 시각 피드백은 navigation 이 시작된 후 (logout.html 페이지) 에서 처리.
if (typeof document !== 'undefined' && typeof window !== 'undefined' && !window.__ymanLogoutBound) {
    document.addEventListener('click', (e) => {
        const btn = e.target?.closest?.('#logout-btn, #drawer-logout-btn, #account-signout, [data-logout]');
        if (!btn) return;
        // a 태그면 href 만 fresh 하게 — preventDefault 호출 X, 브라우저 navigation 진행.
        // body class 변경/storage cleanup 같은 부수 작업 절대 X (navigation 막을 수 있음).
        if (btn.tagName === 'A') {
            try { btn.setAttribute('href', 'logout.html?_t=' + Date.now()); } catch {}
            return;   // 브라우저가 a href 로 navigate
        }
        // button 류는 명시적 navigation. performLogout 도 부수작업 없이 navigate 만.
        performLogout(e);
    }, true);
    window.__ymanLogoutBound = true;
}

// 사이트 전체 공통 footer — 사업자 정보 + 이용약관·개인정보처리방침 링크.
// 페이지에 <footer id="app-footer"></footer> 마커가 있으면 거기 inject, 없으면 body 끝에 자동 append.
function renderAppFooter() {
    const year = new Date().getFullYear();
    const html = `
        <div class="app-footer-inner">
            <div>
                <div class="app-footer-brand">
                    <img src="logo_main.png" alt="">
                    <span>어센트라 (Ascentra)</span>
                </div>
                <p class="app-footer-info">
                    <span>대표 장동훈</span><span>사업자등록번호 393-39-01518</span><span>경기도 화성시 효행로 30, 202호</span><span><a href="mailto:nxnxax@gmail.com">nxnxax@gmail.com</a></span>
                </p>
                <div class="app-footer-bottom">&copy; ${year} Ascentra. All rights reserved.</div>
            </div>
            <nav class="app-footer-links" aria-label="법적 고지">
                <a href="terms.html">이용약관</a>
                <a href="privacy.html">개인정보처리방침</a>
            </nav>
        </div>
    `;
    let host = document.getElementById('app-footer');
    if (!host) {
        document.querySelectorAll('[data-yman-footer]').forEach(el => el.remove());
        host = document.createElement('footer');
        host.id = 'app-footer';
        host.className = 'app-footer';
        host.setAttribute('data-yman-footer', '');
        document.body.appendChild(host);
    } else if (!host.classList.contains('app-footer')) {
        host.classList.add('app-footer');
    }
    host.innerHTML = html;
}

export function mountAppFooter() {
    renderAppFooter();
}

/** 헤더를 #app-header 자리에 즉시 렌더 — session 없이도 캐시로 동작 (FOUC 방지). */
export function mountAppHeader(opts) {
    const root = document.getElementById('app-header');
    if (!root) return;

    const path = ((opts && opts.activeKey) || (location.pathname.split('/').pop() || 'index.html')).toLowerCase();
    // cachedName 비어있어도 currentSession.user 있으면 metadata/email 로 즉시 이름 도출.
    // 새 탭 / 로그인 직후 sessionStorage 빈 상태에서 빈 헤더 → 닉네임 사라짐 증상 차단.
    let cachedName = readCachedDisplayName();
    if (!cachedName && currentSession?.user) {
        cachedName = getDisplayName(null, currentSession.user) || '';
        if (cachedName) cacheDisplayName(cachedName);
    }
    const cachedAdmin = readCachedAdminFlag();

    // body 클래스로 가시성 제어 — CSS 가 admin-only / user-menu / login-btn 조정.
    // 로그인 직후엔 cachedName 이 비어있을 수 있음 (특히 logout localStorage.clear 직후).
    // currentSession 가 동기적으로 set 되어 있으면 그것도 신호로 사용 → anon 으로 잘못 그려지는 시간 차 차단.
    const loggedInHint = !!cachedName || !!currentSession?.user;
    document.body.classList.toggle('is-admin', cachedAdmin || (loggedInHint && isAdmin(currentSession)));
    document.body.classList.toggle('is-anon', !loggedInHint);

    // 주 기능 — 고객 관리대장 (메인 강조) + 양식 선택 슬롯 2개 (드롭다운).
    // 각 슬롯의 선택값은 localStorage 에 사용자 이메일별로 저장됨.
    // 비로그인(cachedName 없음) 시에는 본인 키 잔재가 있어도 무시하고 'anon' 키 사용
    // → 비로그인엔 항상 '+ 신규 양식 신청' 표시. 로그인 상태에서만 localStorage backup 사용.
    const isAnonymous = !cachedName;
    const userEmail = (() => {
        if (isAnonymous) return '';
        try {
            return (sessionStorage.getItem('erp.userEmail')
                 || localStorage.getItem('erp.userEmail.last')
                 || '').toLowerCase();
        } catch { return ''; }
    })();
    const slotKey = (slot) => `yman_nav_${slot}:${userEmail || 'anon'}`;
    const getSlot = (slot, fallback) => {
        try { return localStorage.getItem(slotKey(slot)) || fallback; }
        catch { return fallback; }
    };
    // 사용자 정의 양식 목록 — sessionStorage 캐시 (refreshNavForms 가 비동기 갱신).
    // 각 양식의 navSlot 메타도 함께 저장됨 (slot1 / slot2). 슬롯 메뉴는 자기 slot 의 양식만 노출.
    let cachedForms = [];
    try {
        const raw = sessionStorage.getItem('erp.customForms');
        if (raw) cachedForms = JSON.parse(raw) || [];
    } catch { cachedForms = []; }
    const toOption = (f) => ({
        key: `forms.html?form=${f.id}`,
        label: f.name,
        href: `forms.html?form=${f.id}`,
        custom: true,
    });
    const slot1Forms = cachedForms.filter(f => (f.navSlot || 'slot1') === 'slot1').map(toOption);
    const slot2Forms = cachedForms.filter(f => f.navSlot === 'slot2').map(toOption);

    // 슬롯별 옵션: [신규 양식 신청 (해당 슬롯 지정)] + 기본 양식 + 사용자 정의 양식들 (slot 매칭)
    const SLOT1_OPTIONS = [
        { key: 'forms.html?new=1&slot=slot1', label: '+ 신규 양식 신청', href: 'forms.html?new=1&slot=slot1', isNew: true },
        { key: 'org.html',      label: '조직도',           href: 'org.html' },
        ...slot1Forms,
    ];
    const SLOT2_OPTIONS = [
        { key: 'forms.html?new=1&slot=slot2', label: '+ 신규 양식 신청', href: 'forms.html?new=1&slot=slot2', isNew: true },
        { key: 'contracts.html', label: '계약자 관리대장', href: 'contracts.html' },
        ...slot2Forms,
    ];
    // 사용자가 한 번도 슬롯을 선택한 적 없거나 비로그인 → SLOT_OPTIONS[0] 인 "+ 신규 양식 신청"
    // 을 기본 활성으로. 한 번 선택했다면 localStorage 의 그 키 유지.
    const slot1Sel = getSlot('slot1', '');
    const slot2Sel = getSlot('slot2', '');
    const slot1Active = (slot1Sel && SLOT1_OPTIONS.find(o => o.key === slot1Sel)) || SLOT1_OPTIONS[0];
    const slot2Active = (slot2Sel && SLOT2_OPTIONS.find(o => o.key === slot2Sel)) || SLOT2_OPTIONS[0];

    const secondaryItems = [
        // '내 양식' 메뉴 제거 — 양식 진입은 슬롯 dropdown(메뉴 1/2)으로만.
        { key: 'kapp_premium.php',  label: 'N키워드 분석', href: 'kapp_premium.php', icon: ICON.chart },
        { key: 'lotto2233.html',    label: '재미로 보는<br>사주+로또추천', href: 'lotto2233.html', extraCls: 'nav-link-multi', rawLabel: true, icon: ICON.sparkles },
        { key: 'card-builder.html', label: '명함',         href: 'card-builder.html', adminOnly: true, icon: ICON.card },
        { key: 'upload.html',       label: '개인자료함',   href: 'upload.html', icon: ICON.upload },
    ];

    // 커뮤니티 — 클릭 불가, hover 시 하위 메뉴.
    const communityItems = [
        { key: 'board.html?cat=notice', label: '공지사항',     href: 'board.html?cat=notice', icon: ICON.megaphone },
        { key: 'board.html?cat=free',   label: '자유게시판',   href: 'board.html?cat=free',   icon: ICON.chat },
        { key: 'board.html?cat=qna',    label: '문의게시판',   href: 'board.html?cat=qna',    icon: ICON.help },
    ];

    const renderItem = (item, baseCls) => {
        const isActive = path === item.key.toLowerCase();
        const cls = `${baseCls}${isActive ? ' active' : ''}${item.extraCls ? ' ' + item.extraCls : ''}`;
        const dataAttr = item.adminOnly ? ' data-admin-only' : '';
        const labelHtml = item.rawLabel ? item.label : escapeHtmlSafe(item.label);
        const iconHtml = item.icon ? `<span class="nav-icon">${item.icon}</span>` : '';
        return `<a class="${cls}" href="${item.href}"${dataAttr}>${iconHtml}<span class="nav-label">${labelHtml}</span></a>`;
    };

    // 현재 페이지가 커뮤니티 게시판이면 active 표시.
    const fullPath = path + (location.search || '');
    const isCommunityActive = communityItems.some(i => fullPath === i.key.toLowerCase());

    const communityHtml = `
        <div class="nav-dropdown ${isCommunityActive ? 'is-active' : ''}">
            <span class="nav-link nav-link-secondary nav-static" tabindex="0">
                <span class="nav-icon">${ICON.users}</span>
                <span class="nav-label">커뮤니티</span>
            </span>
            <div class="nav-dropdown-menu">
                ${communityItems.map(i => `
                    <a class="nav-dropdown-item${fullPath === i.key.toLowerCase() ? ' active' : ''}" href="${i.href}">
                        <span class="nav-dropdown-icon">${i.icon}</span>
                        <span>${escapeHtmlSafe(i.label)}</span>
                    </a>
                `).join('')}
            </div>
        </div>
    `;

    // 1) 메인 강조 pill — 고객 관리대장
    const mainPillHtml = `
        <a class="nav-pill nav-pill-main${path === 'customers.html' ? ' active' : ''}" href="customers.html">
            <span class="nav-label">고객 관리대장</span>
        </a>
    `;

    // 2) 양식 선택 슬롯 1 — 초기/비로그인: "+ 신규 양식 신청", 한 번이라도 선택했으면 그 양식 라벨
    const slot1IsActive = path === slot1Active.key.toLowerCase();
    const slot1Html = `
        <div class="nav-dropdown nav-pill-dropdown ${slot1IsActive ? 'is-active' : ''}" data-nav-slot="slot1">
            <button class="nav-pill nav-pill-slot${slot1IsActive ? ' active' : ''}" type="button" data-slot-open="slot1">
                ${slot1Active.isNew ? '' : '<span class="nav-pill-prefix">양식</span>'}
                <span class="nav-label" data-slot-label="slot1">${escapeHtmlSafe(slot1Active.label)}</span>
                <span class="nav-pill-caret">▾</span>
            </button>
            <div class="nav-dropdown-menu">
                ${SLOT1_OPTIONS.map(o => `
                    <a class="nav-dropdown-item${o.key === slot1Sel ? ' active' : ''}" href="${o.href}" data-slot-pick="slot1" data-slot-key="${o.key}">
                        <span>${escapeHtmlSafe(o.label)}</span>
                    </a>
                `).join('')}
            </div>
        </div>
    `;

    // 3) 양식 선택 슬롯 2 — 초기/비로그인: "+ 신규 양식 신청", 한 번이라도 선택했으면 그 양식 라벨
    const slot2IsActive = path === slot2Active.key.toLowerCase();
    const slot2Html = `
        <div class="nav-dropdown nav-pill-dropdown ${slot2IsActive ? 'is-active' : ''}" data-nav-slot="slot2">
            <button class="nav-pill nav-pill-slot${slot2IsActive ? ' active' : ''}" type="button" data-slot-open="slot2">
                ${slot2Active.isNew ? '' : '<span class="nav-pill-prefix">양식</span>'}
                <span class="nav-label" data-slot-label="slot2">${escapeHtmlSafe(slot2Active.label)}</span>
                <span class="nav-pill-caret">▾</span>
            </button>
            <div class="nav-dropdown-menu">
                ${SLOT2_OPTIONS.map(o => `
                    <a class="nav-dropdown-item${o.key === slot2Sel ? ' active' : ''}" href="${o.href}" data-slot-pick="slot2" data-slot-key="${o.key}">
                        <span>${escapeHtmlSafe(o.label)}</span>
                    </a>
                `).join('')}
            </div>
        </div>
    `;

    const primaryHtml = mainPillHtml + slot1Html + slot2Html;
    const secondaryHtml = secondaryItems.map(i => renderItem(i, 'nav-link nav-link-secondary')).join('') + communityHtml;

    if (!root.classList.contains('app-header')) root.classList.add('app-header');
    root.innerHTML = `
        <div class="header-container">
            <h1><a href="index.html" class="brand-logo" aria-label="YOUNGMAN 홈"><img src="logo_main.png" alt="YOUNGMAN"></a></h1>
            <button type="button" class="mobile-menu-toggle" aria-label="메뉴 열기" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
            <nav class="nav-primary">${primaryHtml}</nav>
            <nav class="nav-secondary">${secondaryHtml}</nav>
            <a href="index.html#login" class="header-auth-btn" id="open-login-btn">로그인</a>
            <div id="user-menu" class="user-menu">
                <span id="user-display" class="user-display">${escapeHtmlSafe(cachedName)}</span>
                <a href="profile.html" id="profile-link" class="user-menu-link"><span class="profile-link-icon">${ICON.user}</span><span class="profile-link-label">내 정보</span></a>
                <a href="admin.html" id="admin-link" class="user-menu-link">관리자</a>
                <a href="logout.html" id="logout-btn" class="user-menu-btn" role="button">로그아웃</a>
            </div>
        </div>
    `;

    // 모바일 드로어 — body 직속으로 별도 렌더 (헤더의 backdrop-filter 가 fixed
    // descendant 의 containing block 을 만들어버려서 헤더 안에 두면 위치 안 잡힘).
    // 헤더 nav 와 같은 내용을 복사해 mobile 미디어쿼리로 가시성 swap.
    document.querySelectorAll('[data-yman-drawer]').forEach(el => el.remove());
    const drawerWrap = document.createElement('div');
    drawerWrap.innerHTML = `
        <div class="mobile-drawer-backdrop" data-mobile-drawer-close data-yman-drawer></div>
        <aside class="mobile-drawer" data-yman-drawer>
            <div class="mobile-drawer-head">
                <span class="mobile-drawer-title">메뉴</span>
                <button type="button" class="mobile-drawer-close" data-mobile-drawer-close aria-label="닫기">×</button>
            </div>
            <nav class="nav-primary">${primaryHtml}</nav>
            <nav class="nav-secondary">${secondaryHtml}</nav>
            <div class="mobile-drawer-account">
                <a href="admin.html" class="mobile-drawer-account-link" data-admin-only data-anon-hide><span class="mobile-drawer-icon">⚙</span><span>관리자</span></a>
                <a href="logout.html" class="mobile-drawer-account-link" id="drawer-logout-btn" data-anon-hide role="button"><span class="mobile-drawer-icon">↩</span><span>로그아웃</span></a>
                <a href="index.html#login" class="mobile-drawer-account-link" data-anon-show><span class="mobile-drawer-icon">→</span><span>로그인</span></a>
            </div>
        </aside>
    `;
    [...drawerWrap.children].forEach(c => document.body.appendChild(c));
    const drawerEl = document.querySelector('.mobile-drawer[data-yman-drawer]');

    renderBottomNav(path);
    renderAppFooter();

    // 양식 선택 슬롯 — 항목 클릭 시 localStorage 저장 후 페이지 이동.
    document.querySelectorAll('[data-slot-pick]').forEach(el => {
        el.addEventListener('click', (e) => {
            const slot = el.dataset.slotPick;
            const key = el.dataset.slotKey;
            // "+ 신규 양식 신청" (forms.html) 은 슬롯 저장 안 함 — 양식 만들러 가는 일회성
            if (key !== 'forms.html') {
                try { localStorage.setItem(slotKey(slot), key); } catch {}
            }
            // 기본 <a href> 동작이 페이지 이동 처리
        });
    });

    // 슬롯 pill 버튼 클릭:
    // - 모바일 drawer 안에서는 항상 dropdown 토글 (페이지 이동 X — 하위 항목 선택해야)
    // - 데스크탑에선 caret(▾) = dropdown 토글, 그 외 영역 = 현재 선택된 양식 페이지로 이동
    document.querySelectorAll('[data-slot-open]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const inDrawer = !!btn.closest('.mobile-drawer');
            if (inDrawer) {
                e.preventDefault();
                btn.closest('.nav-dropdown')?.classList.toggle('open');
                return;
            }
            const isCaret = e.target.closest('.nav-pill-caret');
            if (isCaret) {
                e.preventDefault();
                btn.closest('.nav-dropdown')?.classList.toggle('open');
                return;
            }
            const slot = btn.dataset.slotOpen;
            const target = slot === 'slot1' ? slot1Active : slot2Active;
            if (target && target.key !== 'forms.html') {
                window.location.href = target.href;
            } else {
                btn.closest('.nav-dropdown')?.classList.toggle('open');
            }
        });
    });

    // 바깥 클릭 시 열린 슬롯 dropdown 닫기
    document.addEventListener('click', (e) => {
        if (e.target.closest('.nav-pill-dropdown')) return;
        document.querySelectorAll('.nav-pill-dropdown.open').forEach(d => d.classList.remove('open'));
    });

    // 슬롯 dropdown hover 정책: caret 아이콘 또는 펼쳐진 메뉴에 hover 했을 때만 열림.
    // 라벨(텍스트)에 hover 해도 메뉴가 열리지 않도록 .nav-dropdown:hover CSS 룰은
    // .nav-pill-dropdown 제외. caret↔menu 이동 중 끊김 방지를 위해 closeTimer 사용.
    document.querySelectorAll('.nav-pill-dropdown').forEach(dd => {
        if (dd.closest('.mobile-drawer')) return;   // 모바일 drawer 는 클릭 토글 그대로
        const caret = dd.querySelector('.nav-pill-caret');
        const menu = dd.querySelector('.nav-dropdown-menu');
        if (!caret || !menu) return;
        let closeTimer = null;
        const openNow = () => { clearTimeout(closeTimer); dd.classList.add('open'); };
        const scheduleClose = () => {
            clearTimeout(closeTimer);
            closeTimer = setTimeout(() => dd.classList.remove('open'), 140);
        };
        caret.addEventListener('mouseenter', openNow);
        caret.addEventListener('mouseleave', scheduleClose);
        menu.addEventListener('mouseenter', openNow);
        menu.addEventListener('mouseleave', scheduleClose);
    });

    // 백그라운드로 사용자 정의 양식 목록 갱신 — 다음 페이지 진입 시 dropdown 에 반영
    refreshNavFormsCache();

    // 로그아웃: #logout-btn / #drawer-logout-btn 는 이제 <a href="logout.html"> 이라
    // 브라우저 native navigation 으로 작동. capture handler 가 fresh timestamp 만 attach.
    // 별도 click handler 등록 X — 등록하면 preventDefault 가 native navigation 을 막을 수 있음.

    // 로그인 버튼 — 메인 페이지가 아니면 메인 페이지의 #auth-screen 모달이 없어서
    // 'index.html#login' 으로 이동하던 기존 동작 대신 현재 페이지에서 모달 띄우기.
    // 메인 페이지 (#auth-screen 존재) 에서는 기존 main.js 흐름 그대로.
    const openLoginBtn = root.querySelector('#open-login-btn');
    if (openLoginBtn) {
        openLoginBtn.addEventListener('click', (e) => {
            // 메인 페이지: #auth-screen 이 이미 있고 main.js 가 hash 핸들러로 모달 열기 — 기본 동작 유지.
            if (document.getElementById('auth-screen')) return;
            e.preventDefault();
            openSharedLoginModal();
        });
    }
    // 모바일 드로어 안의 로그인 링크도 같은 처리
    const drawerLoginBtn = drawerEl?.querySelector('a[href="index.html#login"]');
    if (drawerLoginBtn) {
        drawerLoginBtn.addEventListener('click', (e) => {
            if (document.getElementById('auth-screen')) return;
            e.preventDefault();
            openSharedLoginModal();
        });
    }

    // 모바일 사이드 드로어 — 햄버거 토글 / 아코디언 서브메뉴
    const hamburger = root.querySelector('.mobile-menu-toggle');
    const drawer = drawerEl;   // body 로 이동된 드로어 참조
    const backdropEl = document.querySelector('.mobile-drawer-backdrop[data-yman-drawer]');
    const closeDrawer = () => {
        drawer?.classList.remove('open');
        hamburger?.classList.remove('open');
        hamburger?.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('mobile-drawer-open');
    };
    if (hamburger && drawer) {
        hamburger.addEventListener('click', () => {
            const isOpen = drawer.classList.toggle('open');
            hamburger.classList.toggle('open', isOpen);
            hamburger.setAttribute('aria-expanded', String(isOpen));
            document.body.classList.toggle('mobile-drawer-open', isOpen);
        });
        // backdrop / X 버튼 → 닫힘
        if (backdropEl) backdropEl.addEventListener('click', closeDrawer);
        drawer.querySelectorAll('[data-mobile-drawer-close]').forEach(el => {
            el.addEventListener('click', closeDrawer);
        });
        // a[href] 링크 클릭 시 자동 닫힘 (드롭다운 트리거 제외)
        drawer.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (link) closeDrawer();
        });
        // 커뮤니티 등 dropdown 의 nav-static 클릭 → 아코디언 토글 (모바일에서만)
        drawer.querySelectorAll('.nav-dropdown').forEach(dd => {
            const trigger = dd.querySelector('.nav-static');
            if (!trigger) return;
            trigger.addEventListener('click', (e) => {
                if (window.matchMedia('(max-width: 860px)').matches) {
                    e.preventDefault();
                    e.stopPropagation();
                    dd.classList.toggle('expanded');
                }
            });
        });
        // ESC → 닫힘
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && drawer.classList.contains('open')) closeDrawer();
        });
    }
}

/** session 로드 후 헤더의 사용자 정보 갱신 + 캐시. profile fetch 비용 한 번. */
export async function refreshAppHeader() {
    const loggedIn = !!currentSession?.user;
    const admin = loggedIn && isAdmin(currentSession);

    document.body.classList.toggle('is-admin', admin);
    document.body.classList.toggle('is-anon', !loggedIn);
    cacheAdminFlag(admin);

    if (!loggedIn) { cacheDisplayName(''); return; }

    // 1) metadata 기반 이름 즉시 cache + 헤더 재렌더 — apiRequest 지연 동안 빈 표시 차단.
    let displayName = getDisplayName(null, currentSession.user);
    if (displayName) {
        cacheDisplayName(displayName);
        const earlyDisplay = document.getElementById('user-display');
        if (earlyDisplay) earlyDisplay.textContent = displayName;
        try { mountAppHeader(); } catch {}
    }

    // 2) members.name/nickname 가져와서 가장 정확한 이름으로 갱신.
    try {
        const payload = await apiRequest('auth-profile');
        if (payload?.profile) {
            const refined = getDisplayName(payload.profile, currentSession.user);
            if (refined) displayName = refined;
        }
    } catch {}

    cacheDisplayName(displayName);
    const display = document.getElementById('user-display');
    if (display) display.textContent = displayName;

    // 첫 mountAppHeader 가 logout 직후의 빈 cachedName 으로 anon 헤더를 그렸다면,
    // 여기서 새 cachedName 으로 헤더 DOM 을 한 번 더 그려서 visual 동기화.
    // (로그인 직후 헤더에 로그인 버튼이 남아있다가 페이지 이동해야만 정상화되던 문제 fix)
    try { mountAppHeader(); } catch {}

    // bootApp 흐름의 마지막 단계 — currentSession 가 확정된 뒤이므로 여기서
    // 사용자 양식 목록을 서버에서 가져와 슬롯 dropdown 을 갱신. 첫 mountAppHeader
    // 시점엔 currentSession 가 없어 refreshNavFormsCache 가 skip 됐을 수 있음.
    try { await refreshNavFormsCache(); } catch {}
}

/** 페이지 부트스트랩 한 줄 호출 — mountAppHeader → initSupabase → refreshAppHeader. */
export async function bootApp(opts) {
    mountAppHeader(opts);
    try { await initSupabase(); } catch {}
    if (opts?.requireAdmin && !requireAdmin()) return false;
    await refreshAppHeader();
    return true;
}

function escapeHtmlSafe(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/**
 * 모달 backdrop 클릭 close 헬퍼 — pointerdown/pointerup 모두 backdrop 위일 때만 close.
 * input 안에서 텍스트 드래그(또는 select-all) 후 mouseup 이 backdrop 위에서 발생해도 close 안 됨.
 */
function bindBackdropClose(backdrop, closeFn) {
    let downOnBackdrop = false;
    backdrop.addEventListener('pointerdown', (e) => {
        downOnBackdrop = (e.target === backdrop);
    });
    backdrop.addEventListener('pointerup', (e) => {
        const wasDownOnBackdrop = downOnBackdrop;
        downOnBackdrop = false;
        if (wasDownOnBackdrop && e.target === backdrop) closeFn();
    });
}

/* =========================================================================
   아이디(이메일) 찾기 모달 — 2단계: 이름+휴대폰 → SMS 인증번호 발송 → 코드 입력 → 마스킹 이메일.
   records.php (public) endpoints:
     - find-email-send-otp  : 인증번호 SMS 발송 (관리자 Solapi 자격증명 사용)
     - find-email-verify-otp: 코드 검증 + 이름 매칭 + 마스킹 이메일
   ========================================================================= */
function openFindIdModal() {
    document.querySelectorAll('[data-shared-auth]').forEach(el => el.remove());
    const md = document.createElement('div');
    md.dataset.sharedAuth = '1';
    md.innerHTML = `
        <div class="shared-auth-backdrop">
            <div class="shared-auth-panel" role="dialog" aria-modal="true">
                <button type="button" class="shared-auth-close" aria-label="닫기">&times;</button>
                <div class="shared-auth-brand"><img src="logo_main.png" alt="YOUNGMAN"></div>
                <h2 class="shared-auth-title">아이디 찾기</h2>
                <p class="shared-auth-sub">가입 시 입력한 이름과 휴대폰 번호로 SMS 인증을 진행합니다.</p>
                <form class="shared-auth-form" novalidate>
                    <label>이름 <input type="text" name="name" required autocomplete="name" placeholder="실명"></label>
                    <div style="display:flex;gap:6px;align-items:flex-end;">
                        <label style="flex:1;">휴대폰 <input type="tel" name="phone" required autocomplete="tel" placeholder="010-0000-0000"></label>
                        <button type="button" data-send-otp style="padding:9px 12px;background:#0e0d0c;color:#fff;border:0;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;">인증번호 받기</button>
                    </div>
                    <div data-otp-zone hidden>
                        <label>인증번호 <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="6자리 숫자" autocomplete="one-time-code"></label>
                        <small style="display:block;margin-top:4px;color:#8a847e;font-size:11.5px;">5분 안에 입력해주세요. <span data-otp-target></span></small>
                    </div>
                    <p class="shared-auth-message" aria-live="polite"></p>
                    <button type="submit" class="shared-auth-submit" disabled>인증 후 아이디 찾기</button>
                </form>
                <p class="shared-auth-switch">
                    <button type="button" class="shared-auth-mode-btn" data-back>← 로그인으로 돌아가기</button>
                </p>
            </div>
        </div>
    `;
    document.body.appendChild(md);
    const close = () => md.remove();
    md.querySelector('.shared-auth-close').addEventListener('click', close);
    bindBackdropClose(md.querySelector('.shared-auth-backdrop'), close);
    md.querySelector('[data-back]').addEventListener('click', () => { close(); openSharedLoginModal('login'); });
    const form = md.querySelector('.shared-auth-form');
    const msgEl = md.querySelector('.shared-auth-message');
    const submitBtn = md.querySelector('.shared-auth-submit');
    const sendOtpBtn = md.querySelector('[data-send-otp]');
    const otpZone   = md.querySelector('[data-otp-zone]');
    const otpTargetEl = md.querySelector('[data-otp-target]');
    let otpSent = false;
    setTimeout(() => form.name?.focus(), 50);

    // 인증번호 받기
    sendOtpBtn.addEventListener('click', async () => {
        const name  = form.name.value.trim();
        const phone = form.phone.value.trim();
        if (!name) { msgEl.style.color = '#c8362c'; msgEl.textContent = '이름을 먼저 입력해주세요.'; return; }
        if (!phone) { msgEl.style.color = '#c8362c'; msgEl.textContent = '휴대폰 번호를 입력해주세요.'; return; }
        sendOtpBtn.disabled = true; sendOtpBtn.textContent = '발송 중…'; msgEl.textContent = '';
        try {
            const resp = await fetch('records.php?resource=find-email-send-otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ resource: 'find-email-send-otp', phone }),
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok || !data.ok) {
                msgEl.style.color = '#c8362c';
                msgEl.textContent = data?.error || '인증번호 발송 실패';
                sendOtpBtn.disabled = false; sendOtpBtn.textContent = '인증번호 받기';
                return;
            }
            otpSent = true;
            otpZone.hidden = false;
            otpTargetEl.textContent = (data.sentTo ? '발송 대상: ' + data.sentTo : '');
            msgEl.style.color = '#1b5e20';
            msgEl.textContent = '✅ 인증번호를 발송했습니다. 문자메시지를 확인해주세요.';
            submitBtn.disabled = false;
            sendOtpBtn.textContent = '재발송';
            // 60초 동안 재발송 lock
            let secs = 60;
            sendOtpBtn.disabled = true;
            const iv = setInterval(() => {
                secs--;
                sendOtpBtn.textContent = `재발송 (${secs}s)`;
                if (secs <= 0) { clearInterval(iv); sendOtpBtn.disabled = false; sendOtpBtn.textContent = '재발송'; }
            }, 1000);
            setTimeout(() => form.code?.focus(), 100);
        } catch (err) {
            msgEl.style.color = '#c8362c';
            msgEl.textContent = translateAuthError(err?.message, 'SMS 발송 오류');
            sendOtpBtn.disabled = false; sendOtpBtn.textContent = '인증번호 받기';
        }
    });

    // 인증번호 검증 + 아이디 찾기
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!otpSent) { msgEl.style.color = '#c8362c'; msgEl.textContent = '먼저 인증번호를 받아주세요.'; return; }
        const name  = form.name.value.trim();
        const phone = form.phone.value.trim();
        const code  = (form.code?.value || '').trim();
        if (!name || !phone || !code) { msgEl.style.color = '#c8362c'; msgEl.textContent = '이름/휴대폰/인증번호를 모두 입력해주세요.'; return; }
        submitBtn.disabled = true; submitBtn.textContent = '검증 중…'; msgEl.textContent = '';
        try {
            const resp = await fetch('records.php?resource=find-email-verify-otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ resource: 'find-email-verify-otp', name, phone, code }),
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok || !data.ok) {
                msgEl.style.color = '#c8362c';
                msgEl.textContent = data?.error || '인증 실패';
                submitBtn.disabled = false; submitBtn.textContent = '인증 후 아이디 찾기';
                return;
            }
            msgEl.style.color = '#1b5e20';
            msgEl.innerHTML = '<b>가입된 아이디(이메일):</b><br>'
                + '<code style="display:inline-block;margin-top:4px;padding:6px 10px;background:#fbf7ef;border-radius:6px;font-size:14px;color:#0e0d0c;font-family:\'SF Mono\',Menlo,monospace;">' + escapeHtmlSafe(data.email) + '</code>'
                + '<br><small style="color:#4f4943;font-size:11.5px;display:block;margin-top:6px;">전체 이메일은 가입 시 사용한 메일함을 확인해 주세요.</small>';
            submitBtn.disabled = false; submitBtn.textContent = '로그인으로 이동';
            submitBtn.type = 'button';
            submitBtn.addEventListener('click', () => { close(); openSharedLoginModal('login'); }, { once: true });
        } catch (err) {
            msgEl.style.color = '#c8362c';
            msgEl.textContent = translateAuthError(err?.message, '검증 실패');
            submitBtn.disabled = false; submitBtn.textContent = '인증 후 아이디 찾기';
        }
    });
}

/* =========================================================================
   비밀번호 찾기 모달 — 3단계:
     1) 이름 + 휴대폰 → SMS 인증번호 발송
     2) 인증번호 입력 → 검증 → resetToken 발급
     3) 새 비밀번호 입력 → supabase admin API 로 변경
   Google 가입자는 step 2 응답에서 별도 안내 (구글 계정 보안 설정 링크).
   ========================================================================= */
function openFindPasswordModal(prefillEmail = '') {
    document.querySelectorAll('[data-shared-auth]').forEach(el => el.remove());
    const md = document.createElement('div');
    md.dataset.sharedAuth = '1';
    md.innerHTML = `
        <div class="shared-auth-backdrop">
            <div class="shared-auth-panel" role="dialog" aria-modal="true">
                <button type="button" class="shared-auth-close" aria-label="닫기">&times;</button>
                <div class="shared-auth-brand"><img src="logo_main.png" alt="YOUNGMAN"></div>
                <h2 class="shared-auth-title">비밀번호 재설정</h2>
                <p class="shared-auth-sub" data-sub>본인 확인을 위해 이름과 휴대폰 번호로 SMS 인증을 진행합니다.</p>
                <form class="shared-auth-form" novalidate>
                    <div data-step-verify>
                        <label>이름 <input type="text" name="name" required autocomplete="name" placeholder="실명"></label>
                        <div style="display:flex;gap:6px;align-items:flex-end;">
                            <label style="flex:1;">휴대폰 <input type="tel" name="phone" required autocomplete="tel" placeholder="010-0000-0000"></label>
                            <button type="button" data-send-otp style="padding:9px 12px;background:#0e0d0c;color:#fff;border:0;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;">인증번호 받기</button>
                        </div>
                        <div data-otp-zone hidden>
                            <label>인증번호 <input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="6자리 숫자" autocomplete="one-time-code"></label>
                            <small style="display:block;margin-top:4px;color:#8a847e;font-size:11.5px;">5분 안에 입력해주세요. <span data-otp-target></span></small>
                        </div>
                    </div>
                    <div data-step-reset hidden>
                        <div style="padding:10px 12px;background:#e6f4ea;border:1px solid #a5d6a7;border-radius:8px;font-size:12.5px;color:#1b5e20;margin-bottom:8px;">
                            ✅ 본인 확인 완료. 새 비밀번호를 입력해 주세요.
                        </div>
                        <label>새 비밀번호 <input type="password" name="newPassword" minlength="6" placeholder="6자 이상"></label>
                        <label>비밀번호 확인 <input type="password" name="newPasswordConfirm" minlength="6" placeholder="다시 입력"></label>
                    </div>
                    <p class="shared-auth-message" aria-live="polite"></p>
                    <button type="submit" class="shared-auth-submit" disabled>인증 후 진행</button>
                </form>
                <p class="shared-auth-switch">
                    <button type="button" class="shared-auth-mode-btn" data-back>← 로그인으로 돌아가기</button>
                </p>
            </div>
        </div>
    `;
    document.body.appendChild(md);
    const close = () => md.remove();
    md.querySelector('.shared-auth-close').addEventListener('click', close);
    bindBackdropClose(md.querySelector('.shared-auth-backdrop'), close);
    md.querySelector('[data-back]').addEventListener('click', () => { close(); openSharedLoginModal('login'); });

    const form = md.querySelector('.shared-auth-form');
    const msgEl = md.querySelector('.shared-auth-message');
    const submitBtn = md.querySelector('.shared-auth-submit');
    const sendOtpBtn = md.querySelector('[data-send-otp]');
    const otpZone   = md.querySelector('[data-otp-zone]');
    const otpTargetEl = md.querySelector('[data-otp-target]');
    const stepVerify  = md.querySelector('[data-step-verify]');
    const stepReset   = md.querySelector('[data-step-reset]');
    const subEl       = md.querySelector('[data-sub]');

    let phase = 'verify';   // 'verify' | 'reset'
    let resetToken = null;
    let matchedEmail = null;
    let otpSent = false;
    setTimeout(() => form.name?.focus(), 50);

    // 인증번호 받기
    sendOtpBtn.addEventListener('click', async () => {
        const phone = form.phone.value.trim();
        if (!phone) { msgEl.style.color = '#c8362c'; msgEl.textContent = '휴대폰 번호를 입력해주세요.'; return; }
        sendOtpBtn.disabled = true; sendOtpBtn.textContent = '발송 중…'; msgEl.textContent = '';
        try {
            const resp = await fetch('records.php?resource=find-pwd-send-otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ resource: 'find-pwd-send-otp', phone }),
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok || !data.ok) {
                msgEl.style.color = '#c8362c'; msgEl.textContent = data?.error || 'SMS 발송 실패';
                sendOtpBtn.disabled = false; sendOtpBtn.textContent = '인증번호 받기';
                return;
            }
            otpSent = true;
            otpZone.hidden = false;
            otpTargetEl.textContent = data.sentTo ? '발송 대상: ' + data.sentTo : '';
            msgEl.style.color = '#1b5e20';
            msgEl.textContent = '✅ 인증번호를 발송했습니다.';
            submitBtn.disabled = false;
            let secs = 60;
            sendOtpBtn.disabled = true;
            const iv = setInterval(() => {
                secs--;
                sendOtpBtn.textContent = `재발송 (${secs}s)`;
                if (secs <= 0) { clearInterval(iv); sendOtpBtn.disabled = false; sendOtpBtn.textContent = '재발송'; }
            }, 1000);
            setTimeout(() => form.code?.focus(), 100);
        } catch (err) {
            msgEl.style.color = '#c8362c'; msgEl.textContent = translateAuthError(err?.message, 'SMS 발송 오류');
            sendOtpBtn.disabled = false; sendOtpBtn.textContent = '인증번호 받기';
        }
    });

    // submit — phase 에 따라 verify or reset
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (phase === 'verify') {
            // Step 2: 인증번호 + 이름 매칭 검증
            const name  = form.name.value.trim();
            const phone = form.phone.value.trim();
            const code  = (form.code?.value || '').trim();
            if (!otpSent) { msgEl.style.color = '#c8362c'; msgEl.textContent = '먼저 인증번호를 받아주세요.'; return; }
            if (!name || !phone || !code) { msgEl.style.color = '#c8362c'; msgEl.textContent = '이름/휴대폰/인증번호를 모두 입력해주세요.'; return; }
            submitBtn.disabled = true; submitBtn.textContent = '검증 중…'; msgEl.textContent = '';
            try {
                const resp = await fetch('records.php?resource=find-pwd-verify-otp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ resource: 'find-pwd-verify-otp', name, phone, code }),
                });
                const data = await resp.json().catch(() => ({}));
                // Google 가입자 케이스 — ok:false + reason:'oauth_provider'
                if (data?.reason === 'oauth_provider') {
                    msgEl.style.color = '#c8362c';
                    msgEl.innerHTML = '<b>구글 로그인 계정입니다.</b><br>'
                        + '<small style="display:block;margin-top:4px;color:#4f4943;font-size:11.5px;">이 계정의 비밀번호는 Google 에서 관리합니다. 구글 계정 보안 설정에서 비밀번호를 변경해주세요.</small>'
                        + '<a href="https://myaccount.google.com/security" target="_blank" rel="noopener" style="display:inline-block;margin-top:8px;padding:7px 14px;background:#4285F4;color:#fff;border-radius:6px;font-size:12.5px;font-weight:700;text-decoration:none;">Google 계정 보안 설정 →</a>';
                    submitBtn.textContent = '닫기';
                    submitBtn.disabled = false; submitBtn.type = 'button';
                    submitBtn.addEventListener('click', close, { once: true });
                    return;
                }
                if (!resp.ok || !data.ok) {
                    msgEl.style.color = '#c8362c'; msgEl.textContent = data?.error || '인증 실패';
                    submitBtn.disabled = false; submitBtn.textContent = '인증 후 진행';
                    return;
                }
                // 검증 성공 → Step 3 (새 비밀번호)
                resetToken = data.resetToken;
                matchedEmail = data.email;
                phase = 'reset';
                stepVerify.hidden = true;
                stepReset.hidden = false;
                subEl.textContent = `${matchedEmail} 계정의 새 비밀번호를 설정합니다.`;
                msgEl.textContent = '';
                submitBtn.textContent = '비밀번호 변경';
                submitBtn.disabled = false;
                setTimeout(() => form.newPassword?.focus(), 80);
            } catch (err) {
                msgEl.style.color = '#c8362c'; msgEl.textContent = translateAuthError(err?.message, '검증 실패');
                submitBtn.disabled = false; submitBtn.textContent = '인증 후 진행';
            }
        } else {
            // Step 3: 새 비밀번호 설정
            const phone        = form.phone.value.trim();
            const newPassword  = form.newPassword.value;
            const confirmPwd   = form.newPasswordConfirm.value;
            if (newPassword.length < 6) { msgEl.style.color = '#c8362c'; msgEl.textContent = '비밀번호는 6자 이상이어야 합니다.'; return; }
            if (newPassword !== confirmPwd) { msgEl.style.color = '#c8362c'; msgEl.textContent = '비밀번호와 확인이 일치하지 않습니다.'; return; }
            submitBtn.disabled = true; submitBtn.textContent = '변경 중…'; msgEl.textContent = '';
            try {
                const resp = await fetch('records.php?resource=find-pwd-reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        resource: 'find-pwd-reset',
                        phone, resetToken, email: matchedEmail, newPassword,
                    }),
                });
                const data = await resp.json().catch(() => ({}));
                if (!resp.ok || !data.ok) {
                    msgEl.style.color = '#c8362c'; msgEl.textContent = data?.error || '비밀번호 변경 실패';
                    submitBtn.disabled = false; submitBtn.textContent = '비밀번호 변경';
                    return;
                }
                msgEl.style.color = '#1b5e20';
                msgEl.innerHTML = '<b>✅ 비밀번호가 변경되었습니다.</b><br>'
                    + '<small style="display:block;margin-top:4px;color:#4f4943;font-size:11.5px;">새 비밀번호로 로그인해 주세요.</small>';
                submitBtn.textContent = '로그인으로 이동';
                submitBtn.disabled = false; submitBtn.type = 'button';
                submitBtn.addEventListener('click', () => { close(); openSharedLoginModal('login'); }, { once: true });
            } catch (err) {
                msgEl.style.color = '#c8362c'; msgEl.textContent = translateAuthError(err?.message, '변경 실패');
                submitBtn.disabled = false; submitBtn.textContent = '비밀번호 변경';
            }
        }
    });
}

/**
 * Supabase / OAuth 영문 에러를 한국어 친화 메시지로 변환.
 * 매칭 안 된 케이스는 원본 그대로 (디버깅 용) 또는 fallback 반환.
 */
function translateAuthError(raw, fallback) {
    const s = String(raw || '').trim();
    if (!s) return fallback || '인증 처리에 실패했습니다.';

    // 자주 나오는 supabase 패턴들 — 우선순위 순
    if (/invalid\s*login\s*credentials/i.test(s))           return '비밀번호가 일치하지 않습니다.';
    if (/email\s*not\s*confirmed/i.test(s))                 return '이메일 인증이 완료되지 않았습니다. 가입 시 받은 메일을 확인해 주세요.';
    if (/user\s*not\s*found/i.test(s))                      return '가입되지 않은 이메일입니다.';
    if (/already\s*registered|user.*exist|email.*exist/i.test(s))  return '이미 가입된 이메일입니다.';
    if (/password.*at\s*least\s*\d+|password.*too\s*short|weak\s*password/i.test(s)) return '비밀번호는 6자 이상이어야 합니다.';
    if (/validate\s*email|invalid\s*email|invalid.*email.*format/i.test(s)) return '올바른 이메일 형식이 아닙니다.';
    if (/email\s*rate\s*limit|over\s*email\s*send\s*rate|too\s*many\s*request/i.test(s)) return '잠시 후 다시 시도해주세요 (요청 횟수 제한).';
    if (/database\s*error.*new\s*user|saving\s*new\s*user/i.test(s)) return '사용자 정보 저장 실패. 잠시 후 다시 시도해주세요.';
    if (/signup.*disabled|sign[\s_-]*ups?.*not\s*allowed/i.test(s)) return '현재 회원가입이 일시 중단되어 있습니다.';
    if (/captcha|verification.*failed/i.test(s))            return '보안 인증에 실패했습니다. 잠시 후 다시 시도해주세요.';
    if (/provider.*not.*found|provider.*disabled/i.test(s)) return 'Google 로그인 설정에 문제가 있습니다. 관리자에게 문의해 주세요.';
    if (/oauth.*error|oauth.*denied/i.test(s))              return 'Google 로그인이 취소되었거나 거부되었습니다.';
    if (/network\s*error|fetch.*failed|failed\s*to\s*fetch|networkerror/i.test(s)) return '네트워크 오류 — 인터넷 연결을 확인하고 다시 시도해 주세요.';
    if (/jwt|token.*expired|token.*invalid/i.test(s))       return '인증이 만료되었습니다. 다시 로그인해 주세요.';
    if (/cors|cross.origin/i.test(s))                       return '브라우저 보안 정책으로 차단되었습니다. 새로고침 후 다시 시도해 주세요.';

    // 매칭 실패 — 원본 메시지 (사용자/개발자가 보고 진단 가능)
    return s;
}

/* =========================================================================
   서브 페이지용 통합 인증 모달 — 로그인 + 회원가입 모두 지원.
   메인 페이지(#auth-screen) 가 없는 페이지에서 같은 흐름으로 사용.
   Supabase signInWithPassword / signUp / signInWithOAuth + records.php
   auth-member POST 호출 — main.js 의 handleAuthSubmit 과 동일 로직.
   ========================================================================= */
function openSharedLoginModal(initialMode = 'login') {
    document.querySelectorAll('[data-shared-auth]').forEach(el => el.remove());

    const md = document.createElement('div');
    md.dataset.sharedAuth = '1';
    md.innerHTML = `
        <div class="shared-auth-backdrop">
            <div class="shared-auth-panel" role="dialog" aria-modal="true">
                <button type="button" class="shared-auth-close" aria-label="닫기">&times;</button>
                <div class="shared-auth-brand"><img src="logo_main.png" alt="YOUNGMAN"></div>
                <h2 class="shared-auth-title" data-title>로그인</h2>
                <p class="shared-auth-sub" data-sub>이메일 또는 Google 계정으로 로그인합니다.</p>

                <button type="button" class="shared-auth-google">
                    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                        <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.9c1.7-1.56 2.7-3.86 2.7-6.62z"/>
                        <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.9-2.26c-.8.54-1.83.86-3.06.86-2.35 0-4.34-1.58-5.05-3.72H.96v2.33A9 9 0 0 0 9 18z"/>
                        <path fill="#FBBC05" d="M3.95 10.7A5.41 5.41 0 0 1 3.67 9c0-.59.1-1.16.28-1.7V4.97H.96A9 9 0 0 0 0 9c0 1.45.35 2.82.96 4.03l2.99-2.33z"/>
                        <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.97L3.95 7.3C4.66 5.16 6.65 3.58 9 3.58z"/>
                    </svg>
                    <span data-google-label>Google 로 로그인</span>
                </button>

                <div class="shared-auth-divider"><span>또는 이메일로</span></div>

                <form class="shared-auth-form" novalidate>
                    <div class="shared-auth-fields" data-signup-only hidden>
                        <label>이름 <input type="text" name="fullName" autocomplete="name" placeholder="실명"></label>
                        <label>휴대폰 <input type="tel" name="phone" autocomplete="tel" placeholder="010-0000-0000"></label>
                    </div>
                    <div class="signup-input-row" data-signup-input>
                        <label style="flex:1;">이메일 <input type="email" name="email" autocomplete="email" required placeholder="name@example.com"></label>
                        <button type="button" data-check-email class="check-dup-btn" data-signup-only hidden>중복확인</button>
                    </div>
                    <small class="check-result" data-check-email-result hidden style="margin-top:-6px;display:block;"></small>
                    <div class="shared-auth-fields" data-signup-only hidden>
                        <div class="signup-input-row">
                            <label style="flex:1;">닉네임 <input type="text" name="nickname" minlength="2" maxlength="20" placeholder="2~20자 한글/영문/숫자"></label>
                            <button type="button" data-check-nickname class="check-dup-btn">중복확인</button>
                        </div>
                        <small class="check-result" data-check-nickname-result hidden style="margin-top:-6px;display:block;"></small>
                    </div>
                    <label>비밀번호 <input type="password" name="password" autocomplete="current-password" required minlength="6" placeholder="6자 이상"></label>
                    <div class="shared-auth-fields" data-login-only style="flex-direction:row;align-items:center;justify-content:space-between;gap:8px;font-size:12.5px;">
                        <label style="flex-direction:row;align-items:center;gap:6px;font-weight:500;color:#4f4943;cursor:pointer;">
                            <input type="checkbox" name="rememberMe" checked style="width:14px;height:14px;accent-color:#c8362c;margin:0;"> 로그인 유지
                        </label>
                        <span style="display:flex;gap:10px;">
                            <button type="button" data-find-id style="background:none;border:0;color:#4f4943;font-size:12px;cursor:pointer;text-decoration:underline;font-family:inherit;padding:0;">아이디 찾기</button>
                            <span style="color:#d4cfc7;">|</span>
                            <button type="button" data-find-pwd style="background:none;border:0;color:#4f4943;font-size:12px;cursor:pointer;text-decoration:underline;font-family:inherit;padding:0;">비밀번호 찾기</button>
                        </span>
                    </div>
                    <div class="shared-auth-fields" data-signup-only hidden>
                        <label>비밀번호 확인 <input type="password" name="passwordConfirm" minlength="6" placeholder="다시 입력"></label>
                        <div class="shared-auth-consents">
                            <label><input type="checkbox" name="agreeAll"> 전체 동의</label>
                            <label><input type="checkbox" name="agreeTerms"> <span class="req">필수</span> <a href="terms.html" target="_blank" rel="noopener">이용약관</a>에 동의</label>
                            <label><input type="checkbox" name="agreePrivacy"> <span class="req">필수</span> <a href="privacy.html" target="_blank" rel="noopener">개인정보처리방침</a>에 동의</label>
                            <label><input type="checkbox" name="agreeMarketing"> <span class="opt">선택</span> 마케팅 정보 수신 동의</label>
                        </div>
                    </div>
                    <p class="shared-auth-message" aria-live="polite"></p>
                    <button type="submit" class="shared-auth-submit" data-submit-label>로그인</button>
                </form>

                <p class="shared-auth-switch">
                    <span data-switch-text>아직 회원이 아니신가요?</span>
                    <button type="button" class="shared-auth-mode-btn" data-mode-btn>회원가입</button>
                </p>
            </div>
        </div>
    `;
    document.body.appendChild(md);

    const root        = md.querySelector('.shared-auth-panel');
    const backdrop    = md.querySelector('.shared-auth-backdrop');
    const closeBtn    = md.querySelector('.shared-auth-close');
    const form        = md.querySelector('.shared-auth-form');
    const googleBtn   = md.querySelector('.shared-auth-google');
    const msgEl       = md.querySelector('.shared-auth-message');
    const submitBtn   = md.querySelector('.shared-auth-submit');
    const titleEl     = md.querySelector('[data-title]');
    const subEl       = md.querySelector('[data-sub]');
    const switchText  = md.querySelector('[data-switch-text]');
    const modeBtn     = md.querySelector('[data-mode-btn]');
    const submitLabel = md.querySelector('[data-submit-label]');
    const googleLabel = md.querySelector('[data-google-label]');
    const signupOnly  = md.querySelectorAll('[data-signup-only]');
    const loginOnly   = md.querySelectorAll('[data-login-only]');
    const agreeAll    = form.agreeAll;
    const agreeReqs   = [form.agreeTerms, form.agreePrivacy];
    const agreeOpts   = [form.agreeMarketing];

    let mode = (initialMode === 'signup') ? 'signup' : 'login';

    function applyMode() {
        const isSignup = mode === 'signup';
        titleEl.textContent = isSignup ? '회원가입' : '로그인';
        subEl.textContent   = isSignup ? '이메일 또는 Google 계정으로 가입합니다.' : '이메일 또는 Google 계정으로 로그인합니다.';
        submitLabel.textContent = isSignup ? '회원가입' : '로그인';
        googleLabel.textContent = isSignup ? 'Google 로 가입' : 'Google 로 로그인';
        switchText.textContent  = isSignup ? '이미 회원이신가요?' : '아직 회원이 아니신가요?';
        modeBtn.textContent     = isSignup ? '로그인' : '회원가입';
        signupOnly.forEach(el => el.hidden = !isSignup);
        loginOnly.forEach(el => el.hidden = isSignup);
        form.password.setAttribute('autocomplete', isSignup ? 'new-password' : 'current-password');
        msgEl.textContent = '';
    }
    applyMode();

    // 아이디 / 비밀번호 찾기 링크 — 로그인 모달 닫고 별도 모달 open
    md.querySelector('[data-find-id]')?.addEventListener('click', () => {
        close();
        openFindIdModal();
    });
    md.querySelector('[data-find-pwd]')?.addEventListener('click', () => {
        close();
        openFindPasswordModal(form.email?.value?.trim() || '');
    });

    modeBtn.addEventListener('click', () => { mode = (mode === 'signup') ? 'login' : 'signup'; applyMode(); });

    const close = () => md.remove();
    closeBtn.addEventListener('click', close);
    bindBackdropClose(backdrop, close);
    const escHandler = (e) => { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', escHandler); } };
    document.addEventListener('keydown', escHandler);
    setTimeout(() => form.email?.focus(), 50);

    // 이메일 / 닉네임 중복확인 — auth-availability (public) 호출.
    const checkEmailBtn      = md.querySelector('[data-check-email]');
    const checkEmailResultEl = md.querySelector('[data-check-email-result]');
    const checkNickBtn       = md.querySelector('[data-check-nickname]');
    const checkNickResultEl  = md.querySelector('[data-check-nickname-result]');

    const showCheckResult = (el, ok, text) => {
        if (!el) return;
        el.hidden = false;
        el.style.color = ok ? '#1b5e20' : '#c8362c';
        el.style.fontSize = '11.5px';
        el.style.fontWeight = '600';
        el.textContent = text;
    };

    async function checkAvailability(kind, value) {
        const params = new URLSearchParams();
        params.set('resource', 'auth-availability');
        if (kind === 'email') params.set('email', value);
        if (kind === 'nickname') params.set('nickname', value);
        const resp = await fetch('records.php?' + params.toString(), { method: 'GET' });
        const data = await resp.json().catch(() => ({}));
        return data;
    }

    checkEmailBtn?.addEventListener('click', async () => {
        const v = form.email.value.trim();
        if (!v) { showCheckResult(checkEmailResultEl, false, '이메일을 입력해주세요.'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { showCheckResult(checkEmailResultEl, false, '올바른 이메일 형식이 아닙니다.'); return; }
        checkEmailBtn.disabled = true; checkEmailBtn.textContent = '확인 중…';
        try {
            const data = await checkAvailability('email', v);
            if (data?.email_taken === true) {
                showCheckResult(checkEmailResultEl, false, '이미 사용 중인 이메일입니다.');
            } else if (data?.email_invalid) {
                showCheckResult(checkEmailResultEl, false, '올바른 이메일 형식이 아닙니다.');
            } else {
                showCheckResult(checkEmailResultEl, true, '✅ 사용 가능한 이메일입니다.');
            }
        } catch (e) {
            showCheckResult(checkEmailResultEl, false, '확인 실패: ' + (e?.message || e));
        } finally {
            checkEmailBtn.disabled = false; checkEmailBtn.textContent = '중복확인';
        }
    });
    // 이메일 변경 시 결과 reset
    form.email?.addEventListener('input', () => {
        if (checkEmailResultEl) checkEmailResultEl.hidden = true;
    });

    checkNickBtn?.addEventListener('click', async () => {
        const v = form.nickname.value.trim();
        if (!v) { showCheckResult(checkNickResultEl, false, '닉네임을 입력해주세요.'); return; }
        checkNickBtn.disabled = true; checkNickBtn.textContent = '확인 중…';
        try {
            const data = await checkAvailability('nickname', v);
            if (data?.nickname_invalid) {
                showCheckResult(checkNickResultEl, false, '닉네임은 2~20자, 한글/영문/숫자/_/- 만 가능합니다.');
            } else if (data?.nickname_taken === true) {
                showCheckResult(checkNickResultEl, false, '이미 사용 중인 닉네임입니다.');
            } else {
                showCheckResult(checkNickResultEl, true, '✅ 사용 가능한 닉네임입니다.');
            }
        } catch (e) {
            showCheckResult(checkNickResultEl, false, '확인 실패: ' + (e?.message || e));
        } finally {
            checkNickBtn.disabled = false; checkNickBtn.textContent = '중복확인';
        }
    });
    form.nickname?.addEventListener('input', () => {
        if (checkNickResultEl) checkNickResultEl.hidden = true;
    });

    // 전체동의 체크 동기화
    agreeAll?.addEventListener('change', () => {
        [...agreeReqs, ...agreeOpts].forEach(el => { if (el) el.checked = agreeAll.checked; });
    });
    [...agreeReqs, ...agreeOpts].forEach(el => el?.addEventListener('change', () => {
        if (agreeAll) agreeAll.checked = [...agreeReqs, ...agreeOpts].every(x => x?.checked);
    }));

    function normalizePhone(v) {
        const d = String(v || '').replace(/[^\d]/g, '');
        if (/^01[016789]\d{7,8}$/.test(d)) return d;
        return '';
    }
    function validNickname(v) {
        return /^[가-힣A-Za-z0-9_-]{2,20}$/.test(String(v || ''));
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const isSignup = mode === 'signup';
        const email = form.email?.value?.trim() || '';
        const password = form.password?.value || '';

        console.log('[auth submit]', { mode, email, hasPassword: !!password });   // 디버깅

        if (!email || !password) { msgEl.textContent = '이메일과 비밀번호를 입력해주세요.'; return; }

        // supabaseClient 미초기화 가드 — bootApp 이 init 못 한 케이스
        if (!supabaseClient?.auth) {
            msgEl.style.color = '#c8362c';
            msgEl.textContent = '인증 초기화 중입니다. 잠시 후 다시 시도해주세요.';
            try { await initSupabase(); } catch {}
            return;
        }

        if (isSignup) {
            const fullName  = form.fullName?.value?.trim() || '';
            const phone     = normalizePhone(form.phone?.value || '');
            const nickname  = form.nickname?.value?.trim() || '';
            const passwordConfirm = form.passwordConfirm?.value || '';

            if (!fullName)                              { msgEl.textContent = '이름을 입력해주세요.'; return; }
            if (!phone)                                 { msgEl.textContent = '올바른 휴대폰 번호를 입력해주세요 (010-...).'; return; }
            if (!validNickname(nickname))               { msgEl.textContent = '닉네임은 2~20자, 한글/영문/숫자/_/- 만 가능합니다.'; return; }
            if (password !== passwordConfirm)           { msgEl.textContent = '비밀번호와 확인이 일치하지 않습니다.'; return; }
            if (!form.agreeTerms.checked || !form.agreePrivacy.checked) {
                msgEl.textContent = '필수 약관에 동의해주세요.'; return;
            }

            submitBtn.disabled = true; submitBtn.textContent = '가입 중…'; msgEl.textContent = '';
            try {
                const consentMeta = {
                    terms_agreed: true,
                    privacy_agreed: true,
                    marketing_opt_in: !!form.agreeMarketing?.checked,
                    consent_at: new Date().toISOString(),
                };
                console.log('[signUp] calling supabase.auth.signUp...');
                const { data, error } = await supabaseClient.auth.signUp({
                    email, password,
                    options: { data: {
                        full_name: fullName, phone, nickname,
                        phone_verified: false, identity_verified: false,
                        app_registered: true, signup_method: 'email',
                        ...consentMeta,
                    }},
                });
                console.log('[signUp] result', { error, hasSession: !!data?.session, userId: data?.user?.id });
                if (error) throw error;
                if (!data.session) {
                    msgEl.style.color = '#1b5e20';
                    msgEl.textContent = '가입 확인 메일을 보냈습니다. 메일 인증 후 로그인하세요.';
                    submitBtn.disabled = false; submitBtn.textContent = '회원가입';
                    return;
                }
                // member 행 저장 (records.php auth-member POST) — 실패 시 사용자에게 명시 알림.
                try {
                    const token = data.session.access_token;
                    const memberResp = await fetch('records.php?resource=auth-member&ensure=1', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
                        body: JSON.stringify({
                            resource: 'auth-member',
                            ensure: true,   // idempotent — 이미 있어도 OK
                            email, fullName, phone, nickname, provider: 'email',
                            ...consentMeta,
                        }),
                    });
                    const memberData = await memberResp.json().catch(() => ({}));
                    console.log('[members POST]', memberResp.status, memberData);
                    if (!memberResp.ok && !memberData?.ok) {
                        const failMsg = memberData?.error || ('HTTP ' + memberResp.status);
                        msgEl.style.color = '#c8362c';
                        msgEl.innerHTML = '<b>회원 정보 등록 실패</b><br>'
                            + '<small style="display:block;margin-top:4px;color:#4f4943;">' + escapeHtmlSafe(failMsg) + '</small>'
                            + '<small style="display:block;margin-top:4px;font-size:11px;color:#8a847e;">Supabase 인증은 완료됐지만 사이트 회원 정보 저장 실패. 관리자에게 위 메시지를 알려주세요.</small>';
                        submitBtn.disabled = false; submitBtn.textContent = '회원가입';
                        return;
                    }
                } catch (memberErr) {
                    console.error('[members POST] error', memberErr);
                    msgEl.style.color = '#c8362c';
                    msgEl.textContent = '회원 정보 등록 네트워크 오류: ' + (memberErr?.message || memberErr);
                    submitBtn.disabled = false; submitBtn.textContent = '회원가입';
                    return;
                }
                close();
                await navigateAfterAuth();
            } catch (err) {
                console.error('[signUp] error', err);
                msgEl.style.color = '#c8362c';
                const raw = String(err?.message || '');
                // supabase "User already registered" → 큰 안내 + 비밀번호 재설정 옵션 + 로그인 자동 전환
                if (/already\s*registered|already\s*exists|user.*exist/i.test(raw)) {
                    msgEl.innerHTML = ''
                        + '<b style="color:#c8362c;font-size:14px;">이미 가입된 이메일입니다.</b><br>'
                        + '<span style="color:#4f4943;font-size:12.5px;">로그인 모드로 전환하거나, 비밀번호를 모르면 재설정 메일을 받아주세요.</span><br>'
                        + '<button type="button" id="ymanResetPwdBtn" style="margin-top:8px;padding:6px 12px;background:#fff5ed;border:1px solid rgba(200,54,44,.4);color:#c8362c;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;">📧 비밀번호 재설정 메일 보내기</button>';
                    submitBtn.disabled = false; submitBtn.textContent = '회원가입';
                    const resetBtn = msgEl.querySelector('#ymanResetPwdBtn');
                    if (resetBtn) {
                        resetBtn.addEventListener('click', async () => {
                            resetBtn.disabled = true; resetBtn.textContent = '메일 보내는 중…';
                            try {
                                const { error: resetErr } = await supabaseClient.auth.resetPasswordForEmail(email, {
                                    redirectTo: window.location.origin + '/index.html',
                                });
                                if (resetErr) throw resetErr;
                                msgEl.innerHTML = '<b style="color:#1b5e20;">📧 ' + email + ' 으로 재설정 메일을 보냈습니다.</b><br><span style="font-size:12px;color:#4f4943;">메일함을 확인하고 링크를 클릭하세요.</span>';
                            } catch (e2) {
                                resetBtn.disabled = false; resetBtn.textContent = '📧 비밀번호 재설정 메일 보내기';
                                msgEl.insertAdjacentHTML('beforeend', '<br><span style="color:#c8362c;font-size:11.5px;">전송 실패: ' + escapeHtmlSafe(translateAuthError(e2?.message, '알 수 없음')) + '</span>');
                            }
                        });
                    }
                    // 5초 후 자동으로 로그인 모드 전환 (사용자가 비밀번호 안다면)
                    setTimeout(() => {
                        if (mode === 'signup') {
                            mode = 'login';
                            applyMode();
                            msgEl.innerHTML = '<span style="color:#0e0d0c;font-size:13px;">로그인 모드로 전환했습니다. 비밀번호를 입력하고 "로그인" 을 눌러주세요.</span>';
                        }
                    }, 5000);
                } else {
                    msgEl.textContent = translateAuthError(raw, '가입 처리에 실패했습니다.');
                    submitBtn.disabled = false; submitBtn.textContent = '회원가입';
                }
            }
        } else {
            submitBtn.disabled = true; submitBtn.textContent = '로그인 중…'; msgEl.textContent = '';
            try {
                console.log('[signIn] calling signInWithPassword...');
                const { data, error } = await supabaseClient.auth.signInWithPassword({ email, password });
                console.log('[signIn] result', { error, hasSession: !!data?.session });
                if (error) throw error;
                // 로그인 유지 체크박스 처리:
                // 체크: localStorage (supabase default — 영구)
                // 해제: sessionStorage.erp.endSessionOnClose='1' set + 브라우저 unload 시 sb-* 토큰 제거
                const remember = form.rememberMe?.checked !== false;
                try {
                    if (!remember) {
                        sessionStorage.setItem('erp.endSessionOnClose', '1');
                    } else {
                        sessionStorage.removeItem('erp.endSessionOnClose');
                    }
                } catch {}
                close();
                await navigateAfterAuth();
            } catch (err) {
                console.error('[signIn] error', err);
                msgEl.style.color = '#c8362c';
                msgEl.textContent = translateAuthError(err?.message, '로그인에 실패했습니다.');
                submitBtn.disabled = false; submitBtn.textContent = '로그인';
            }
        }
    });

    googleBtn.addEventListener('click', () => {
        // 동기 흐름 유지 — await 한 번이라도 들어가면 모바일이 user gesture 잃음으로 인식 → OAuth navigation 차단.
        msgEl.textContent = '';
        googleBtn.disabled = true;

        const client = supabaseClient;
        console.log('[google oauth] click', { hasClient: !!client, hasAuth: !!client?.auth });

        if (!client?.auth?.signInWithOAuth) {
            msgEl.style.color = '#c8362c';
            msgEl.textContent = '인증 초기화 중입니다. 한 번 더 클릭해주세요.';
            googleBtn.disabled = false;
            try { initSupabase().catch(() => {}); } catch {}
            return;
        }

        // OAuth callback 도 login-complete.html 을 거치게 해서 일관된 transition 흐름.
        const origin = window.location.origin;
        const path   = window.location.pathname.replace(/^\//, '') || 'index.html';
        const search = window.location.search || '';
        const nextRaw = path + search;
        const redirectTo = origin + '/login-complete.html?next=' + encodeURIComponent(nextRaw);
        console.log('[google oauth] redirectTo', redirectTo);

        // signInWithOAuth 는 sync 으로 OAuth URL 생성 + location.assign 시도 → 같은 user gesture 안에 실행.
        // prompt=select_account: 항상 계정 선택 화면 표시 (가입 시 다른 계정 선택 가능).
        try {
            client.auth.signInWithOAuth({
                provider: 'google',
                options: {
                    redirectTo,
                    queryParams: { prompt: 'select_account' },
                },
            }).then(({ error, data }) => {
                console.log('[google oauth] response', { hasUrl: !!data?.url, error });
                if (error) {
                    msgEl.style.color = '#c8362c';
                    msgEl.textContent = translateAuthError(error.message, 'Google 로그인 실패');
                    googleBtn.disabled = false;
                }
                // 성공 시 supabase 가 이미 redirect 시작 — 추가 처리 X.
            }).catch((err) => {
                console.error('[google oauth] catch', err);
                msgEl.style.color = '#c8362c';
                msgEl.textContent = translateAuthError(err?.message, 'Google 로그인 실패');
                googleBtn.disabled = false;
            });
        } catch (sync_err) {
            console.error('[google oauth] sync throw', sync_err);
            msgEl.style.color = '#c8362c';
            msgEl.textContent = sync_err?.message || 'OAuth 호출 실패';
            googleBtn.disabled = false;
        }
    });
}

/* =========================================================================
   레거시 호환 — 기존 페이지들이 setupHeaderUser({...}) 로 호출하던 거 그대로 동작.
   내부에선 refreshAppHeader 가 처리. 인자는 무시.
   ========================================================================= */
export function setupHeaderUser(/* opts */) {
    refreshAppHeader();   // fire-and-forget
}

/**
 * admin 페이지 진입 차단 — admin 이 아니면 index 로 리다이렉트.
 * upload.html / card-builder.html 같은 페이지에서 boot 시 호출.
 */
export function requireAdmin() {
    if (!currentSession?.user) {
        window.location.replace('index.html');
        return false;
    }
    if (!isAdmin(currentSession)) {
        window.location.replace('index.html');
        return false;
    }
    return true;
}
