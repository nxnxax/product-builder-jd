/**
 * contracts.js — 계약자 관리대장 (Phase 3)
 *
 * page_type='contract'. 조직도(page_type='org') 의 settings 와 직원 데이터를 읽어
 * 담당자 자동 매핑 + 직급별·타입별 수수료 계산 + 정산 모달.
 *
 * 직급 위계 규칙:
 *  - 팀원 계약 → 팀원/팀장/본부장 모두 자기 직급 수수료 받음
 *  - 팀장 계약 → 팀장(=팀원+팀장 둘 다 받음), 본부장(본부장만)
 *  - 본부장 계약 → 본부장(=팀원+팀장+본부장 셋 다 받음)
 */

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260508-tight';
import { attachColumnFilters, applyColumnFilters, openRowAddModal } from './ledger-shared.js?v=20260509-filter2';

const PAGE_TYPE = 'contract';
const TAX_RATE = 0.033;   // 실수령액 = commission * (1 - TAX_RATE)

const TITLES = ['본부장', '팀장', '팀원'];

const DEFAULT_FIELDS = [
    { key: 'no',        label: 'NO',        type: 'auto_number',   filterable: false, width: 42 },
    { key: 'paid',      label: '수수료',    type: 'pay_switch',    filterable: true,  width: 80 },
    { key: 'manager',   label: '담당자',    type: 'manager_select',filterable: true,  width: 110 },
    { key: 'unitType',  label: '타입',      type: 'text',          filterable: true,  width: 80 },
    { key: 'dong',      label: '동',        type: 'text',          filterable: true,  width: 60 },
    { key: 'ho',        label: '호',        type: 'text',          filterable: true,  width: 60 },
    { key: 'customer',  label: '고객명',    type: 'text',          filterable: true,  width: 100 },
    { key: 'subDate',   label: '청약일',    type: 'date',          filterable: true,  width: 120 },
    { key: 'mainDate',  label: '정계약일',  type: 'date',          filterable: true,  width: 120 },
    { key: 'docs',      label: '서류보완',  type: 'text',          filterable: true,  width: 100 },
    { key: 'phone',     label: '연락처',    type: 'tel',           filterable: false, width: 130 },
    { key: 'commission',label: '수수료/실수령',type: 'commission_view',filterable:false, width: 110 },
    { key: 'status',    label: '계약상태',  type: 'status_switch', filterable: true,  width: 80 },
    { key: 'memo',      label: '비고',      type: 'text',          filterable: false, width: 140 },
];

/* ============== State ============== */
let supabaseClient = null;
let groups = [];
let activeGroupIds = [];
let multiMode = false;
let records = [];
let editingGroupId = null;
let filterState = { filters: {} };
let selectedIds = new Set();

let orgGroups = [];          // page_type=org 의 그룹들 (설정에서 연동 선택용)
let linkedOrgGroupId = null; // 활성 그룹의 settings.linkedOrgGroupId (단일 그룹 선택 시)
let orgEmployees = [];       // 연동 조직도의 직원 레코드들
let orgSettings = null;      // 연동 조직도의 settings (수수료)

/* ============== Boot ============== */
(async function boot() {
    try {
        const result = await initSupabase();
        supabaseClient = result?.client || null;
    } catch (e) { supabaseClient = null; }
    const session = getSession();
    if (!supabaseClient || !session) {
        document.getElementById('content').innerHTML =
            `<div class="empty">로그인이 필요합니다. <a href="index.html" style="color:var(--ledger-accent);font-weight:600;">홈으로 가서 로그인</a></div>`;
        return;
    }
    bindUI();
    await loadOrgIndex();
    await loadGroups();
})();

async function api(resource, opts = {}) {
    return apiRequest(resource, {
        method: opts.method || 'GET',
        body: opts.body ? JSON.stringify(opts.body) : undefined,
        query: opts.query,
    });
}

/* ============== Org index (설정 모달 + 자동 매핑용) ============== */
async function loadOrgIndex() {
    try {
        const data = await api('ledger-groups', { query: 'page_type=org' });
        orgGroups = data.items || [];
    } catch (e) { orgGroups = []; }
}

