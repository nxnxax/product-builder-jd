import {
    initSupabase,
    apiRequest,
    mountAppHeader,
    refreshAppHeader,
    isAdmin,
    getInitial,
} from './auth-shared.js?v=20260612-is-admin';

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
const aiModelForm = document.getElementById('ai-model-form');
const aiModelMessage = document.getElementById('ai-model-message');
const aiModelSave = document.getElementById('ai-model-save');

// 진단 탭
const traceEmail = document.getElementById('trace-email');
const traceBtn = document.getElementById('trace-btn');
const traceMessage = document.getElementById('trace-message');
const traceTable = document.getElementById('trace-table');
const traceList = document.getElementById('trace-list');
const traceEmpty = document.getElementById('trace-empty');
const errorsRefresh = document.getElementById('errors-refresh');
const errorsClear = document.getElementById('errors-clear');
const errorsMessage = document.getElementById('errors-message');
const errorsTable = document.getElementById('errors-table');
const errorsList = document.getElementById('errors-list');
const errorsEmpty = document.getElementById('errors-empty');

const logsList = document.getElementById('logs-list');
const logsEmpty = document.getElementById('logs-empty');

const memberModal = document.getElementById('member-modal');
const memberClose = document.getElementById('member-close');
const memberCancel = document.getElementById('member-cancel');
const memberForm = document.getElementById('member-form');
const memberEmailDisplay = document.getElementById('member-email-display');
const memberRoleInput = document.getElementById('member-role');
const memberStatusInput = document.getElementById('member-status');
const memberPlanInput = document.getElementById('member-plan');
const memberPlanStatusInput = document.getElementById('member-plan-status');
const memberSummaryUsedInput = document.getElementById('member-summary-used');
const memberPeriodEndInput = document.getElementById('member-period-end');
// 분 단위 과금 (Phase 2)
const memberUsageMinutesInput = document.getElementById('member-usage-minutes');
const memberSummaryLimitMinutesInput = document.getElementById('member-summary-limit-minutes');
const memberOverageBalanceInput = document.getElementById('member-overage-balance');
const memberOverageEnabledInput = document.getElementById('member-overage-enabled');
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

    // 사장님 2026-05-25 — trialing 폐지. 옛 가입자 호환만 위해 Free 와 동일 매핑.
    const PLAN_DISPLAY = {
        free: { label: 'Free', cls: '' },
        trialing: { label: 'Free', cls: '' },
        plus: { label: 'Plus', cls: 'pill-plus' },
        premium: { label: 'Plus', cls: 'pill-plus' },
        pro: { label: 'Pro', cls: 'pill-pro' },
    };
    const PLAN_STATUS_DISPLAY = {
        active: { label: '활성', cls: 'active' },
        trialing: { label: '활성', cls: 'active' },
        past_due: { label: '결제실패', cls: 'suspended' },
        cancelled: { label: '해지', cls: '' },
    };

    filtered.forEach(member => {
        const tr = document.createElement('tr');
        const initial = escape(getInitial(member.name, member.email));
        const planKey = (member.plan || 'free').toLowerCase();
        const planInfo = PLAN_DISPLAY[planKey] || { label: planKey, cls: '' };
        const statusKey = (member.plan_status || 'trialing').toLowerCase();
        const statusInfo = PLAN_STATUS_DISPLAY[statusKey] || { label: statusKey, cls: '' };
        const used = Number.isFinite(+member.summary_used) ? +member.summary_used : 0;
        const limitVal = member.summary_limit;
        const limitStr = (limitVal == null) ? '∞' : String(limitVal);
        const usageColor = (limitVal != null && limitVal > 0 && used >= limitVal) ? '#b91c1c' : 'inherit';
        const periodEnd = member.current_period_end ? escape(member.current_period_end) : '—';

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
            <td data-label="플랜">
                <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start;">
                    <span class="pill ${planInfo.cls}" style="font-size:11px;">${escape(planInfo.label)}</span>
                    <span class="pill ${statusInfo.cls}" style="font-size:10.5px;">${escape(statusInfo.label)}</span>
                </div>
            </td>
            <td data-label="사용량" style="color:${usageColor};font-variant-numeric:tabular-nums;">${used} / ${escape(limitStr)}</td>
            <td data-label="다음 결제일">${periodEnd}</td>
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
    // 구독 결제 필드 — 응답에 있으면 채움, 없으면 default. 옛 trialing 은 자동 free 매핑.
    if (memberPlanInput) {
        // 사장님 2026-05-26 — 옛 plan key 정규화 (sales/master/agency 신규 요금제).
        let planVal = member.plan;
        if (planVal === 'trialing') planVal = 'free';
        if (planVal === 'plus' || planVal === 'premium') planVal = 'sales';
        if (planVal === 'pro') planVal = 'master';
        memberPlanInput.value = ['free', 'sales', 'master', 'agency'].includes(planVal) ? planVal : 'free';
    }
    if (memberPlanStatusInput) {
        const statusVal = (member.plan_status === 'trialing') ? 'active' : member.plan_status;
        memberPlanStatusInput.value = ['active', 'past_due', 'cancelled'].includes(statusVal) ? statusVal : 'active';
    }
    if (memberSummaryUsedInput) {
        memberSummaryUsedInput.value = (member.summary_used != null && Number.isFinite(+member.summary_used)) ? String(member.summary_used) : '0';
    }
    if (memberPeriodEndInput) {
        const raw = String(member.current_period_end || '');
        const m = raw.match(/^(\d{4})[-.](\d{2})[-.](\d{2})/);
        memberPeriodEndInput.value = m ? `${m[1]}-${m[2]}-${m[3]}` : '';
    }
    // Phase 2 분 단위 prefill
    if (memberUsageMinutesInput) {
        const sec = +(member.usage_seconds_period || 0);
        memberUsageMinutesInput.value = Number.isFinite(sec) ? String(Math.round(sec / 60)) : '0';
    }
    if (memberSummaryLimitMinutesInput) {
        memberSummaryLimitMinutesInput.value = (member.summary_limit_minutes != null && Number.isFinite(+member.summary_limit_minutes))
            ? String(member.summary_limit_minutes) : '';
    }
    if (memberOverageBalanceInput) {
        const balSec = +(member.overage_balance_seconds || 0);
        memberOverageBalanceInput.value = Number.isFinite(balSec) ? String(Math.round(balSec / 60)) : '0';
    }
    if (memberOverageEnabledInput) {
        memberOverageEnabledInput.checked = !!(+member.overage_enabled);
    }
    memberMessage.textContent = '';
    memberMessage.className = 'form-help';
    memberModal.classList.remove('hidden');
}

// 만료일 빠른 버튼 (open 시점에 한 번만 bind — modal 은 재사용이라 안전)
if (memberPeriodEndInput) {
    document.querySelectorAll('[data-period-quick]').forEach(btn => {
        btn.addEventListener('click', () => {
            const days = parseInt(btn.dataset.periodQuick, 10) || 0;
            const d = new Date();
            d.setDate(d.getDate() + days);
            memberPeriodEndInput.value = d.toISOString().slice(0, 10);
        });
    });
    document.querySelectorAll('[data-period-clear]').forEach(btn => {
        btn.addEventListener('click', () => { memberPeriodEndInput.value = ''; });
    });
}

// 자동 충전 잔액 빠른 부여 버튼
if (memberOverageBalanceInput) {
    document.querySelectorAll('[data-overage-add]').forEach(btn => {
        btn.addEventListener('click', () => {
            const add = parseInt(btn.dataset.overageAdd, 10) || 0;
            const cur = parseInt(memberOverageBalanceInput.value, 10) || 0;
            memberOverageBalanceInput.value = String(Math.max(0, cur + add));
        });
    });
    document.querySelectorAll('[data-overage-clear]').forEach(btn => {
        btn.addEventListener('click', () => { memberOverageBalanceInput.value = '0'; });
    });
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
        const body = {
            resource: 'admin-members',
            email: editingMember.email,
            role: memberRoleInput.value,
            status: memberStatusInput.value,
        };
        // 구독 결제 필드 — 입력값이 있을 때만 포함 (관리자 수동 변경 의도 명시).
        if (memberPlanInput && memberPlanInput.value) body.plan = memberPlanInput.value;
        if (memberPlanStatusInput && memberPlanStatusInput.value) body.plan_status = memberPlanStatusInput.value;
        if (memberSummaryUsedInput && memberSummaryUsedInput.value !== '') {
            const n = parseInt(memberSummaryUsedInput.value, 10);
            if (Number.isFinite(n) && n >= 0) body.summary_used = n;
        }
        if (memberPeriodEndInput) {
            // 빈 값이면 null 보내서 서버가 NULL 로 reset.
            const v = memberPeriodEndInput.value;
            if (v === '') body.current_period_end = null;
            else if (/^\d{4}-\d{2}-\d{2}$/.test(v)) body.current_period_end = v + ' 23:59:59';
        }
        // Phase 2 분 단위 — records.php admin-members PATCH 가 받음
        if (memberUsageMinutesInput && memberUsageMinutesInput.value !== '') {
            const n = parseInt(memberUsageMinutesInput.value, 10);
            if (Number.isFinite(n) && n >= 0) body.usage_minutes_period = n;
        }
        if (memberSummaryLimitMinutesInput) {
            const v = memberSummaryLimitMinutesInput.value.trim();
            if (v === '') body.summary_limit_minutes = null;  // plan default 사용
            else {
                const n = parseInt(v, 10);
                if (Number.isFinite(n) && n >= 0) body.summary_limit_minutes = n;
            }
        }
        if (memberOverageBalanceInput && memberOverageBalanceInput.value !== '') {
            const n = parseInt(memberOverageBalanceInput.value, 10);
            if (Number.isFinite(n) && n >= 0) body.overage_balance_minutes = n;
        }
        if (memberOverageEnabledInput) {
            body.overage_enabled = memberOverageEnabledInput.checked ? 1 : 0;
        }
        await apiRequest('admin-members', {
            method: 'PATCH',
            body: JSON.stringify(body),
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

/* ── 사장님 2026-05-24 — 관리자 통계 탭 (기간 검색) ── */
const statsFromInput = document.getElementById('stats-from');
const statsToInput   = document.getElementById('stats-to');
const statsApplyBtn  = document.getElementById('stats-apply');
const statsRangeLabel = document.getElementById('stats-range-label');
const statsKpiVisitors  = document.getElementById('stats-kpi-visitors');
const statsKpiPageviews = document.getElementById('stats-kpi-pageviews');
const statsKpiSignups   = document.getElementById('stats-kpi-signups');
const statsKpiPayments  = document.getElementById('stats-kpi-payments');
const statsKpiCancels   = document.getElementById('stats-kpi-cancels');
const statsKpiUsage     = document.getElementById('stats-kpi-usage');
const statsReferrersTbody = document.getElementById('stats-referrers');
const statsDailyTbody     = document.getElementById('stats-daily');
const statsMembersTbody   = document.getElementById('stats-members');
const statsEventsTbody    = document.getElementById('stats-events');
const statsChartCanvas    = document.getElementById('stats-chart-visitors');
const statsChartRevenueCanvas = document.getElementById('stats-chart-revenue');
// 확장 KPI (사장님 2026-05-24)
const statsKpiMau     = document.getElementById('stats-kpi-mau');
const statsKpiMrr     = document.getElementById('stats-kpi-mrr');
const statsKpiArpu    = document.getElementById('stats-kpi-arpu');
const statsKpiRevenue = document.getElementById('stats-kpi-revenue');
const statsKpiStt     = document.getElementById('stats-kpi-stt');
const statsKpiJobs    = document.getElementById('stats-kpi-jobs');
const statsKpiAvg     = document.getElementById('stats-kpi-avg');
// 확장 표
const statsFunnelTbody     = document.getElementById('stats-funnel');
const statsPlansTbody      = document.getElementById('stats-plans');
const statsMemberUsageTbody = document.getElementById('stats-member-usage');
const statsJobsStatusTbody = document.getElementById('stats-jobs-status');
const statsProvidersTbody  = document.getElementById('stats-providers');
const statsAutochargeEnabled = document.getElementById('stats-autocharge-enabled');
const statsAutochargePct = document.getElementById('stats-autocharge-pct');
const statsAutochargeBalance = document.getElementById('stats-autocharge-balance');

let _lastStatsEvents = [];
let _eventsFilter = 'all';
let statsChartRevenueInstance = null;

const FUNNEL_STEPS = [
    { key: 'signups',      label: '회원 가입' },
    { key: 'firstCallers', label: '첫 통화 (AI 사용)' },
    { key: 'firstSavers',  label: '첫 고객 저장' },
    { key: 'firstPayers',  label: '첫 결제' },
];
const PLAN_LABEL = {
    free: 'Free',
    sales: 'Sales',
    master: 'Master',
    agency: 'Agency',
    // 옛 plan key fallback (DB migration 잔재 호환)
    trialing: 'Free',
    plus: 'Sales',
    premium: 'Sales',
    pro: 'Master',
    other: '기타',
};
const STATUS_LABEL_JOBS = {
    audio_pending: '음성 대기',
    queued: '큐 대기',
    processing: 'AI 처리중',
    ready_to_review: '검토 대기',
    completed: '완료',
    saved: '저장됨',
    failed_retryable: '재시도 실패',
    failed_permanent: '영구 실패',
    dismissed: '폐기',
    llm_processing: 'LLM 처리중',
    stt_processing: 'STT 처리중',
};

function fmtKrw(n) {
    return '₩' + (Math.round(Number(n) || 0)).toLocaleString();
}

let statsChartInstance = null;
let statsLoaded = false;

function fmtDateInput(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${dd}`;
}

function setStatsPreset(kind) {
    if (!statsFromInput || !statsToInput) return;
    const today = new Date();
    statsToInput.value = fmtDateInput(today);
    if (kind === 'month') {
        const first = new Date(today.getFullYear(), today.getMonth(), 1);
        statsFromInput.value = fmtDateInput(first);
    } else {
        const days = parseInt(kind, 10) || 7;
        const from = new Date(today);
        from.setDate(from.getDate() - (days - 1));
        statsFromInput.value = fmtDateInput(from);
    }
}

async function loadStatsRange() {
    if (!statsFromInput || !statsToInput) return;
    const from = statsFromInput.value;
    const to   = statsToInput.value;
    if (!from || !to) {
        statsRangeLabel.textContent = '시작/종료 날짜를 모두 선택하세요.';
        return;
    }
    statsRangeLabel.textContent = '불러오는 중…';
    statsApplyBtn.disabled = true;
    try {
        const payload = await apiRequest('admin-stats-range', {
            query: `from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
        });
        renderStatsRange(payload);
    } catch (e) {
        statsRangeLabel.textContent = e.message || '통계 로드 실패';
    } finally {
        statsApplyBtn.disabled = false;
    }
}

const STATS_EVENT_LABEL = {
    signup: '가입',
    payment: '결제',
    cancel: '취소',
    summary_view: '요약보기',
    auto_confirm: '양식전송',
    saju_reading: '사주풀이',
};
const STATS_EVENT_COLOR = {
    signup:       'background:#e8f5e9;color:#2e7d32;',
    payment:      'background:#e3f2fd;color:#1565c0;',
    cancel:       'background:#fbe9e7;color:#c62828;',
    summary_view: 'background:#fff3e0;color:#ef6c00;',
    auto_confirm: 'background:#f3e5f5;color:#6a1b9a;',
    saju_reading: 'background:#fff8e1;color:#a36a00;',
};

function statsBadge(type) {
    const style = STATS_EVENT_COLOR[type] || 'background:#eee;color:#444;';
    return `<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11.5px;font-weight:600;${style}">${escape(STATS_EVENT_LABEL[type] || type)}</span>`;
}

// 통계 표 페이지네이션 (사장님 2026-05-26 — 15개씩 페이지 분할)
const STATS_PAGE_SIZE = 15;
const _statsPage = { daily: 1, referrers: 1, members: 1, events: 1, memberUsage: 1 };
function renderStatsPaginated(tbody, items, colspan, renderRow, emptyMsg, pageKey) {
    const total = items.length;
    if (total === 0) {
        tbody.innerHTML = `<tr><td colspan="${colspan}" style="text-align:center;color:var(--fg-tertiary);padding:24px;">${emptyMsg}</td></tr>`;
        _setStatsNav(tbody, 1, 1, pageKey, 0);
        return;
    }
    const totalPages = Math.max(1, Math.ceil(total / STATS_PAGE_SIZE));
    if (_statsPage[pageKey] > totalPages) _statsPage[pageKey] = 1;
    const cur = _statsPage[pageKey];
    tbody.innerHTML = items.slice((cur - 1) * STATS_PAGE_SIZE, cur * STATS_PAGE_SIZE).map(renderRow).join('');
    _setStatsNav(tbody, cur, totalPages, pageKey, total);
}
function _setStatsNav(tbody, cur, totalPages, pageKey, total) {
    const table = tbody.closest('table');
    if (!table) return;
    let nav = table.parentElement.querySelector(`[data-stats-nav="${pageKey}"]`);
    if (!nav) {
        nav = document.createElement('div');
        nav.dataset.statsNav = pageKey;
        nav.style.cssText = 'display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:8px 0 0;font-size:12px;color:var(--fg-tertiary);';
        table.parentElement.appendChild(nav);
    }
    if (totalPages <= 1) { nav.innerHTML = ''; return; }
    nav.innerHTML = `<button type="button" class="tiny-btn" data-stats-page="${pageKey}" data-dir="-1" ${cur === 1 ? 'disabled' : ''}>이전</button><span>${cur} / ${totalPages} · 총 ${total}건</span><button type="button" class="tiny-btn" data-stats-page="${pageKey}" data-dir="1" ${cur === totalPages ? 'disabled' : ''}>다음</button>`;
}
let _lastStatsPayload = null;
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-stats-page]');
    if (!btn) return;
    const k = btn.dataset.statsPage;
    _statsPage[k] = Math.max(1, _statsPage[k] + Number(btn.dataset.dir));
    if (_lastStatsPayload) renderStatsRange(_lastStatsPayload);
});

