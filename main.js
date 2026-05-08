/**
 * ERP Integrated Management System - Main Logic
 */

// --- State Management ---
const API_URL = 'records.php';
const MIGRATION_FLAG = 'erpDbMigrationComplete';
const OAUTH_INTENT_KEY = 'erpOAuthIntent';
const OAUTH_EMAIL_KEY = 'erpOAuthEmail';
const AUTH_NOTICE_KEY = 'erpAuthNotice';
const SIGNUP_REQUIRED_MESSAGE = '회원가입이 필요합니다. 회원가입을 진행하시겠습니까?';

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

async function handlePostOAuthSession() {
    const user = currentSession?.user;
    if (!user) return;

    const providers = user.app_metadata?.providers || [];
    const provider = user.app_metadata?.provider || '';
    const isGoogleUser = provider === 'google' || providers.includes('google');
    if (!isGoogleUser) return;

    localStorage.removeItem(OAUTH_INTENT_KEY);

    const isRegistered = await isRegisteredMember(user);
    if (isRegistered) {
        localStorage.removeItem(OAUTH_EMAIL_KEY);
        return;
    }

    if (user.email) localStorage.setItem(OAUTH_EMAIL_KEY, user.email);
    localStorage.setItem(AUTH_NOTICE_KEY, SIGNUP_REQUIRED_MESSAGE);
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
        if (currentSession?.access_token) {
            headers.Authorization = `Bearer ${currentSession.access_token}`;
        }

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

function renderSignedIn() {
    authScreen.classList.add('hidden');
    appHeader.classList.remove('hidden');
    appDashboard.classList.remove('hidden');

    if (authEnabled && currentSession?.user?.email) {
        userEmail.textContent = currentSession.user.email;
        userMenu.classList.remove('hidden');
        openLoginBtn.classList.add('hidden');
    } else {
        userMenu.classList.add('hidden');
        openLoginBtn.classList.remove('hidden');
    }
}

function renderAuthRequiredTables() {
    customerList.innerHTML = '<tr><td colspan="4">로그인 후 고객 데이터를 확인할 수 있습니다.</td></tr>';
    employeeList.innerHTML = '<tr><td colspan="5">로그인 후 직원 데이터를 확인할 수 있습니다.</td></tr>';
    customerEmpty.classList.add('hidden');
    employeeEmpty.classList.add('hidden');
}

// --- API ---
async function apiRequest(resource, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {})
    };

    if (currentSession?.access_token) {
        headers.Authorization = `Bearer ${currentSession.access_token}`;
    }

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
        navEmployees.classList.add('active');
        employeeSection.classList.remove('hidden');
    } else if (view === 'marketing') {
        navMarketing.classList.add('active');
        marketingSection.classList.remove('hidden');
    }
}

navCustomers.addEventListener('click', () => switchView('customers'));
navEmployees.addEventListener('click', () => switchView('employees'));
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
authScreen.addEventListener('click', (event) => {
    if (event.target === authScreen) closeAuthPanel();
});
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
    authName.required = mode === 'signup';
    authPhone.required = mode === 'signup';
    authPasswordConfirm.required = mode === 'signup';
    if (mode === 'login') authPasswordConfirm.value = '';
    authSubmit.textContent = mode === 'login' ? '로그인' : '회원가입';
    if (googleLoginLabel) googleLoginLabel.textContent = mode === 'login' ? 'Google로 로그인' : 'Google로 회원가입';
    authPassword.autocomplete = mode === 'login' ? 'current-password' : 'new-password';
    authSwitchText.textContent = mode === 'login' ? '아직 회원이 아니신가요?' : '이미 회원이신가요?';
    setAuthMessage('', '');
    hideAuthNotice();
    setIdentityStatus('PASS/NICE/KCB 본인확인 연동 후 인증 완료 처리됩니다.', '');
}

function openAuthPanel(mode = 'login') {
    setAuthMode(mode);
    authScreen.classList.remove('hidden');
    setTimeout(() => authEmail.focus(), 0);
}

function closeAuthPanel() {
    authScreen.classList.add('hidden');
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

    if (authMode === 'signup') {
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
    }

    authSubmit.disabled = true;
    authSubmit.textContent = authMode === 'login' ? '로그인 중...' : '가입 중...';
    setAuthMessage('', '');

    try {
        if (authMode === 'signup') {
            const { data, error } = await supabaseClient.auth.signUp({
                email,
                password,
                options: {
                    data: {
                        full_name: fullName,
                        phone,
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

async function handleGoogleLogin() {
    if (!supabaseClient) return;

    googleLoginBtn.disabled = true;
    setAuthMessage('', '');

    try {
        localStorage.setItem(OAUTH_INTENT_KEY, authMode === 'signup' ? 'signup' : 'login');
        const { error } = await supabaseClient.auth.signInWithOAuth({
            provider: 'google',
            options: {
                redirectTo: window.location.origin + window.location.pathname
            }
        });
        if (error) throw error;
    } catch (error) {
        localStorage.removeItem(OAUTH_INTENT_KEY);
        googleLoginBtn.disabled = false;
        setAuthMessage(error?.message || 'Google 로그인에 실패했습니다.', 'error');
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
    openAuthPanel('signup');
    const oauthEmail = localStorage.getItem(OAUTH_EMAIL_KEY);
    localStorage.removeItem(OAUTH_EMAIL_KEY);
    if (oauthEmail) {
        authEmail.value = oauthEmail;
        authEmail.readOnly = false;
        setTimeout(() => authName.focus(), 0);
    }
}

function handleAuthNoticeCancel() {
    localStorage.removeItem(OAUTH_EMAIL_KEY);
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
    customerList.innerHTML = '<tr><td colspan="4">데이터를 불러오는 중입니다...</td></tr>';
    employeeList.innerHTML = '<tr><td colspan="5">데이터를 불러오는 중입니다...</td></tr>';
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
                <td data-label="전화번호">${escapeHtml(item.phone || '-')}</td>
                <td data-label="가입일">${escapeHtml(item.createdAt || '-')}</td>
                <td class="action-btns">
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
                <td data-label="연락처">${escapeHtml(item.contact || '-')}</td>
                <td data-label="투입일">${escapeHtml(item.startDate || '-')}</td>
                <td class="action-btns">
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
    customerList.innerHTML = `<tr><td colspan="4">DB 연결 오류: ${message}</td></tr>`;
    employeeList.innerHTML = `<tr><td colspan="5">DB 연결 오류: ${message}</td></tr>`;
    customerEmpty.classList.add('hidden');
    employeeEmpty.classList.add('hidden');
    alert(`DB 작업 실패: ${error?.message || error}`);
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