async function loadOrgEmployees(orgGroupId) {
    if (!orgGroupId) { orgEmployees = []; orgSettings = null; return; }
    try {
        const data = await api('ledger-records', { query: 'group_id=' + orgGroupId });
        orgEmployees = data.items || [];
    } catch (e) { orgEmployees = []; }
    const og = orgGroups.find(g => g.id === orgGroupId);
    orgSettings = og?.settings || null;
}

/* ============== Groups (계약 현장) ============== */
async function loadGroups() {
    try {
        const data = await api('ledger-groups', { query: 'page_type=' + PAGE_TYPE });
        groups = data.items || [];
    } catch (e) {
        showError('현장 로드 실패: ' + e.message);
        return;
    }
    if (groups.length === 0) {
        renderGroupBar();
        document.getElementById('content').innerHTML = `
            <div class="empty">
                <b>아직 등록된 현장이 없습니다.</b><br>
                상단의 <b>+ 새 현장</b> 버튼으로 첫 현장을 만들어 주세요.
            </div>`;
        return;
    }
    const def = groups.find(g => g.isDefault) || groups[0];
    activeGroupIds = [def.id];
    multiMode = false;
    await syncLinkedOrgFromActive();
    renderGroupBar();
    await loadRecords();
}

async function syncLinkedOrgFromActive() {
    if (activeGroupIds.length !== 1) {
        linkedOrgGroupId = null;
        orgEmployees = [];
        orgSettings = null;
        return;
    }
    const g = groups.find(x => x.id === activeGroupIds[0]);
    linkedOrgGroupId = g?.settings?.linkedOrgGroupId || null;
    if (!linkedOrgGroupId && orgGroups.length > 0) {
        linkedOrgGroupId = (orgGroups.find(o => o.isDefault) || orgGroups[0]).id;
    }
    await loadOrgEmployees(linkedOrgGroupId);
}

function renderGroupBar() {
    const pillsEl = document.getElementById('groupPills');
    pillsEl.innerHTML = groups.map(g => {
        const active = activeGroupIds.includes(g.id);
        if (multiMode) {
            return `<label class="group-pill multi-mode ${active ? 'active' : ''}">
                <input type="checkbox" data-gid="${g.id}" ${active ? 'checked' : ''}>${escapeHtml(g.name)}${g.isDefault ? ' ★' : ''}
            </label>`;
        }
        return `<button type="button" class="group-pill ${active ? 'active' : ''}" data-gid="${g.id}">
            ${escapeHtml(g.name)}${g.isDefault ? ' ★' : ''}
        </button>`;
    }).join('');

    pillsEl.querySelectorAll('button.group-pill').forEach(b => {
        b.addEventListener('click', async () => {
            activeGroupIds = [parseInt(b.dataset.gid, 10)];
            await syncLinkedOrgFromActive();
            renderGroupBar();
            await loadRecords();
        });
    });
    pillsEl.querySelectorAll('input[type=checkbox][data-gid]').forEach(cb => {
        cb.addEventListener('change', async () => {
            const gid = parseInt(cb.dataset.gid, 10);
            if (cb.checked) activeGroupIds = Array.from(new Set([...activeGroupIds, gid]));
            else activeGroupIds = activeGroupIds.filter(id => id !== gid);
            if (activeGroupIds.length === 0 && groups.length > 0) activeGroupIds = [groups[0].id];
            await syncLinkedOrgFromActive();
            await loadRecords();
            renderGroupBar();
        });
    });

    const multiBtn = document.getElementById('multiToggleBtn');
    multiBtn.textContent = multiMode ? '✓ 멀티 선택' : '멀티 선택';
    multiBtn.classList.toggle('primary', multiMode);
}