// 사장님 2026-06-08 — 실제 사용된 STT/LLM AI 를 작은 뱃지로 표시 ("STT모델+LLM모델" 문자열 파싱).
function aiModelBadges(model) {
    if (!model) return '';
    const fallback = model.includes('*');
    const parts = model.replace(/\*/g, '').split('+');
    const friendly = (s) => {
        s = (s || '').toLowerCase().trim();
        if (s.includes('together-whisper')) return ['받아쓰기 · Together', '#e8f0fe', '#1967d2'];
        if (s.includes('groq-whisper'))     return ['받아쓰기 · Groq', '#e8f0fe', '#1967d2'];
        if (s.includes('whisper'))          return ['받아쓰기 · OpenAI', '#e8f0fe', '#1967d2'];
        if (s.includes('qwen'))             return ['요약 · Qwen', '#f3e8fd', '#8a2620'];
        if (s.includes('claude'))           return ['요약 · Claude', '#f3e8fd', '#8a2620'];
        return null;
    };
    const badges = parts.map(friendly).filter(Boolean).map(([t, bg, fg]) =>
        `<span style="display:inline-block;padding:2px 8px;border-radius:9px;font-size:11.5px;font-weight:600;background:${bg};color:${fg};margin-right:4px;">${escape(t)}</span>`
    ).join('');
    return badges + (fallback ? '<span style="color:#c8362c;font-size:11.5px;font-weight:600;">⚠️ 대체 처리됨</span>' : '');
}

