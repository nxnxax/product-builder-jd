/**
 * ERP Integrated Management System - Main Logic
 */

// --- State Management ---
const API_URL = 'records.php';
const MIGRATION_FLAG = 'erpDbMigrationComplete';
const OAUTH_INTENT_KEY = 'erpOAuthIntent';
const OAUTH_MODE_PARAM = 'oauth_mode';
const OAUTH_EMAIL_KEY = 'erpOAuthEmail';
const OAUTH_PENDING_SIGNUP_KEY = 'erpOAuthPendingSignup';
const AUTH_NOTICE_ACTION_KEY = 'erpAuthNoticeAction';
const AUTH_NOTICE_KEY = 'erpAuthNotice';
const SIGNUP_REQUIRED_MESSAGE = '회원가입이 필요합니다. 회원가입을 진행하시겠습니까?';
const SIGNUP_EXISTING_MESSAGE = '이미 가입된 계정입니다. 로그인하시겠습니까?';

let customers = [];
let employees = [];
let currentView = 'customers'; // 'customers', 'employees', or 'marketing'
let customerFilter = '';
let employeeFilter = '';
let isLoading = false;
let authEnabled = false;
let authMode = 'login';
let supabaseClient = null;
let currentSession = null;
let oauthSignupPending = false;

// --- DOM Elements ---
const navCustomers = document.getElementById('nav-customers');
const navEmployees = document.getElementById('nav-employees');
const navMarketing = document.getElementById('nav-marketing');

const authScreen = document.getElementById('auth-screen');
const appHeader = document.getElementById('app-header');
const appDashboard = document.getElementById('app-dashboard');
const authForm = document.getElementById('auth-form');
const closeAuthBtn = document.getElementById('close-auth-btn');
const signupFields = document.getElementById('signup-fields');
const authName = document.getElementById('auth-name');
const authPhone = document.getElementById('auth-phone');
const authEmail = document.getElementById('auth-email');
const authPassword = document.getElementById('auth-password');
const confirmPasswordField = document.getElementById('confirm-password-field');
const authPasswordConfirm = document.getElementById('auth-password-confirm');
const authNickname = document.getElementById('auth-nickname');
const nicknameField = document.getElementById('nickname-field');
const authNicknameStatus = document.getElementById('auth-nickname-status');
const authEmailStatus = document.getElementById('auth-email-status');
const authSubmit = document.getElementById('auth-submit');
const authMessage = document.getElementById('auth-message');
const authNotice = document.getElementById('auth-notice');
const authNoticeText = document.getElementById('auth-notice-text');
const authNoticeAccept = document.getElementById('auth-notice-accept');
const authNoticeCancel = document.getElementById('auth-notice-cancel');
const identityVerifyBtn = document.getElementById('identity-verify-btn');
const identityStatus = document.getElementById('identity-status');
const googleLoginBtn = document.getElementById('google-login-btn');
const googleLoginLabel = document.getElementById('google-login-label');
const loginTab = document.getElementById('login-tab');
const signupTab = document.getElementById('signup-tab');
const authSwitchText = document.getElementById('auth-switch-text');
const openLoginBtn = document.getElementById('open-login-btn');
const userMenu = document.getElementById('user-menu');
const userEmail = document.getElementById('user-email');
const logoutBtn = document.getElementById('logout-btn');
const adminLink = document.getElementById('admin-link');

const customerSection = document.getElementById('customer-section');
const employeeSection = document.getElementById('employee-section');
const marketingSection = document.getElementById('marketing-section');

const customerList = document.getElementById('customer-list');
const employeeList = document.getElementById('employee-list');
const customerEmpty = document.getElementById('customer-empty');
const employeeEmpty = document.getElementById('employee-empty');

const appModal = document.getElementById('app-modal');
const appForm = document.getElementById('app-form');
const formFields = document.getElementById('form-fields');
const modalTitle = document.getElementById('modal-title');
const saveBtn = document.getElementById('save-btn');

const customerSearch = document.getElementById('customer-search');
const employeeSearch = document.getElementById('employee-search');

// --- Initialization ---
document.addEventListener('DOMContentLoaded', initApp);

async function initApp() {
    try {
        await initAuth();
        applyInitialHash();
        if (oauthSignupPending) {
            renderAuthRequiredTables();
            return;
        }

        if (authEnabled && !currentSession) {
            renderSignedOut();
            renderAuthRequiredTables();
            showPendingAuthNotice();
            return;
        }

        renderSignedIn();
        renderLoading();
        await loadAll();
        await migrateLocalStorageIfNeeded();
        renderAll();
    } catch (error) {
        showError(error);
    }
}

async function initAuth() {
    const config = await loadSupabaseConfig();
    if (!config) {
        authEnabled = false;
        return;
    }

    const { createClient } = await import('https://esm.sh/@supabase/supabase-js@2');
    supabaseClient = createClient(config.url, config.anonKey);
    authEnabled = true;

    const { data, error } = await supabaseClient.auth.getSession();
    if (error) throw error;
    currentSession = data.session || null;
    if (currentSession) await handlePostOAuthSession();

    supabaseClient.auth.onAuthStateChange((event, session) => {
        currentSession = session || null;
        if (event === 'SIGNED_OUT' || !currentSession) {
            renderSignedOut();
            renderAuthRequiredTables();
            showPendingAuthNotice();
            return;
        }

        setTimeout(async () => {
            try {
                await handlePostOAuthSession();
                if (!currentSession) {
                    renderSignedOut();
                    renderAuthRequiredTables();
                    showPendingAuthNotice();
                    return;
                }
                if (oauthSignupPending) {
                    renderAuthRequiredTables();
                    return;
                }

                renderSignedIn();
                renderLoading();
                await loadAll();
                renderAll();
            } catch (error) {
                showError(error);
            }
        }, 0);
    });
}

async function loadSupabaseConfig() {
    try {
        const config = await import(`./supabase_config.js?v=${Date.now()}`);
        const url = normalizeSupabaseUrl(config.SUPABASE_URL);
        const anonKey = String(config.SUPABASE_ANON_KEY || '').trim();
        const isPlaceholder = url.includes('your-project-ref') || anonKey.includes('your-supabase-anon-key');
        if (!url || !anonKey || isPlaceholder) return null;
        return { url, anonKey };
    } catch {
        return null;
    }
}

function normalizeSupabaseUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
        return new URL(raw).origin;
    } catch {
        return raw.replace(/\/+$/, '');
    }
}

function getOAuthIntentFromUrl() {
    try {
        const url = new URL(window.location.href);
        let mode = url.searchParams.get(OAUTH_MODE_PARAM);
        
        // Also check hash for fragment-based params (Supabase often uses fragments)
        if (!mode && url.hash) {
            const hashPart = url.hash.substring(1);
            const hashParams = new URLSearchParams(hashPart.includes('?') ? hashPart.split('?')[1] : hashPart);
            mode = hashParams.get(OAUTH_MODE_PARAM);
        }
        
        return normalizeOAuthIntent(mode);
    } catch {
        return '';
    }
}

function normalizeOAuthIntent(value) {
    return value === 'signup' ? 'signup' : value === 'login' ? 'login' : '';
}

function getStoredOAuthIntent() {
    const fromUrl = getOAuthIntentFromUrl();
    if (fromUrl) return fromUrl;

    const fromSession = safeStorageGet(sessionStorage, OAUTH_INTENT_KEY);
    if (normalizeOAuthIntent(fromSession)) return fromSession;

    const fromLocal = safeStorageGet(localStorage, OAUTH_INTENT_KEY);
    if (normalizeOAuthIntent(fromLocal)) return fromLocal;

    return safeStorageGet(localStorage, OAUTH_PENDING_SIGNUP_KEY) === '1' ? 'signup' : 'login';
}

function saveOAuthIntent(intent) {
    const mode = normalizeOAuthIntent(intent) || 'login';
    safeStorageSet(sessionStorage, OAUTH_INTENT_KEY, mode);
    safeStorageSet(localStorage, OAUTH_INTENT_KEY, mode);
}

function clearOAuthIntent() {
    safeStorageRemove(sessionStorage, OAUTH_INTENT_KEY);
    safeStorageRemove(localStorage, OAUTH_INTENT_KEY);
    cleanOAuthIntentUrl();
}

function cleanOAuthIntentUrl() {
    try {
        const url = new URL(window.location.href);
        let changed = false;
        if (url.searchParams.has(OAUTH_MODE_PARAM)) {
            url.searchParams.delete(OAUTH_MODE_PARAM);
            changed = true;
        }
        // Also try to clean from hash if present
        if (url.hash.includes(OAUTH_MODE_PARAM)) {
            // Complex to clean hash accurately without library, but we can try basic replacement
            // Or just leave it as it's less critical than storage
        }
        if (changed) {
            const nextUrl = `${url.pathname}${url.search}${url.hash}`;
            window.history.replaceState({}, document.title, nextUrl);
        }
    } catch {
        // Ignore history cleanup failures.
    }
}

function buildOAuthRedirectTo(intent) {
    const url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set(OAUTH_MODE_PARAM, normalizeOAuthIntent(intent) || 'login');
    return url.toString();
}

function safeStorageGet(storage, key) {
    try {
        return storage.getItem(key) || '';
    } catch {
        return '';
    }
}

function safeStorageSet(storage, key, value) {
    try {
        storage.setItem(key, value);
    } catch {
        // Storage can be unavailable in restricted browser contexts.
    }
}

function safeStorageRemove(storage, key) {
    try {
        storage.removeItem(key);
    } catch {
        // Storage can be unavailable in restricted browser contexts.
    }
}

function summarizeAuthToken(token) {
    const value = String(token || '');
    const dotCount = (value.match(/\./g) || []).length;
    return {
        prefix20: value.slice(0, 20),
        isJwt: dotCount === 2,
        dotCount
    };
}

function getFirebaseCurrentUser() {
    const firebaseGlobal = window.firebase;
    if (firebaseGlobal?.auth && typeof firebaseGlobal.auth === 'function') {
        return firebaseGlobal.auth().currentUser || null;
    }
    if (window.firebaseAuth?.currentUser) return window.firebaseAuth.currentUser;
    if (window.auth?.currentUser) return window.auth.currentUser;
    return null;
}

async function getFirebaseIdToken(forceRefresh = true) {
    const user = getFirebaseCurrentUser();
    console.log('[Auth] Firebase currentUser exists:', Boolean(user));
    if (!user || typeof user.getIdToken !== 'function') return null;

    const token = await user.getIdToken(forceRefresh);
    console.log('[Auth] Using Firebase ID Token for API:', summarizeAuthToken(token));
    return token;
}

async function getApiAuthToken({ forceRefresh = false } = {}) {
    const firebaseToken = await getFirebaseIdToken(forceRefresh);
    if (firebaseToken) return firebaseToken;

    if (supabaseClient) {
        const { data } = await supabaseClient.auth.getSession();
        const session = data.session || currentSession;
        currentSession = session || currentSession;
        if (session?.access_token) {
            console.log('[Auth] Using Supabase access token for API:', summarizeAuthToken(session.access_token));
            return session.access_token;
        }
    }

    console.log('[Auth] No API auth token available.');
    return null;
}

let isProcessingOAuth = false;