function bindUI() {
    document.getElementById('multiToggleBtn').addEventListener('click', async () => {
        multiMode = !multiMode;
        if (!multiMode && activeGroupIds.length > 1) activeGroupIds = activeGroupIds.slice(0, 1);
        await syncLinkedOrgFromActive();
        renderGroupBar();
        await loadRecords();
    });
    document.getElementById('newGroupBtn').addEventListener('click', () => openGroupModal(null));
    document.getElementById('editGroupBtn').addEventListener('click', () => {
        if (activeGroupIds.length === 1) openGroupModal(activeGroupIds[0]);
        else alert('편집할 현장 하나만 선택해주세요.');
    });
    document.getElementById('settingsBtn').addEventListener('click', openSettingsModal);
    document.getElementById('settleBtn').addEventListener('click', openSettleModal);

    document.getElementById('groupCancelBtn').addEventListener('click', () => closeModal('groupModal'));
    document.getElementById('groupSaveBtn').addEventListener('click', saveGroup);
    document.getElementById('groupDeleteBtn').addEventListener('click', deleteGroup);

    document.getElementById('settingsCancelBtn').addEventListener('click', () => closeModal('settingsModal'));
    document.getElementById('settingsSaveBtn').addEventListener('click', saveSettings);

    document.getElementById('settleCloseBtn').addEventListener('click', () => closeModal('settleModal'));
    document.getElementById('settleMarkPaidBtn').addEventListener('click', markPaidFromSettle);

    document.getElementById('bulkClearBtn').addEventListener('click', () => { selectedIds.clear(); renderRecords(); });
    document.getElementById('bulkDeleteBtn').addEventListener('click', bulkDelete);
}

/* ============== Group modal ============== */
function openGroupModal(groupId) {
    editingGroupId = groupId;
    const g = groupId ? groups.find(x => x.id === groupId) : null;
    document.getElementById('groupModalTitle').textContent = g ? '현장 편집' : '새 현장 만들기';
    document.getElementById('groupNameInput').value = g?.name || '';
    document.getElementById('groupIsDefaultInput').checked = !!g?.isDefault;
    document.getElementById('groupDeleteBtn').style.display = g ? '' : 'none';
    document.getElementById('groupErrorMsg').textContent = '';
    document.getElementById('groupModal').classList.remove('hidden');
}

async function saveGroup() {
    const name = document.getElementById('groupNameInput').value.trim();
    const isDefault = document.getElementById('groupIsDefaultInput').checked;
    if (!name) { document.getElementById('groupErrorMsg').textContent = '현장 이름을 입력해주세요.'; return; }
    try {
        if (editingGroupId) {
            await api('ledger-groups', { method: 'PATCH', body: { id: editingGroupId, name, isDefault } });
        } else {
            const created = await api('ledger-groups', {
                method: 'POST',
                body: {
                    pageType: PAGE_TYPE, name, isDefault,
                    fieldSchema: { fields: DEFAULT_FIELDS },
                    settings: { linkedOrgGroupId: (orgGroups[0] || {}).id || null },
                },
            });
            activeGroupIds = [created.id];
        }
        closeModal('groupModal');
        await loadGroups();
    } catch (e) {
        document.getElementById('groupErrorMsg').textContent = e.message;
    }
}

async function deleteGroup() {
    if (!editingGroupId) return;
    if (!confirm('이 현장과 그 안의 모든 계약을 영구 삭제합니다. 진행하시겠습니까?')) return;
    try {
        await api('ledger-groups', { method: 'DELETE', body: { id: editingGroupId } });
        closeModal('groupModal');
        activeGroupIds = [];
        await loadGroups();
    } catch (e) {
        document.getElementById('groupErrorMsg').textContent = e.message;
    }
}

/* ============== Settings modal ============== */
function openSettingsModal() {
    if (activeGroupIds.length !== 1) { alert('설정은 현장 하나에만 적용됩니다. 현장 한 개만 선택한 상태에서 열어주세요.'); return; }
    const g = groups.find(x => x.id === activeGroupIds[0]);
    if (!g) return;
    document.getElementById('settingsGroupName').textContent = g.name;
    const sel = document.getElementById('linkedOrgSelect');
    sel.innerHTML = '<option value="">— 조직도 그룹 선택 —</option>' +
        orgGroups.map(o => `<option value="${o.id}" ${(g.settings?.linkedOrgGroupId === o.id) ? 'selected' : ''}>${escapeHtml(o.name)}${o.isDefault ? ' ★' : ''}</option>`).join('');
    if (orgGroups.length === 0) {
        sel.insertAdjacentHTML('afterend', '<p class="desc" style="color:#b91c1c;margin-top:6px;">아직 조직도 그룹이 없습니다. <a href="org.html" style="color:var(--ledger-accent);">조직도 페이지</a>에서 먼저 만들어 주세요.</p>');
    }
    document.getElementById('settingsErrorMsg').textContent = '';
    document.getElementById('settingsModal').classList.remove('hidden');
}

