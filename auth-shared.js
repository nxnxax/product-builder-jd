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

export function setupHeaderUser({ userMenu, userEmail, adminLink, logoutBtn }) {
    const loggedIn = !!currentSession?.user;
    const admin = loggedIn && isAdmin(currentSession);

    // 로그인 버튼 — 비로그인 시만 노출.
    document.querySelectorAll('#open-login-btn').forEach(btn => {
        btn.classList.toggle('hidden', loggedIn);
    });

    // admin 전용 메뉴 (업로드 / 명함 제작 등) — admin 만 노출.
    document.querySelectorAll('[data-admin-only]').forEach(el => {
        el.classList.toggle('hidden', !admin);
    });

    if (!loggedIn) {
        if (userMenu) userMenu.classList.add('hidden');
        return;
    }
    if (userEmail) userEmail.textContent = currentSession.user.email || '';
    if (userMenu) userMenu.classList.remove('hidden');
    if (adminLink) adminLink.classList.toggle('hidden', !admin);
    if (logoutBtn && !logoutBtn.dataset.bound) {
        logoutBtn.dataset.bound = '1';
        logoutBtn.addEventListener('click', async () => {
            if (supabaseClient) await supabaseClient.auth.signOut();
            window.location.href = 'index.html';
        });
    }
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