async function handlePostOAuthSession() {
    if (isProcessingOAuth) return;

    const user = currentSession?.user;
    if (!user) return;

    const providers = user.app_metadata?.providers || [];
    const provider = user.app_metadata?.provider || '';
    const isGoogleUser = provider === 'google' || providers.includes('google');
    if (!isGoogleUser) return;

    isProcessingOAuth = true;
    try {
        const intent = getStoredOAuthIntent();
        console.log('[Auth] Processing OAuth session. Intent:', intent, 'Email:', user.email);
        clearOAuthIntent();

        // 1) 이미 회원인지 확인. 회원이면 그대로 통과.
        let isRegistered = false;
        try {
            isRegistered = await isRegisteredMember(user);
        } catch (e) {
            console.warn('[Auth] Membership check failed, treating as unregistered:', e);
        }

        if (isRegistered) {
            localStorage.removeItem(OAUTH_EMAIL_KEY);
            localStorage.removeItem(OAUTH_PENDING_SIGNUP_KEY);
            oauthSignupPending = false;
            return;
        }

        // 2) 회원이 아니면 — 의도(login/signup) 상관 없이 Google 프로필 정보로 자동 회원가입.
        //    사용자 입장에선 Google 로그인 한 번으로 가입+로그인 동시에 끝나야 자연스러움.
        const fullName = user.user_metadata?.full_name
                       || user.user_metadata?.name
                       || (user.email ? user.email.split('@')[0] : '');
        console.log('[Auth] Auto-registering Google user as member:', user.email);
        try {
            await completeGoogleSignup({ fullName, phone: '', nickname: '' });
            // completeGoogleSignup 가 closeAuthPanel + renderSignedIn 까지 처리.
        } catch (e) {
            // 자동가입 실패 시 사용자에게 명확히 알리고 패널 띄움.
            console.error('[Auth] Auto-signup failed:', e);
            try { openAuthPanel('login'); } catch {}
            const msg = (e && e.message) ? e.message : '회원 정보를 저장하지 못했습니다.';
            setAuthMessage('Google 로그인은 됐지만 회원 등록에 실패했습니다: ' + msg + ' — 잠시 후 다시 시도해주세요.', 'error');
            try { await supabaseClient.auth.signOut(); } catch {}
        }
    } catch (error) {
        console.error('[Auth] OAuth session processing failed:', error);
        try { openAuthPanel('login'); } catch {}
        const msg = (error && error.message) ? error.message : String(error);
        try { setAuthMessage('Google 로그인 처리 중 오류: ' + msg, 'error'); } catch {}
    } finally {
        isProcessingOAuth = false;
        // 어떤 경로로 끝나든 버튼은 다시 클릭 가능하게.
        if (googleLoginBtn) googleLoginBtn.disabled = false;
    }
}

function startGoogleSignupFlow(user) {
    oauthSignupPending = true;
    localStorage.setItem(OAUTH_PENDING_SIGNUP_KEY, '1');
    if (user?.email) localStorage.setItem(OAUTH_EMAIL_KEY, user.email);

    renderSignedOut();
    renderAuthRequiredTables();
    openAuthPanel('signup');
    authEmail.value = user?.email || '';
    authEmail.readOnly = true;
    authPassword.required = false;
    authPasswordConfirm.required = false;
    authPassword.closest('.form-group')?.classList.add('hidden');
    confirmPasswordField.classList.add('hidden');
    if (nicknameField) nicknameField.classList.remove('hidden');
    if (authNickname) authNickname.required = true;
    if (user?.user_metadata?.full_name) authName.value = user.user_metadata.full_name;
    authSubmit.textContent = '회원가입 완료';
    setAuthMessage('Google 계정 확인이 완료되었습니다. 닉네임을 입력하면 회원가입이 완료됩니다.', 'success');
    setTimeout(() => (authNickname || authName)?.focus(), 0);
}

async function blockUnregisteredGoogleLogin(email) {
    if (email) localStorage.setItem(OAUTH_EMAIL_KEY, email);
    localStorage.setItem(AUTH_NOTICE_KEY, SIGNUP_REQUIRED_MESSAGE);
    localStorage.setItem(AUTH_NOTICE_ACTION_KEY, 'signup');
    await supabaseClient.auth.signOut();
    currentSession = null;
}

async function isRegisteredMember(user) {
    const email = String(user?.email || '').trim().toLowerCase();
    if (!email) return false;

    const apiStatus = await fetchMembershipStatus(email);
    if (apiStatus !== null) return apiStatus;

    const tableStatus = await findSupabaseMembership(email);
    if (tableStatus !== null) return tableStatus;

    return false;
}

async function fetchMembershipStatus(email) {
    try {
        const headers = {};
        const token = await getApiAuthToken({ forceRefresh: true });
        if (token) headers.Authorization = `Bearer ${token}`;

        const response = await fetch(`${API_URL}?resource=auth-membership&email=${encodeURIComponent(email)}`, { headers });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.ok === false) throw new Error(payload?.error || '회원 조회 실패');
        return payload.registered === true;
    } catch {
        return null;
    }
}

async function findSupabaseMembership(email) {
    for (const table of ['members', 'users']) {
        try {
            const { data, error } = await supabaseClient
                .from(table)
                .select('email')
                .ilike('email', email)
                .limit(1);

            if (error) continue;
            return Array.isArray(data) && data.length > 0;
        } catch {
            continue;
        }
    }

    return null;
}

function showPendingAuthNotice() {
    const notice = localStorage.getItem(AUTH_NOTICE_KEY);
    if (!notice) return;

    localStorage.removeItem(AUTH_NOTICE_KEY);
    setTimeout(() => {
        if (googleLoginBtn) googleLoginBtn.disabled = false;
        openAuthPanel('login');
        showAuthNotice(notice);
    }, 0);
}

function renderSignedOut() {
    authScreen.classList.add('hidden');
    appHeader.classList.remove('hidden');
    appDashboard.classList.remove('hidden');
    openLoginBtn.classList.remove('hidden');
    userMenu.classList.add('hidden');
    if (googleLoginBtn) googleLoginBtn.disabled = false;
    setAuthMessage('', '');
}

function isValidNickname(value) {
    const v = String(value || '').trim();
    if (v.length < 2 || v.length > 20) return false;
    return /^[A-Za-z0-9_\-가-힣]+$/.test(v);
}

function setAvailabilityStatus(el, text, type) {
    if (!el) return;
    el.textContent = text || '';
    el.classList.toggle('checking', type === 'checking');
    el.classList.toggle('success', type === 'success');
    el.classList.toggle('error', type === 'error');
}

let lastAvailabilityKey = '';
async function checkAvailability({ email, nickname }) {
    const params = new URLSearchParams();
    if (email) params.set('email', email);
    if (nickname) params.set('nickname', nickname);
    if (![...params.keys()].length) return null;
    try {
        const res = await fetch(`${API_URL}?resource=auth-availability&${params.toString()}`);
        if (!res.ok) return null;
        return await res.json();
    } catch {
        return null;
    }
}