async function saveSettings() {
    const g = groups.find(x => x.id === activeGroupIds[0]);
    if (!g) return;
    const linkedId = parseInt(document.getElementById('linkedOrgSelect').value, 10) || null;
    const newSettings = { ...(g.settings || {}), linkedOrgGroupId: linkedId };
    try {
        await api('ledger-groups', { method: 'PATCH', body: { id: g.id, settings: newSettings } });
        g.settings = newSettings;
        linkedOrgGroupId = linkedId;
        await loadOrgEmployees(linkedId);
        closeModal('settingsModal');
        renderRecords();
    } catch (e) {
        document.getElementById('settingsErrorMsg').textContent = e.message;
    }
}

/* ============== Records ============== */
async function loadRecords() {
    if (activeGroupIds.length === 0) { records = []; renderRecords(); return; }
    try {
        const data = await api('ledger-records', { query: 'group_ids=' + activeGroupIds.join(',') });
        records = data.items || [];
    } catch (e) {
        showError('레코드 로드 실패: ' + e.message);
        records = [];
    }
    selectedIds.clear();
    renderRecords();
}

function renderRecords() {
    const content = document.getElementById('content');
    if (activeGroupIds.length === 0) { content.innerHTML = `<div class="empty">현장을 선택해주세요.</div>`; return; }

    // 멀티 모드면 그룹별 섹션, 단일 모드면 한 섹션.
    const sections = activeGroupIds.map(gid => {
        const g = groups.find(x => x.id === gid);
        const grpRecs = records.filter(r => r.groupId === gid);
        const filtered = applyFilters(grpRecs);
        return renderSection(g, filtered);
    }).join('');
    content.innerHTML = sections;
    bindTableEvents();
    updateBulkBar();
}

function applyFilters(rows) {
    return applyColumnFilters(filterState.filters, rows, (r, k) => r.data?.[k]);
}

function renderSection(group, rows) {
    if (!group) return '';
    return `
        <section class="contract-section" data-gid="${group.id}">
            <div class="contract-head">
                <div>
                    <h3>${escapeHtml(group.name)}</h3>
                    <span class="count">${rows.length}건</span>
                </div>
                <div class="actions">
                    <button class="tiny-btn primary" type="button" data-add-row="${group.id}">+ 계약 추가</button>
                </div>
            </div>
            <div class="tbl-wrap">
                <table class="ledger-tbl">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" data-select-all="${group.id}"></th>
                            ${DEFAULT_FIELDS.map(f => `<th style="min-width:${f.width || 90}px;" data-col-key="${f.key}">${escapeHtml(f.label)}</th>`).join('')}
                            <th class="col-action"></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.length === 0
                            ? `<tr><td colspan="${DEFAULT_FIELDS.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">필터 결과가 없습니다.</td></tr>`
                            : rows.map((r, i) => renderRow(r, i + 1, group.id)).join('')}
                    </tbody>
                </table>
            </div>
        </section>`;
}

function renderRow(r, displayNo, gid) {
    const d = r.data || {};
    return `
        <tr data-id="${r.id}" data-gid="${gid}" class="${selectedIds.has(r.id) ? 'selected' : ''}">
            <td class="col-check"><input type="checkbox" data-select="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''}></td>
            ${DEFAULT_FIELDS.map(f => `<td>${renderCell(f, r, d, displayNo)}</td>`).join('')}
            <td class="col-action"><button class="row-action-btn" data-delete-row="${r.id}" title="삭제">×</button></td>
        </tr>`;
}

