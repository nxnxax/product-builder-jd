/**
 * Shared auth + API helpers used by index, profile, and admin pages.
 * Keeps Supabase client initialization in one place.
 */

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
        }
        supabaseClient.auth.onAuthStateChange((event, session) => {
            const had = !!currentSession?.user;
            currentSession = session || null;
            cacheUserEmail(currentSession?.user?.email);
            // 로그인 전환 시점에 사용자 정의 양식 목록을 서버에서 새로 가져와
            // 슬롯 dropdown 에 즉시 반영. 같은 디바이스에서 로그아웃→재로그인
            // 케이스의 양식 누락 문제 fix.
            if (currentSession?.user && (event === 'SIGNED_IN' || event === 'INITIAL_SESSION' || event === 'TOKEN_REFRESHED' || !had)) {
                try { refreshNavFormsCache(); } catch {}
            }
        });
        return { client: supabaseClient, session: currentSession };
    })();
    return initPromise;
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

export async function apiRequest(resource, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
    };
    const token = await getAccessToken({ forceRefresh: true });
    if (token) headers.Authorization = `Bearer ${token}`;

    const url = options.query
        ? `${API_URL}?resource=${encodeURIComponent(resource)}&${options.query}`
        : `${API_URL}?resource=${encodeURIComponent(resource)}`;

    const response = await fetch(url, {
        method: options.method || 'GET',
        headers,
        body: options.body,
    });
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
    const candidates = [
        profile?.name,
        profile?.full_name,
        user?.user_metadata?.full_name,
        user?.user_metadata?.name,
        user?.user_metadata?.nickname,
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
    // 원래 가려던 페이지 (현재 URL) 을 next 로 인코딩 — login-complete 가 redirect.
    let next = '';
    try {
        const path = window.location.pathname.replace(/^\//, '');
        next = (path || 'customers.html') + window.location.search;
        // index.html 에서 로그인 했으면 customers.html 로 (메인 대신 작업 페이지로).
        if (!path || /^index\.html/i.test(path)) next = 'customers.html';
    } catch { next = 'customers.html'; }

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
    try { e?.preventDefault?.(); e?.stopPropagation?.(); } catch {}

    // 시각적 즉시 반영 (사용자 피드백 — navigation 사이 짧은 순간)
    try {
        document.body.classList.add('is-anon');
        document.body.classList.remove('is-admin');
    } catch {}

    const target = 'logout.html?_t=' + Date.now();
    try {
        window.location.replace(target);
    } catch {
        try { window.location.href = target; } catch {
            try { window.location.assign(target); } catch {}
        }
    }
}

// document 레벨 backup 클릭 위임 — 브라우저 navigation 을 막지 않음 (가장 reliable).
// <a href="logout.html"> 인 경우: fresh timestamp 만 attach → 브라우저가 native navigation.
// <button> 인 경우 (예: profile.html 의 #account-signout): performLogout 호출 (location.replace).
if (typeof document !== 'undefined' && typeof window !== 'undefined' && !window.__ymanLogoutBound) {
    document.addEventListener('click', (e) => {
        const btn = e.target?.closest?.('#logout-btn, #drawer-logout-btn, #account-signout, [data-logout]');
        if (!btn) return;
        // 시각 보조 (즉시 anon 상태 표시)
        try {
            document.body.classList.add('is-anon');
            document.body.classList.remove('is-admin');
        } catch {}
        // a 태그면 href 만 fresh 하게 — preventDefault 호출 X, 브라우저 navigation 진행.
        if (btn.tagName === 'A') {
            try { btn.setAttribute('href', 'logout.html?_t=' + Date.now()); } catch {}
            return;   // 브라우저가 a href 로 navigate 함
        }
        // button 류는 명시적 navigation.
        performLogout(e);
    }, true);   // capture phase — 다른 핸들러보다 먼저
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
    const cachedName = readCachedDisplayName();
    const cachedAdmin = readCachedAdminFlag();

    // body 클래스로 가시성 제어 — CSS 가 admin-only / user-menu / login-btn 조정.
    document.body.classList.toggle('is-admin', cachedAdmin);
    document.body.classList.toggle('is-anon', !cachedName);

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

    let displayName = getDisplayName(null, currentSession.user);
    // members.name 가져와서 가장 정확한 이름으로 갱신.
    try {
        const payload = await apiRequest('auth-profile');
        if (payload?.profile) displayName = getDisplayName(payload.profile, currentSession.user);
    } catch {}

    cacheDisplayName(displayName);
    const display = document.getElementById('user-display');
    if (display) display.textContent = displayName;

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
                    <label>이메일 <input type="email" name="email" autocomplete="email" required placeholder="name@example.com"></label>
                    <div class="shared-auth-fields" data-signup-only hidden>
                        <label>닉네임 <input type="text" name="nickname" minlength="2" maxlength="20" placeholder="2~20자 한글/영문/숫자"></label>
                    </div>
                    <label>비밀번호 <input type="password" name="password" autocomplete="current-password" required minlength="6" placeholder="6자 이상"></label>
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
        form.password.setAttribute('autocomplete', isSignup ? 'new-password' : 'current-password');
        msgEl.textContent = '';
    }
    applyMode();

    modeBtn.addEventListener('click', () => { mode = (mode === 'signup') ? 'login' : 'signup'; applyMode(); });

    const close = () => md.remove();
    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    const escHandler = (e) => { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', escHandler); } };
    document.addEventListener('keydown', escHandler);
    setTimeout(() => form.email?.focus(), 50);

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
        const email = form.email.value.trim();
        const password = form.password.value;

        if (!email || !password) { msgEl.textContent = '이메일과 비밀번호를 입력해주세요.'; return; }

        if (isSignup) {
            const fullName  = form.fullName.value.trim();
            const phone     = normalizePhone(form.phone.value);
            const nickname  = form.nickname.value.trim();
            const passwordConfirm = form.passwordConfirm.value;

            if (!fullName)                              { msgEl.textContent = '이름을 입력해주세요.'; return; }
            if (!phone)                                 { msgEl.textContent = '올바른 휴대폰 번호를 입력해주세요 (010-...).'; return; }
            if (!validNickname(nickname))               { msgEl.textContent = '닉네임은 2~20자, 한글/영문/숫자/_/- 만 가능합니다.'; return; }
            if (password !== passwordConfirm)           { msgEl.textContent = '비밀번호와 확인이 일치하지 않습니다.'; return; }
            if (!form.agreeTerms.checked || !form.agreePrivacy.checked) {
                msgEl.textContent = '필수 약관에 동의해주세요.'; return;
            }

            submitBtn.disabled = true; submitBtn.textContent = '가입 중…'; msgEl.textContent = '';
            try {
                if (!supabaseClient) { await initSupabase(); }
                const consentMeta = {
                    terms_agreed: true,
                    privacy_agreed: true,
                    marketing_opt_in: !!form.agreeMarketing.checked,
                    consent_at: new Date().toISOString(),
                };
                const { data, error } = await supabaseClient.auth.signUp({
                    email, password,
                    options: { data: {
                        full_name: fullName, phone, nickname,
                        phone_verified: false, identity_verified: false,
                        app_registered: true, signup_method: 'email',
                        ...consentMeta,
                    }},
                });
                if (error) throw error;
                if (!data.session) {
                    msgEl.style.color = '#1b5e20';
                    msgEl.textContent = '가입 확인 메일을 보냈습니다. 메일 인증 후 로그인하세요.';
                    submitBtn.disabled = false; submitBtn.textContent = '회원가입';
                    return;
                }
                // member 행 저장 (records.php auth-member POST)
                try {
                    const token = data.session.access_token;
                    await fetch('records.php?resource=auth-member', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
                        body: JSON.stringify({
                            resource: 'auth-member',
                            email, fullName, phone, nickname, provider: 'email',
                            ...consentMeta,
                        }),
                    });
                } catch {}
                close();
                await navigateAfterAuth();
            } catch (err) {
                msgEl.style.color = '#c8362c';
                msgEl.textContent = err?.message || '가입 처리에 실패했습니다.';
                submitBtn.disabled = false; submitBtn.textContent = '회원가입';
            }
        } else {
            submitBtn.disabled = true; submitBtn.textContent = '로그인 중…'; msgEl.textContent = '';
            try {
                if (!supabaseClient) { await initSupabase(); }
                const { error } = await supabaseClient.auth.signInWithPassword({ email, password });
                if (error) throw error;
                close();
                await navigateAfterAuth();
            } catch (err) {
                msgEl.style.color = '#c8362c';
                msgEl.textContent = err?.message || '로그인 실패';
                submitBtn.disabled = false; submitBtn.textContent = '로그인';
            }
        }
    });

    googleBtn.addEventListener('click', async () => {
        msgEl.textContent = '';
        googleBtn.disabled = true;
        try {
            if (!supabaseClient) { await initSupabase(); }
            // OAuth callback 도 login-complete.html 을 거치게 해서 일관된 transition 흐름.
            const origin = window.location.origin;
            const path   = window.location.pathname.replace(/^\//, '') || 'customers.html';
            const search = window.location.search || '';
            // index.html 에서 시작했으면 작업 페이지(customers)로.
            const nextRaw = /^index\.html/i.test(path) ? 'customers.html' : (path + search);
            const redirectTo = origin + '/login-complete.html?next=' + encodeURIComponent(nextRaw);
            const { error } = await supabaseClient.auth.signInWithOAuth({
                provider: 'google',
                options: { redirectTo },
            });
            if (error) throw error;
        } catch (err) {
            msgEl.style.color = '#c8362c';
            msgEl.textContent = err?.message || 'Google 로그인 실패';
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