function debounce(fn, ms) {
    let t = null;
    return (...args) => {
        if (t) clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
}

const liveCheckEmail = debounce(async () => {
    const email = authEmail.value.trim();
    if (authMode !== 'signup' && !oauthSignupPending) { setAvailabilityStatus(authEmailStatus, '', ''); return; }
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setAvailabilityStatus(authEmailStatus, '', ''); return; }
    if (oauthSignupPending) { setAvailabilityStatus(authEmailStatus, '', ''); return; } // email is fixed via Google
    const key = 'e:' + email;
    lastAvailabilityKey = key;
    setAvailabilityStatus(authEmailStatus, '확인 중…', 'checking');
    const r = await checkAvailability({ email });
    if (lastAvailabilityKey !== key) return;
    if (!r || !r.ok) { setAvailabilityStatus(authEmailStatus, '확인 실패 — 가입 진행 시 서버에서 재검증됩니다.', ''); return; }
    setAvailabilityStatus(authEmailStatus,
        r.email_taken ? '이미 가입된 이메일입니다.' : '사용 가능한 이메일입니다.',
        r.email_taken ? 'error' : 'success');
}, 400);

const liveCheckNickname = debounce(async () => {
    if (!authNickname) return;
    const nickname = authNickname.value.trim();
    if (authMode !== 'signup' && !oauthSignupPending) { setAvailabilityStatus(authNicknameStatus, '', ''); return; }
    if (!nickname) { setAvailabilityStatus(authNicknameStatus, '', ''); return; }
    if (!isValidNickname(nickname)) {
        setAvailabilityStatus(authNicknameStatus, '2~20자, 한글/영문/숫자/_/- 만 가능', 'error');
        return;
    }
    const key = 'n:' + nickname;
    lastAvailabilityKey = key;
    setAvailabilityStatus(authNicknameStatus, '확인 중…', 'checking');
    const r = await checkAvailability({ nickname });
    if (lastAvailabilityKey !== key) return;
    if (!r || !r.ok) { setAvailabilityStatus(authNicknameStatus, '확인 실패 — 가입 진행 시 서버에서 재검증됩니다.', ''); return; }
    setAvailabilityStatus(authNicknameStatus,
        r.nickname_taken ? '이미 사용 중인 닉네임입니다.' : '사용 가능한 닉네임입니다.',
        r.nickname_taken ? 'error' : 'success');
}, 400);

authEmail.addEventListener('input', liveCheckEmail);
if (authNickname) authNickname.addEventListener('input', liveCheckNickname);

async function resolveDisplayName() {
    if (!currentSession?.user) return '';
    const meta = currentSession.user.user_metadata || {};
    const nick = String(meta.nickname || '').trim();
    if (nick) return nick;
    const fullName = String(meta.full_name || meta.name || '').trim();
    if (fullName) return fullName;

    // Fallback: ask backend (auth-profile returns nickname/name from members row)
    try {
        const token = await getApiAuthToken({ forceRefresh: false });
        if (!token) return currentSession.user.email || '';
        const res = await fetch(`${API_URL}?resource=auth-profile`, {
            headers: { Authorization: `Bearer ${token}` }
        });
        if (!res.ok) return currentSession.user.email || '';
        const data = await res.json().catch(() => null);
        const profile = data?.profile || {};
        const profileNick = String(profile.nickname || '').trim();
        if (profileNick) {
            // Cache into user_metadata for next time
            try { await supabaseClient.auth.updateUser({ data: { nickname: profileNick } }); } catch {}
            return profileNick;
        }
        const profileName = String(profile.name || '').trim();
        if (profileName) return profileName;
    } catch {}
    return currentSession.user.email || '';
}

let adminBootstrapTried = false;
async function ensureAdminBootstrap() {
    if (adminBootstrapTried) return;
    adminBootstrapTried = true;
    try {
        const token = await getApiAuthToken({ forceRefresh: false });
        if (!token) return;
        const res = await fetch(`${API_URL}?resource=admin-bootstrap`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
        });
        if (!res.ok) return; // 403 etc. — not an admin allowlisted email
        const data = await res.json().catch(() => null);
        const role = String(data?.role || '').toLowerCase();
        if (role !== 'admin' && role !== 'owner') return;

        if (adminLink) adminLink.classList.remove('hidden');

        // Persist role into Supabase user_metadata so subsequent sessions
        // see it directly from the JWT without another bootstrap call.
        try {
            await supabaseClient.auth.updateUser({ data: { role } });
            const { data: refreshed } = await supabaseClient.auth.getSession();
            currentSession = refreshed?.session || currentSession;
        } catch {
            /* non-fatal — admin link is already visible for this session */
        }
    } catch {
        /* network or env problem — leave admin link as-is */
    }
}

function renderSignedIn() {
    authScreen.classList.add('hidden');
    appHeader.classList.remove('hidden');
    appDashboard.classList.remove('hidden');

    if (authEnabled && currentSession?.user?.email) {
        userEmail.textContent = currentSession.user.email;
        userEmail.title = currentSession.user.email;
        resolveDisplayName().then((label) => {
            if (currentSession?.user?.email) userEmail.textContent = label || currentSession.user.email;
        });
        userMenu.classList.remove('hidden');
        openLoginBtn.classList.add('hidden');

        if (adminLink) {
            const meta = currentSession.user.app_metadata || {};
            const userMeta = currentSession.user.user_metadata || {};
            const role = String(meta.role || userMeta.role || '').toLowerCase();
            const isAdmin = role === 'admin' || role === 'owner' || meta.is_admin === true || userMeta.is_admin === true;
            adminLink.classList.toggle('hidden', !isAdmin);
            if (!isAdmin) ensureAdminBootstrap();
        }
    } else {
        userMenu.classList.add('hidden');
        openLoginBtn.classList.remove('hidden');
        if (adminLink) adminLink.classList.add('hidden');
    }
}

function renderAuthRequiredTables() {
    customerList.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--fg-tertiary);padding:48px 16px;">로그인 후 데이터를 확인할 수 있습니다.</td></tr>';
    employeeList.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--fg-tertiary);padding:48px 16px;">로그인 후 데이터를 확인할 수 있습니다.</td></tr>';
    customerEmpty.classList.add('hidden');
    employeeEmpty.classList.add('hidden');
}