function renderCell(f, r, d, displayNo) {
    const id = r.id;
    if (f.type === 'auto_number') return `<span class="col-no">${displayNo}</span>`;
    if (f.type === 'pay_switch') {
        const unpaid = !!d.paid_unpaid;   // true = 미지급(on), false = 지급(off)
        return `<button class="pay-switch ${unpaid ? 'unpaid' : 'paid'}" data-pay-switch data-id="${id}">${unpaid ? '미지급' : '지급'}</button>`;
    }
    if (f.type === 'status_switch') {
        const v = d.status || '';
        const cls = v === 'active' ? 'active' : v === 'cancel' ? 'cancel' : '';
        const lbl = v === 'active' ? '정계약' : v === 'cancel' ? '해지' : '미정';
        return `<button class="status-pill ${cls}" data-status-switch data-id="${id}">${lbl}</button>`;
    }
    if (f.type === 'manager_select') {
        const opts = ['<option value="">-</option>']
            .concat(orgEmployees.map(e => {
                const name = e.data?.name || '';
                const team = e.data?.team || '';
                const title = e.data?.title || '';
                return `<option value="${escapeAttr(name)}" ${d.manager === name ? 'selected' : ''}>${escapeHtml(name)} (${team}팀·${title})</option>`;
            }))
            .join('');
        return `<select data-field="manager" data-id="${id}">${opts}</select>`;
    }
    if (f.type === 'commission_view') {
        const calc = computeCommissionForRow(d);
        if (!calc.amount) return `<span style="color:#a3a39a;font-size:11px;">-</span>`;
        return `<span class="commission-cell">₩${formatNum(calc.amount)}<span class="net">→ ₩${formatNum(calc.net)}</span></span>`;
    }
    if (f.type === 'date') return `<input type="date" data-field="${f.key}" data-id="${id}" value="${escapeAttr(d[f.key] || '')}">`;
    if (f.type === 'tel')  return `<input type="tel"  data-field="${f.key}" data-id="${id}" value="${escapeAttr(d[f.key] || '')}" placeholder="010-...">`;
    return `<input type="text" data-field="${f.key}" data-id="${id}" value="${escapeAttr(d[f.key] || '')}" placeholder="${escapeAttr(f.label)}">`;
}

function bindTableEvents() {
    attachColumnFilters({
        state: filterState,
        headers: document.querySelectorAll('.ledger-tbl thead th[data-col-key]'),
        fields: DEFAULT_FIELDS,
        getRows: () => records,
        getValue: (r, k) => r.data?.[k],
        onChange: () => renderRecords(),
    });
    document.querySelectorAll('[data-add-row]').forEach(b => {
        b.addEventListener('click', () => addRow(parseInt(b.dataset.addRow, 10)));
    });
    document.querySelectorAll('[data-field][data-id]').forEach(el => {
        el.addEventListener('change', () => updateRowField(parseInt(el.dataset.id, 10), el.dataset.field, el.value));
    });
    document.querySelectorAll('[data-pay-switch]').forEach(b => {
        b.addEventListener('click', () => togglePay(parseInt(b.dataset.id, 10)));
    });
    document.querySelectorAll('[data-status-switch]').forEach(b => {
        b.addEventListener('click', () => cycleStatus(parseInt(b.dataset.id, 10)));
    });
    document.querySelectorAll('[data-delete-row]').forEach(b => {
        b.addEventListener('click', () => deleteRow(parseInt(b.dataset.deleteRow, 10)));
    });
    document.querySelectorAll('[data-select]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = parseInt(cb.dataset.select, 10);
            if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
            cb.closest('tr')?.classList.toggle('selected', cb.checked);
            updateBulkBar();
        });
    });
    document.querySelectorAll('[data-select-all]').forEach(cb => {
        cb.addEventListener('change', () => {
            const gid = parseInt(cb.dataset.selectAll, 10);
            const targets = applyFilters(records.filter(r => r.groupId === gid));
            targets.forEach(r => cb.checked ? selectedIds.add(r.id) : selectedIds.delete(r.id));
            renderRecords();
        });
    });
}

