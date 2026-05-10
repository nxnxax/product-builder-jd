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
        home:      SVG('<path d="M3 10.5 12 3l9 7.5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>'),
        building:  SVG('<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-4h6v4"/><path d="M8 7h.01"/><path d="M12 7h.01"/><path d="M16 7h.01"/><path d="M8 11h.01"/><path d="M12 11h.01"/><path d="M16 11h.01"/>'),
        fileText:  SVG('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>'),
        users:     SVG('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'),
        user:      SVG('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'),
        megaphone: SVG('<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>'),
        chat:      SVG('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'),
        help:      SVG('<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>'),
    };

    const secondaryItems = [
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

    const primaryHtml = primaryItems.map(i => renderItem(i, 'nav-pill')).join('');
    const secondaryHtml = secondaryItems.map(i => renderItem(i, 'nav-link nav-link-secondary')).join('') + communityHtml;
    const bottomItems = [
        { key: 'index.html',     label: '홈',             href: 'index.html',     icon: ICON.home },
        { key: 'customers.html', label: '고객관리대장',   href: 'customers.html', icon: ICON.users },
        { key: 'org.html',       label: '조직도',         href: 'org.html',       icon: ICON.building },
        { key: 'contracts.html', label: '계약자 관리대장', href: 'contracts.html', icon: ICON.fileText },
    ];
    const bottomHtml = bottomItems.map(item => {
        const isHome = item.key === 'index.html' && (path === '' || path === 'index.html');
        const isActive = isHome || path === item.key;
        return `
            <a class="mobile-bottom-nav-item${isActive ? ' active' : ''}" href="${item.href}">
                <span class="mobile-bottom-nav-icon">${item.icon}</span>
                <span class="mobile-bottom-nav-label">${escapeHtmlSafe(item.label)}</span>
            </a>
        `;
    }).join('');

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
                <button type="button" id="logout-btn" class="user-menu-btn">로그아웃</button>
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
                <button type="button" class="mobile-drawer-account-link" id="drawer-logout-btn" data-anon-hide><span class="mobile-drawer-icon">↩</span><span>로그아웃</span></button>
                <a href="index.html#login" class="mobile-drawer-account-link" data-anon-show><span class="mobile-drawer-icon">→</span><span>로그인</span></a>
            </div>
        </aside>
    `;
    [...drawerWrap.children].forEach(c => document.body.appendChild(c));
    const drawerEl = document.querySelector('.mobile-drawer[data-yman-drawer]');

    document.querySelectorAll('[data-yman-bottom-nav]').forEach(el => el.remove());
    const bottomNav = document.createElement('nav');
    bottomNav.className = 'mobile-bottom-nav';
    bottomNav.setAttribute('aria-label', '주요 메뉴');
    bottomNav.setAttribute('data-yman-bottom-nav', '');
    bottomNav.innerHTML = bottomHtml;
    document.body.appendChild(bottomNav);

    // logout 핸들러 — 헤더 + 드로어 모두
    const handleLogout = async () => {
        cacheDisplayName('');
        cacheAdminFlag(false);
        try { if (supabaseClient) await supabaseClient.auth.signOut(); } catch {}
        window.location.href = 'index.html';
    };
    const logoutBtn = root.querySelector('#logout-btn');
    if (logoutBtn) logoutBtn.addEventListener('click', handleLogout);
    const drawerLogoutBtn = drawerEl?.querySelector('#drawer-logout-btn');
    if (drawerLogoutBtn) drawerLogoutBtn.addEventListener('click', handleLogout);

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