// 사주풀이 활동로그용 — 어떤 LLM 이 사주를 풀었는지 뱃지. detail 토큰('qwen'/'gpt-4o') 기준.
function sajuAiBadge(detail) {
    const s = (detail || '').toLowerCase();
    let label, bg, fg;
    if (s.includes('qwen'))      { label = '사주 · Qwen (Together)';  bg = '#f3e8fd'; fg = '#8a2620'; }
    else if (s.includes('gpt'))  { label = '사주 · gpt-4o (OpenAI)';  bg = '#e8f0fe'; fg = '#1967d2'; }
    else return escape(detail || '');
    return `<span style="display:inline-block;padding:2px 8px;border-radius:9px;font-size:11.5px;font-weight:600;background:${bg};color:${fg};">${escape(label)}</span>`;
}

function renderEventsTable() {
    const list = _eventsFilter === 'all' ? _lastStatsEvents : _lastStatsEvents.filter(e => e.type === _eventsFilter);
    renderStatsPaginated(statsEventsTbody, list, 4,
        e => `<tr><td style="white-space:nowrap;">${escape((e.occurred_at || '').replace('T',' ').slice(0,19))}</td><td>${statsBadge(e.type)}</td><td>${escape(e.email || '(unknown)')}</td><td style="color:var(--fg-secondary);">${e.type === 'saju_reading' ? sajuAiBadge(e.detail) : (e.aiModel ? aiModelBadges(e.aiModel) : escape(e.detail || ''))}</td></tr>`,
        '데이터 없음', 'events');
}