// --- API ---
async function apiRequest(resource, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {})
    };

    const token = await getApiAuthToken({ forceRefresh: true });
    if (token) headers.Authorization = `Bearer ${token}`;

    const response = await fetch(`${API_URL}?resource=${encodeURIComponent(resource)}`, {
        ...options,
        headers
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok === false) {
        throw new Error(payload?.error || `요청 실패 (${response.status})`);
    }
    return payload;
}

async function loadResource(resource) {
    const payload = await apiRequest(resource);
    return Array.isArray(payload.items) ? payload.items : [];
}

async function loadAll() {
    isLoading = true;
    [customers, employees] = await Promise.all([
        loadResource('customers'),
        loadResource('employees')
    ]);
    isLoading = false;
}

async function migrateLocalStorageIfNeeded() {
    if (localStorage.getItem(MIGRATION_FLAG) === '1') return;

    const localCustomers = parseLocalArray('customers');
    const localEmployees = parseLocalArray('employees');
    const hasLocalData = localCustomers.length > 0 || localEmployees.length > 0;
    const hasRemoteData = customers.length > 0 || employees.length > 0;

    if (!hasLocalData || hasRemoteData) {
        localStorage.setItem(MIGRATION_FLAG, '1');
        return;
    }

    await Promise.all([
        ...localCustomers.map(item => apiRequest('customers', {
            method: 'POST',
            body: JSON.stringify({ resource: 'customers', data: item })
        })),
        ...localEmployees.map(item => apiRequest('employees', {
            method: 'POST',
            body: JSON.stringify({ resource: 'employees', data: item })
        }))
    ]);

    localStorage.setItem(MIGRATION_FLAG, '1');
    await loadAll();
}

function parseLocalArray(key) {
    try {
        const value = JSON.parse(localStorage.getItem(key) || '[]');
        return Array.isArray(value) ? value : [];
    } catch {
        return [];
    }
}

// --- Navigation Logic ---
function switchView(view) {
    currentView = view;
    
    // Update Nav Buttons
    [navCustomers, navEmployees, navMarketing].forEach(btn => {
        if (btn) btn.classList.remove('active');
    });
    
    // Update Sections
    [customerSection, employeeSection, marketingSection].forEach(sec => {
        if (sec) sec.classList.add('hidden');
    });

    if (view === 'customers') {
        navCustomers.classList.add('active');
        customerSection.classList.remove('hidden');
    } else if (view === 'employees') {
        // 조직도 페이지로 통합됨. 옛 #employees 해시는 자동 리디렉션.
        window.location.href = 'org.html';
        return;
    } else if (view === 'marketing') {
        if (navMarketing) navMarketing.classList.add('active');
        if (marketingSection) marketingSection.classList.remove('hidden');
    }
}

if (navCustomers) navCustomers.addEventListener('click', () => switchView('customers'));
if (navEmployees) navEmployees.addEventListener('click', () => switchView('employees'));
if (navMarketing) {
    navMarketing.addEventListener('click', () => switchView('marketing'));
}
window.addEventListener('hashchange', applyInitialHash);

function applyInitialHash() {
    const hash = window.location.hash.replace('#', '');
    if (['customers', 'employees', 'marketing'].includes(hash)) {
        switchView(hash);
    }
}

openLoginBtn.addEventListener('click', () => openAuthPanel('login'));
closeAuthBtn.addEventListener('click', closeAuthPanel);
// Modal closes only via the X button — backdrop clicks (incl. drag-end) ignored.
loginTab.addEventListener('click', () => setAuthMode('login'));
signupTab.addEventListener('click', () => setAuthMode('signup'));
identityVerifyBtn.addEventListener('click', handleIdentityVerify);
googleLoginBtn.addEventListener('click', handleGoogleLogin);
authNoticeAccept.addEventListener('click', handleAuthNoticeAccept);
authNoticeCancel.addEventListener('click', handleAuthNoticeCancel);
logoutBtn.addEventListener('click', signOut);
authForm.addEventListener('submit', handleAuthSubmit);

function setAuthMode(mode) {
    authMode = mode;
    loginTab.classList.toggle('hidden', mode === 'login');
    signupTab.classList.toggle('hidden', mode === 'signup');
    signupFields.classList.toggle('hidden', mode !== 'signup');
    confirmPasswordField.classList.toggle('hidden', mode !== 'signup');
    if (nicknameField) nicknameField.classList.toggle('hidden', mode !== 'signup');
    authName.required = mode === 'signup';
    authPhone.required = mode === 'signup';
    if (authNickname) authNickname.required = mode === 'signup';
    authPassword.required = true;
    authPassword.readOnly = false;
    authPassword.closest('.form-group')?.classList.remove('hidden');
    authPasswordConfirm.required = mode === 'signup';
    authEmail.readOnly = false;
    if (mode === 'login') authPasswordConfirm.value = '';
    if (authEmailStatus) setAvailabilityStatus(authEmailStatus, '', '');
    if (authNicknameStatus) setAvailabilityStatus(authNicknameStatus, '', '');
    authSubmit.textContent = mode === 'login' ? '로그인' : '회원가입';
    if (googleLoginLabel) googleLoginLabel.textContent = mode === 'login' ? 'Google로 로그인' : 'Google로 회원가입';
    authPassword.autocomplete = mode === 'login' ? 'current-password' : 'new-password';
    authSwitchText.textContent = mode === 'login' ? '아직 회원이 아니신가요?' : '이미 회원이신가요?';
    setAuthMessage('', '');
    hideAuthNotice();
    setIdentityStatus('PASS/NICE/KCB 본인확인 연동 후 인증 완료 처리됩니다.', '');

    if (oauthSignupPending && mode === 'signup') {
        loginTab.classList.add('hidden');
        signupTab.classList.add('hidden');
        authSwitchText.textContent = 'Google 회원가입 추가정보 입력';
        authEmail.readOnly = true;
        authPassword.required = false;
        authPasswordConfirm.required = false;
        authPassword.closest('.form-group')?.classList.add('hidden');
        confirmPasswordField.classList.add('hidden');
        if (nicknameField) nicknameField.classList.remove('hidden');
        if (authNickname) authNickname.required = true;
        authSubmit.textContent = '회원가입 완료';
    }
}

