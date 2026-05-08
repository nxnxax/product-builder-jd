import {
    initSupabase,
    getSession,
    getClient,
    apiRequest,
    setupHeaderUser,
    getInitial,
} from './auth-shared.js';

const tabButtons = document.querySelectorAll('.side-nav button[data-tab]');
const tabSections = document.querySelectorAll('.profile-tab');
const loadingState = document.getElementById('loading-state');
const signedOutState = document.getElementById('signed-out-state');

const userMenu = document.getElementById('user-menu');
const userEmail = document.getElementById('user-email');
const adminLink = document.getElementById('admin-link');
const logoutBtn = document.getElementById('logout-btn');

const profileInitial = document.getElementById('profile-initial');
const profileName = document.getElementById('profile-display-name');
const profileEmail = document.getElementById('profile-display-email');
const profileStatus = document.getElementById('profile-status-pill');
const profileRole = document.getElementById('profile-role-pill');
const profileProvider = document.getElementById('profile-provider-pill');
const profileCreated = document.getElementById('profile-created');
const profileUpdated = document.getElementById('profile-updated');
const profileLastLogin = document.getElementById('profile-last-login');

const basicForm = document.getElementById('basic-form');
const basicName = document.getElementById('basic-name');
const basicPhone = document.getElementById('basic-phone');
const basicEmail = document.getElementById('basic-email');
const basicMessage = document.getElementById('basic-message');
const basicSave = document.getElementById('basic-save');
const basicReset = document.getElementById('basic-reset');

const passwordForm = document.getElementById('password-form');
const pwNew = document.getElementById('pw-new');
const pwConfirm = document.getElementById('pw-confirm');
const passwordMessage = document.getElementById('password-message');
const passwordSave = document.getElementById('password-save');

const accountSignout = document.getElementById('account-signout');
const accountDelete = document.getElementById('account-delete');

let snapshot = null;

const STATUS_LABEL = { active: '활성', suspended: '정지', banned: '차단' };
const ROLE_LABEL = { admin: '관리자', owner: '운영자', member: '일반회원' };
const PROVIDER_LABEL = { google: 'Google 연동', email: '이메일', github: 'GitHub' };

function showTab(tab) {
    tabButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tab));
    tabSections.forEach(section => {
        section.classList.toggle('hidden', section.id !== `tab-${tab}`);
    });
}

tabButtons.forEach(btn => {
    btn.addEventListener('click', () => showTab(btn.dataset.tab));
});

function applyMessage(el, text, type) {
    el.textContent = text || '';
    el.classList.remove('error', 'success');
    if (type) el.classList.add(type);
}

function fillProfile(profile) {
    snapshot = { ...profile };
    profileInitial.textContent = getInitial(profile.name, profile.email);
    profileName.textContent = profile.name || profile.email || '—';
    profileEmail.textContent = profile.email || '—';

    profileStatus.textContent = STATUS_LABEL[profile.status] || '활성';
    profileStatus.className = `pill ${profile.status === 'active' ? 'active' : profile.status === 'suspended' ? 'suspended' : ''}`.trim();
    profileRole.textContent = ROLE_LABEL[profile.role] || '일반회원';
    profileRole.className = `pill ${profile.role === 'admin' || profile.role === 'owner' ? 'admin' : ''}`.trim();
    profileProvider.textContent = PROVIDER_LABEL[profile.provider] || '이메일';

    profileCreated.textContent = profile.createdAt || '—';
    profileUpdated.textContent = profile.updatedAt || '—';
    profileLastLogin.textContent = profile.lastLoginAt || '—';

    basicName.value = profile.name || '';
    basicPhone.value = profile.phone || '';
    basicEmail.value = profile.email || '';
}

async function loadProfile() {
    try {
        const payload = await apiRequest('auth-profile');
        fillProfile(payload.profile || {});
        loadingState.classList.add('hidden');
        document.querySelectorAll('.profile-tab').forEach(s => s.classList.remove('hidden'));
        showTab('overview');
    } catch (error) {
        loadingState.innerHTML = `<p style="color:var(--danger);font-size:14px;margin:0;">${error.message || '프로필을 불러오지 못했습니다.'}</p>`;
    }
}

basicForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    applyMessage(basicMessage, '저장 중…', null);
    basicSave.disabled = true;
    try {
        await apiRequest('auth-profile', {
            method: 'PUT',
            body: JSON.stringify({
                resource: 'auth-profile',
                data: { name: basicName.value.trim(), phone: basicPhone.value.trim() },
            }),
        });
        const client = getClient();
        if (client) {
            await client.auth.updateUser({
                data: {
                    full_name: basicName.value.trim(),
                    phone: basicPhone.value.trim(),
                },
            });
        }
        applyMessage(basicMessage, '저장되었습니다.', 'success');
        await loadProfile();
    } catch (error) {
        applyMessage(basicMessage, error.message || '저장 실패', 'error');
    } finally {
        basicSave.disabled = false;
    }
});

basicReset?.addEventListener('click', () => {
    if (!snapshot) return;
    basicName.value = snapshot.name || '';
    basicPhone.value = snapshot.phone || '';
    applyMessage(basicMessage, '되돌렸습니다.', null);
});

passwordForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (pwNew.value.length < 6) {
        applyMessage(passwordMessage, '비밀번호는 6자 이상이어야 합니다.', 'error');
        return;
    }
    if (pwNew.value !== pwConfirm.value) {
        applyMessage(passwordMessage, '비밀번호가 일치하지 않습니다.', 'error');
        return;
    }
    passwordSave.disabled = true;
    applyMessage(passwordMessage, '변경 중…', null);
    try {
        const client = getClient();
        if (!client) throw new Error('인증 클라이언트가 초기화되지 않았습니다.');
        const { error } = await client.auth.updateUser({ password: pwNew.value });
        if (error) throw error;
        pwNew.value = '';
        pwConfirm.value = '';
        applyMessage(passwordMessage, '비밀번호가 변경되었습니다.', 'success');
    } catch (error) {
        applyMessage(passwordMessage, error.message || '비밀번호 변경 실패', 'error');
    } finally {
        passwordSave.disabled = false;
    }
});

accountSignout?.addEventListener('click', async () => {
    const client = getClient();
    if (client) await client.auth.signOut();
    window.location.href = 'index.html';
});

accountDelete?.addEventListener('click', () => {
    alert('계정 삭제는 보안상 별도 확인이 필요합니다. 관리자에게 문의해 주세요.');
});

(async function start() {
    const { session } = await initSupabase();
    if (!session) {
        loadingState.classList.add('hidden');
        signedOutState.classList.remove('hidden');
        return;
    }
    setupHeaderUser({ userMenu, userEmail, adminLink, logoutBtn });
    await loadProfile();
})();