function renderStatsRange(payload) {
    _lastStatsPayload = payload;
    const totals = payload?.totals || {};
    const daily = Array.isArray(payload?.daily) ? payload.daily : [];
    const referrers = Array.isArray(payload?.referrers) ? payload.referrers : [];
    const events = Array.isArray(payload?.events) ? payload.events : [];
    const members = Array.isArray(payload?.members) ? payload.members : [];
    const range = payload?.range || {};
    statsRangeLabel.textContent = `${range.from} ~ ${range.to} · 총 ${daily.length}일`;

    statsKpiVisitors.textContent  = (totals.visitors  ?? 0).toLocaleString();
    statsKpiPageviews.textContent = `페이지뷰 ${(totals.pageviews ?? 0).toLocaleString()}`;
    statsKpiSignups.textContent   = (totals.newSignups    ?? 0).toLocaleString();
    statsKpiPayments.textContent  = (totals.newPayments   ?? 0).toLocaleString();
    statsKpiCancels.textContent   = (totals.cancelledSubs ?? 0).toLocaleString();
    statsKpiUsage.textContent     = `${(totals.summaryViews ?? 0).toLocaleString()} / ${(totals.autoConfirms ?? 0).toLocaleString()}`;

    // 일별 표 (8 컬럼)
    renderStatsPaginated(statsDailyTbody, daily, 8,
        r => `<tr><td>${escape(r.date)}</td><td>${(r.visitors || 0).toLocaleString()}</td><td>${(r.pageviews || 0).toLocaleString()}</td><td>${(r.newSignups || 0).toLocaleString()}</td><td>${(r.newPayments || 0).toLocaleString()}</td><td>${(r.cancelledSubs || 0).toLocaleString()}</td><td>${(r.summaryViews || 0).toLocaleString()}</td><td>${(r.autoConfirms || 0).toLocaleString()}</td></tr>`,
        '데이터 없음', 'daily');

    // 유입경로 표
    renderStatsPaginated(statsReferrersTbody, referrers, 2,
        r => `<tr><td>${escape(r.source)}</td><td>${(r.count || 0).toLocaleString()}</td></tr>`,
        '데이터 없음', 'referrers');

    // 회원별 활동 표
    renderStatsPaginated(statsMembersTbody, members, 7,
        m => `<tr><td>${escape(m.email || '(unknown)')}</td><td>${escape((m.signupAt || '').slice(0,10) || '—')}</td><td>${(m.payments || 0).toLocaleString()}</td><td>${(m.cancels || 0).toLocaleString()}</td><td>${(m.summaryViews || 0).toLocaleString()}</td><td>${(m.autoConfirms || 0).toLocaleString()}</td><td style="white-space:nowrap;color:var(--fg-secondary);">${escape((m.lastActivity || '').replace('T',' ').slice(0,19) || '—')}</td></tr>`,
        '활동한 회원이 없습니다.', 'members');

    // 활동 로그 (시간순 + 필터)
    _lastStatsEvents = events;
    renderEventsTable();

    // ── 사장님 2026-05-24 확장 — 9개 신규 섹션 렌더 ──
    const jobsStats = payload?.jobsStats || {};
    const memberUsage = Array.isArray(payload?.memberUsage) ? payload.memberUsage : [];
    const planDistribution = payload?.planDistribution || {};
    const dailyRevenue = Array.isArray(payload?.dailyRevenue) ? payload.dailyRevenue : [];
    const totalRevenue = payload?.totalRevenue || 0;
    const mrr = payload?.mrr || 0;
    const arpu = payload?.arpu || 0;
    const mauDau = payload?.mauDau || { mau: 0, dau: 0 };
    const funnel = payload?.funnel || {};
    const providerUsage = payload?.providerUsage || {};
    const autoCharge = payload?.autoChargeStats || {};

    // 확장 KPI 카드
    statsKpiMau.textContent  = `${(mauDau.dau || 0).toLocaleString()} / ${(mauDau.mau || 0).toLocaleString()}`;
    statsKpiMrr.textContent  = fmtKrw(mrr);
    statsKpiArpu.textContent = `ARPU ${fmtKrw(arpu)}`;
    statsKpiRevenue.textContent = fmtKrw(totalRevenue);
    if (jobsStats.sttSuccessRate !== null && jobsStats.sttSuccessRate !== undefined) {
        statsKpiStt.textContent = (jobsStats.sttSuccessRate || 0).toFixed(1) + '%';
    } else {
        statsKpiStt.textContent = '—';
    }
    statsKpiJobs.textContent = `전체 ${(jobsStats.totalJobs || 0).toLocaleString()} / 성공 ${(jobsStats.successJobs || 0).toLocaleString()} / 실패 ${(jobsStats.failedJobs || 0).toLocaleString()}`;
    const avgDur = jobsStats.avgDurationSec, avgLat = jobsStats.avgLatencySec;
    statsKpiAvg.textContent = `${avgDur !== null && avgDur !== undefined ? avgDur + 's' : '—'} / ${avgLat !== null && avgLat !== undefined ? avgLat + 's' : '—'}`;

    // Funnel 표
    const signups = funnel.signups || 0;
    statsFunnelTbody.innerHTML = FUNNEL_STEPS.map(step => {
        const v = funnel[step.key] || 0;
        const pct = signups > 0 ? ((v / signups) * 100).toFixed(1) : '0.0';
        return `<tr>
            <td>${escape(step.label)}</td>
            <td>${v.toLocaleString()}</td>
            <td>${pct}%</td>
        </tr>`;
    }).join('');

    // 플랜 분포 표
    const planTotal = Object.values(planDistribution).reduce((a, b) => a + (Number(b) || 0), 0);
    statsPlansTbody.innerHTML = Object.entries(planDistribution).map(([k, v]) => {
        const c = Number(v) || 0;
        const pct = planTotal > 0 ? ((c / planTotal) * 100).toFixed(1) : '0.0';
        return `<tr>
            <td>${escape(PLAN_LABEL[k] || k)}</td>
            <td>${c.toLocaleString()}</td>
            <td>${pct}%</td>
        </tr>`;
    }).join('');

    // ★ 회원별 요약 사용시간/사용률
    renderStatsPaginated(statsMemberUsageTbody, memberUsage, 9, m => {
        const pct = Number(m.usagePct || 0);
        const barColor = pct >= 100 ? '#c62828' : (pct >= 80 ? '#ef6c00' : '#1565c0');
        const barWidth = Math.min(100, pct);
        const pctBar = `<div style="display:flex;align-items:center;gap:8px;"><div style="flex:1;height:8px;background:#eee;border-radius:4px;overflow:hidden;min-width:80px;"><div style="height:100%;width:${barWidth}%;background:${barColor};"></div></div><span style="font-size:12px;color:${barColor};font-weight:600;">${pct.toFixed(1)}%</span></div>`;
        return `<tr><td>${escape(m.email || '(unknown)')}</td><td>${escape(PLAN_LABEL[m.plan] || m.plan || '—')}</td><td>${escape(m.planStatus || '—')}</td><td>${(m.usedMin || 0).toLocaleString()}</td><td>${(m.limitMin || 0).toLocaleString()}</td><td style="min-width:140px;">${pctBar}</td><td>${m.overageEnabled ? 'ON' : 'OFF'}</td><td>${(m.overageBalMin || 0).toLocaleString()}</td><td style="white-space:nowrap;color:var(--fg-secondary);font-size:12px;">${escape((m.periodEnd || '').slice(0,10) || '—')}</td></tr>`;
    }, '데이터 없음', 'memberUsage');

    // recording_jobs status 분포
    const byStatus = jobsStats.byStatus || {};
    const statusEntries = Object.entries(byStatus);
    statsJobsStatusTbody.innerHTML = statusEntries.length === 0
        ? `<tr><td colspan="2" style="text-align:center;color:var(--fg-tertiary);padding:24px;">데이터 없음</td></tr>`
        : statusEntries.map(([k, v]) => `<tr>
            <td>${escape(STATUS_LABEL_JOBS[k] || k)} <span style="color:var(--fg-tertiary);font-size:11px;">(${escape(k)})</span></td>
            <td>${(Number(v) || 0).toLocaleString()}</td>
        </tr>`).join('');

    // AI provider 사용량
    const providerEntries = Object.entries(providerUsage);
    statsProvidersTbody.innerHTML = providerEntries.length === 0
        ? `<tr><td colspan="2" style="text-align:center;color:var(--fg-tertiary);padding:24px;">데이터 없음</td></tr>`
        : providerEntries.map(([k, v]) => `<tr>
            <td>${escape(k)}</td>
            <td>${(Number(v) || 0).toLocaleString()}</td>
        </tr>`).join('');

    // 자동충전 통계
    statsAutochargeEnabled.textContent = (autoCharge.enabledCount || 0).toLocaleString() + ' 명';
    statsAutochargePct.textContent = `${(autoCharge.enabledPct || 0).toFixed(1)}% / 전체 ${(autoCharge.totalMembers || 0).toLocaleString()}명`;
    statsAutochargeBalance.textContent = (autoCharge.avgBalanceMin || 0).toFixed(1) + ' 분';

    // 매출 추이 chart (line)
    if (typeof window.Chart === 'function' && statsChartRevenueCanvas) {
        if (statsChartRevenueInstance) {
            statsChartRevenueInstance.destroy();
            statsChartRevenueInstance = null;
        }
        statsChartRevenueInstance = new window.Chart(statsChartRevenueCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: dailyRevenue.map(r => r.date),
                datasets: [{
                    label: '일별 매출 (원)',
                    data: dailyRevenue.map(r => r.revenue || 0),
                    borderColor: '#1565c0',
                    backgroundColor: 'rgba(21,101,192,0.12)',
                    fill: true,
                    tension: 0.25,
                    pointRadius: 2,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 8 } },
                    y: { beginAtZero: true, ticks: { callback: (v) => fmtKrw(v) } },
                },
            },
        });
    }

    // 방문자 추이 chart (Chart.js)
    if (typeof window.Chart === 'function' && statsChartCanvas) {
        if (statsChartInstance) {
            statsChartInstance.destroy();
            statsChartInstance = null;
        }
        statsChartInstance = new window.Chart(statsChartCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: daily.map(r => r.date),
                datasets: [{
                    label: '방문자',
                    data: daily.map(r => r.visitors || 0),
                    backgroundColor: 'rgba(200, 54, 44, 0.75)',
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 8 } },
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                },
            },
        });
    }
}

