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
let expandedGroupIds = new Set();
let selectedExtraIds = new Set();   // 기본 외 추가 표시 중인 그룹들
let extraPanelOpen = false;
let records = [];
let editingGroupId = null;
let settingsGroupId = null;
let filterState = { filters: {} };
let selectedIds = new Set();

let orgGroups = [];                  // page_type=org 의 그룹들 (설정에서 연동 선택용)
const orgEmployeesByGroup = new Map(); // gid → employees[]
const orgSettingsByGroup = new Map();  // gid → settings

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

async function loadOrgEmployeesForGroup(orgGroupId) {
    if (!orgGroupId) return;
    if (orgEmployeesByGroup.has(orgGroupId)) return; // cached
    try {
        const data = await api('ledger-records', { query: 'group_id=' + orgGroupId });
        orgEmployeesByGroup.set(orgGroupId, data.items || []);
    } catch (e) { orgEmployeesByGroup.set(orgGroupId, []); }
    const og = orgGroups.find(g => g.id === orgGroupId);
    orgSettingsByGroup.set(orgGroupId, og?.settings || null);
}

/** 계약 그룹의 연동 org group id 결정 (없으면 사용자 default org). */
function linkedOrgIdFor(group) {
    let id = group?.settings?.linkedOrgGroupId || null;
    if (!id && orgGroups.length > 0) {
        id = (orgGroups.find(o => o.isDefault) || orgGroups[0]).id;
    }
    return id;
}

function orgEmployeesFor(group) { return orgEmployeesByGroup.get(linkedOrgIdFor(group)) || []; }
function orgSettingsFor(group)  { return orgSettingsByGroup.get(linkedOrgIdFor(group))  || null; }

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
        document.getElementById('content').innerHTML = `
            <div class="empty">
                <b>아직 등록된 현장이 없습니다.</b><br>
                상단의 <b>+ 새 현장</b> 버튼으로 첫 현장을 만들어 주세요.
            </div>`;
        return;
    }
    if (expandedGroupIds.size === 0) {
        const def = groups.find(g => g.isDefault) || groups[0];
        expandedGroupIds.add(def.id);
    }
    // 모든 그룹의 연동 org employees 미리 로드 (담당자 dropdown · 정산 계산용)
    await Promise.all(groups.map(g => loadOrgEmployeesForGroup(linkedOrgIdFor(g))));
    await loadRecords();
}

function bindUI() {
    document.getElementById('newGroupBtn').addEventListener('click', () => openGroupModal(null));
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
            expandedGroupIds.add(created.id);
            if (!isDefault) selectedExtraIds.add(created.id);
            extraPanelOpen = true;
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
        expandedGroupIds.delete(editingGroupId);
        selectedExtraIds.delete(editingGroupId);
        await loadGroups();
    } catch (e) {
        document.getElementById('groupErrorMsg').textContent = e.message;
    }
}

/* ============== Settings modal ============== */
function openSettingsModal(groupId) {
    settingsGroupId = groupId;
    const g = groups.find(x => x.id === groupId);
    if (!g) return;
    document.getElementById('settingsGroupName').textContent = g.name;
    const sel = document.getElementById('linkedOrgSelect');
    sel.innerHTML = '<option value="">— 조직도 그룹 선택 —</option>' +
        orgGroups.map(o => `<option value="${o.id}" ${(g.settings?.linkedOrgGroupId === o.id) ? 'selected' : ''}>${escapeHtml(o.name)}${o.isDefault ? ' ★' : ''}</option>`).join('');
    document.getElementById('settingsErrorMsg').textContent = orgGroups.length === 0
        ? '아직 조직도 그룹이 없습니다. 조직도 페이지에서 먼저 만들어 주세요.' : '';
    document.getElementById('settingsModal').classList.remove('hidden');
}

async function saveSettings() {
    const g = groups.find(x => x.id === settingsGroupId);
    if (!g) return;
    const linkedId = parseInt(document.getElementById('linkedOrgSelect').value, 10) || null;
    const newSettings = { ...(g.settings || {}), linkedOrgGroupId: linkedId };
    try {
        await api('ledger-groups', { method: 'PATCH', body: { id: g.id, settings: newSettings } });
        g.settings = newSettings;
        await loadOrgEmployeesForGroup(linkedId);
        closeModal('settingsModal');
        renderRecords();
    } catch (e) {
        document.getElementById('settingsErrorMsg').textContent = e.message;
    }
}