function openAuthPanel(mode = 'login') {
    setAuthMode(mode);
    if (googleLoginBtn) googleLoginBtn.disabled = false;
    authScreen.classList.remove('hidden');
    setTimeout(() => authEmail.focus(), 0);
}

// Restore button state if the page is brought back from BFCache after a
// Google OAuth redirect — otherwise .disabled stays true and the wait
// cursor on hover looks like an infinite loading spinner.
window.addEventListener('pageshow', () => {
    if (googleLoginBtn) googleLoginBtn.disabled = false;
});

function closeAuthPanel() {
    if (oauthSignupPending) {
        cancelPendingGoogleSignup();
        return;
    }

    authScreen.classList.add('hidden');
    hideAuthNotice();
}

async function cancelPendingGoogleSignup() {
    oauthSignupPending = false;
    localStorage.removeItem(OAUTH_PENDING_SIGNUP_KEY);
    localStorage.removeItem(OAUTH_EMAIL_KEY);
    authEmail.readOnly = false;
    authPassword.required = true;
    authPassword.closest('.form-group')?.classList.remove('hidden');
    if (supabaseClient) await supabaseClient.auth.signOut();
    currentSession = null;
    renderSignedOut();
    renderAuthRequiredTables();
    hideAuthNotice();
}

function handleIdentityVerify() {
    const name = authName.value.trim();
    const phone = normalizeKoreanMobile(authPhone.value);

    if (!name) {
        setIdentityStatus('가입자 이름을 먼저 입력하세요.', 'error');
        authName.focus();
        return;
    }

    if (!phone) {
        setIdentityStatus('올바른 휴대폰 번호를 입력하세요. 예: 010-1234-5678', 'error');
        authPhone.focus();
        return;
    }

    setIdentityStatus('외부 본인확인 사업자(PASS/NICE/KCB) 설정이 아직 없어 실명인증 요청을 보낼 수 없습니다.', 'error');
}

async function handleAuthSubmit(event) {
    event.preventDefault();
    if (!supabaseClient) return;

    const email = authEmail.value.trim();
    const password = authPassword.value;
    const passwordConfirm = authPasswordConfirm.value;
    const fullName = authName.value.trim();
    const phone = normalizeKoreanMobile(authPhone.value);
    const nickname = (authNickname?.value || '').trim();

    if (authMode === 'signup' || oauthSignupPending) {
        if (!isValidNickname(nickname)) {
            setAuthMessage('닉네임은 2~20자, 한글/영문/숫자/_/- 만 가능합니다.', 'error');
            authNickname?.focus();
            return;
        }
    }

    if (authMode === 'signup' && !oauthSignupPending) {
        if (!fullName) {
            setAuthMessage('가입자 이름을 입력하세요.', 'error');
            authName.focus();
            return;
        }

        if (!phone) {
            setAuthMessage('올바른 휴대폰 번호를 입력하세요.', 'error');
            authPhone.focus();
            return;
        }

        if (password !== passwordConfirm) {
            setAuthMessage('비밀번호와 비밀번호 확인이 일치하지 않습니다.', 'error');
            authPasswordConfirm.focus();
            return;
        }

        const avail = await checkAvailability({ email, nickname });
        if (avail && avail.ok) {
            if (avail.email_taken) {
                setAuthMessage('이미 가입된 이메일입니다.', 'error');
                authEmail.focus();
                return;
            }
            if (avail.nickname_taken) {
                setAuthMessage('이미 사용 중인 닉네임입니다.', 'error');
                authNickname?.focus();
                return;
            }
        }
    }

    authSubmit.disabled = true;
    authSubmit.textContent = authMode === 'login' ? '로그인 중...' : '가입 중...';
    setAuthMessage('', '');

    try {
        if (oauthSignupPending) {
            await completeGoogleSignup({ fullName, phone, nickname });
            return;
        }

        if (authMode === 'signup') {
            const { data, error } = await supabaseClient.auth.signUp({
                email,
                password,
                options: {
                    data: {
                        full_name: fullName,
                        phone,
                        nickname,
                        phone_verified: false,
                        identity_verified: false,
                        app_registered: true,
                        signup_method: 'email'
                    }
                }
            });
            if (error) throw error;
            if (!data.session) {
                setAuthMessage('가입 확인 메일을 보냈습니다. 메일 인증 후 로그인하세요.', 'success');
                return;
            }
            // Persist member row so nickname / profile lookups work for email signups too.
            try {
                const token = data.session.access_token;
                await fetch(`${API_URL}?resource=auth-member`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
                    body: JSON.stringify({
                        resource: 'auth-member',
                        email, fullName, phone, nickname, provider: 'email'
                    })
                });
            } catch { /* non-fatal — backend may already have it via trigger */ }
        } else {
            const { error } = await supabaseClient.auth.signInWithPassword({ email, password });
            if (error) throw error;
        }
    } catch (error) {
        setAuthMessage(error?.message || '인증 처리에 실패했습니다.', 'error');
    } finally {
        authSubmit.disabled = false;
        authSubmit.textContent = authMode === 'login' ? '로그인' : '회원가입';
    }
}