function addRow(gid) {
    openRowAddModal({
        title: '새 계약 추가',
        fields: DEFAULT_FIELDS,
        defaults: { paid_unpaid: true, status: 'active' },
        customRender: (f) => {
            const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
            if (f.type === 'manager_select') {
                const opts = ['<option value="">-</option>']
                    .concat(orgEmployees.map(e => {
                        const name = e.data?.name || '';
                        const team = e.data?.team || '?';
                        const title = e.data?.title || '';
                        return `<option value="${escapeAttr(name)}">${escapeHtml(name)} (${team}팀·${title})</option>`;
                    })).join('');
                return `<div class="modal-row">${lbl}<div class="row-control"><select data-field="manager" style="width:100%">${opts}</select></div></div>`;
            }
            if (f.type === 'status_switch') {
                return `<div class="modal-row">${lbl}<div class="row-control"><select data-field="status" style="width:100%">
                    <option value="">미정</option>
                    <option value="active" selected>정계약</option>
                    <option value="cancel">해지</option>
                </select></div></div>`;
            }
            if (f.type === 'pay_switch') {
                return `<div class="modal-row">${lbl}<div class="row-control"><label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#4f4943;font-weight:400;cursor:pointer;margin:0;">
                    <input type="checkbox" data-field="paid_unpaid" checked style="width:auto;accent-color:#c8362c;"> 미지급 (체크 해제 시 지급완료로 시작)
                </label></div></div>`;
            }
            return null;
        },
        onSubmit: async (data) => {
            await api('ledger-records', { method: 'POST', body: { groupId: gid, data, source: 'web' } });
            await loadRecords();
        },
    });
}

async function updateRowField(id, field, value) {
    try {
        await api('ledger-records', { method: 'PATCH', body: { id, data: { [field]: value } } });
        const r = records.find(x => x.id === id);
        if (r) { r.data = r.data || {}; r.data[field] = value; }
        // 담당자/타입 바뀌면 수수료 셀도 다시 그려야 함.
        if (field === 'manager' || field === 'unitType') renderRecords();
    } catch (e) {
        showError('저장 실패: ' + e.message);
        await loadRecords();
    }
}

async function togglePay(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    const next = !r.data?.paid_unpaid;
    await updateRowField(id, 'paid_unpaid', next);
    renderRecords();
}

async function cycleStatus(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    const cur = r.data?.status || '';
    const next = cur === '' ? 'active' : cur === 'active' ? 'cancel' : '';
    await updateRowField(id, 'status', next);
    renderRecords();
}

async function deleteRow(id) {
    if (!confirm('이 계약을 삭제할까요?')) return;
    try {
        await api('ledger-records', { method: 'DELETE', body: { id } });
        await loadRecords();
    } catch (e) { showError('삭제 실패: ' + e.message); }
}

async function bulkDelete() {
    const ids = [...selectedIds];
    if (ids.length === 0) return;
    if (!confirm(`선택한 ${ids.length}건을 삭제합니다. 진행할까요?`)) return;
    try {
        await api('ledger-records-bulk', { method: 'POST', body: { ids } });
        selectedIds.clear();
        await loadRecords();
    } catch (e) { showError('일괄 삭제 실패: ' + e.message); }
}

function updateBulkBar() {
    const bar = document.getElementById('bulkBar');
    document.getElementById('bulkCount').textContent = selectedIds.size;
    bar.classList.toggle('active', selectedIds.size > 0);
}

/* ============== Commission lookup ============== */
function findEmployeeByName(name) {
    if (!name) return null;
    return orgEmployees.find(e => (e.data?.name || '').trim() === String(name).trim()) || null;
}

function commissionTable(unitType) {
    if (!orgSettings) return null;
    const t = (unitType || '').trim();
    if (t && Array.isArray(orgSettings.type_commissions)) {
        const hit = orgSettings.type_commissions.find(x => x.type === t);
        if (hit) return { '본부장': +hit['본부장']||0, '팀장': +hit['팀장']||0, '팀원': +hit['팀원']||0 };
    }
    const def = orgSettings.default_commissions || {};
    return { '본부장': +def['본부장']||0, '팀장': +def['팀장']||0, '팀원': +def['팀원']||0 };
}

/** 한 행의 (담당자가 받는) 수수료를 계산. 행 셀에 표시. */
function computeCommissionForRow(d) {
    const emp = findEmployeeByName(d?.manager);
    if (!emp) return { amount: 0, net: 0 };
    const tbl = commissionTable(d?.unitType);
    if (!tbl) return { amount: 0, net: 0 };
    const role = emp.data?.title || '';
    let amount = 0;
    if (role === '팀원')   amount = tbl['팀원'];
    if (role === '팀장')   amount = tbl['팀원'] + tbl['팀장'];
    if (role === '본부장') amount = tbl['팀원'] + tbl['팀장'] + tbl['본부장'];
    return { amount, net: Math.round(amount * (1 - TAX_RATE)) };
}