/* ============== Records ============== */
async function loadRecords() {
    if (groups.length === 0) { records = []; renderRecords(); return; }
    try {
        const allIds = groups.map(g => g.id).join(',');
        const data = await api('ledger-records', { query: 'group_ids=' + allIds });
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
    if (groups.length === 0) return;

    const mainGroup = groups.find(g => g.isDefault) || groups[0];
    const others = groups.filter(g => g.id !== mainGroup.id);

    let html = '';
    if (others.length > 0) html += renderExtraPicker(others);
    html += renderGroupCard(mainGroup);
    others.filter(g => selectedExtraIds.has(g.id)).forEach(g => {
        html += renderGroupCard(g);
    });
    content.innerHTML = html;

    bindAccordionEvents();
    bindExtraPickerEvents();
    bindTableEvents();
    updateBulkBar();
}

function renderExtraPicker(others) {
    const showing = others.filter(g => selectedExtraIds.has(g.id)).length;
    return `
        <div class="extra-groups ${extraPanelOpen ? 'open' : ''}">
            <button class="extra-head" data-toggle-extra type="button">
                <span class="extra-arrow">▶</span>
                <h4>현장목록</h4>
                <span class="count-pill">${others.length}개${showing > 0 ? ` · ${showing}개 표시 중` : ''}</span>
            </button>
            <div class="extra-picker">
                ${others.map(g => `
                    <button type="button"
                            class="group-chip ${selectedExtraIds.has(g.id) ? 'active' : ''}"
                            data-toggle-extra-id="${g.id}">
                        ${escapeHtml(g.name)}
                    </button>
                `).join('')}
            </div>
        </div>`;
}

function bindExtraPickerEvents() {
    document.querySelectorAll('[data-toggle-extra]').forEach(b => {
        b.addEventListener('click', () => { extraPanelOpen = !extraPanelOpen; renderRecords(); });
    });
    document.querySelectorAll('[data-toggle-extra-id]').forEach(b => {
        b.addEventListener('click', () => {
            const id = parseInt(b.dataset.toggleExtraId, 10);
            if (selectedExtraIds.has(id)) selectedExtraIds.delete(id);
            else selectedExtraIds.add(id);
            renderRecords();
        });
    });
}

function applyFilters(rows) {
    return applyColumnFilters(filterState.filters, rows, (r, k) => r.data?.[k]);
}

function renderGroupCard(group) {
    const grpRecs = records.filter(r => r.groupId === group.id);
    const bodyHtml = renderTable(group, applyFilters(grpRecs));
    return `
        <div class="accordion-card open" data-gid="${group.id}">
            <div class="accordion-head">
                <h3>${escapeHtml(group.name)}</h3>
                <label class="main-checkbox" title="이 현장을 메인으로 설정">
                    <input type="checkbox" data-set-main="${group.id}" ${group.isDefault ? 'checked' : ''}>
                    <span>메인그룹</span>
                </label>
                <span class="count-pill">${grpRecs.length}건</span>
                <div class="head-actions">
                    <button type="button" data-edit-gid="${group.id}">편집</button>
                    <button type="button" data-settings-gid="${group.id}">⚙ 설정</button>
                </div>
            </div>
            <div class="accordion-body">${bodyHtml}</div>
        </div>`;
}

function renderTable(group, rows) {
    return `
        <div style="display:flex;justify-content:flex-end;padding:10px 18px;border-bottom:1px solid var(--ledger-line);background:#fbfaf5;">
            <button class="tiny-btn primary" type="button" data-add-row data-gid="${group.id}">+ 계약 추가</button>
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
                        ? `<tr><td colspan="${DEFAULT_FIELDS.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">표시할 계약이 없습니다. 우상단 "+ 계약 추가" 로 등록하세요.</td></tr>`
                        : rows.map((r, i) => renderRow(r, i + 1, group)).join('')}
                </tbody>
            </table>
        </div>`;
}


function bindAccordionEvents() {
    document.querySelectorAll('[data-edit-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); openGroupModal(parseInt(b.dataset.editGid, 10)); });
    });
    document.querySelectorAll('[data-settings-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); openSettingsModal(parseInt(b.dataset.settingsGid, 10)); });
    });
    document.querySelectorAll('[data-set-main]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = parseInt(cb.dataset.setMain, 10);
            if (cb.checked) setMainGroup(id);
            else cb.checked = true;
        });
    });
}

async function setMainGroup(gid) {
    const target = groups.find(g => g.id === gid);
    if (!target || target.isDefault) return;
    const currentMain = groups.find(g => g.isDefault);
    try {
        await api('ledger-groups', { method: 'PATCH', body: { id: gid, isDefault: true } });
        if (currentMain) {
            selectedExtraIds.add(currentMain.id);
            extraPanelOpen = true;
        }
        selectedExtraIds.delete(gid);
        await loadGroups();
    } catch (e) {
        alert('메인 그룹 변경 실패: ' + (e.message || ''));
    }
}

function renderRow(r, displayNo, group) {
    const d = r.data || {};
    return `
        <tr data-id="${r.id}" data-gid="${group.id}" class="${selectedIds.has(r.id) ? 'selected' : ''}">
            <td class="col-check"><input type="checkbox" data-select="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''}></td>
            ${DEFAULT_FIELDS.map(f => `<td>${renderCell(f, r, d, displayNo, group)}</td>`).join('')}
            <td class="col-action"><button class="row-action-btn" data-delete-row="${r.id}" title="삭제">×</button></td>
        </tr>`;
}

