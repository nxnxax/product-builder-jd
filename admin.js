import {
    initSupabase,
    apiRequest,
    mountAppHeader,
    refreshAppHeader,
    isAdmin,
    getInitial,
} from './auth-shared.js?v=20260516-balance-compact';

const STATUS_LABEL = { active: '활성', suspended: '정지', banned: '차단' };
const ROLE_LABEL = { admin: '관리자', owner: '운영자', member: '일반회원' };

const loadingState = document.getElementById('loading-state');
const forbiddenState = document.getElementById('forbidden-state');
const adminApp = document.getElementById('admin-app');

/* 헤더 user-menu / user-display / admin-link / logout-btn 은 mountAppHeader 가 생성. */

const tabs = document.querySelectorAll('.tabs .tab');
const tabSections = document.querySelectorAll('.admin-tab');

const kpiMembers = document.getElementById('kpi-members');
const kpiMembersDelta = document.getElementById('kpi-members-delta');
const kpiCustomers = document.getElementById('kpi-customers');
const kpiEmployees = document.getElementById('kpi-employees');
const kpiTrendTotal = document.getElementById('kpi-trend-total');
const kpiSpark = document.getElementById('kpi-spark');
const recentSignups = document.getElementById('recent-signups');

const membersList = document.getElementById('members-list');
const membersEmpty = document.getElementById('members-empty');
const membersSearch = document.getElementById('members-search');

const settingsForm = document.getElementById('settings-form');
const settingSiteName = document.getElementById('setting-site-name');
const settingSiteTagline = document.getElementById('setting-site-tagline');
const settingContactEmail = document.getElementById('setting-contact-email');
const settingNotice = document.getElementById('setting-notice');
const settingSignupEnabled = document.getElementById('setting-signup-enabled');
const settingsMessage = document.getElementById('settings-message');
const settingsSave = document.getElementById('settings-save');

const logsList = document.getElementById('logs-list');
const logsEmpty = document.getElementById('logs-empty');

const memberModal = document.getElementById('member-modal');
const memberClose = document.getElementById('member-close');
const memberCancel = document.getElementById('member-cancel');
const memberForm = document.getElementById('member-form');
const memberEmailDisplay = document.getElementById('member-email-display');
const memberRoleInput = document.getElementById('member-role');
const memberStatusInput = document.getElementById('member-status');
const memberMessage = document.getElementById('member-message');
const memberSave = document.getElementById('member-save');

let allMembers = [];
let memberFilter = '';
let editingMember = null;

function escape(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch]));
}

function showTab(tab) {
    tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    tabSections.forEach(s => s.classList.toggle('hidden', s.id !== `tab-${tab}`));
}

tabs.forEach(t => t.addEventListener('click', () => showTab(t.dataset.tab)));

