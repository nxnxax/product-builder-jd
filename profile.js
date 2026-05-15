import {
    initSupabase,
    getSession,
    getClient,
    apiRequest,
    mountAppHeader,
    refreshAppHeader,
    getInitial,
} from './auth-shared.js?v=20260515-toggle-fix';

const tabButtons = document.querySelectorAll('.side-nav button[data-tab]');
const tabSections = document.querySelectorAll('.profile-tab');
const loadingState = document.getElementById('loading-state');
const signedOutState = document.getElementById('signed-out-state');

/* 헤더 user-menu / user-display / admin-link / logout-btn 은 mountAppHeader 가 생성. */

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

const tokenForm = document.getElementById('token-form');
const tokenLabel = document.getElementById('token-label');
const tokenCreate = document.getElementById('token-create');
const tokenMessage = document.getElementById('token-message');
const tokenFresh = document.getElementById('token-fresh');
const tokenFreshValue = document.getElementById('token-fresh-value');
const tokenFreshCopy = document.getElementById('token-fresh-copy');
const tokenList = document.getElementById('token-list');
const tokenListEmpty = document.getElementById('token-list-empty');

let snapshot = null;

const STATUS_LABEL = { active: '활성', suspended: '정지', banned: '차단' };
const ROLE_LABEL = { admin: '관리자', owner: '운영자', member: '일반회원' };
const PROVIDER_LABEL = { google: 'Google 연동', email: '이메일', github: 'GitHub' };

function showTab(tab) {
    tabButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tab));
    tabSections.forEach(section => {
        section.classList.toggle('hidden', section.id !== `tab-${tab}`);
    });
    if (tab !== 'mobile') hideFreshToken();
    if (tab === 'mobile') loadTokens();
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

function hideFreshToken() {
    if (!tokenFresh) return;
    tokenFresh.classList.add('hidden');
    if (tokenFreshValue) tokenFreshValue.textContent = '';
}

function formatDateTime(value) {
    if (!value) return '—';
    const d = new Date(value.replace(' ', 'T') + (value.includes('T') ? '' : 'Z'));
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString('ko-KR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function renderTokens(items) {
    if (!tokenList) return;
    const active = items.filter(t => !t.revokedAt);
    const revoked = items.filter(t => t.revokedAt);

    if (items.length === 0) {
        tokenList.innerHTML = '<p class="form-help" id="token-list-empty">아직 발급된 토큰이 없습니다.</p>';
        return;
    }

    const rowHtml = (t) => {
        const isRevoked = !!t.revokedAt;
        const lastUsed = t.lastUsedAt
            ? `${formatDateTime(t.lastUsedAt)}${t.lastUsedIp ? ` · ${t.lastUsedIp}` : ''}`
            : '사용 기록 없음';
        const meta = isRevoked
            ? `폐기됨 · ${formatDateTime(t.revokedAt)}`
            : `발급 ${formatDateTime(t.createdAt)} · 최근 사용 ${lastUsed}`;
        const actionBtn = isRevoked
            ? ''
            : `<button type="button" class="secondary-btn token-revoke-btn" data-id="${t.id}" style="padding:4px 10px;font-size:12px;">폐기</button>`;
        return `
            <div class="field-row" style="${isRevoked ? 'opacity:0.55;' : ''}">
                <div class="field-label" style="display:flex;flex-direction:column;gap:4px;">
                    <span style="font-weight:600;">${escapeHtml(t.label || '이름 없음')}</span>
                    <code style="font-family:var(--font-mono);font-size:12px;color:var(--fg-tertiary);">${escapeHtml(t.prefix || '')}…</code>
                </div>
                <div class="field-control" style="flex-direction:row;align-items:center;justify-content:space-between;gap:12px;">
                    <span class="form-help" style="margin:0;">${escapeHtml(meta)}</span>
                    ${actionBtn}
                </div>
            </div>
        `;
    };

    tokenList.innerHTML = [...active, ...revoked].map(rowHtml).join('');

    tokenList.querySelectorAll('.token-revoke-btn').forEach(btn => {
        btn.addEventListener('click', () => revokeToken(parseInt(btn.dataset.id, 10)));
    });
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function loadTokens() {
    if (!tokenList) return;
    try {
        const payload = await apiRequest('mobile-tokens');
        renderTokens(payload.items || []);
    } catch (error) {
        tokenList.innerHTML = `<p class="form-help error">${escapeHtml(error.message || '토큰 목록을 불러오지 못했습니다.')}</p>`;
    }
}

async function revokeToken(id) {
    if (!id) return;
    if (!confirm('이 토큰을 폐기하시겠습니까? 폐기된 토큰을 사용하던 기기는 즉시 거부됩니다.')) return;
    try {
        await apiRequest('mobile-tokens', {
            method: 'DELETE',
            body: JSON.stringify({ id }),
        });
        await loadTokens();
    } catch (error) {
        applyMessage(tokenMessage, error.message || '폐기 실패', 'error');
    }
}

tokenForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const label = tokenLabel.value.trim();
    tokenCreate.disabled = true;
    applyMessage(tokenMessage, '발급 중…', null);
    try {
        const payload = await apiRequest('mobile-tokens', {
            method: 'POST',
            body: JSON.stringify({ label }),
        });
        tokenFreshValue.textContent = payload.token || '';
        tokenFresh.classList.remove('hidden');
        tokenLabel.value = '';
        applyMessage(tokenMessage, '발급되었습니다. 평문 토큰을 안전한 곳에 저장하세요.', 'success');
        await loadTokens();
    } catch (error) {
        applyMessage(tokenMessage, error.message || '발급 실패', 'error');
    } finally {
        tokenCreate.disabled = false;
    }
});

tokenFreshCopy?.addEventListener('click', async () => {
    const value = tokenFreshValue?.textContent || '';
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
        tokenFreshCopy.textContent = '복사됨';
        setTimeout(() => { tokenFreshCopy.textContent = '복사'; }, 1500);
    } catch {
        tokenFreshValue.focus?.();
        const range = document.createRange();
        range.selectNodeContents(tokenFreshValue);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
});

(async function start() {
    mountAppHeader();
    const { session } = await initSupabase();
    if (!session) {
        loadingState.classList.add('hidden');
        signedOutState.classList.remove('hidden');
        return;
    }
    await refreshAppHeader();
    await loadProfile();
})();