if (statsApplyBtn) {
    statsApplyBtn.addEventListener('click', loadStatsRange);
    document.querySelectorAll('[data-stats-preset]').forEach(btn => {
        btn.addEventListener('click', () => {
            setStatsPreset(btn.dataset.statsPreset);
            loadStatsRange();
        });
    });
    // 활동 로그 필터 버튼
    document.querySelectorAll('[data-events-filter]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-events-filter]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            _eventsFilter = btn.dataset.eventsFilter;
            _statsPage.events = 1;
            renderEventsTable();
        });
    });
    // 통계 탭 처음 진입 시 자동 로드 (최근 30일 default)
    const statsTabBtn = document.querySelector('.tab[data-tab="stats"]');
    if (statsTabBtn) {
        statsTabBtn.addEventListener('click', () => {
            if (statsLoaded) return;
            statsLoaded = true;
            setStatsPreset('30');
            loadStatsRange();
        });
    }
    // 매출 탭 — 진입 시마다 최신 로드.
    const revenueTabBtn = document.querySelector('.tab[data-tab="revenue"]');
    if (revenueTabBtn) revenueTabBtn.addEventListener('click', loadRevenue);
}

function won(n) { return '₩' + Number(n || 0).toLocaleString('ko-KR'); }

let _revData = null, _revSub = 'daily', _revBound = false;
const _revPage = { daily: 1, weekly: 1, monthly: 1, payments: 1 };
const _revRange = { daily: { from: '', to: '' }, weekly: { from: '', to: '' }, monthly: { from: '', to: '' } };
let _revPayQ = '';
const REV_BUCKETS_PER_PAGE = 12, REV_PAYMENTS_PER_PAGE = 25;

function revFmtDate(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function revFmtMonth(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); }
function revInRange(r, sub) {
    const rg = _revRange[sub];
    if (!rg.from && !rg.to) return true;
    const v = sub === 'monthly' ? (r.paid_at || '').slice(0, 7) : (r.paid_at || '').slice(0, 10);
    if (!v) return false;
    if (rg.from && v < rg.from) return false;
    if (rg.to && v > rg.to) return false;
    return true;
}

function revIsoWeek(s) {
    const d = new Date(s.slice(0, 10) + 'T00:00:00');
    const t = new Date(d); t.setDate(t.getDate() + 3 - ((t.getDay() + 6) % 7));
    const w1 = new Date(t.getFullYear(), 0, 4);
    const wk = 1 + Math.round(((t - w1) / 86400000 - 3 + ((w1.getDay() + 6) % 7)) / 7);
    return t.getFullYear() + '-W' + String(wk).padStart(2, '0');
}
function revKeyFn(sub) {
    if (sub === 'daily') return r => (r.paid_at || '').slice(0, 10);
    if (sub === 'weekly') return r => revIsoWeek(r.paid_at || '');
    return r => (r.paid_at || '').slice(0, 7); // monthly
}
function revAggregate(rows, keyFn) {
    const map = {};
    for (const r of rows) {
        const k = keyFn(r);
        if (!k) continue;
        if (!map[k]) map[k] = { key: k, count: 0, revenue: 0, google: 0, vat: 0, cost: 0, net: 0, usage_min: 0 };
        const m = map[k];
        m.count++; m.revenue += r.total; m.google += r.google_fee; m.vat += r.vat;
        m.cost += r.cost; m.net += r.net; m.usage_min += r.usage_min;
    }
    return Object.values(map).sort((a, b) => b.key.localeCompare(a.key)); // 최신 desc
}
function revGraph(buckets) {
    const show = buckets.slice(0, 18).reverse(); // 오래된→최신
    if (!show.length) return '';
    const max = Math.max(1, ...show.map(b => Math.abs(b.net)));
    const bars = show.map(b => {
        const h = Math.round(Math.abs(b.net) / max * 96) + 4;
        const neg = b.net < 0;
        return `<div style="flex:1;min-width:24px;display:flex;flex-direction:column;align-items:center;gap:3px;justify-content:flex-end;">
            <div style="font-size:9px;color:var(--fg-tertiary);white-space:nowrap;">${(b.net / 10000).toFixed(1)}만</div>
            <div title="${won(b.net)}" style="width:62%;height:${h}px;background:${neg ? '#c8362c' : '#1f9d55'};border-radius:3px 3px 0 0;"></div>
            <div style="font-size:9px;color:var(--fg-secondary);white-space:nowrap;">${b.key.replace(/^\d{4}-/, '')}</div>
        </div>`;
    }).join('');
    return `<div style="font-size:12px;color:var(--fg-secondary);margin-bottom:6px;">순마진 추이 (최근 ${show.length})</div>
        <div style="display:flex;align-items:flex-end;gap:4px;height:140px;padding:8px;background:var(--bg-subtle,#f7f5f1);border-radius:10px;overflow-x:auto;margin-bottom:14px;">${bars}</div>`;
}
function revNavPager(ns, page, totalPages) {
    if (totalPages <= 1) return '';
    return `<div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:12px;">
        <button id="rev-${ns}-prev" type="button" class="tiny-btn" ${page <= 1 ? 'disabled' : ''}>◀ 이전</button>
        <span style="font-size:13px;color:var(--fg-secondary);">${page} / ${totalPages}</span>
        <button id="rev-${ns}-next" type="button" class="tiny-btn" ${page >= totalPages ? 'disabled' : ''}>다음 ▶</button>
    </div>`;
}
function revAggTable(buckets) {
    const head = (_revSub === 'daily' ? '날짜' : _revSub === 'weekly' ? '주차' : '월');
    const th = [head, '건수', '매출', 'Google 15%', '부가세', '사용량', 'AI원가', '순마진']
        .map(h => `<th style="text-align:right;padding:8px 10px;font-size:12px;color:var(--fg-secondary);white-space:nowrap;border-bottom:1px solid var(--line,#eee);">${h}</th>`).join('');
    const trs = buckets.map(b => `<tr>
        <td style="text-align:left;padding:8px 10px;font-size:13px;font-weight:600;white-space:nowrap;border-bottom:1px solid var(--line,#f0f0f0);">${b.key}</td>
        <td style="text-align:right;padding:8px 10px;font-size:13px;border-bottom:1px solid var(--line,#f0f0f0);">${b.count}건</td>
        <td style="text-align:right;padding:8px 10px;font-size:13px;border-bottom:1px solid var(--line,#f0f0f0);">${won(b.revenue)}</td>
        <td style="text-align:right;padding:8px 10px;font-size:13px;color:#c8362c;border-bottom:1px solid var(--line,#f0f0f0);">-${won(b.google)}</td>
        <td style="text-align:right;padding:8px 10px;font-size:13px;color:#c8362c;border-bottom:1px solid var(--line,#f0f0f0);">-${won(b.vat)}</td>
        <td style="text-align:right;padding:8px 10px;font-size:13px;border-bottom:1px solid var(--line,#f0f0f0);">${(b.usage_min || 0).toLocaleString()}분</td>
        <td style="text-align:right;padding:8px 10px;font-size:13px;color:#c8362c;border-bottom:1px solid var(--line,#f0f0f0);">-${won(b.cost)}</td>
        <td style="text-align:right;padding:8px 10px;font-size:13px;border-bottom:1px solid var(--line,#f0f0f0);"><b style="color:${b.net >= 0 ? '#1f9d55' : '#c8362c'}">${won(b.net)}</b></td>
    </tr>`).join('');
    return `<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:600px;"><thead><tr>${th}</tr></thead><tbody>${trs}</tbody></table></div>`;
}