function renderCell(f, r, d, displayNo, group) {
    const id = r.id;
    if (f.type === 'auto_number') return `<span class="col-no">${displayNo}</span>`;
    if (f.type === 'pay_switch') {
        const unpaid = !!d.paid_unpaid;
        return `<button class="pay-switch ${unpaid ? 'unpaid' : 'paid'}" data-pay-switch data-id="${id}">${unpaid ? '미지급' : '지급'}</button>`;
    }
    if (f.type === 'status_switch') {
        const v = d.status || '';
        const cls = v === 'active' ? 'active' : v === 'cancel' ? 'cancel' : '';
        const lbl = v === 'active' ? '정계약' : v === 'cancel' ? '해지' : '미정';
        return `<button class="status-pill ${cls}" data-status-switch data-id="${id}">${lbl}</button>`;
    }
    if (f.type === 'manager_select') {
        const employees = orgEmployeesFor(group);
        const opts = ['<option value="">-</option>']
            .concat(employees.map(e => {
                const name = e.data?.name || '';
                const team = e.data?.team || '';
                const title = e.data?.title || '';
                return `<option value="${escapeAttr(name)}" ${d.manager === name ? 'selected' : ''}>${escapeHtml(name)} (${team}팀·${title})</option>`;
            }))
            .join('');
        return `<select data-field="manager" data-id="${id}">${opts}</select>`;
    }
    if (f.type === 'commission_view') {
        const calc = computeCommissionForRow(d, group);
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
        b.addEventListener('click', () => addRow(parseInt(b.dataset.gid, 10)));
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
    const group = groups.find(x => x.id === gid);
    const employees = orgEmployeesFor(group);
    openRowAddModal({
        title: '새 계약 추가',
        fields: DEFAULT_FIELDS,
        defaults: { paid_unpaid: true, status: 'active' },
        customRender: (f) => {
            const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
            if (f.type === 'manager_select') {
                const opts = ['<option value="">-</option>']
                    .concat(employees.map(e => {
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

/* ============== Commission lookup (계약 그룹별 org 연동) ============== */
function findEmployeeByName(name, contractGroup) {
    if (!name) return null;
    const employees = orgEmployeesFor(contractGroup);
    return employees.find(e => (e.data?.name || '').trim() === String(name).trim()) || null;
}

function commissionTable(unitType, contractGroup) {
    const settings = orgSettingsFor(contractGroup);
    if (!settings) return null;
    const t = (unitType || '').trim();
    if (t && Array.isArray(settings.type_commissions)) {
        const hit = settings.type_commissions.find(x => x.type === t);
        if (hit) return { '본부장': +hit['본부장']||0, '팀장': +hit['팀장']||0, '팀원': +hit['팀원']||0 };
    }
    const def = settings.default_commissions || {};
    return { '본부장': +def['본부장']||0, '팀장': +def['팀장']||0, '팀원': +def['팀원']||0 };
}

function computeCommissionForRow(d, contractGroup) {
    const emp = findEmployeeByName(d?.manager, contractGroup);
    if (!emp) return { amount: 0, net: 0 };
    const tbl = commissionTable(d?.unitType, contractGroup);
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
    // 정산 대상: 펼쳐진 그룹들에서 미지급 + 해지 아닌 + 필터 통과 계약.
    const targetGroupIds = expandedGroupIds.size > 0 ? [...expandedGroupIds] : groups.map(g => g.id);
    const all = [];
    targetGroupIds.forEach(gid => {
        const group = groups.find(g => g.id === gid);
        if (!group) return;
        const grpRecs = records.filter(r => r.groupId === gid && r.data?.paid_unpaid && r.data?.status !== 'cancel');
        applyFilters(grpRecs).forEach(r => all.push({ row: r, group }));
    });

    if (all.length === 0) {
        document.getElementById('settleSummary').textContent = '정산 대상 계약이 없습니다 (펼쳐진 현장의 미지급 + 해지 아닌 계약만).';
        document.getElementById('settleBody').innerHTML = '';
        document.getElementById('settleModal').classList.remove('hidden');
        return;
    }

    // 위계 규칙으로 payout 빌드. 각 계약은 자기 그룹의 org 연동 사용.
    const payouts = [];
    all.forEach(({ row: r, group }) => {
        const d = r.data || {};
        const emp = findEmployeeByName(d.manager, group);
        if (!emp) return;
        const tbl = commissionTable(d.unitType, group);
        if (!tbl) return;
        const employees = orgEmployeesFor(group);
        const role = emp.data?.title || '';
        const team = parseInt(emp.data?.team, 10);
        const teamLead = employees.find(e => e.data?.title === '팀장' && parseInt(e.data?.team, 10) === team);
        const head = employees.find(e => e.data?.title === '본부장');
        const label = `[${group.name}] ${d.dong || '?'}동 ${d.ho || '?'}호 (${d.customer || '?'})`;

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