async function completeGoogleSignup({ fullName, phone, nickname }) {
    const user = currentSession?.user;
    const email = String(user?.email || authEmail.value || '').trim().toLowerCase();
    if (!user || !email) throw new Error('Google 인증 세션이 없습니다. 다시 시도하세요.');

    const token = await getApiAuthToken({ forceRefresh: true });
    if (!token) throw new Error('Google 인증 토큰을 가져오지 못했습니다. 다시 시도하세요.');

    const response = await fetch(`${API_URL}?resource=auth-member`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`
        },
        body: JSON.stringify({
            resource: 'auth-member',
            email,
            fullName: fullName || user.user_metadata?.full_name || user.user_metadata?.name || email.split('@')[0],
            phone,
            nickname,
            provider: 'google'
        })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok === false) {
        throw new Error(payload?.error || '회원 정보를 저장하지 못했습니다.');
    }

    const userData = {
        full_name: fullName,
        phone,
        nickname,
        phone_verified: false,
        identity_verified: false,
        app_registered: true,
        signup_method: 'google'
    };
    if (payload.role === 'admin' || payload.role === 'owner') {
        userData.role = payload.role;
    }
    const { error } = await supabaseClient.auth.updateUser({ data: userData });
    if (error) throw error;

    localStorage.removeItem(OAUTH_PENDING_SIGNUP_KEY);
    localStorage.removeItem(OAUTH_EMAIL_KEY);
    oauthSignupPending = false;
    authEmail.readOnly = false;
    authPassword.required = true;
    authPassword.closest('.form-group')?.classList.remove('hidden');

    const { data } = await supabaseClient.auth.getSession();
    currentSession = data.session || currentSession;
    closeAuthPanel();
    renderSignedIn();
    renderLoading();
    await loadAll();
    await migrateLocalStorageIfNeeded();
    renderAll();
}

async function handleGoogleLogin() {
    if (!supabaseClient) {
        // 인증 시스템 자체가 안 떠 있는 경우 — 사용자에게 명확히 알림.
        try { openAuthPanel('login'); } catch {}
        setAuthMessage('인증 시스템이 아직 준비되지 않았습니다. 페이지를 새로고침 후 다시 시도해주세요.', 'error');
        return;
    }

    googleLoginBtn.disabled = true;
    setAuthMessage('', '');

    try {
        const intent = authMode === 'signup' ? 'signup' : 'login';
        saveOAuthIntent(intent);
        const { error } = await supabaseClient.auth.signInWithOAuth({
            provider: 'google',
            options: {
                redirectTo: buildOAuthRedirectTo(intent),
                queryParams: { prompt: 'select_account' }
            }
        });
        if (error) throw error;
        // 정상이면 페이지가 Google로 리디렉트되므로 이 라인 이후는 거의 도달 안함.
    } catch (error) {
        clearOAuthIntent();
        googleLoginBtn.disabled = false;
        const msg = error?.message || 'Google 로그인에 실패했습니다.';
        console.error('[Auth] handleGoogleLogin error:', error);
        setAuthMessage(msg, 'error');
    }
}

async function signOut() {
    if (!supabaseClient) return;
    await supabaseClient.auth.signOut();
}

function setAuthMessage(message, type) {
    authMessage.textContent = message;
    authMessage.classList.toggle('error', type === 'error');
    authMessage.classList.toggle('success', type === 'success');
}

function showAuthNotice(message) {
    authNoticeText.textContent = message;
    authNotice.classList.remove('hidden');
    setAuthMessage('', '');
}

function hideAuthNotice() {
    authNotice.classList.add('hidden');
    authNoticeText.textContent = '';
}

function handleAuthNoticeAccept() {
    hideAuthNotice();
    const action = localStorage.getItem(AUTH_NOTICE_ACTION_KEY) || 'signup';
    localStorage.removeItem(AUTH_NOTICE_ACTION_KEY);
    const oauthEmail = localStorage.getItem(OAUTH_EMAIL_KEY);
    localStorage.removeItem(OAUTH_EMAIL_KEY);

    openAuthPanel(action === 'login' ? 'login' : 'signup');
    if (oauthEmail) {
        authEmail.value = oauthEmail;
        authEmail.readOnly = false;
        setTimeout(() => (action === 'login' ? authPassword : authName).focus(), 0);
    }
}

function handleAuthNoticeCancel() {
    localStorage.removeItem(OAUTH_EMAIL_KEY);
    localStorage.removeItem(AUTH_NOTICE_ACTION_KEY);
    hideAuthNotice();
    closeAuthPanel();
}

function setIdentityStatus(message, type) {
    identityStatus.textContent = message;
    identityStatus.classList.toggle('error', type === 'error');
    identityStatus.classList.toggle('success', type === 'success');
}

function normalizeKoreanMobile(value) {
    const digits = String(value || '').replace(/\D/g, '');
    if (/^010\d{8}$/.test(digits)) return `+82${digits.slice(1)}`;
    if (/^8210\d{8}$/.test(digits)) return `+${digits}`;
    if (/^\+8210\d{8}$/.test(String(value || '').trim())) return String(value).trim();
    return '';
}

// --- CRUD Operations ---

async function addItem(type, data) {
    const resource = type === 'customer' ? 'customers' : 'employees';
    await apiRequest(resource, {
        method: 'POST',
        body: JSON.stringify({ resource, data })
    });
    await refreshResource(resource);
}

async function updateItem(type, id, data) {
    const resource = type === 'customer' ? 'customers' : 'employees';
    await apiRequest(resource, {
        method: 'PUT',
        body: JSON.stringify({ resource, id, data })
    });
    await refreshResource(resource);
}

async function deleteItem(type, id) {
    const msg = type === 'customer' ? '이 고객 정보를 삭제하시겠습니까?' : '이 직원 정보를 삭제하시겠습니까?';
    if (!confirm(msg)) return;

    try {
        const resource = type === 'customer' ? 'customers' : 'employees';
        await apiRequest(resource, {
            method: 'DELETE',
            body: JSON.stringify({ resource, id })
        });
        await refreshResource(resource);
    } catch (error) {
        showError(error);
    }
}

async function refreshResource(resource) {
    if (resource === 'customers') {
        customers = await loadResource('customers');
        renderCustomers();
    } else {
        employees = await loadResource('employees');
        renderEmployees();
    }
}

function requireSignedIn() {
    if (!authEnabled || currentSession) return true;
    openAuthPanel('login');
    return false;
}

// --- UI Rendering ---

function renderLoading() {
    isLoading = true;
    customerList.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--fg-tertiary);padding:48px 16px;">불러오는 중…</td></tr>';
    employeeList.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--fg-tertiary);padding:48px 16px;">불러오는 중…</td></tr>';
    customerEmpty.classList.add('hidden');
    employeeEmpty.classList.add('hidden');
}

function renderAll() {
    isLoading = false;
    renderCustomers();
    renderEmployees();
}

function renderCustomers() {
    const filtered = customers.filter(c =>
        (c.name || '').toLowerCase().includes(customerFilter.toLowerCase())
    );

    customerList.innerHTML = '';
    if (!isLoading && filtered.length === 0) {
        customerEmpty.classList.remove('hidden');
    } else {
        customerEmpty.classList.add('hidden');
        filtered.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="이름"><strong>${escapeHtml(item.name)}</strong></td>
                <td data-label="연락처">${escapeHtml(item.phone || '—')}</td>
                <td data-label="등록일">${escapeHtml(item.createdAt || '—')}</td>
                <td class="action-btns" data-label="">
                    <button class="edit-btn" onclick="openAppModal('customer', '${escapeAttr(item.id)}')">수정</button>
                    <button class="delete-btn" onclick="deleteAppItem('customer', '${escapeAttr(item.id)}')">삭제</button>
                </td>
            `;
            customerList.appendChild(tr);
        });
    }
}