async function loadRevenue() {
    const assumpEl = document.getElementById('rev-assumptions');
    const payEl = document.getElementById('rev-payments-view');
    if (payEl) payEl.innerHTML = '<p class="form-help">불러오는 중…</p>';
    try {
        _revData = await apiRequest('admin-revenue');
        const a = _revData.assumptions || {};
        if (assumpEl) assumpEl.textContent =
            `순마진 = 결제액 − Google ${a.google_fee_pct}% − 부가세(10/110) − AI원가 ${a.cost_per_min}원/분 (추정).`;
        if (!_revBound) {
            _revBound = true;
            document.querySelectorAll('#rev-subtabs .rev-sub').forEach(btn => {
                btn.addEventListener('click', () => {
                    _revSub = btn.dataset.rev;
                    document.querySelectorAll('#rev-subtabs .rev-sub').forEach(b => b.classList.toggle('active', b === btn));
                    renderRevAnalysis();
                });
            });
            const rf = document.getElementById('rev-refresh');
            if (rf) rf.addEventListener('click', loadRevenue);
        }
        renderRevPayments();
        renderRevAnalysis();
    } catch (e) {
        if (payEl) payEl.innerHTML = `<p class="form-help" style="color:#c8362c;">매출 조회 실패: ${escape(e.message || String(e))}</p>`;
    }
}

// ── 1. 실시간 결제내역 (최상단) ──
function renderRevPayments() {
    const el = document.getElementById('rev-payments-view');
    if (!el || !_revData) return;
    let rows = (_revData.payments || []).slice(); // 이미 paid_at desc
    const q = _revPayQ.trim();
    if (q) rows = rows.filter(r => (r.paid_at || '').includes(q) || (r.email || '').includes(q));

    const totalPages = Math.max(1, Math.ceil(rows.length / REV_PAYMENTS_PER_PAGE));
    if (_revPage.payments > totalPages) _revPage.payments = totalPages;
    const page = _revPage.payments;
    const slice = rows.slice((page - 1) * REV_PAYMENTS_PER_PAGE, page * REV_PAYMENTS_PER_PAGE);

    const th = ['결제일시', '이메일', '결제액', 'Google 15%', '부가세', '사용량', 'AI원가', '순마진', '상태']
        .map(h => `<th style="text-align:right;padding:8px 10px;font-size:12px;color:var(--fg-secondary);white-space:nowrap;border-bottom:1px solid var(--line,#eee);">${h}</th>`).join('');
    let body = '', lastDay = null;
    for (const r of slice) {
        const day = (r.paid_at || '').slice(0, 10);
        if (day !== lastDay) {
            lastDay = day;
            const dr = rows.filter(x => (x.paid_at || '').slice(0, 10) === day);
            const dRev = dr.reduce((s, x) => s + x.total, 0), dNet = dr.reduce((s, x) => s + x.net, 0);
            body += `<tr><td colspan="9" style="background:#efece7;font-weight:700;font-size:12px;padding:7px 10px;border-top:2px solid #ddd6cc;">📅 ${day} — ${dr.length}건 · 매출 ${won(dRev)} · 순마진 <span style="color:${dNet >= 0 ? '#1f9d55' : '#c8362c'}">${won(dNet)}</span></td></tr>`;
        }
        body += `<tr>
            <td style="text-align:left;padding:7px 10px;font-size:13px;white-space:nowrap;border-bottom:1px solid var(--line,#f0f0f0);">${(r.paid_at || '').replace('T', ' ').slice(0, 16)}</td>
            <td style="text-align:left;padding:7px 10px;font-size:12px;white-space:nowrap;border-bottom:1px solid var(--line,#f0f0f0);">${escape(r.email || '')}</td>
            <td style="text-align:right;padding:7px 10px;font-size:13px;border-bottom:1px solid var(--line,#f0f0f0);">${won(r.total)}</td>
            <td style="text-align:right;padding:7px 10px;font-size:13px;color:#c8362c;border-bottom:1px solid var(--line,#f0f0f0);">-${won(r.google_fee)}</td>
            <td style="text-align:right;padding:7px 10px;font-size:13px;color:#c8362c;border-bottom:1px solid var(--line,#f0f0f0);">-${won(r.vat)}</td>
            <td style="text-align:right;padding:7px 10px;font-size:13px;border-bottom:1px solid var(--line,#f0f0f0);">${(r.usage_min || 0).toLocaleString()}분</td>
            <td style="text-align:right;padding:7px 10px;font-size:13px;color:#c8362c;border-bottom:1px solid var(--line,#f0f0f0);">-${won(r.cost)}</td>
            <td style="text-align:right;padding:7px 10px;font-size:13px;border-bottom:1px solid var(--line,#f0f0f0);"><b style="color:${r.net >= 0 ? '#1f9d55' : '#c8362c'}">${won(r.net)}</b></td>
            <td style="text-align:right;padding:7px 10px;font-size:12px;border-bottom:1px solid var(--line,#f0f0f0);">${r.period_done ? '<span style="color:#1f9d55">확정</span>' : '<span style="color:var(--fg-tertiary)">진행중</span>'}</td>
        </tr>`;
    }
    el.innerHTML = revPaySearchBar()
        + (rows.length ? `<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:760px;"><thead><tr>${th}</tr></thead><tbody>${body}</tbody></table></div>` : '<p class="form-help">아직 결제 내역이 없습니다.</p>')
        + revNavPager('pay', page, totalPages);
    bindRevPaymentsControls();
}

