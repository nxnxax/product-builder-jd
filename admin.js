import {
    initSupabase,
    apiRequest,
    mountAppHeader,
    refreshAppHeader,
    isAdmin,
    getInitial,
} from './auth-shared.js?v=20260517-session-persist';

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
};
const STATS_EVENT_COLOR = {
    signup:       'background:#e8f5e9;color:#2e7d32;',
    payment:      'background:#e3f2fd;color:#1565c0;',
    cancel:       'background:#fbe9e7;color:#c62828;',
    summary_view: 'background:#fff3e0;color:#ef6c00;',
    auto_confirm: 'background:#f3e5f5;color:#6a1b9a;',
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

function renderEventsTable() {
    const list = _eventsFilter === 'all' ? _lastStatsEvents : _lastStatsEvents.filter(e => e.type === _eventsFilter);
    renderStatsPaginated(statsEventsTbody, list, 4,
        e => `<tr><td style="white-space:nowrap;">${escape((e.occurred_at || '').replace('T',' ').slice(0,19))}</td><td>${statsBadge(e.type)}</td><td>${escape(e.email || '(unknown)')}</td><td style="color:var(--fg-secondary);">${escape(e.detail || '')}</td></tr>`,
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
