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
        supabaseClient.auth.onAuthStateChange((_event, session) => {
            currentSession = session || null;
        });
        return { client: supabaseClient, session: currentSession };
    })();
    return initPromise;
}

export function getSession() { return currentSession; }
export function getClient() { return supabaseClient; }

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

    // 주 기능 (관리대장 3종) — 큰 pill 버튼으로 항상 강조.
    // 보조 기능 (마케팅·Lotto·명함·업로드) — 우측에 작게 깔림.
    const primaryItems = [
        { key: 'customers.html', label: '고객 관리대장',    href: 'customers.html' },
        { key: 'org.html',       label: '조직도',           href: 'org.html' },
        { key: 'contracts.html', label: '계약자 관리대장', href: 'contracts.html' },
    ];
    // 단색 라인 아이콘 (Lucide 스타일) — currentColor 따라감.
    const SVG = (path) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
    const ICON = {
        chart:     SVG('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>'),
        sparkles:  SVG('<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/>'),
        card:      SVG('<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>'),
        upload:    SVG('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>'),
        users:     SVG('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
        megaphone: SVG('<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>'),
        chat:      SVG('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'),
        help:      SVG('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'),
    };

    const secondaryItems = [
        { key: 'index.html',        label: 'N키워드 분석', href: 'index.html#marketing', icon: ICON.chart },
        { key: 'lotto2233.html',    label: '재미로 보는<br>사주+로또추천', href: 'lotto2233.html', extraCls: 'nav-link-multi', rawLabel: true, icon: ICON.sparkles },
        { key: 'card-builder.html', label: '명함',         href: 'card-builder.html', adminOnly: true, icon: ICON.card },
        { key: 'upload.html',       label: '업로드',       href: 'upload.html', adminOnly: true, icon: ICON.upload },
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
                <span class="nav-label">커뮤니티 <span class="nav-caret">▾</span></span>
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

    const primaryHtml = primaryItems.map(i => renderItem(i, 'nav-pill')).join('');
    const secondaryHtml = secondaryItems.map(i => renderItem(i, 'nav-link nav-link-secondary')).join('') + communityHtml;

    if (!root.classList.contains('app-header')) root.classList.add('app-header');
    root.innerHTML = `
        <div class="header-container">
            <h1><a href="index.html" class="brand-logo" aria-label="YOUNGMAN 홈"><img src="logo_main.png" alt="YOUNGMAN"></a></h1>
            <nav class="nav-primary">${primaryHtml}</nav>
            <nav class="nav-secondary">${secondaryHtml}</nav>
            <a href="index.html#login" class="header-auth-btn" id="open-login-btn">로그인</a>
            <div id="user-menu" class="user-menu">
                <span id="user-display" class="user-display">${escapeHtmlSafe(cachedName)}</span>
                <a href="profile.html" id="profile-link" class="user-menu-link">내 정보</a>
                <a href="admin.html" id="admin-link" class="user-menu-link">관리자</a>
                <button type="button" id="logout-btn" class="user-menu-btn">로그아웃</button>
            </div>
        </div>
    `;

    // logout 핸들러는 매 마운트마다 새로 바인딩 (innerHTML 재생성됐으니).
    const logoutBtn = root.querySelector('#logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async () => {
            cacheDisplayName('');
            cacheAdminFlag(false);
            try { if (supabaseClient) await supabaseClient.auth.signOut(); } catch {}
            window.location.href = 'index.html';
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