function revPaySearchBar() {
    return `<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
        <input id="rev-pay-search" type="text" value="${escape(_revPayQ)}" placeholder="날짜(YYYY-MM-DD) 또는 이메일 검색"
            style="font-size:13px;padding:6px 10px;min-width:220px;">
        ${_revPayQ ? '<button id="rev-pay-clear" type="button" class="tiny-btn">×</button>' : ''}
    </div>`;
}

function bindRevPaymentsControls() {
    const search = document.getElementById('rev-pay-search');
    if (search) {
        let tmr = null;
        search.addEventListener('input', () => {
            clearTimeout(tmr);
            tmr = setTimeout(() => { _revPayQ = search.value; _revPage.payments = 1; renderRevPayments(); }, 300);
        });
    }
    const clr = document.getElementById('rev-pay-clear');
    if (clr) clr.addEventListener('click', () => { _revPayQ = ''; _revPage.payments = 1; renderRevPayments(); });
    const prev = document.getElementById('rev-pay-prev');
    if (prev) prev.addEventListener('click', () => { if (_revPage.payments > 1) { _revPage.payments--; renderRevPayments(); } });
    const next = document.getElementById('rev-pay-next');
    if (next) next.addEventListener('click', () => { _revPage.payments++; renderRevPayments(); });
}

// ── 2. 매출 분석 (일/주/월 + 기간 선택 + 하단 합계) ──
function renderRevAnalysis() {
    const el = document.getElementById('rev-analysis-view');
    if (!el || !_revData) return;
    const sub = _revSub;
    const rows = (_revData.payments || []).filter(r => revInRange(r, sub));
    const buckets = revAggregate(rows, revKeyFn(sub));

    const totalPages = Math.max(1, Math.ceil(buckets.length / REV_BUCKETS_PER_PAGE));
    if (_revPage[sub] > totalPages) _revPage[sub] = totalPages;
    const page = _revPage[sub];
    const slice = buckets.slice((page - 1) * REV_BUCKETS_PER_PAGE, page * REV_BUCKETS_PER_PAGE);

    el.innerHTML = revRangeBar(sub)
        + (buckets.length
            ? revGraph(buckets) + revAggTable(slice) + revNavPager('an', page, totalPages)
            : '<p class="form-help" style="margin:10px 0;">선택한 기간에 결제 데이터가 없습니다.</p>')
        + revRangeSummary(rows, sub);
    bindRevAnalysisControls(sub);
}

function revRangeBar(sub) {
    const rg = _revRange[sub];
    const type = sub === 'monthly' ? 'month' : 'date';
    const presets = sub === 'monthly'
        ? [['최근 6개월', 'm6'], ['올해', 'ytd']]
        : [['오늘', 'd0'], ['최근 7일', 'd7'], ['최근 30일', 'd30'], ['이번 달', 'mtd']];
    const presetBtns = presets.map(([label, key]) =>
        `<button type="button" class="tiny-btn rev-preset" data-preset="${key}">${label}</button>`).join('');
    return `<div id="rev-range" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
        <span style="font-size:13px;color:var(--fg-secondary);">기간</span>
        <input id="rev-from" type="${type}" value="${rg.from}" style="font-size:13px;padding:6px 8px;border:1px solid var(--line,#e3ddd2);border-radius:8px;">
        <span style="color:var(--fg-tertiary);">~</span>
        <input id="rev-to" type="${type}" value="${rg.to}" style="font-size:13px;padding:6px 8px;border:1px solid var(--line,#e3ddd2);border-radius:8px;">
        ${presetBtns}
        ${(rg.from || rg.to) ? '<button type="button" id="rev-range-clear" class="tiny-btn">전체</button>' : ''}
    </div>`;
}

function revRangeSummary(rows, sub) {
    const s = rows.reduce((a, r) => { a.rev += r.total; a.google += r.google_fee; a.vat += r.vat; a.cost += r.cost; a.net += r.net; a.min += r.usage_min; a.count++; return a; },
        { rev: 0, google: 0, vat: 0, cost: 0, net: 0, min: 0, count: 0 });
    const rg = _revRange[sub];
    const label = (rg.from || rg.to) ? `${rg.from || '처음'} ~ ${rg.to || '지금'}` : '전체 기간';
    return `<div style="margin-top:18px;padding-top:14px;border-top:2px solid var(--line,#e3ddd2);">
        <div style="font-size:13px;color:var(--fg-secondary);margin-bottom:8px;">선택 기간 <b>${label}</b> · 결제 ${s.count}건</div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            ${revStat('매출', won(s.rev), '')}
            ${revStat('Google 수수료', '-' + won(s.google), '')}
            ${revStat('부가세', '-' + won(s.vat), '')}
            ${revStat('AI 원가', '-' + won(s.cost), s.min.toLocaleString() + '분')}
            ${revStat('순마진', won(s.net), '', true)}
        </div>
    </div>`;
}

function revApplyPreset(sub, key) {
    const now = new Date();
    const rg = _revRange[sub];
    if (sub === 'monthly') {
        if (key === 'm6') { rg.from = revFmtMonth(new Date(now.getFullYear(), now.getMonth() - 5, 1)); rg.to = revFmtMonth(now); }
        else if (key === 'ytd') { rg.from = now.getFullYear() + '-01'; rg.to = revFmtMonth(now); }
        return;
    }
    if (key === 'd0') { rg.from = revFmtDate(now); rg.to = revFmtDate(now); }
    else if (key === 'd7') { const f = new Date(now); f.setDate(f.getDate() - 6); rg.from = revFmtDate(f); rg.to = revFmtDate(now); }
    else if (key === 'd30') { const f = new Date(now); f.setDate(f.getDate() - 29); rg.from = revFmtDate(f); rg.to = revFmtDate(now); }
    else if (key === 'mtd') { rg.from = revFmtDate(new Date(now.getFullYear(), now.getMonth(), 1)); rg.to = revFmtDate(now); }
}

function bindRevAnalysisControls(sub) {
    const from = document.getElementById('rev-from');
    if (from) from.addEventListener('change', () => { _revRange[sub].from = from.value; _revPage[sub] = 1; renderRevAnalysis(); });
    const to = document.getElementById('rev-to');
    if (to) to.addEventListener('change', () => { _revRange[sub].to = to.value; _revPage[sub] = 1; renderRevAnalysis(); });
    const clr = document.getElementById('rev-range-clear');
    if (clr) clr.addEventListener('click', () => { _revRange[sub] = { from: '', to: '' }; _revPage[sub] = 1; renderRevAnalysis(); });
    document.querySelectorAll('#rev-range .rev-preset').forEach(btn => {
        btn.addEventListener('click', () => { revApplyPreset(sub, btn.dataset.preset); _revPage[sub] = 1; renderRevAnalysis(); });
    });
    const prev = document.getElementById('rev-an-prev');
    if (prev) prev.addEventListener('click', () => { if (_revPage[sub] > 1) { _revPage[sub]--; renderRevAnalysis(); } });
    const next = document.getElementById('rev-an-next');
    if (next) next.addEventListener('click', () => { _revPage[sub]++; renderRevAnalysis(); });
}

