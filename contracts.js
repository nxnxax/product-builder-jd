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

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260516-mms';
import { attachColumnFilters, applyColumnFilters, openRowAddModal, attachPhoneAutoFormat, attachThousandFormat, formatThousand, unformatThousand, getEffectiveFields, mountFieldManager,
         exportRecordsToExcel, pickExcelFile, parseExcelFile, suggestFieldMapping, openImportPreviewModal,
         saveImportSession, loadImportSession, clearImportSession,
         findBlankRecordIds, showSweepToast,
         attachCellClickHandlers,
         isLedgerMobile, onLedgerViewportChange } from './ledger-shared.js?v=20260516-mms';

const PAGE_TYPE = 'contract';
const TAX_RATE = 0.033;   // 실수령액 = commission * (1 - TAX_RATE)

const TITLES = ['본부장', '팀장', '팀원'];

const DEFAULT_FIELDS = [
    { key: 'no',           label: 'NO',          type: 'auto_number',    filterable: false, width: 42 },
    { key: 'paid',         label: '수수료',      type: 'pay_switch',     filterable: true,  width: 80 },
    { key: 'manager',      label: '담당자',      type: 'manager_select', filterable: true,  width: 100 },
    { key: 'managerTitle', label: '직함',        type: 'manager_title',  filterable: true,  width: 70 },
    { key: 'unitType',     label: '타입',        type: 'text',           filterable: true,  width: 70 },
    { key: 'dong',         label: '동',          type: 'text',           filterable: true,  width: 60 },
    { key: 'ho',           label: '호',          type: 'text',           filterable: true,  width: 60 },
    { key: 'customer',     label: '고객명',      type: 'text',           filterable: true,  width: 100 },
    { key: 'subDate',      label: '청약일',      type: 'date',           filterable: true,  width: 120 },
    { key: 'mainDate',     label: '정계약일',    type: 'date',           filterable: true,  width: 120 },
    { key: 'docs',         label: '서류보완',    type: 'text',           filterable: true,  width: 100 },
    { key: 'phone',        label: '연락처',      type: 'tel',            filterable: false, width: 130 },
    { key: 'commission',   label: '수수료/실수령', type: 'commission_view', filterable: false, width: 110 },
    { key: 'status',       label: '계약상태',    type: 'status_switch',  filterable: true,  width: 80 },
    { key: 'memo',         label: '비고',        type: 'text',           filterable: false, width: 140 },
];

const FIELD_SYNONYMS = {
    paid:         ['수수료', '수수료지급', '정산', '지급', '지급여부'],
    manager:      ['담당자', '담당', '영업담당', '영업사원', '담당직원', '담당자명'],
    managerTitle: ['직함', '직급', '직위', '직책'],
    unitType:     ['타입', '평형', '평수', 'type', '면적'],
    dong:         ['동', '동수', '건물동', '동호'],
    ho:           ['호', '호수', '호실'],
    customer:     ['고객명', '고객', '성명', '이름', '계약자', '계약자명', '매수자', '분양고객', '명의자'],
    subDate:      ['청약일', '청약일자', '가계약일', '가청약일', '청약'],
    mainDate:     ['정계약일', '본계약일', '계약일', '계약일자', '본계약'],
    docs:         ['서류보완', '서류', '보완서류', '서류상태', '제출서류'],
    phone:        ['연락처', '휴대폰', '휴대폰번호', '핸드폰', '핸드폰번호', '전화번호', '전화', '모바일', 'HP', 'tel', 'phone', '번호'],
    status:       ['계약상태', '상태', '진행상태'],
    memo:         ['비고', '메모', '특이사항', '참고', '기타', 'note', 'remarks'],
};
const FALLBACK_FIELD_KEY = 'memo';

// 한국어 status 값 ↔ 내부 코드. export 시 한국어로, import 시 코드로 변환.
const STATUS_TO_KO = { active: '정계약', cancel: '해지', '': '가계약' };
function statusFromKo(s) {
    const v = String(s || '').trim();
    if (/정계약|active/i.test(v)) return 'active';
    if (/해지|취소|cancel/i.test(v)) return 'cancel';
    return '';
}

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
let searchByGroup = {};   // groupId → 그룹 카드 내부 검색어

let orgGroups = [];                  // page_type=org 의 그룹들 (설정에서 연동 선택용)
const orgEmployeesByGroup = new Map(); // gid → employees[]
const orgSettingsByGroup = new Map();  // gid → settings

// 마지막으로 빌드된 정산 결과 (markPaid 시 그룹 settings 로 freeze 저장하기 위해 보관).
// shape: { [gid]: { groupName, perEmployee: [{ empId, name, title, team, account, lines:[{cid,role,label,amount}] }] } }
let lastSettlementByGroup = null;

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
    onLedgerViewportChange(() => renderRecords());
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
    // 참조된 org 그룹이 더 이상 존재하지 않으면 (사용자가 그 조직도 그룹을
    // 삭제한 케이스) fetch 안 하고 빈 배열로 캐시 — 콘솔 404 노출 차단.
    if (!orgGroups.find(g => g.id === orgGroupId)) {
        orgEmployeesByGroup.set(orgGroupId, []);
        orgSettingsByGroup.set(orgGroupId, null);
        return;
    }
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
    // settings 에 저장된 linkedOrgGroupId 가 실제로는 삭제된 그룹을 가리킬 수 있음.
    // 그 경우 default org 로 fallback.
    if (id && !orgGroups.find(g => g.id === id)) id = null;
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
    document.getElementById('newFromOrgBtn').addEventListener('click', () => openOrgPickerModal());
    document.getElementById('settleBtn').addEventListener('click', openSettleModal);

    document.getElementById('groupCancelBtn').addEventListener('click', () => closeModal('groupModal'));
    document.getElementById('groupSaveBtn').addEventListener('click', saveGroup);
    document.getElementById('groupDeleteBtn').addEventListener('click', deleteGroup);

    document.getElementById('settingsCancelBtn').addEventListener('click', () => closeModal('settingsModal'));
    document.getElementById('settingsSaveBtn').addEventListener('click', saveSettings);
    document.getElementById('settingsDeleteBtn').addEventListener('click', deleteGroupFromSettings);

    document.getElementById('settleCloseBtn').addEventListener('click', () => closeModal('settleModal'));
    document.getElementById('settleMarkPaidBtn').addEventListener('click', markPaidFromSettle);
    document.getElementById('historyCloseBtn').addEventListener('click', () => closeModal('historyModal'));

    document.getElementById('bulkClearBtn').addEventListener('click', () => { selectedIds.clear(); renderRecords(); });
    document.getElementById('bulkDeleteBtn').addEventListener('click', bulkDelete);

    document.getElementById('exportAllBtn')?.addEventListener('click', exportAllGroups);
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
    const dup = groups.find(g => g.name === name && g.id !== editingGroupId);
    if (dup) { document.getElementById('groupErrorMsg').textContent = `이미 같은 이름의 현장이 있습니다 ("${name}"). 다른 이름을 사용해 주세요.`; return; }
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