function renderEmployees() {
    const filtered = employees.filter(e =>
        (e.name || '').toLowerCase().includes(employeeFilter.toLowerCase()) ||
        (e.title || '').toLowerCase().includes(employeeFilter.toLowerCase())
    );

    employeeList.innerHTML = '';
    if (!isLoading && filtered.length === 0) {
        employeeEmpty.classList.remove('hidden');
    } else {
        employeeEmpty.classList.add('hidden');
        filtered.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="이름"><strong>${escapeHtml(item.name)}</strong></td>
                <td data-label="직함">${escapeHtml(item.title)}</td>
                <td data-label="연락처">${escapeHtml(item.contact || '—')}</td>
                <td data-label="투입일">${escapeHtml(item.startDate || '—')}</td>
                <td class="action-btns" data-label="">
                    <button class="edit-btn" onclick="openAppModal('employee', '${escapeAttr(item.id)}')">수정</button>
                    <button class="delete-btn" onclick="deleteAppItem('employee', '${escapeAttr(item.id)}')">삭제</button>
                </td>
            `;
            employeeList.appendChild(tr);
        });
    }
}

function showError(error) {
    const message = escapeHtml(error?.message || '알 수 없는 오류가 발생했습니다.');
    customerList.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--danger);padding:48px 16px;">${message}</td></tr>`;
    employeeList.innerHTML = `<tr><td colspan="5" style="text-align:center;color:var(--danger);padding:48px 16px;">${message}</td></tr>`;
    customerEmpty.classList.add('hidden');
    employeeEmpty.classList.add('hidden');
    alert(`처리 실패: ${error?.message || error}`);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[ch]));
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
}

// Global scope for onclick handlers in generated HTML
window.openAppModal = openAppModal;
window.deleteAppItem = deleteItem;

// --- Event Listeners ---

customerSearch.addEventListener('input', (e) => {
    customerFilter = e.target.value;
    renderCustomers();
});

employeeSearch.addEventListener('input', (e) => {
    employeeFilter = e.target.value;
    renderEmployees();
});

document.getElementById('add-customer-btn').addEventListener('click', () => {
    if (requireSignedIn()) openAppModal('customer');
});
document.getElementById('add-employee-btn').addEventListener('click', () => {
    if (requireSignedIn()) openAppModal('employee');
});
document.getElementById('close-modal').addEventListener('click', closeModal);
document.getElementById('cancel-btn').addEventListener('click', closeModal);

function openAppModal(type, id = null) {
    appForm.reset();
    document.getElementById('item-id').value = id || '';
    document.getElementById('item-type').value = type;
    
    let fieldsHtml = '';
    if (type === 'customer') {
        const item = id ? customers.find(c => c.id === id) : null;
        modalTitle.textContent = id ? '고객 정보 수정' : '새 고객 등록';
        fieldsHtml = `
            <div class="form-group">
                <label for="name">이름</label>
                <input type="text" id="name" required value="${escapeAttr(item?.name || '')}" placeholder="성함을 입력하세요">
            </div>
            <div class="form-group">
                <label for="phone">전화번호</label>
                <input type="tel" id="phone" value="${escapeAttr(item?.phone || '')}" placeholder="010-0000-0000">
            </div>
            <div class="form-group">
                <label for="notes">메모</label>
                <textarea id="notes" rows="3" placeholder="추가 사항">${escapeHtml(item?.notes || '')}</textarea>
            </div>
        `;
    } else {
        const item = id ? employees.find(e => e.id === id) : null;
        modalTitle.textContent = id ? '직원 정보 수정' : '새 직원 등록';
        fieldsHtml = `
            <div class="form-group">
                <label for="name">이름</label>
                <input type="text" id="name" required value="${escapeAttr(item?.name || '')}" placeholder="성함을 입력하세요">
            </div>
            <div class="form-group">
                <label for="title">직함</label>
                <input type="text" id="title" required value="${escapeAttr(item?.title || '')}" placeholder="예: 과장, 개발자">
            </div>
            <div class="form-group">
                <label for="contact">연락처</label>
                <input type="tel" id="contact" value="${escapeAttr(item?.contact || '')}" placeholder="010-0000-0000">
            </div>
            <div class="form-group">
                <label for="startDate">투입일</label>
                <input type="date" id="startDate" value="${escapeAttr(item?.startDate || '')}">
            </div>
        `;
    }
    
    formFields.innerHTML = fieldsHtml;
    appModal.classList.remove('hidden');
}

function closeModal() {
    appModal.classList.add('hidden');
}

appForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const id = document.getElementById('item-id').value;
    const type = document.getElementById('item-type').value;
    
    const data = {};
    const inputs = formFields.querySelectorAll('input, textarea');
    inputs.forEach(input => {
        data[input.id] = input.value.trim();
    });

    saveBtn.disabled = true;
    saveBtn.textContent = '저장 중...';

    try {
        if (id) {
            await updateItem(type, id, data);
        } else {
            await addItem(type, data);
        }
        closeModal();
    } catch (error) {
        showError(error);
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = '데이터 저장';
    }
});

window.addEventListener('click', (e) => {
    if (e.target === appModal) closeModal();
});