function revStat(label, value, sub, big) {
    return `<div style="flex:1;min-width:120px;padding:12px 14px;background:var(--bg-subtle,#f7f5f1);border-radius:10px;">
        <div style="font-size:12px;color:var(--fg-secondary);">${label}</div>
        <div style="font-size:${big ? '20px' : '17px'};font-weight:800;color:${big ? '#1f9d55' : 'var(--fg)'};margin-top:2px;">${value}</div>
        ${sub ? `<div style="font-size:11px;color:var(--fg-tertiary);margin-top:1px;">${sub}</div>` : ''}
    </div>`;
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

if (aiModelForm) {
    aiModelForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        aiModelSave.disabled = true;
        aiModelMessage.textContent = '저장 중…';
        aiModelMessage.className = 'form-help';
        const llm = (aiModelForm.querySelector('input[name="llm-primary"]:checked') || {}).value || 'anthropic';
        const stt = (aiModelForm.querySelector('input[name="stt-primary"]:checked') || {}).value || 'whisper';
        try {
            await apiRequest('admin-settings', {
                method: 'PUT',
                body: JSON.stringify({
                    resource: 'admin-settings',
                    settings: { llm_primary: llm, stt_primary: stt },
                }),
            });
            aiModelMessage.textContent = '저장되었습니다. 다음 통화 요약부터 적용됩니다.';
            aiModelMessage.className = 'form-help success';
        } catch (error) {
            aiModelMessage.textContent = error.message || '저장 실패';
            aiModelMessage.className = 'form-help error';
        } finally {
            aiModelSave.disabled = false;
        }
    });
}

function applyAiModelSettings(settings) {
    if (!aiModelForm) return;
    const llm = settings.llm_primary === 'together' ? 'together' : 'anthropic';
    const stt = (settings.stt_primary === 'together' || settings.stt_primary === 'groq') ? settings.stt_primary : 'whisper';
    const llmEl = aiModelForm.querySelector(`input[name="llm-primary"][value="${llm}"]`);
    const sttEl = aiModelForm.querySelector(`input[name="stt-primary"][value="${stt}"]`);
    if (llmEl) llmEl.checked = true;
    if (sttEl) sttEl.checked = true;
}

async function loadSettings() {
    try {
        const payload = await apiRequest('admin-settings');
        const settings = payload.settings || {};
        settingSiteName.value = settings.site_name || '';
        settingSiteTagline.value = settings.site_tagline || '';
        settingContactEmail.value = settings.contact_email || '';
        settingNotice.value = settings.notice || '';
        settingSignupEnabled.checked = settings.signup_enabled !== '0';
        applyAiModelSettings(settings);
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

/* ───── 진단 탭: 통화 job 추적 + 서버 에러 로그 ───── */
async function traceJobs() {
    const email = (traceEmail.value || '').trim();
    if (!email) { traceMessage.textContent = '이메일을 입력하세요.'; traceMessage.className = 'form-help error'; return; }
    traceMessage.textContent = '조회 중…'; traceMessage.className = 'form-help';
    try {
        const payload = await apiRequest('admin-job-trace', { query: 'email=' + encodeURIComponent(email) + '&limit=80' });
        const items = Array.isArray(payload.items) ? payload.items : [];
        traceMessage.textContent = `${payload.count ?? items.length}건`;
        traceMessage.className = 'form-help success';
        if (items.length === 0) { traceTable.style.display = 'none'; traceEmpty.classList.remove('hidden'); traceList.innerHTML = ''; return; }
        traceEmpty.classList.add('hidden'); traceTable.style.display = '';
        traceList.innerHTML = items.map(it => {
            const gap = it.gapSec;
            let gapCell = '-', rowStyle = '';
            if (gap != null) {
                if (gap >= 3600) { gapCell = `<b style="color:#c8362c;">${(gap / 3600).toFixed(1)}시간 밀림</b>`; rowStyle = 'background:#fff5f5;'; }
                else if (gap >= 600) { gapCell = `<span style="color:#c8362c;">${Math.round(gap / 60)}분 밀림</span>`; }
                else if (gap >= 0) { gapCell = `${Math.round(gap / 60)}분`; }
                else { gapCell = '즉시'; }
            }
            return `<tr style="${rowStyle}">
                <td style="white-space:nowrap;">${escape((it.recordedAt || '').slice(0, 19))}</td>
                <td style="white-space:nowrap;">${escape((it.usageCountedAt || '').slice(0, 19) || '-')}</td>
                <td style="white-space:nowrap;">${gapCell}</td>
                <td>${it.durationSec ?? 0}</td>
                <td>${escape(it.status || '')}</td>
                <td style="font-size:11px;color:var(--fg-tertiary);">${escape(it.clientReqId || '')}</td>
                <td style="font-size:11px;color:var(--fg-tertiary);">${escape(it.audioSha || '')}</td>
            </tr>`;
        }).join('');
    } catch (e) {
        traceMessage.textContent = e.message || '조회 실패'; traceMessage.className = 'form-help error';
    }
}

async function loadErrors() {
    try {
        const payload = await apiRequest('admin-errors', { query: 'limit=150' });
        const items = Array.isArray(payload.items) ? payload.items : [];
        if (items.length === 0) { errorsTable.style.display = 'none'; errorsEmpty.classList.remove('hidden'); errorsList.innerHTML = ''; return; }
        errorsEmpty.classList.add('hidden'); errorsTable.style.display = '';
        errorsList.innerHTML = items.map(it => `<tr>
            <td style="white-space:nowrap;">${escape((it.createdAt || '').slice(0, 19))}</td>
            <td><span style="font-size:11px;font-weight:600;color:#c8362c;">${escape(it.context || '')}</span></td>
            <td style="max-width:360px;word-break:break-word;">${escape(it.message || '')}</td>
            <td style="font-size:11px;color:var(--fg-tertiary);max-width:240px;word-break:break-word;">${escape(it.detail || '')}</td>
            <td style="font-size:11px;">${escape(it.actor || '')}</td>
        </tr>`).join('');
    } catch (e) {
        errorsTable.style.display = '';
        errorsList.innerHTML = `<tr><td colspan="5" style="color:var(--danger);">${escape(e.message || '로드 실패')}</td></tr>`;
    }
}

async function clearErrors() {
    if (!confirm('서버 에러 로그를 전부 삭제할까요?')) return;
    errorsMessage.textContent = '비우는 중…'; errorsMessage.className = 'form-help';
    try {
        await apiRequest('admin-errors', { method: 'DELETE', body: JSON.stringify({ resource: 'admin-errors' }) });
        errorsMessage.textContent = '비웠습니다.'; errorsMessage.className = 'form-help success';
        loadErrors();
    } catch (e) {
        errorsMessage.textContent = e.message || '실패'; errorsMessage.className = 'form-help error';
    }
}

if (traceBtn) traceBtn.addEventListener('click', traceJobs);
if (traceEmail) traceEmail.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); traceJobs(); } });
if (errorsRefresh) errorsRefresh.addEventListener('click', loadErrors);
if (errorsClear) errorsClear.addEventListener('click', clearErrors);
const _diagTabBtn = document.querySelector('.tab[data-tab="diagnostics"]');
if (_diagTabBtn) _diagTabBtn.addEventListener('click', () => { loadErrors(); });

(async function start() {
    mountAppHeader();
    let { session } = await initSupabase();
    // session 복원 race (asymmetric JWT) — 첫 시도 null 이면 짧게 1회 재시도.
    if (!session) {
        await new Promise(r => setTimeout(r, 400));
        try { session = (await initSupabase()).session; } catch {}
    }
    if (!session) {
        loadingState.classList.add('hidden');
        forbiddenState.classList.remove('hidden');
        return;
    }
    await refreshAppHeader();

    // profile.role 기반 admin (refreshAppHeader 가 body.is-admin 으로 반영) 도 신뢰.
    const adminByProfile = document.body.classList.contains('is-admin');
    if (!isAdmin(session) && !adminByProfile) {
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