function renderKpis(stats) {
    kpiMembers.textContent = (stats.totalMembers ?? 0).toLocaleString();
    kpiMembersDelta.textContent = `최근 7일 +${stats.newMembers7d ?? 0}`;
    kpiCustomers.textContent = (stats.totalCustomers ?? 0).toLocaleString();
    kpiEmployees.textContent = (stats.totalEmployees ?? 0).toLocaleString();

    const trend = Array.isArray(stats.memberTrend) ? stats.memberTrend : [];
    const trendTotal = trend.reduce((acc, d) => acc + (d.count || 0), 0);
    kpiTrendTotal.textContent = trendTotal.toLocaleString();

    const max = Math.max(1, ...trend.map(d => d.count || 0));
    kpiSpark.innerHTML = trend.map(d => {
        const h = Math.max(3, Math.round(((d.count || 0) / max) * 36));
        return `<span style="height:${h}px" title="${escape(d.date)}: ${d.count || 0}"></span>`;
    }).join('');

    recentSignups.innerHTML = '';
    const signups = Array.isArray(stats.recentSignups) ? stats.recentSignups : [];
    if (signups.length === 0) {
        recentSignups.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--fg-tertiary);padding:32px 16px;">최근 가입자가 없습니다.</td></tr>';
        return;
    }
    signups.forEach(member => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td data-label="이름">${escape(member.name || '—')}</td>
            <td data-label="이메일">${escape(member.email || '—')}</td>
            <td data-label="가입 방식">${escape(member.provider === 'google' ? 'Google' : '이메일')}</td>
            <td data-label="가입일">${escape(member.createdAt || '—')}</td>
        `;
        recentSignups.appendChild(tr);
    });
}

function renderMembers() {
    const term = memberFilter.toLowerCase();
    const filtered = allMembers.filter(m =>
        (m.email || '').toLowerCase().includes(term) ||
        (m.name || '').toLowerCase().includes(term)
    );

    membersList.innerHTML = '';
    if (filtered.length === 0) {
        membersEmpty.classList.remove('hidden');
        return;
    }
    membersEmpty.classList.add('hidden');

    filtered.forEach(member => {
        const tr = document.createElement('tr');
        const initial = escape(getInitial(member.name, member.email));
        tr.innerHTML = `
            <td data-label="회원">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="avatar">${initial}</span>
                    <div>
                        <div style="font-weight:600;">${escape(member.name || '이름 없음')}</div>
                        <div style="color:var(--fg-tertiary);font-size:12px;">${escape(member.email)}</div>
                    </div>
                </div>
            </td>
            <td data-label="가입 방식">${escape(member.provider === 'google' ? 'Google' : '이메일')}</td>
            <td data-label="권한"><span class="pill ${member.role === 'admin' || member.role === 'owner' ? 'admin' : ''}">${escape(ROLE_LABEL[member.role] || '일반회원')}</span></td>
            <td data-label="상태"><span class="pill ${member.status === 'active' ? 'active' : member.status === 'suspended' ? 'suspended' : ''}">${escape(STATUS_LABEL[member.status] || '활성')}</span></td>
            <td data-label="가입일">${escape(member.createdAt || '—')}</td>
            <td class="action-btns" data-label="">
                <button class="edit-btn" data-email="${escape(member.email)}">편집</button>
            </td>
        `;
        membersList.appendChild(tr);
    });

    membersList.querySelectorAll('button[data-email]').forEach(btn => {
        btn.addEventListener('click', () => openMemberEdit(btn.dataset.email));
    });
}

function openMemberEdit(email) {
    const member = allMembers.find(m => m.email === email);
    if (!member) return;
    editingMember = member;
    memberEmailDisplay.textContent = member.email;
    memberRoleInput.value = member.role === 'admin' || member.role === 'owner' ? 'admin' : 'member';
    memberStatusInput.value = ['active', 'suspended', 'banned'].includes(member.status) ? member.status : 'active';
    memberMessage.textContent = '';
    memberMessage.className = 'form-help';
    memberModal.classList.remove('hidden');
}

function closeMemberEdit() {
    memberModal.classList.add('hidden');
    editingMember = null;
}

memberClose.addEventListener('click', closeMemberEdit);
memberCancel.addEventListener('click', closeMemberEdit);
memberModal.addEventListener('click', (event) => {
    if (event.target === memberModal) closeMemberEdit();
});

memberForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!editingMember) return;
    memberSave.disabled = true;
    memberMessage.textContent = '저장 중…';
    memberMessage.className = 'form-help';
    try {
        await apiRequest('admin-members', {
            method: 'PATCH',
            body: JSON.stringify({
                resource: 'admin-members',
                email: editingMember.email,
                role: memberRoleInput.value,
                status: memberStatusInput.value,
            }),
        });
        closeMemberEdit();
        await loadMembers();
    } catch (error) {
        memberMessage.textContent = error.message || '저장 실패';
        memberMessage.className = 'form-help error';
    } finally {
        memberSave.disabled = false;
    }
});

membersSearch.addEventListener('input', (e) => {
    memberFilter = e.target.value;
    renderMembers();
});

async function loadMembers() {
    try {
        const payload = await apiRequest('admin-members');
        allMembers = Array.isArray(payload.items) ? payload.items : [];
        renderMembers();
    } catch (error) {
        membersList.innerHTML = `<tr><td colspan="6" style="color:var(--danger);text-align:center;padding:32px 16px;">${escape(error.message || '불러오기 실패')}</td></tr>`;
    }
}

async function loadStats() {
    try {
        const payload = await apiRequest('admin-stats');
        renderKpis(payload.stats || {});
    } catch (error) {
        kpiMembers.textContent = '!';
        kpiCustomers.textContent = '!';
        kpiEmployees.textContent = '!';
        kpiMembersDelta.textContent = error.message || '통계 로드 실패';
    }
}

const cleanupBtn = document.getElementById('cleanup-orphans-btn');
const cleanupMessage = document.getElementById('cleanup-message');

if (cleanupBtn) {
    cleanupBtn.addEventListener('click', async () => {
        const confirmed = window.confirm(
            '소유자(owner_email)가 비어있는 모든 고객/직원 행을 영구 삭제합니다.\n\n복구 불가능합니다. 진행하시겠습니까?'
        );
        if (!confirmed) return;

        cleanupBtn.disabled = true;
        cleanupMessage.textContent = '삭제 중…';
        cleanupMessage.className = 'form-help';
        try {
            const payload = await apiRequest('admin-cleanup-orphans', {
                method: 'POST',
                body: JSON.stringify({ resource: 'admin-cleanup-orphans' }),
            });
            const c = payload?.deleted?.customers ?? 0;
            const e = payload?.deleted?.employees ?? 0;
            cleanupMessage.textContent = `삭제 완료: 고객 ${c}건, 직원 ${e}건`;
            cleanupMessage.className = 'form-help success';
        } catch (error) {
            cleanupMessage.textContent = error.message || '삭제 실패';
            cleanupMessage.className = 'form-help error';
        } finally {
            cleanupBtn.disabled = false;
        }
    });
}

settingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    settingsSave.disabled = true;
    settingsMessage.textContent = '저장 중…';
    settingsMessage.className = 'form-help';
    try {
        await apiRequest('admin-settings', {
            method: 'PUT',
            body: JSON.stringify({
                resource: 'admin-settings',
                settings: {
                    site_name: settingSiteName.value,
                    site_tagline: settingSiteTagline.value,
                    contact_email: settingContactEmail.value,
                    notice: settingNotice.value,
                    signup_enabled: settingSignupEnabled.checked ? '1' : '0',
                },
            }),
        });
        settingsMessage.textContent = '저장되었습니다.';
        settingsMessage.className = 'form-help success';
    } catch (error) {
        settingsMessage.textContent = error.message || '저장 실패';
        settingsMessage.className = 'form-help error';
    } finally {
        settingsSave.disabled = false;
    }
});

async function loadSettings() {
    try {
        const payload = await apiRequest('admin-settings');
        const settings = payload.settings || {};
        settingSiteName.value = settings.site_name || '';
        settingSiteTagline.value = settings.site_tagline || '';
        settingContactEmail.value = settings.contact_email || '';
        settingNotice.value = settings.notice || '';
        settingSignupEnabled.checked = settings.signup_enabled !== '0';
    } catch (error) {
        settingsMessage.textContent = error.message || '설정 로드 실패';
        settingsMessage.className = 'form-help error';
    }
}

function formatRelative(timestamp) {
    if (!timestamp) return '';
    const ts = new Date(timestamp);
    if (isNaN(ts.getTime())) return timestamp;
    const diff = (Date.now() - ts.getTime()) / 1000;
    if (diff < 60) return `${Math.round(diff)}초 전`;
    if (diff < 3600) return `${Math.round(diff / 60)}분 전`;
    if (diff < 86400) return `${Math.round(diff / 3600)}시간 전`;
    return ts.toLocaleString('ko-KR', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const EVENT_LABEL = {
    'profile.update': '내 정보를 수정했습니다',
    'admin.member.update': '회원 권한·상태를 변경했습니다',
    'admin.settings.update': '사이트 설정을 변경했습니다',
};

async function loadLogs() {
    try {
        const payload = await apiRequest('admin-logs', { query: 'limit=80' });
        const items = Array.isArray(payload.items) ? payload.items : [];
        if (items.length === 0) {
            logsList.innerHTML = '';
            logsEmpty.classList.remove('hidden');
            return;
        }
        logsEmpty.classList.add('hidden');
        logsList.innerHTML = items.map(item => `
            <li class="activity-item">
                <span class="activity-time">${escape(formatRelative(item.createdAt))}</span>
                <div class="activity-body">
                    <div class="activity-actor">${escape(item.actor || '시스템')}</div>
                    <div class="activity-meta">${escape(EVENT_LABEL[item.event] || item.event || '')}${item.detail ? ' · ' + escape(item.detail) : ''}</div>
                </div>
            </li>
        `).join('');
    } catch (error) {
        logsList.innerHTML = `<li class="activity-item"><div class="activity-body" style="color:var(--danger);">${escape(error.message || '로그 로드 실패')}</div></li>`;
    }
}

(async function start() {
    mountAppHeader();
    const { session } = await initSupabase();
    if (!session) {
        loadingState.classList.add('hidden');
        forbiddenState.classList.remove('hidden');
        return;
    }
    await refreshAppHeader();

    if (!isAdmin(session)) {
        // Verify with server, since role may be set in DB rather than user_metadata.
        try {
            await apiRequest('admin-stats');
        } catch (error) {
            if (error.status === 403) {
                loadingState.classList.add('hidden');
                forbiddenState.classList.remove('hidden');
                return;
            }
        }
    }

    loadingState.classList.add('hidden');
    adminApp.classList.remove('hidden');

    await Promise.all([loadStats(), loadMembers(), loadSettings(), loadLogs()]);
})();