/* ============== Settle modal ============== */
function openSettleModal() {
    // 정산 대상: 현재 보이는(필터링된) 계약 중 paid_unpaid=true (미지급) AND status='active' 또는 빈값
    const visibleByGroup = activeGroupIds.map(gid => ({
        gid,
        rows: applyFilters(records.filter(r => r.groupId === gid && r.data?.paid_unpaid && r.data?.status !== 'cancel')),
    }));
    const all = visibleByGroup.flatMap(x => x.rows);

    if (all.length === 0) {
        document.getElementById('settleSummary').textContent = '정산 대상 계약이 없습니다 (필터링된 행 중 미지급 + 해지 아닌 계약만 정산).';
        document.getElementById('settleBody').innerHTML = '';
        document.getElementById('settleModal').classList.remove('hidden');
        return;
    }
    if (!orgSettings) {
        document.getElementById('settleSummary').textContent = '연동 조직도 그룹이 없거나 설정이 없어 정산할 수 없습니다. 설정에서 먼저 연동해 주세요.';
        document.getElementById('settleBody').innerHTML = '';
        document.getElementById('settleModal').classList.remove('hidden');
        return;
    }

    // 위계 규칙으로 payout 빌드: { recipientEmpId, contractId, role, amount, contractLabel }
    const payouts = [];
    all.forEach(r => {
        const d = r.data || {};
        const emp = findEmployeeByName(d.manager);
        if (!emp) return;
        const tbl = commissionTable(d.unitType);
        if (!tbl) return;
        const role = emp.data?.title || '';
        const team = parseInt(emp.data?.team, 10);
        const teamLead = orgEmployees.find(e => e.data?.title === '팀장' && parseInt(e.data?.team, 10) === team);
        const head = orgEmployees.find(e => e.data?.title === '본부장');
        const label = `${d.dong || '?'}동 ${d.ho || '?'}호 (${d.customer || '?'})`;

        if (role === '팀원') {
            payouts.push({ emp, role: '팀원', amount: tbl['팀원'], cid: r.id, label });
            if (teamLead) payouts.push({ emp: teamLead, role: '팀장', amount: tbl['팀장'], cid: r.id, label });
            if (head)     payouts.push({ emp: head,     role: '본부장', amount: tbl['본부장'], cid: r.id, label });
        } else if (role === '팀장') {
            payouts.push({ emp, role: '팀원', amount: tbl['팀원'], cid: r.id, label });
            payouts.push({ emp, role: '팀장', amount: tbl['팀장'], cid: r.id, label });
            if (head)     payouts.push({ emp: head, role: '본부장', amount: tbl['본부장'], cid: r.id, label });
        } else if (role === '본부장') {
            payouts.push({ emp, role: '팀원', amount: tbl['팀원'], cid: r.id, label });
            payouts.push({ emp, role: '팀장', amount: tbl['팀장'], cid: r.id, label });
            payouts.push({ emp, role: '본부장', amount: tbl['본부장'], cid: r.id, label });
        }
    });

    // 직급별 → 직원별 그룹핑
    const byRole = { '본부장': new Map(), '팀장': new Map(), '팀원': new Map() };
    payouts.forEach(p => {
        const empMap = byRole[p.emp.data?.title] || byRole['팀원'];
        if (!empMap.has(p.emp.id)) empMap.set(p.emp.id, { emp: p.emp, lines: [] });
        empMap.get(p.emp.id).lines.push(p);
    });

    let html = '';
    let grandTotal = 0;
    let grandNet = 0;
    let grandContracts = new Set();
    TITLES.forEach(title => {
        const empMap = byRole[title];
        if (empMap.size === 0) return;
        html += `<div class="settle-section">
            <div class="settle-section-head">${title} <span style="color:#8a847e;font-size:12px;font-weight:500;">${empMap.size}명</span></div>`;
        empMap.forEach(({ emp, lines }) => {
            const totalRaw = lines.reduce((s, l) => s + l.amount, 0);
            const net = Math.round(totalRaw * (1 - TAX_RATE));
            grandTotal += totalRaw;
            grandNet += net;
            lines.forEach(l => grandContracts.add(l.cid));
            const account = emp.data?.account || '';
            html += `<div class="settle-emp">
                <div class="settle-emp-head">
                    <span><b>${escapeHtml(emp.data?.name || '-')}</b><span class="badge">${escapeHtml((emp.data?.team || '?') + '팀 · ' + (emp.data?.title || ''))}</span></span>
                    <span style="color:#8a847e;font-size:11.5px;">${lines.length}건</span>
                </div>
                <ul class="settle-row-list">
                    ${lines.map((l, i) => `
                        <li>
                            <span style="font-size:11.5px;color:#4f4943;">${escapeHtml(l.role)} · ${escapeHtml(l.label)}</span>
                            <input type="number" data-payout-edit="${i}" data-emp="${emp.id}" data-cid="${l.cid}" data-role="${escapeAttr(l.role)}" value="${l.amount}" min="0" step="10000">
                        </li>`).join('')}
                </ul>
                <div class="settle-totals">
                    <div class="stat">건별 수수료 합<b data-sum-emp="${emp.id}">₩${formatNum(totalRaw)}</b></div>
                    <div class="stat">실수령액 (3.3% 차감)<b class="net" data-net-emp="${emp.id}">₩${formatNum(net)}</b></div>
                    <div class="stat">계좌번호<b style="font-size:12px;font-family:ui-monospace,Menlo,monospace;">${escapeHtml(account || '미입력')}</b></div>
                </div>
            </div>`;
        });
        html += `</div>`;
    });

    document.getElementById('settleSummary').innerHTML = `
        총 <b>${grandContracts.size}건</b> 계약 / 총 지급 <b style="color:var(--ledger-accent);">₩${formatNum(grandTotal)}</b>
        / 총 실수령 <b style="color:var(--ledger-accent);">₩${formatNum(grandNet)}</b>
    `;
    document.getElementById('settleBody').innerHTML = html;

    // 인라인 편집: 직원 합계 / 실수령 자동 갱신
    document.querySelectorAll('[data-payout-edit]').forEach(inp => {
        inp.addEventListener('input', () => {
            const empId = inp.dataset.emp;
            const sumEl = document.querySelector(`[data-sum-emp="${empId}"]`);
            const netEl = document.querySelector(`[data-net-emp="${empId}"]`);
            const allInps = document.querySelectorAll(`[data-payout-edit][data-emp="${empId}"]`);
            let sum = 0;
            allInps.forEach(i => sum += parseInt(i.value, 10) || 0);
            sumEl.textContent = '₩' + formatNum(sum);
            netEl.textContent = '₩' + formatNum(Math.round(sum * (1 - TAX_RATE)));
        });
    });

    // 모달이 한 번에 처리할 계약 ID 들 저장
    document.getElementById('settleModal').dataset.contractIds = JSON.stringify([...grandContracts]);
    document.getElementById('settleModal').classList.remove('hidden');
}

async function markPaidFromSettle() {
    const raw = document.getElementById('settleModal').dataset.contractIds || '[]';
    const ids = JSON.parse(raw);
    if (!Array.isArray(ids) || ids.length === 0) { closeModal('settleModal'); return; }
    if (!confirm(`${ids.length}건 계약을 "지급 완료" 로 변경합니다. 진행할까요?`)) return;
    try {
        for (const id of ids) {
            await api('ledger-records', { method: 'PATCH', body: { id, data: { paid_unpaid: false } } });
        }
        closeModal('settleModal');
        await loadRecords();
    } catch (e) { alert('일괄 업데이트 실패: ' + e.message); }
}

/* ============== Utils ============== */
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s) { return String(s ?? '').replace(/"/g, '&quot;'); }
function formatNum(n) { return Number(n || 0).toLocaleString('ko-KR'); }
function showError(msg) {
    console.error(msg);
    const c = document.getElementById('content');
    if (c) c.insertAdjacentHTML('afterbegin', `<div style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:13px;">${escapeHtml(msg)}</div>`);
}