async function deleteGroupFromSettings() {
    if (!settingsGroupId) return;
    const g = groups.find(x => x.id === settingsGroupId);
    if (!g) return;
    if (!confirm(`"${g.name}" 현장과 그 안의 모든 계약을 영구 삭제합니다. 진행하시겠습니까?`)) return;
    try {
        await api('ledger-groups', { method: 'DELETE', body: { id: settingsGroupId } });
        closeModal('settingsModal');
        expandedGroupIds.delete(settingsGroupId);
        selectedExtraIds.delete(settingsGroupId);
        settingsGroupId = null;
        await loadGroups();
    } catch (e) {
        document.getElementById('settingsErrorMsg').textContent = e.message;
    }
}

/* ============== Settings modal ============== */
let settingsFieldDraft = [];

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
    settingsFieldDraft = JSON.parse(JSON.stringify(g.settings?.customFields || []));
    mountFieldManager({
        container: document.getElementById('fieldManagerBox'),
        defaultFields: DEFAULT_FIELDS,
        customFields: settingsFieldDraft,
        onChange: (next) => { settingsFieldDraft = next; },
    });
    document.getElementById('settingsModal').classList.remove('hidden');
}

async function saveSettings() {
    const g = groups.find(x => x.id === settingsGroupId);
    if (!g) return;
    const linkedId = parseInt(document.getElementById('linkedOrgSelect').value, 10) || null;
    const newSettings = { ...(g.settings || {}), linkedOrgGroupId: linkedId, customFields: settingsFieldDraft };
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
let _sweptOnce = false;
async function loadRecords() {
    if (groups.length === 0) { records = []; renderRecords(); return; }
    try {
        const allIds = groups.map(g => g.id).join(',');
        const data = await api('ledger-records', { query: 'group_ids=' + allIds });
        records = data.items || [];
        if (!_sweptOnce) {
            _sweptOnce = true;
            const blanks = findBlankRecordIds(records);
            if (blanks.length > 0) {
                try {
                    await api('ledger-records-bulk', { method: 'POST', body: { ids: blanks } });
                    const re = await api('ledger-records', { query: 'group_ids=' + allIds });
                    records = re.items || [];
                    showSweepToast(blanks.length);
                } catch (e) { console.warn('빈 행 자동 정리 실패:', e); }
            }
        }
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

    // bulk-bar 가 #content 자식이면 innerHTML 갱신 시 같이 삭제되니 잠시 body 로 빼냄
    const bulkBar = document.getElementById('bulkBar');
    if (bulkBar && bulkBar.parentElement === content) {
        document.body.appendChild(bulkBar);
    }

    let html = '';
    html += renderGroupPicker(groups, mainGroup.id);
    html += renderGroupCard(mainGroup);
    others.filter(g => selectedExtraIds.has(g.id)).forEach(g => {
        html += renderGroupCard(g);
    });
    content.innerHTML = html;

    // 사용자 요청 배치: [그룹목록(extra-groups)] → [bulk-bar] → [그룹 카드들]
    const picker = content.querySelector('.extra-groups');
    if (bulkBar && picker) {
        picker.insertAdjacentElement('afterend', bulkBar);
    }

    bindAccordionEvents();
    bindExtraPickerEvents();
    bindTableEvents();
    updateBulkBar();
}

function renderGroupPicker(allGroups, mainId) {
    const others = allGroups.filter(g => g.id !== mainId);
    const showing = others.filter(g => selectedExtraIds.has(g.id)).length + 1;
    return `
        <div class="extra-groups ${extraPanelOpen ? 'open' : ''}">
            <div class="extra-head">
                <button class="extra-toggle" data-toggle-extra type="button">
                    <span class="extra-arrow">▶</span>
                    <h4>현장목록 <span class="extra-count">${allGroups.length}개</span><span class="extra-count-sub">· ${showing}개 표시 중</span></h4>
                </button>
            </div>
            <div class="extra-picker">
                ${allGroups.map(g => {
                    const isMain = g.id === mainId;
                    const active = isMain || selectedExtraIds.has(g.id);
                    return `<button type="button"
                            class="group-chip ${active ? 'active' : ''} ${isMain ? 'main' : ''}"
                            ${isMain ? 'disabled title="메인그룹 — 항상 표시됩니다"' : `data-toggle-extra-id="${g.id}"`}>
                        ${isMain ? '★ ' : ''}${escapeHtml(g.name)}
                    </button>`;
                }).join('')}
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

/* ============== 조직도에서 현장 만들기 ============== */
async function openOrgPickerModal() {
    // 조직도 페이지에서 새로 만들어진 그룹이 있을 수 있으니 매번 새로 받기.
    await loadOrgIndex();
    if (!orgGroups.length) {
        alert('등록된 조직도 그룹이 없습니다. 먼저 조직도 페이지에서 그룹을 만들어 주세요.');
        return;
    }
    closeOrgPickerModal();
    // 최신 등록순 (id 큰 것부터)
    const sorted = [...orgGroups].sort((a, b) => b.id - a.id);
    const existingNames = new Set(groups.map(g => g.name));
    const existingLinks = new Set(groups.map(g => g.settings?.linkedOrgGroupId).filter(Boolean));

    // 각 조직도 그룹의 직원 정보 미리 받기 — 사용자가 어떤 그룹인지 알아볼 수 있도록.
    await Promise.all(sorted.map(g => loadOrgEmployeesForGroup(g.id)));

    const md = document.createElement('div');
    md.className = 'modal-backdrop org-picker-modal';
    md.innerHTML = `
        <div class="modal-panel narrow">
            <header class="modal-header">
                <div>
                    <h2>조직도에서 현장 만들기</h2>
                    <p class="modal-subtitle">선택한 조직도 그룹과 동일한 이름으로 현장을 만들고 자동 연동합니다. 그룹 옆 인원수·이름 미리보기로 어느 그룹인지 확인하세요.</p>
                </div>
            </header>
            <div class="modal-body">
                <div class="org-pick-list">
                    ${sorted.map(g => {
                        const dup = existingLinks.has(g.id) || existingNames.has(g.name);
                        const emps = orgEmployeesByGroup.get(g.id) || [];
                        const names = emps.map(e => e.data?.name).filter(Boolean).slice(0, 3);
                        const preview = names.length === 0
                            ? '비어있음'
                            : names.join(', ') + (emps.length > names.length ? ` 외 ${emps.length - names.length}명` : '');
                        return `
                            <button type="button" class="org-pick-item ${dup ? 'dup' : ''}" data-org-id="${g.id}" ${dup ? 'disabled' : ''}>
                                <div class="org-pick-main">
                                    <span class="org-pick-name">${escapeHtml(g.name)}</span>
                                    <span class="org-pick-preview">${escapeHtml(preview)}</span>
                                </div>
                                <span class="org-pick-count">${emps.length}명</span>
                                ${g.isDefault ? '<span class="org-pick-badge">메인</span>' : ''}
                                ${dup ? '<span class="org-pick-badge dim">이미 등록됨</span>' : ''}
                            </button>
                        `;
                    }).join('')}
                </div>
            </div>
            <footer class="modal-footer">
                <button class="tiny-btn" type="button" data-close-org-picker>닫기</button>
            </footer>
        </div>
    `;
    document.body.appendChild(md);

    md.addEventListener('click', (e) => { if (e.target === md) closeOrgPickerModal(); });
    md.querySelector('[data-close-org-picker]').addEventListener('click', closeOrgPickerModal);
    md.querySelectorAll('[data-org-id]:not([disabled])').forEach(btn => {
        btn.addEventListener('click', () => createContractFromOrg(parseInt(btn.dataset.orgId, 10)));
    });
}

function closeOrgPickerModal() {
    document.querySelectorAll('.org-picker-modal').forEach(m => m.remove());
}

async function createContractFromOrg(orgGroupId) {
    const og = orgGroups.find(g => g.id === orgGroupId);
    if (!og) return;
    try {
        const created = await api('ledger-groups', {
            method: 'POST',
            body: {
                pageType: PAGE_TYPE,
                name: og.name,
                isDefault: false,
                fieldSchema: { fields: DEFAULT_FIELDS },
                settings: { linkedOrgGroupId: og.id },
            },
        });
        // 캐시 무효화 — 조직도 페이지에서 직원이 추가/변경됐을 수 있으니 새로 fetch.
        orgEmployeesByGroup.delete(og.id);
        selectedExtraIds.add(created.id);
        extraPanelOpen = true;
        closeOrgPickerModal();
        await loadGroups();
    } catch (e) {
        alert('현장 만들기 실패: ' + (e.message || ''));
    }
}

function applyFilters(rows, groupId) {
    let out = applyColumnFilters(filterState.filters, rows, (r, k) => {
        if (k === 'paid') return r.data?.paid_unpaid;
        if (k === 'managerTitle') {
            const mgr = r.data?.manager;
            if (!mgr) return '';
            const grp = groups.find(g => g.id === r.groupId);
            const employees = orgEmployeesFor(grp);
            const emp = employees.find(e => (e.data?.name || '') === mgr);
            return emp?.data?.title || '';
        }
        return r.data?.[k];
    });
    // 그룹별 텍스트 검색 — 행 안의 모든 값을 부분 매칭
    if (groupId != null) {
        const q = (searchByGroup[groupId] || '').trim().toLowerCase();
        if (q !== '') {
            out = out.filter(r => {
                const d = r.data || {};
                return Object.values(d).some(v => {
                    if (v == null || v === '') return false;
                    return String(v).toLowerCase().includes(q);
                });
            });
        }
    }
    return out;
}

function renderGroupCard(group) {
    const grpRecs = records.filter(r => r.groupId === group.id);
    const open = expandedGroupIds.has(group.id);
    const bodyHtml = open ? renderTable(group, applyFilters(grpRecs, group.id)) : '';
    return `
        <div class="accordion-card ${open ? 'open' : ''}" data-gid="${group.id}">
            <div class="accordion-head">
                <h3>${escapeHtml(group.name)} <span class="head-count">(${grpRecs.length}건)</span></h3>
                <label class="main-checkbox" title="이 현장을 메인으로 설정">
                    <input type="checkbox" data-set-main="${group.id}" ${group.isDefault ? 'checked' : ''}>
                    <span>메인그룹</span>
                </label>
                <div class="head-actions">
                    <button type="button" data-toggle-gid="${group.id}" title="${open ? '접기' : '펼치기'}">${open ? '▼ 접기' : '▶ 펼치기'}</button>
                    <button type="button" data-export-gid="${group.id}" title="이 현장을 엑셀로 다운로드">📥 엑셀 다운로드</button>
                    <button type="button" data-import-gid="${group.id}" title="엑셀 파일을 이 현장에 업로드">📤 엑셀 가져오기</button>
                    ${loadImportSession(PAGE_TYPE, group.id) ? `<button type="button" data-reimport-gid="${group.id}" title="마지막 가져오기 매핑 다시 열어 수정">🔄 매핑 수정</button>` : ''}
                    ${(group.settings?.payment_batches?.length > 0) ? `<button type="button" data-history-gid="${group.id}" title="이 현장의 지급 내역 보기">📜 지급내역 (${group.settings.payment_batches.length})</button>` : ''}
                    <button type="button" data-edit-gid="${group.id}">편집</button>
                    <button type="button" data-settings-gid="${group.id}">⚙ 설정</button>
                </div>
            </div>
            <div class="accordion-body">${bodyHtml}</div>
        </div>`;
}

const MOBILE_PRIMARY_KEYS_CONTRACTS = ['customer', 'mainDate', 'phone'];

function renderTable(group, rows) {
    if (isLedgerMobile()) return renderMobileCards(group, rows);
    const fields = getEffectiveFields(group, DEFAULT_FIELDS);
    const q = escapeAttr(searchByGroup[group.id] || '');
    return `
        <div class="ledger-card-toolbar">
            <div class="ledger-search-wrap">
                <input type="search" class="ledger-search-input" data-search-gid="${group.id}" value="${q}" placeholder="🔍 행 안에서 검색…" autocomplete="off">
                ${q ? `<button type="button" class="ledger-search-clear" data-search-clear-gid="${group.id}" aria-label="검색 지우기">×</button>` : ''}
            </div>
            <button class="tiny-btn primary" type="button" data-add-row data-gid="${group.id}">+ 계약 추가</button>
        </div>
        <div class="tbl-wrap">
            <table class="ledger-tbl">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" data-select-all="${group.id}"></th>
                        ${fields.map(f => `<th style="min-width:${f.width || 90}px;" data-col-key="${f.key}">${escapeHtml(f.label)}</th>`).join('')}
                        <th class="col-action"></th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.length === 0
                        ? `<tr><td colspan="${fields.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">표시할 계약이 없습니다. 우상단 "+ 계약 추가" 로 등록하세요.</td></tr>`
                        : rows.map((r, i) => renderRow(r, i + 1, group)).join('')}
                </tbody>
            </table>
        </div>`;
}

function renderMobileCards(group, rows) {
    const fields = getEffectiveFields(group, DEFAULT_FIELDS);
    const cardsHtml = rows.length === 0
        ? `<div class="ledger-cards-empty">표시할 계약이 없습니다.</div>`
        : rows.map((r, i) => renderMobileCard(r, i + 1, group, fields)).join('');
    const q = escapeAttr(searchByGroup[group.id] || '');
    return `
        <div class="ledger-cards-toolbar">
            <div class="ledger-search-wrap">
                <input type="search" class="ledger-search-input" data-search-gid="${group.id}" value="${q}" placeholder="🔍 행 안에서 검색…" autocomplete="off">
                ${q ? `<button type="button" class="ledger-search-clear" data-search-clear-gid="${group.id}" aria-label="검색 지우기">×</button>` : ''}
            </div>
            <button class="tiny-btn primary" type="button" data-add-row data-gid="${group.id}">+ 계약 추가</button>
        </div>
        <div class="ledger-cards">${cardsHtml}</div>`;
}

function renderMobileCard(r, displayNo, group, fields) {
    const d = r.data || {};
    const cls = [
        'ledger-card',
        selectedIds.has(r.id) ? 'selected' : '',
        d.paid_unpaid ? '' : 'row-paid',
        d.status === 'cancel' ? 'row-cancel' : '',
    ].filter(Boolean).join(' ');
    const primaryField = fields.find(f => f.key === 'customer') || fields.find(f => f.type !== 'auto_number');
    const titleVal = primaryField ? (d[primaryField.key] || '-') : '-';
    const subKeys = MOBILE_PRIMARY_KEYS_CONTRACTS.filter(k => k !== primaryField?.key);
    const subParts = subKeys.map(k => {
        const f = fields.find(x => x.key === k);
        if (!f) return '';
        const v = d[k];
        const display = (!v) ? '-' : (f.type === 'date' ? String(v).replace(/-/g, '.') : String(v));
        return `
            <div class="ledger-card-sub-item">
                <span class="ledger-card-sub-label">${escapeHtml(f.label || '')}</span>
                <span class="ledger-card-sub-val">${escapeHtml(display)}</span>
            </div>`;
    }).filter(Boolean).join('');
    const detailFields = fields.filter(f => f.type !== 'auto_number' && f.key !== primaryField?.key);
    const detailHtml = detailFields.map(f => `
        <div class="ledger-card-field">
            <span class="ledger-card-label">${escapeHtml(f.label || '')}</span>
            <span class="ledger-card-value">${renderCell(f, r, d, displayNo, group)}</span>
        </div>
    `).join('');
    return `
        <div class="${cls}" data-id="${r.id}" data-gid="${group.id}">
            <div class="ledger-card-head">
                <input type="checkbox" class="ledger-card-check" data-select="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''}>
                <div class="ledger-card-summary">
                    <div class="ledger-card-title">${escapeHtml(titleVal)}</div>
                    ${subParts ? `<div class="ledger-card-sub">${subParts}</div>` : ''}
                </div>
                <button class="ledger-card-toggle" type="button" aria-label="펼치기/접기"><span class="toggle-label-open">펼치기</span><span class="toggle-label-close">접기</span></button>
            </div>
            <div class="ledger-card-body">
                ${detailHtml}
                <div class="ledger-card-actions">
                    <button class="row-action-btn" data-edit-row="${r.id}" type="button"><span class="ico">✎</span><span class="lbl">수정</span></button>
                    <button class="row-action-btn danger" data-delete-row="${r.id}" type="button"><span class="ico">×</span><span class="lbl">삭제</span></button>
                </div>
            </div>
        </div>`;
}


function bindAccordionEvents() {
    document.querySelectorAll('[data-edit-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); openGroupModal(parseInt(b.dataset.editGid, 10)); });
    });
    document.querySelectorAll('[data-settings-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); openSettingsModal(parseInt(b.dataset.settingsGid, 10)); });
    });
    document.querySelectorAll('[data-export-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); exportGroup(parseInt(b.dataset.exportGid, 10)); });
    });
    document.querySelectorAll('[data-import-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); importToGroup(parseInt(b.dataset.importGid, 10)); });
    });
    document.querySelectorAll('[data-reimport-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); reopenImportSession(parseInt(b.dataset.reimportGid, 10)); });
    });
    document.querySelectorAll('[data-toggle-gid]').forEach(b => {
        b.addEventListener('click', (e) => {
            e.stopPropagation();
            const gid = parseInt(b.dataset.toggleGid, 10);
            if (expandedGroupIds.has(gid)) expandedGroupIds.delete(gid);
            else expandedGroupIds.add(gid);
            renderRecords();
        });
    });
    document.querySelectorAll('[data-history-gid]').forEach(b => {
        b.addEventListener('click', (e) => { e.stopPropagation(); openPaymentHistoryModal(parseInt(b.dataset.historyGid, 10)); });
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
    const cls = [
        selectedIds.has(r.id) ? 'selected' : '',
        d.paid_unpaid ? '' : 'row-paid',     // 지급 완료된 행 = 회색
        d.status === 'cancel' ? 'row-cancel' : '',  // 해지된 행 = 분홍
    ].filter(Boolean).join(' ');
    const fields = getEffectiveFields(group, DEFAULT_FIELDS);
    const primaryIdx = fields.findIndex(f => f.type !== 'auto_number');
    return `
        <tr data-id="${r.id}" data-gid="${group.id}" class="${cls}">
            <td class="col-check"><input type="checkbox" data-select="${r.id}" ${selectedIds.has(r.id) ? 'checked' : ''}></td>
            ${fields.map((f, i) => {
                const cls = [
                    f.type === 'auto_number' ? 'col-no' : '',
                    i === primaryIdx ? 'col-primary' : '',
                ].filter(Boolean).join(' ');
                const label = f.type === 'auto_number' ? '' : (f.label || '');
                return `<td${cls ? ` class="${cls}"` : ''}${label ? ` data-label="${escapeHtml(label)}"` : ''}>${renderCell(f, r, d, displayNo, group)}</td>`;
            }).join('')}
            <td class="col-action">
                <button class="row-action-btn" data-edit-row="${r.id}" title="수정"><span class="ico">✎</span><span class="lbl">수정</span></button>
                <button class="row-action-btn danger" data-delete-row="${r.id}" title="삭제"><span class="ico">×</span><span class="lbl">삭제</span></button>
            </td>
        </tr>`;
}

function renderCell(f, r, d, displayNo, group) {
    const id = r.id;
    if (f.type === 'auto_number') return `<span class="col-no">${displayNo}</span>`;
    if (f.type === 'pay_switch') {
        const unpaid = !!d.paid_unpaid;
        return `
            <label class="toggle-switch pay-toggle ${unpaid ? 'on' : 'off'}" data-pay-switch data-id="${id}" title="${unpaid ? '미지급' : '지급'}">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label">${unpaid ? '미지급' : '지급'}</span>
            </label>`;
    }
    if (f.type === 'status_switch') {
        const v = d.status || '';
        const cls = v === 'active' ? 'active' : v === 'cancel' ? 'cancel' : '';
        const lbl = v === 'active' ? '정계약' : v === 'cancel' ? '해지' : '가계약';
        return `<button class="status-pill ${cls}" data-status-switch data-id="${id}">${lbl}</button>`;
    }
    if (f.type === 'manager_select') {
        // 인라인 표시 — 이름만 read-only span (수정은 ✎ 버튼 → 모달)
        return d.manager ? `<span class="cell-text">${escapeHtml(d.manager)}</span>` : `<span class="cell-empty">-</span>`;
    }
    if (f.type === 'manager_title') {
        // 담당자에 매칭되는 직원의 직함 자동 도출
        const employees = orgEmployeesFor(group);
        const emp = d.manager ? employees.find(e => (e.data?.name || '') === d.manager) : null;
        const title = emp?.data?.title || '';
        return title ? `<span class="cell-text">${escapeHtml(title)}</span>` : `<span class="cell-empty">-</span>`;
    }
    if (f.type === 'commission_view') {
        const calc = computeCommissionForRow(d, group);
        // 사용자가 수동으로 입력한 manualCommission 이 있으면 그걸 우선
        const amount = (d.manualCommission != null && d.manualCommission !== '') ? Number(d.manualCommission) : calc.amount;
        if (!amount) return `<span class="cell-empty">-</span>`;
        const net = Math.round(amount * (1 - TAX_RATE));
        return `<span class="commission-cell">₩${formatNum(amount)}<span class="net">→ ₩${formatNum(net)}</span></span>`;
    }
    if (f.type === 'textarea') {
        return d[f.key] ? `<span class="cell-text cell-multiline">${escapeHtml(d[f.key])}</span>` : `<span class="cell-empty">-</span>`;
    }
    if (f.type === 'date') {
        return d[f.key] ? `<span class="cell-text">${escapeHtml(String(d[f.key]).replace(/-/g, '.'))}</span>` : `<span class="cell-empty">-</span>`;
    }
    if (f.type === 'toggle') {
        const on = !!d[f.key];
        return `<span class="toggle-cell ${on ? 'on' : 'off'}" data-cell-toggle data-id="${id}" data-field="${escapeAttr(f.key)}" data-value="${on ? '1' : '0'}" title="클릭하여 토글">${escapeHtml(on ? (f.onLabel || 'ON') : (f.offLabel || 'OFF'))}</span>`;
    }
    if (f.type === 'switch') {
        const on = !!d[f.key];
        const lbl = on ? (f.onLabel || 'ON') : (f.offLabel || 'OFF');
        return `<span class="switch-cell ${on ? 'on' : 'off'}" data-cell-switch data-id="${id}" data-field="${escapeAttr(f.key)}" data-value="${on ? '1' : '0'}" aria-label="${escapeAttr(lbl)}" title="클릭하여 토글"><span class="switch-track"><span class="switch-thumb"></span></span><span class="switch-label">${escapeHtml(lbl)}</span></span>`;
    }
    // tel / text / number / resident_id / 기타 모두 read-only span
    return d[f.key] ? `<span class="cell-text">${escapeHtml(d[f.key])}</span>` : `<span class="cell-empty">-</span>`;
}

function bindTableEvents() {
    attachColumnFilters({
        state: filterState,
        headers: document.querySelectorAll('.ledger-tbl thead th[data-col-key]'),
        fields: DEFAULT_FIELDS,
        getRows: () => records,
        getValue: (r, k) => {
            // 'paid' 컬럼은 data.paid_unpaid 에 저장됨 (알리아스).
            if (k === 'paid') return r.data?.paid_unpaid;
            // 'managerTitle' 은 data 에 없고 담당자에서 자동 도출 — getValue 에서 도출.
            if (k === 'managerTitle') {
                const mgr = r.data?.manager;
                if (!mgr) return '';
                const grp = groups.find(g => g.id === r.groupId);
                const employees = orgEmployeesFor(grp);
                const emp = employees.find(e => (e.data?.name || '') === mgr);
                return emp?.data?.title || '';
            }
            return r.data?.[k];
        },
        onChange: () => renderRecords(),
        labelFor: (field, raw) => {
            if (field.key === 'status') {
                if (raw === 'active') return '정계약';
                if (raw === 'cancel') return '해지';
                if (raw === '' || raw == null) return '가계약';
                return raw;
            }
            if (field.key === 'paid') {
                if (raw === 'true' || raw === true) return '미지급';
                if (raw === 'false' || raw === false) return '지급';
                return raw;
            }
            return undefined;
        },
    });
    document.querySelectorAll('[data-add-row]').forEach(b => {
        b.addEventListener('click', () => addRow(parseInt(b.dataset.gid, 10)));
    });
    document.querySelectorAll('[data-edit-row]').forEach(b => {
        b.addEventListener('click', () => editRow(parseInt(b.dataset.editRow, 10)));
    });
    document.querySelectorAll('[data-pay-switch]').forEach(b => {
        b.addEventListener('click', (e) => { e.preventDefault(); togglePay(parseInt(b.dataset.id, 10)); });
    });
    document.querySelectorAll('[data-status-switch]').forEach(b => {
        b.addEventListener('click', () => cycleStatus(parseInt(b.dataset.id, 10)));
    });
    // 그룹별 검색
    document.querySelectorAll('[data-search-gid]').forEach(input => {
        input.addEventListener('input', () => {
            const gid = parseInt(input.dataset.searchGid, 10);
            searchByGroup[gid] = input.value;
            const caret = input.selectionStart;
            renderRecords();
            const restored = document.querySelector(`[data-search-gid="${gid}"]`);
            if (restored) {
                restored.focus();
                try { restored.setSelectionRange(caret, caret); } catch {}
            }
        });
    });
    document.querySelectorAll('[data-search-clear-gid]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const gid = parseInt(btn.dataset.searchClearGid, 10);
            delete searchByGroup[gid];
            renderRecords();
        });
    });
    // 사용자 정의 toggle/switch 셀 클릭 즉시 토글
    attachCellClickHandlers({
        root: document,
        onToggle: async ({ id, fieldKey, nextValue }) => {
            await updateRowField(id, fieldKey, nextValue);
            renderRecords();
        },
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
            const targets = applyFilters(records.filter(r => r.groupId === gid), gid);
            targets.forEach(r => cb.checked ? selectedIds.add(r.id) : selectedIds.delete(r.id));
            renderRecords();
        });
    });
}

async function addRow(gid) {
    const group = groups.find(x => x.id === gid);
    const linkedId = linkedOrgIdFor(group);
    if (linkedId) {
        orgEmployeesByGroup.delete(linkedId);
        await loadOrgEmployeesForGroup(linkedId);
    }
    openContractEntryModal({
        title: '새 계약 추가',
        confirmLabel: '추가',
        defaults: { paid_unpaid: true, status: 'active' },
        group,
        onSubmit: async (data) => {
            await api('ledger-records', { method: 'POST', body: { groupId: gid, data, source: 'web' } });
            await loadRecords();
        },
    });
}

async function editRow(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    const group = groups.find(g => g.id === r.groupId);
    const linkedId = linkedOrgIdFor(group);
    if (linkedId) {
        orgEmployeesByGroup.delete(linkedId);
        await loadOrgEmployeesForGroup(linkedId);
    }
    openContractEntryModal({
        title: '계약 정보 수정',
        confirmLabel: '저장',
        defaults: { ...r.data },
        group,
        onSubmit: async (data) => {
            await api('ledger-records', { method: 'PATCH', body: { id, data } });
            await loadRecords();
        },
    });
}

function openContractEntryModal({ title, confirmLabel, defaults, group, onSubmit }) {
    const employees = orgEmployeesFor(group);
    openRowAddModal({
        title,
        confirmLabel,
        fields: getEffectiveFields(group, DEFAULT_FIELDS),
        defaults,
        customRender: (f, defs) => {
            const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
            if (f.type === 'manager_select') {
                const cur = defs.manager || '';
                const opts = ['<option value="">-</option>']
                    .concat(employees.map(e => {
                        const name = e.data?.name || '';
                        return `<option value="${escapeAttr(name)}" ${cur === name ? 'selected' : ''}>${escapeHtml(name)}</option>`;
                    })).join('');
                return `<div class="modal-row">${lbl}<div class="row-control"><select data-field="manager" data-manager-select>${opts}</select></div></div>`;
            }
            if (f.type === 'manager_title') {
                const emp = defs.manager ? employees.find(e => (e.data?.name || '') === defs.manager) : null;
                const title = emp?.data?.title || '';
                return `<div class="modal-row">${lbl}<div class="row-control"><span class="row-static" data-field="managerTitle" data-readonly data-manager-title>${title ? escapeHtml(title) : '<i style="color:#a3a39a;">담당자 선택 시 자동</i>'}</span></div></div>`;
            }
            if (f.type === 'status_switch') {
                const cur = defs.status || '';
                return `<div class="modal-row">${lbl}<div class="row-control"><select data-field="status">
                    <option value="" ${cur === '' ? 'selected' : ''}>가계약</option>
                    <option value="active" ${cur === 'active' ? 'selected' : ''}>정계약</option>
                    <option value="cancel" ${cur === 'cancel' ? 'selected' : ''}>해지</option>
                </select></div></div>`;
            }
            if (f.type === 'pay_switch') {
                const checked = defs.paid_unpaid !== false ? 'checked' : '';
                return `<div class="modal-row">${lbl}<div class="row-control"><label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#4f4943;font-weight:400;cursor:pointer;margin:0;">
                    <input type="checkbox" data-field="paid_unpaid" ${checked} style="width:auto;accent-color:#c8362c;"> 미지급 (체크 해제 시 지급완료)
                </label></div></div>`;
            }
            if (f.type === 'commission_view') {
                // 수수료/실수령 input — 기본값은 자동 계산, 사용자 수정 가능
                const calc = computeCommissionForRow(defs, group);
                const initial = (defs.manualCommission != null && defs.manualCommission !== '')
                    ? Number(defs.manualCommission) : calc.amount;
                return `<div class="modal-row" style="align-items:start;">${lbl}<div class="row-control" style="flex-direction:column;align-items:stretch;">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" inputmode="numeric" data-thousand data-field="manualCommission" data-commission-input value="${initial ? formatThousand(initial) : ''}" placeholder="자동: ${formatThousand(calc.amount || 0)}" style="flex:1;">
                        <span style="font-size:12px;color:#8a847e;white-space:nowrap;">실수령 → <b data-net-display>₩${formatThousand(initial ? Math.round(initial * (1 - TAX_RATE)) : (calc.net || 0))}</b></span>
                    </div>
                    <span class="row-help">담당자/타입 기준 자동 계산값. 직접 수정 가능. (3.3% 차감 후 실수령액 자동 갱신)</span>
                </div></div>`;
            }
            return null;
        },
        onSubmit,
        afterRender: (md) => {
            // 담당자 변경 시 직함 자동 채우기 + 수수료 자동 재계산
            const sel = md.querySelector('[data-manager-select]');
            const titleEl = md.querySelector('[data-manager-title]');
            const commInput = md.querySelector('[data-commission-input]');
            const netDisplay = md.querySelector('[data-net-display]');
            const recompute = () => {
                const name = sel ? sel.value : '';
                const emp = name ? employees.find(e => (e.data?.name || '') === name) : null;
                if (titleEl) {
                    const t = emp?.data?.title || '';
                    titleEl.innerHTML = t ? escapeHtml(t) : '<i style="color:#a3a39a;">담당자 선택 시 자동</i>';
                }
                // 수수료 자동값 갱신 (사용자가 안 만진 경우만)
                if (commInput && !commInput.dataset.userEdited) {
                    const tempData = { ...defaults, manager: name, unitType: md.querySelector('[data-field="unitType"]')?.value || defaults.unitType };
                    const calc = computeCommissionForRow(tempData, group);
                    commInput.value = calc.amount ? formatThousand(calc.amount) : '';
                    commInput.placeholder = '자동: ' + formatThousand(calc.amount || 0);
                    if (netDisplay) netDisplay.textContent = '₩' + formatThousand(calc.amount ? Math.round(calc.amount * (1 - TAX_RATE)) : 0);
                }
            };
            if (sel) sel.addEventListener('change', recompute);
            const unitInput = md.querySelector('[data-field="unitType"]');
            if (unitInput) unitInput.addEventListener('input', recompute);
            if (commInput) {
                commInput.addEventListener('input', () => {
                    commInput.dataset.userEdited = '1';
                    const digits = String(commInput.value || '').replace(/[^\d]/g, '');
                    const amount = parseInt(digits, 10) || 0;
                    if (netDisplay) netDisplay.textContent = '₩' + formatThousand(Math.round(amount * (1 - TAX_RATE)));
                });
            }
        },
    });
}

async function updateRowField(id, field, value) {
    try {
        await api('ledger-records', { method: 'PATCH', body: { id, data: { [field]: value } } });
        const r = records.find(x => x.id === id);
        if (r) { r.data = r.data || {}; r.data[field] = value; }
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

/* ============== 엑셀 다운로드 / 업로드 ============== */
function buildExportRows(group) {
    // 한글 친화 변환: status 코드 → 한국어, paid_unpaid → 미지급/지급, manager 자동 매칭은 그대로.
    return records.filter(r => r.groupId === group.id).map(r => {
        const d = r.data || {};
        const out = { ...d };
        if ('status' in out) out.status = STATUS_TO_KO[d.status || ''] ?? (d.status || '');
        // paid 토글은 ledger-shared 가 pay_switch 처리 — boolean 그대로 두면 OK.
        return { data: out };
    });
}

async function exportGroup(gid) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const fields = getEffectiveFields(g, DEFAULT_FIELDS);
    const rows = buildExportRows(g);
    if (rows.length === 0) {
        if (!confirm(`"${g.name}" 현장에 계약이 없습니다. 빈 양식만 다운로드할까요?`)) return;
    }
    try {
        await exportRecordsToExcel({
            sheets: [{ name: g.name, fields, rows }],
            fileName: `계약자관리대장_${g.name}_${todayStamp()}.xlsx`,
        });
    } catch (e) { showError('엑셀 다운로드 실패: ' + e.message); }
}

async function exportAllGroups() {
    if (groups.length === 0) { alert('내보낼 현장이 없습니다.'); return; }
    const sheets = groups.map(g => ({
        name: g.name,
        fields: getEffectiveFields(g, DEFAULT_FIELDS),
        rows: buildExportRows(g),
    }));
    try {
        await exportRecordsToExcel({ sheets, fileName: `계약자관리대장_전체_${todayStamp()}.xlsx` });
    } catch (e) { showError('엑셀 다운로드 실패: ' + e.message); }
}

async function importToGroup(gid) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const file = await pickExcelFile();
    if (!file) return;
    let parsed;
    try { parsed = await parseExcelFile(file); }
    catch (e) { showError('엑셀 파일을 읽지 못했습니다: ' + e.message); return; }
    if (!parsed.length || !parsed[0].headers.length) { alert('빈 파일이거나 헤더 행이 없습니다.'); return; }
    const sheet = parsed[0];
    const fields = getEffectiveFields(g, DEFAULT_FIELDS);
    const suggested = suggestFieldMapping(sheet.headers, fields, FIELD_SYNONYMS);

    openImportPreviewModal({
        title: `"${g.name}" 현장에 가져오기`,
        sheetName: sheet.name,
        headers: sheet.headers,
        rows: sheet.rows,
        skippedBlank: sheet.skippedBlank || 0,
        fields,
        fallbackKey: FALLBACK_FIELD_KEY,
        suggested,
        onConfirm: async (mappedRows, finalMapping, ctx) => {
            const newIds = await applyImportRows(g, mappedRows, ctx);
            saveImportSession(PAGE_TYPE, gid, { sheet, mapping: finalMapping, recordIds: newIds });
            await loadRecords();
        },
    });
}

async function applyImportRows(group, mappedRows, ctx) {
    const ids = [];
    const total = mappedRows.length;
    ctx?.setProgress?.(0, total);
    for (let i = 0; i < mappedRows.length; i++) {
        const data = mappedRows[i];
        if ('status' in data) data.status = statusFromKo(data.status);
        if (!('paid_unpaid' in data) && data.paid !== undefined) {
            data.paid_unpaid = data.paid === true || /미지급/.test(String(data.paid));
            delete data.paid;
        }
        if (data.manager && !data.managerTitle) {
            const employees = orgEmployeesFor(group);
            const emp = employees.find(e => (e.data?.name || '') === data.manager);
            if (emp?.data?.title) data.managerTitle = emp.data.title;
        }
        const res = await api('ledger-records', { method: 'POST', body: { groupId: group.id, data, source: 'excel' } });
        if (res?.id) ids.push(res.id);
        ctx?.setProgress?.(i + 1, total);
    }
    return ids;
}

async function reopenImportSession(gid) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const sess = loadImportSession(PAGE_TYPE, gid);
    if (!sess || !sess.sheet) { alert('저장된 가져오기 기록이 없습니다.'); return; }
    const fields = getEffectiveFields(g, DEFAULT_FIELDS);
    const mapping = (Array.isArray(sess.mapping) && sess.mapping.length === sess.sheet.headers.length)
        ? sess.mapping : suggestFieldMapping(sess.sheet.headers, fields, FIELD_SYNONYMS);
    openImportPreviewModal({
        title: `"${g.name}" 매핑 재수정`,
        sheetName: sess.sheet.name,
        headers: sess.sheet.headers,
        rows: sess.sheet.rows,
        fields,
        fallbackKey: FALLBACK_FIELD_KEY,
        suggested: mapping,
        confirmLabel: `${(sess.sheet.rows || []).length}건 다시 적용`,
        extraDanger: {
            label: '이 가져오기 기록 폐기',
            onClick: async () => {
                if (!confirm('가져오기 기록을 폐기합니다. (이미 추가된 행은 그대로 남고 매핑 수정 버튼만 사라집니다.) 진행할까요?')) throw new Error('취소됨');
                clearImportSession(PAGE_TYPE, gid);
                renderRecords();
            },
        },
        onConfirm: async (mappedRows, finalMapping, ctx) => {
            const oldIds = Array.isArray(sess.recordIds) ? sess.recordIds.filter(Boolean) : [];
            if (oldIds.length) {
                ctx?.setProgress?.(0, mappedRows.length, `이전 ${oldIds.length}건 삭제 중...`);
                try { await api('ledger-records-bulk', { method: 'POST', body: { ids: oldIds } }); }
                catch (e) { /* 이미 일부 삭제됐을 수 있음 */ }
            }
            const newIds = await applyImportRows(g, mappedRows, ctx);
            saveImportSession(PAGE_TYPE, gid, { sheet: sess.sheet, mapping: finalMapping, recordIds: newIds });
            await loadRecords();
        },
    });
}

function todayStamp() {
    const d = new Date();
    return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
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

/* ============== 지급 내역 모달 (그룹별) ============== */
function openPaymentHistoryModal(gid) {
    const group = groups.find(g => g.id === gid);
    if (!group) return;
    const batches = (group.settings?.payment_batches || []).slice().reverse();   // 시간 역순 (최신 먼저)

    document.getElementById('historyModalTitle').textContent = `${group.name} — 지급 내역`;
    document.getElementById('historyModalSub').textContent = batches.length === 0
        ? '저장된 지급 내역이 없습니다.'
        : `총 ${batches.length}회 지급 · 최신순으로 표시`;

    const body = document.getElementById('historyBody');
    if (batches.length === 0) {
        body.innerHTML = '<div class="empty" style="background:transparent;border:0;padding:30px;">아직 지급 완료 처리된 정산이 없습니다.</div>';
    } else {
        body.innerHTML = batches.map((b, idx) => renderHistoryBatch(b, idx)).join('');
        body.querySelectorAll('[data-history-toggle]').forEach(btn => {
            btn.addEventListener('click', () => {
                const card = btn.closest('.history-batch');
                card?.classList.toggle('open');
            });
        });
    }
    document.getElementById('historyModal').dataset.gid = String(gid);
    document.getElementById('historyModal').classList.remove('hidden');
}

function renderHistoryBatch(batch, idx) {
    const open = idx === 0;   // 최신 1건은 펼쳐서.
    const perEmp = (batch.perEmployee || []).slice().sort((a, b) => {
        const order = { '본부장': 0, '팀장': 1, '팀원': 2 };
        return (order[a.title] ?? 9) - (order[b.title] ?? 9);
    });
    return `
        <div class="history-batch ${open ? 'open' : ''}">
            <button class="history-batch-head" type="button" data-history-toggle>
                <span class="history-arrow">▶</span>
                <span class="history-date">${escapeHtml(batch.paidAt || '-')}</span>
                <span class="history-meta">${batch.contractCount || 0}건 계약 · ${perEmp.length}명 분배</span>
                <span class="history-totals">
                    <b style="color:var(--ledger-accent);">₩${formatNum(batch.totalGross || 0)}</b>
                    <span style="color:#8a847e;">실수령 ₩${formatNum(batch.totalNet || 0)}</span>
                </span>
            </button>
            <div class="history-batch-body">
                ${perEmp.map(emp => `
                    <div class="settle-emp">
                        <div class="settle-emp-head">
                            <span><b>${escapeHtml(emp.name || '-')}</b><span class="badge">${escapeHtml((emp.team || '?') + '팀 · ' + (emp.title || ''))}</span></span>
                            <span style="color:#8a847e;font-size:11.5px;">${(emp.lines || []).length}건</span>
                        </div>
                        <ul class="settle-row-list">
                            ${(emp.lines || []).map(l => `
                                <li>
                                    <span style="font-size:11.5px;color:#4f4943;">${escapeHtml(l.role)} · ${escapeHtml(l.label)}</span>
                                    <span style="font-size:11.5px;font-variant-numeric:tabular-nums;">₩${formatNum(l.amount || 0)}</span>
                                </li>`).join('')}
                        </ul>
                        <div class="settle-totals">
                            <div class="stat">건별 수수료 합<b>₩${formatNum(emp.totalGross || 0)}</b></div>
                            <div class="stat">실수령액 (3.3% 차감)<b class="net">₩${formatNum(emp.totalNet || 0)}</b></div>
                            <div class="stat">계좌번호<b style="font-size:12px;font-family:ui-monospace,Menlo,monospace;">${escapeHtml(emp.account || '미입력')}</b></div>
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>`;
}

/* ============== Settle modal ============== */
function openSettleModal() {
    // 정산 대상: 메인 + 표시 중인 현장의 미지급 + '정계약' 상태 + 필터 통과 계약만.
    const mainGroup = groups.find(g => g.isDefault) || groups[0];
    const targetGroupIds = mainGroup
        ? [mainGroup.id, ...[...selectedExtraIds].filter(id => id !== mainGroup.id)]
        : groups.map(g => g.id);
    const all = [];
    targetGroupIds.forEach(gid => {
        const group = groups.find(g => g.id === gid);
        if (!group) return;
        const grpRecs = records.filter(r => r.groupId === gid && r.data?.paid_unpaid && r.data?.status === 'active');
        applyFilters(grpRecs, group.id).forEach(r => all.push({ row: r, group }));
    });

    if (all.length === 0) {
        document.getElementById('settleSummary').textContent = '정산 대상 계약이 없습니다 (정계약 + 미지급 계약만 정산됩니다).';
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
        const orgSettings = orgSettingsFor(group);
        const isLeadMode = orgSettings?.owner_role === 'lead';   // 팀장 모드: 본부장 무시
        const role = emp.data?.title || '';
        const team = parseInt(emp.data?.team, 10);
        const teamLead = employees.find(e => e.data?.title === '팀장' && parseInt(e.data?.team, 10) === team);
        const head = isLeadMode ? null : employees.find(e => e.data?.title === '본부장');
        const label = `[${group.name}] ${d.dong || '?'}동 ${d.ho || '?'}호 (${d.customer || '?'})`;

        if (role === '팀원') {
            payouts.push({ emp, role: '팀원', amount: tbl['팀원'], cid: r.id, label });
            if (teamLead) payouts.push({ emp: teamLead, role: '팀장', amount: tbl['팀장'], cid: r.id, label });
            if (head)     payouts.push({ emp: head,     role: '본부장', amount: tbl['본부장'], cid: r.id, label });
        } else if (role === '팀장') {
            payouts.push({ emp, role: '팀원', amount: tbl['팀원'], cid: r.id, label });
            payouts.push({ emp, role: '팀장', amount: tbl['팀장'], cid: r.id, label });
            if (head)     payouts.push({ emp: head, role: '본부장', amount: tbl['본부장'], cid: r.id, label });
        } else if (role === '본부장' && !isLeadMode) {
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

    // 그룹별 freeze 데이터 빌드 (markPaid 시 그룹 settings 로 저장).
    lastSettlementByGroup = {};
    payouts.forEach(p => {
        const r = records.find(x => x.id === p.cid);
        const gid = r?.groupId;
        if (!gid) return;
        const group = groups.find(g => g.id === gid);
        if (!lastSettlementByGroup[gid]) {
            lastSettlementByGroup[gid] = {
                groupName: group?.name || '-',
                perEmployee: new Map(),  // empId → { empId, name, title, team, account, lines: [] }
            };
        }
        const slot = lastSettlementByGroup[gid].perEmployee;
        if (!slot.has(p.emp.id)) {
            slot.set(p.emp.id, {
                empId: p.emp.id,
                name: p.emp.data?.name || '',
                title: p.emp.data?.title || '',
                team: p.emp.data?.team || '',
                account: p.emp.data?.account || '',
                lines: [],
            });
        }
        slot.get(p.emp.id).lines.push({ cid: p.cid, role: p.role, label: p.label, amount: p.amount });
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
                            <input type="text" inputmode="numeric" data-thousand data-payout-edit="${i}" data-emp="${emp.id}" data-cid="${l.cid}" data-role="${escapeAttr(l.role)}" value="${formatThousand(l.amount)}">
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
            allInps.forEach(i => sum += unformatThousand(i.value));
            sumEl.textContent = '₩' + formatNum(sum);
            netEl.textContent = '₩' + formatNum(Math.round(sum * (1 - TAX_RATE)));
        });
    });
    attachThousandFormat(document.getElementById('settleModal'));

    // 모달이 한 번에 처리할 계약 ID 들 저장
    document.getElementById('settleModal').dataset.contractIds = JSON.stringify([...grandContracts]);
    document.getElementById('settleModal').classList.remove('hidden');
}

async function markPaidFromSettle() {
    const raw = document.getElementById('settleModal').dataset.contractIds || '[]';
    const ids = JSON.parse(raw);
    if (!Array.isArray(ids) || ids.length === 0) { closeModal('settleModal'); return; }
    if (!confirm(`${ids.length}건 계약을 "지급 완료" 로 변경하고 지급 내역으로 보관합니다. 진행할까요?`)) return;

    // 인라인 편집된 amount 를 lastSettlementByGroup 에 반영.
    // 정산 모달 안의 input[data-payout-edit][data-emp][data-cid][data-role] 을 읽어서 매칭.
    const edits = new Map();   // key = `${empId}|${cid}|${role}` → amount
    document.querySelectorAll('#settleModal [data-payout-edit]').forEach(inp => {
        const key = `${inp.dataset.emp}|${inp.dataset.cid}|${inp.dataset.role}`;
        edits.set(key, unformatThousand(inp.value));
    });

    const today = new Date().toISOString().slice(0, 10);
    const batchId = 'b_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

    try {
        // 1. 각 계약 row 에 paidAt + paidBatchId 박기 (paid_unpaid: false 도 같이).
        for (const id of ids) {
            await api('ledger-records', {
                method: 'PATCH',
                body: { id, data: { paid_unpaid: false, paidAt: today, paidBatchId: batchId } },
            });
        }

        // 2. 그룹별로 정산 내역 freeze 후 settings.payment_batches 에 push.
        if (lastSettlementByGroup) {
            for (const gid of Object.keys(lastSettlementByGroup)) {
                const group = groups.find(g => g.id === parseInt(gid, 10));
                if (!group) continue;
                const block = lastSettlementByGroup[gid];
                const perEmployee = [...block.perEmployee.values()].map(emp => {
                    const lines = emp.lines.map(l => ({
                        cid: l.cid, role: l.role, label: l.label,
                        amount: edits.has(`${emp.empId}|${l.cid}|${l.role}`)
                            ? edits.get(`${emp.empId}|${l.cid}|${l.role}`)
                            : l.amount,
                    }));
                    const totalGross = lines.reduce((s, x) => s + (x.amount || 0), 0);
                    return {
                        empId: emp.empId, name: emp.name, title: emp.title,
                        team: emp.team, account: emp.account, lines,
                        totalGross, totalNet: Math.round(totalGross * (1 - TAX_RATE)),
                    };
                });
                const totalGross = perEmployee.reduce((s, e) => s + e.totalGross, 0);
                const totalNet   = perEmployee.reduce((s, e) => s + e.totalNet, 0);
                const contractIds = [...new Set(perEmployee.flatMap(e => e.lines.map(l => l.cid)))];
                const entry = { batchId, paidAt: today, totalGross, totalNet,
                                contractCount: contractIds.length, perEmployee };
                const newSettings = {
                    ...(group.settings || {}),
                    payment_batches: [...(group.settings?.payment_batches || []), entry],
                };
                await api('ledger-groups', { method: 'PATCH', body: { id: group.id, settings: newSettings } });
                group.settings = newSettings;
            }
        }

        lastSettlementByGroup = null;
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
