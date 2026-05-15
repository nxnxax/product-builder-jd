/**
 * customers.js — 고객 관리대장 (Phase 4)
 *
 * page_type='customer'. Phase 1 ledger 시스템 위에서 동작.
 * 가장 단순한 페이지 — 수수료 계산이나 팀 분리 같은 복잡 로직 없음.
 *
 * 모바일 앱 연동 사전 준비:
 *  - source 컬럼 'web' 외에 'mobile-call', 'mobile-ocr' 같이 출처 구분
 *  - client_idempotency_key 로 같은 통화의 중복 전송 차단
 */

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260516-bulk-bar-unify';
import { attachColumnFilters, applyColumnFilters, openRowAddModal, attachPhoneAutoFormat, getEffectiveFields, mountFieldManager,
         exportRecordsToExcel, pickExcelFile, parseExcelFile, suggestFieldMapping, openImportPreviewModal,
         saveImportSession, loadImportSession, clearImportSession,
         findBlankRecordIds, showSweepToast,
         attachCellClickHandlers,
         isLedgerMobile, onLedgerViewportChange } from './ledger-shared.js?v=20260516-bulk-bar-unify';

const MOBILE_PRIMARY_KEYS = ['customer', 'phone', 'date'];

const PAGE_TYPE = 'customer';

const LEVEL_OPTIONS = ['계약예정', '관심도 상', '관심도 중', '관심도 하'];

const DEFAULT_FIELDS = [
    { key: 'no',       label: 'NO',     type: 'auto_number',  filterable: false, width: 48 },
    { key: 'managed',  label: '관리',   type: 'manage_switch',filterable: true,  width: 80 },
    { key: 'date',     label: '날짜',   type: 'date',         filterable: true,  width: 130 },
    { key: 'level',    label: '레벨',   type: 'level_select', filterable: true,  width: 110 },
    { key: 'customer', label: '고객명', type: 'text',         filterable: true,  width: 110 },
    { key: 'phone',    label: '연락처', type: 'tel',          filterable: false, width: 130 },
    { key: 'region',   label: '거주지역',type:'text',          filterable: true,  width: 130 },
    { key: 'content',  label: '내용',   type: 'textarea',     filterable: true,  width: 280 },
    { key: 'memo',     label: '비고',   type: 'text',         filterable: false, width: 140 },
];

// 엑셀 헤더 → 우리 필드 매칭용 한국어 동의어 사전. 매핑 안 되는 컬럼은 fallbackKey 로 합쳐짐.
const FIELD_SYNONYMS = {
    managed:  ['관리', '관리상태', '관리여부'],
    date:     ['날짜', '일자', '등록일', '상담일', '통화일', '접수일', '문의일'],
    level:    ['레벨', '등급', '관심도', '관심', '단계'],
    customer: ['고객명', '고객', '성명', '이름', '의뢰인', '문의자'],
    phone:    ['연락처', '휴대폰', '휴대폰번호', '핸드폰', '핸드폰번호', '전화번호', '전화', '모바일', 'HP', 'tel', 'phone', '번호'],
    region:   ['거주지역', '거주지', '주소', '지역', '사는곳', 'address'],
    content:  ['내용', '상담내용', '통화내용', '상담', '문의내용', '메모내용'],
    memo:     ['비고', '메모', '특이사항', '참고', '기타', 'note', 'remarks'],
};
const FALLBACK_FIELD_KEY = 'content';

/* ============== State ============== */
let supabaseClient = null;
let groups = [];
let expandedGroupIds = new Set();   // 그룹 생성/삭제 추적용 (UI 토글 X)
let selectedExtraIds = new Set();   // 기본 그룹 외에 추가로 표시 중인 그룹들
let extraPanelOpen = false;         // "다른 그룹" picker 열림 여부
let records = [];
let editingGroupId = null;
let filterState = { filters: {} };
let selectedIds = new Set();
let searchByGroup = {};   // groupId → 검색어 (그룹 카드 내부 검색창)

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

/* ============== Groups ============== */
async function loadGroups() {
    try {
        const data = await api('ledger-groups', { query: 'page_type=' + PAGE_TYPE });
        groups = data.items || [];
    } catch (e) {
        showError('그룹 로드 실패: ' + e.message);
        return;
    }
    if (groups.length === 0) {
        document.getElementById('content').innerHTML = `
            <div class="empty">
                <b>아직 등록된 그룹이 없습니다.</b><br>
                상단의 <b>+ 새 그룹</b> 버튼으로 첫 그룹을 만들어 주세요.
            </div>`;
        return;
    }
    if (expandedGroupIds.size === 0) {
        const def = groups.find(g => g.isDefault) || groups[0];
        expandedGroupIds.add(def.id);
    }
    await loadRecords();
}

function bindUI() {
    document.getElementById('newGroupBtn').addEventListener('click', () => openGroupModal(null));
    document.getElementById('groupCancelBtn').addEventListener('click', () => closeModal('groupModal'));
    document.getElementById('groupSaveBtn').addEventListener('click', saveGroup);
    document.getElementById('groupDeleteBtn').addEventListener('click', deleteGroup);

    document.getElementById('settingsCancelBtn').addEventListener('click', () => closeModal('settingsModal'));
    document.getElementById('settingsSaveBtn').addEventListener('click', saveSettings);
    document.getElementById('settingsDeleteBtn').addEventListener('click', deleteGroupFromSettings);

    document.getElementById('bulkClearBtn').addEventListener('click', () => { selectedIds.clear(); renderRecords(); });
    document.getElementById('bulkDeleteBtn').addEventListener('click', bulkDelete);
    document.getElementById('bulkSmsBtn')?.addEventListener('click', openSmsModal);

    document.getElementById('exportAllBtn')?.addEventListener('click', exportAllGroups);
}

/* ============== 설정 모달 (필드 관리) ============== */
let settingsGroupId = null;
let settingsFieldDraft = [];

function openSettingsModal(groupId) {
    settingsGroupId = groupId;
    const g = groups.find(x => x.id === groupId);
    if (!g) return;
    document.getElementById('settingsGroupName').textContent = g.name;
    document.getElementById('settingsErrorMsg').textContent = '';
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
    const newSettings = { ...(g.settings || {}), customFields: settingsFieldDraft };
    try {
        await api('ledger-groups', { method: 'PATCH', body: { id: g.id, settings: newSettings } });
        g.settings = newSettings;
        closeModal('settingsModal');
        renderRecords();
    } catch (e) {
        document.getElementById('settingsErrorMsg').textContent = e.message;
    }
}

async function deleteGroupFromSettings() {
    if (!settingsGroupId) return;
    const g = groups.find(x => x.id === settingsGroupId);
    if (!g) return;
    if (!confirm(`"${g.name}" 그룹과 그 안의 모든 행을 영구 삭제합니다. 진행하시겠습니까?`)) return;
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

/* ============== Group modal ============== */
function openGroupModal(groupId) {
    editingGroupId = groupId;
    const g = groupId ? groups.find(x => x.id === groupId) : null;
    document.getElementById('groupModalTitle').textContent = g ? '그룹 편집' : '새 그룹 만들기';
    document.getElementById('groupNameInput').value = g?.name || '';
    document.getElementById('groupIsDefaultInput').checked = !!g?.isDefault;
    document.getElementById('groupDeleteBtn').style.display = g ? '' : 'none';
    document.getElementById('groupErrorMsg').textContent = '';
    document.getElementById('groupModal').classList.remove('hidden');
}

async function saveGroup() {
    const name = document.getElementById('groupNameInput').value.trim();
    const isDefault = document.getElementById('groupIsDefaultInput').checked;
    if (!name) { document.getElementById('groupErrorMsg').textContent = '그룹 이름을 입력해주세요.'; return; }
    // 중복 이름 체크 (자기 자신 제외)
    const dup = groups.find(g => g.name === name && g.id !== editingGroupId);
    if (dup) { document.getElementById('groupErrorMsg').textContent = `이미 같은 이름의 그룹이 있습니다 ("${name}"). 다른 이름을 사용해 주세요.`; return; }
    try {
        if (editingGroupId) {
            await api('ledger-groups', { method: 'PATCH', body: { id: editingGroupId, name, isDefault } });
        } else {
            const created = await api('ledger-groups', {
                method: 'POST',
                body: { pageType: PAGE_TYPE, name, isDefault, fieldSchema: { fields: DEFAULT_FIELDS }, settings: {} },
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
    if (!confirm('이 그룹과 그 안의 모든 행을 영구 삭제합니다. 진행하시겠습니까?')) return;
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

/* ============== Records ============== */
let _sweptOnce = false;
async function loadRecords() {
    if (groups.length === 0) { records = []; renderRecords(); return; }
    try {
        const allIds = groups.map(g => g.id).join(',');
        const data = await api('ledger-records', { query: 'group_ids=' + allIds });
        records = data.items || [];
        // 페이지 첫 로드 시 1회 자동 sweep — 텍스트 없는 빈 행 일괄 정리.
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

function applyFilters(rows, groupId) {
    let out = applyColumnFilters(filterState.filters, rows, (r, k) => r.data?.[k]);
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
    return out;
}

function renderRecords() {
    const content = document.getElementById('content');
    if (groups.length === 0) return;

    const mainGroup = groups.find(g => g.isDefault) || groups[0];
    const others = groups.filter(g => g.id !== mainGroup.id);

    // bulk-bar 가 #content 자식이면 innerHTML 갱신 시 같이 삭제되므로 잠시 body 로 옮김.
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

    // 사용자 요청 배치: [그룹목록(extra-groups)] → [bulk-bar] → [오산롯데 등 그룹 카드]
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
    const showing = others.filter(g => selectedExtraIds.has(g.id)).length + 1; // 메인은 항상 표시
    return `
        <div class="extra-groups ${extraPanelOpen ? 'open' : ''}">
            <div class="extra-head">
                <button class="extra-toggle" data-toggle-extra type="button">
                    <span class="extra-arrow">▶</span>
                    <h4>그룹목록 <span class="extra-count">${allGroups.length}개</span><span class="extra-count-sub">· ${showing}개 표시 중</span></h4>
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

function renderGroupCard(group) {
    const grpRecs = records.filter(r => r.groupId === group.id);
    const open = expandedGroupIds.has(group.id);
    const bodyHtml = open ? renderTable(group, applyFilters(grpRecs, group.id)) : '';
    return `
        <div class="accordion-card ${open ? 'open' : ''}" data-gid="${group.id}">
            <div class="accordion-head">
                <h3>${escapeHtml(group.name)} <span class="head-count">(${grpRecs.length}건)</span></h3>
                <label class="main-checkbox" title="이 그룹을 메인으로 설정">
                    <input type="checkbox" data-set-main="${group.id}" ${group.isDefault ? 'checked' : ''}>
                    <span>메인그룹</span>
                </label>
                <div class="head-actions">
                    <button type="button" data-toggle-gid="${group.id}" title="${open ? '접기' : '펼치기'}">${open ? '▼ 접기' : '▶ 펼치기'}</button>
                    <button type="button" data-export-gid="${group.id}" title="이 그룹을 엑셀로 다운로드">📥 엑셀 다운로드</button>
                    <button type="button" data-import-gid="${group.id}" title="엑셀 파일을 이 그룹에 업로드">📤 엑셀 가져오기</button>
                    ${loadImportSession(PAGE_TYPE, group.id) ? `<button type="button" data-reimport-gid="${group.id}" title="마지막 가져오기 매핑 다시 열어 수정">🔄 매핑 수정</button>` : ''}
                    <button type="button" data-edit-gid="${group.id}">편집</button>
                    <button type="button" data-settings-gid="${group.id}">⚙ 설정</button>
                </div>
            </div>
            <div class="accordion-body">${bodyHtml}</div>
        </div>`;
}

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
            <button class="tiny-btn primary" type="button" data-add-row data-gid="${group.id}">+ 행 추가</button>
        </div>
        <div class="tbl-wrap">
            <table class="ledger-tbl">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" data-select-all="${group.id}"></th>
                        ${fields.map(f => `<th style="min-width:${f.width || 110}px;" data-col-key="${f.key}">${escapeHtml(f.label)}</th>`).join('')}
                        <th class="col-action"></th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.length === 0
                        ? `<tr><td colspan="${fields.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">표시할 항목이 없습니다.</td></tr>`
                        : rows.map((r, i) => renderRow(r, i + 1, group)).join('')}
                </tbody>
            </table>
        </div>`;
}

function renderMobileCards(group, rows) {
    const fields = getEffectiveFields(group, DEFAULT_FIELDS);
    const cardsHtml = rows.length === 0
        ? `<div class="ledger-cards-empty">표시할 항목이 없습니다.</div>`
        : rows.map((r, i) => renderMobileCard(r, i + 1, group, fields)).join('');
    const q = escapeAttr(searchByGroup[group.id] || '');
    return `
        <div class="ledger-cards-toolbar">
            <div class="ledger-search-wrap">
                <input type="search" class="ledger-search-input" data-search-gid="${group.id}" value="${q}" placeholder="🔍 행 안에서 검색…" autocomplete="off">
                ${q ? `<button type="button" class="ledger-search-clear" data-search-clear-gid="${group.id}" aria-label="검색 지우기">×</button>` : ''}
            </div>
            <button class="tiny-btn primary" type="button" data-add-row data-gid="${group.id}">+ 행 추가</button>
        </div>
        <div class="ledger-cards">${cardsHtml}</div>`;
}

function renderMobileCard(r, displayNo, group, fields) {
    const d = r.data || {};
    const dead = !d.managed;
    const rowCls = ['ledger-card', selectedIds.has(r.id) ? 'selected' : '', dead ? 'row-dead' : ''].filter(Boolean).join(' ');
    // 핵심 정보 (접힘 시 표시)
    const primaryField = fields.find(f => f.key === 'customer') || fields.find(f => f.type !== 'auto_number');
    const titleVal = primaryField ? (d[primaryField.key] || '-') : '-';
    const subFieldKeys = MOBILE_PRIMARY_KEYS.filter(k => k !== (primaryField?.key));
    const subParts = subFieldKeys.map(k => {
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
    // 상세 (펼침 시 표시) — primary 외 모든 필드
    const detailFields = fields.filter(f => f.type !== 'auto_number' && f.key !== primaryField?.key);
    const detailHtml = detailFields.map(f => `
        <div class="ledger-card-field">
            <span class="ledger-card-label">${escapeHtml(f.label || '')}</span>
            <span class="ledger-card-value">${renderCell(f, r, d, displayNo)}</span>
        </div>
    `).join('');
    return `
        <div class="${rowCls}" data-id="${r.id}" data-gid="${group.id}">
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
    document.querySelectorAll('[data-set-main]').forEach(cb => {
        cb.addEventListener('change', () => {
            const id = parseInt(cb.dataset.setMain, 10);
            if (cb.checked) setMainGroup(id);
            else cb.checked = true;  // 메인은 항상 1개 — 단독으로 해제 불가
        });
    });
}

async function setMainGroup(gid) {
    const target = groups.find(g => g.id === gid);
    if (!target || target.isDefault) return;
    const currentMain = groups.find(g => g.isDefault);
    try {
        // 백엔드가 같은 owner+page_type 내 다른 default 를 자동 해제 — PATCH 한 번으로 OK.
        await api('ledger-groups', { method: 'PATCH', body: { id: gid, isDefault: true } });
        if (currentMain) {
            selectedExtraIds.add(currentMain.id);   // 옛 메인은 picker 에 선택 상태로 유지
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
    const dead = !d.managed;
    const cls = [
        selectedIds.has(r.id) ? 'selected' : '',
        dead ? 'row-dead' : '',
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
                return `<td${cls ? ` class="${cls}"` : ''}${label ? ` data-label="${escapeHtml(label)}"` : ''}>${renderCell(f, r, d, displayNo)}</td>`;
            }).join('')}
            <td class="col-action">
                <button class="row-action-btn" data-edit-row="${r.id}" title="수정"><span class="ico">✎</span><span class="lbl">수정</span></button>
                <button class="row-action-btn danger" data-delete-row="${r.id}" title="삭제"><span class="ico">×</span><span class="lbl">삭제</span></button>
            </td>
        </tr>`;
}

function renderCell(f, r, d, displayNo) {
    const id = r.id;
    const v = d[f.key];
    if (f.type === 'auto_number') return `<span class="col-no">${displayNo}</span>`;
    if (f.type === 'manage_switch') {
        const on = !!d.managed;
        return `
            <label class="toggle-switch ${on ? 'on' : 'off'}" data-manage-switch data-id="${id}" title="${on ? '관리중' : '비관리중'}">
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label">${on ? '관리중' : '비관리중'}</span>
            </label>`;
    }
    if (f.type === 'level_select') {
        const text = v || '계약예정';
        // 셀 클릭 → LEVEL_OPTIONS 순환 ('계약예정' → '관심도 상' → '중' → '하' → '계약예정').
        const opts = escapeAttr(JSON.stringify(LEVEL_OPTIONS));
        return `<span class="cell-text level-pill" data-cell-cycle data-id="${id}" data-field="level" data-value="${escapeAttr(text)}" data-cycle-options='${opts}' title="클릭하여 다음 레벨로">${escapeHtml(text)}</span>`;
    }
    if (f.type === 'textarea') {
        return v ? `<span class="cell-text cell-multiline">${escapeHtml(v)}</span>` : `<span class="cell-empty">-</span>`;
    }
    if (f.type === 'date') {
        return v ? `<span class="cell-text">${formatDateDisplay(v)}</span>` : `<span class="cell-empty">-</span>`;
    }
    if (f.type === 'toggle') {
        const on = !!v;
        return `<span class="toggle-cell ${on ? 'on' : 'off'}" data-cell-toggle data-id="${id}" data-field="${escapeAttr(f.key)}" data-value="${on ? '1' : '0'}" data-on-label="${escapeAttr(f.onLabel || 'ON')}" data-off-label="${escapeAttr(f.offLabel || 'OFF')}" title="클릭하여 토글">${escapeHtml(on ? (f.onLabel || 'ON') : (f.offLabel || 'OFF'))}</span>`;
    }
    if (f.type === 'switch') {
        const on = !!v;
        const lbl = on ? (f.onLabel || 'ON') : (f.offLabel || 'OFF');
        return `<span class="switch-cell ${on ? 'on' : 'off'}" data-cell-switch data-id="${id}" data-field="${escapeAttr(f.key)}" data-value="${on ? '1' : '0'}" aria-label="${escapeAttr(lbl)}" title="클릭하여 토글"><span class="switch-track"><span class="switch-thumb"></span></span><span class="switch-label">${escapeHtml(lbl)}</span></span>`;
    }
    // tel / text / number / resident_id / 기타: 모두 read-only span (편집은 ✎ 수정 버튼 → 모달)
    return v ? `<span class="cell-text">${escapeHtml(v)}</span>` : `<span class="cell-empty">-</span>`;
}

function formatDateDisplay(v) {
    if (!v) return '';
    // YYYY-MM-DD → YYYY.MM.DD (있으면 그대로 표시)
    return escapeHtml(String(v).replace(/-/g, '.'));
}

function bindTableEvents() {
    attachColumnFilters({
        state: filterState,
        headers: document.querySelectorAll('.ledger-tbl thead th[data-col-key]'),
        fields: DEFAULT_FIELDS,
        getRows: () => records,
        getValue: (r, k) => r.data?.[k],
        onChange: () => renderRecords(),
        labelFor: (field, raw) => {
            if (field.key === 'managed') {
                if (raw === 'true' || raw === true) return '관리중';
                if (raw === 'false' || raw === false) return '비관리중';
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
    document.querySelectorAll('[data-manage-switch]').forEach(b => {
        b.addEventListener('click', (e) => { e.preventDefault(); toggleManaged(parseInt(b.dataset.id, 10)); });
    });
    // 그룹별 검색 입력 — input 시 그 그룹만 필터링 후 재렌더 + 같은 input 포커스 복원.
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

    // 사용자 정의 toggle/switch + 레벨 pill 셀 클릭 즉시 토글/순환 (desktop 표 + mobile 카드 둘 다 커버)
    attachCellClickHandlers({
        root: document,
        onToggle: async ({ id, fieldKey, nextValue }) => {
            await updateRowField(id, fieldKey, nextValue);
            renderRecords();
        },
        onCycle: async ({ id, fieldKey, nextValue }) => {
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

function addRow(gid) {
    const today = new Date().toISOString().slice(0, 10);
    const group = groups.find(g => g.id === gid);
    openRowAddModal({
        title: '새 고객 추가',
        fields: getEffectiveFields(group, DEFAULT_FIELDS),
        defaults: { date: today, managed: true, level: '계약예정' },
        customRender: customerCustomRender,
        onSubmit: async (data) => {
            await api('ledger-records', { method: 'POST', body: { groupId: gid, data, source: 'web' } });
            await loadRecords();
        },
    });
}

function editRow(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    const group = groups.find(g => g.id === r.groupId);
    openRowAddModal({
        title: '고객 정보 수정',
        confirmLabel: '저장',
        fields: getEffectiveFields(group, DEFAULT_FIELDS),
        defaults: { ...r.data },
        customRender: customerCustomRender,
        onSubmit: async (data) => {
            await api('ledger-records', { method: 'PATCH', body: { id, data } });
            await loadRecords();
        },
    });
}

function customerCustomRender(f, defaults) {
    const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
    if (f.type === 'manage_switch') {
        const checked = defaults.managed !== false ? 'checked' : '';
        return `<div class="modal-row">${lbl}<div class="row-control"><label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#4f4943;font-weight:400;cursor:pointer;margin:0;">
            <input type="checkbox" data-field="managed" ${checked} style="width:auto;accent-color:#c8362c;"> 관리 대상 (체크 해제 시 비관리)
        </label></div></div>`;
    }
    if (f.type === 'level_select') {
        const v = defaults.level || '';
        const opts = ['<option value="">-</option>']
            .concat(LEVEL_OPTIONS.map(o => `<option value="${escapeAttr(o)}" ${v === o ? 'selected' : ''}>${escapeHtml(o)}</option>`))
            .join('');
        return `<div class="modal-row">${lbl}<div class="row-control"><select data-field="level">${opts}</select></div></div>`;
    }
    return null;
}

async function updateRowField(id, field, value) {
    try {
        await api('ledger-records', { method: 'PATCH', body: { id, data: { [field]: value } } });
        const r = records.find(x => x.id === id);
        if (r) { r.data = r.data || {}; r.data[field] = value; }
    } catch (e) {
        showError('저장 실패: ' + e.message);
        await loadRecords();
    }
}

async function toggleManaged(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    const next = !r.data?.managed;
    await updateRowField(id, 'managed', next);
    renderRecords();
}

async function deleteRow(id) {
    if (!confirm('이 행을 삭제할까요?')) return;
    try {
        await api('ledger-records', { method: 'DELETE', body: { id } });
        await loadRecords();
    } catch (e) { showError('삭제 실패: ' + e.message); }
}

/* ============== 엑셀 다운로드 / 업로드 ============== */
async function exportGroup(gid) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const fields = getEffectiveFields(g, DEFAULT_FIELDS);
    const rows = records.filter(r => r.groupId === gid);
    if (rows.length === 0) {
        if (!confirm(`"${g.name}" 그룹에 행이 없습니다. 빈 양식만 다운로드할까요?`)) return;
    }
    try {
        await exportRecordsToExcel({
            sheets: [{ name: g.name, fields, rows }],
            fileName: `고객관리대장_${g.name}_${todayStamp()}.xlsx`,
        });
    } catch (e) { showError('엑셀 다운로드 실패: ' + e.message); }
}

async function exportAllGroups() {
    if (groups.length === 0) { alert('내보낼 그룹이 없습니다.'); return; }
    const sheets = groups.map(g => ({
        name: g.name,
        fields: getEffectiveFields(g, DEFAULT_FIELDS),
        rows: records.filter(r => r.groupId === g.id),
    }));
    try {
        await exportRecordsToExcel({ sheets, fileName: `고객관리대장_전체_${todayStamp()}.xlsx` });
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
    const sheet = parsed[0];   // 단일 시트만 사용 (첫 시트)
    const fields = getEffectiveFields(g, DEFAULT_FIELDS);
    const suggested = suggestFieldMapping(sheet.headers, fields, FIELD_SYNONYMS);

    openImportPreviewModal({
        title: `"${g.name}" 에 가져오기`,
        sheetName: sheet.name,
        headers: sheet.headers,
        rows: sheet.rows,
        skippedBlank: sheet.skippedBlank || 0,
        fields,
        fallbackKey: FALLBACK_FIELD_KEY,
        suggested,
        onConfirm: async (mappedRows, finalMapping, ctx) => {
            const newIds = await applyImportRows(gid, mappedRows, ctx);
            saveImportSession(PAGE_TYPE, gid, { sheet, mapping: finalMapping, recordIds: newIds });
            await loadRecords();
        },
    });
}

async function applyImportRows(gid, mappedRows, ctx) {
    const ids = [];
    const total = mappedRows.length;
    ctx?.setProgress?.(0, total);
    for (let i = 0; i < mappedRows.length; i++) {
        const data = mappedRows[i];
        if (data.managed === undefined || data.managed === '') data.managed = true;
        const res = await api('ledger-records', { method: 'POST', body: { groupId: gid, data, source: 'excel' } });
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
                catch (e) { /* 이미 일부 삭제됐을 수 있음 — 진행 */ }
            }
            const newIds = await applyImportRows(gid, mappedRows, ctx);
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

/* ============== Utils ============== */
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s) { return String(s ?? '').replace(/"/g, '&quot;'); }
function showError(msg) {
    console.error(msg);
    const c = document.getElementById('content');
    if (c) c.insertAdjacentHTML('afterbegin', `<div style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:13px;">${escapeHtml(msg)}</div>`);
}

/* ============== SMS 단체 발송 모달 (선택 고객들에게) ============== */
async function openSmsModal() {
    if (selectedIds.size === 0) { alert('먼저 보낼 고객을 체크해 주세요.'); return; }

    // 1) 자격증명 확인 — 미연동이면 안내 + 설정 페이지로 이동
    let cred;
    try { cred = await apiRequest('sms-credentials'); }
    catch (e) { alert('문자 설정을 불러오지 못했습니다: ' + (e.message || e)); return; }
    if (!cred?.configured) {
        if (confirm('Solapi 가 연동되어 있지 않습니다.\n\n본인의 Solapi 계정 + 발신번호를 등록해야 문자 발송이 가능합니다.\n지금 문자 설정 페이지로 이동할까요?')) {
            window.location.href = 'profile.html?tab=sms';
        }
        return;
    }

    const ids = [...selectedIds];
    const md = document.createElement('div');
    md.className = 'modal-backdrop sms-modal';
    md.style.zIndex = '300';
    md.innerHTML = `
        <div class="modal-panel">
            <header class="modal-header">
                <div>
                    <h2>📨 문자 단체 발송</h2>
                    <p class="modal-subtitle">선택한 ${ids.length}명의 고객에게 문자를 보냅니다.</p>
                </div>
            </header>
            <div class="modal-body sms-modal-body">
                <div class="sms-notice">
                    <b>안내</b><br>
                    · 문자 요금은 본인의 <b>Solapi 계정 잔액</b>에서 차감됩니다.<br>
                    · 발신번호: <code>${escapeHtml(cred.senderPhone || '미등록')}</code><br>
                    · 광고성 문자는 <b>수신동의 고객에게만</b> 발송해야 하며, 본문에 <b>[수신거부]</b> 문구가 필요합니다.
                </div>
                <label class="sms-label">문자 내용</label>
                <textarea id="smsText" rows="6" maxlength="2000" placeholder="안녕하세요. ○○분양 담당자입니다 …&#10;&#10;(광고성일 경우 마지막 줄에 '[수신거부] 080-XXX-XXXX' 같이 거부 방법을 명시해 주세요.)"></textarea>
                <div class="sms-meta">
                    <span><b id="smsLen">0</b> 자 · 90바이트 초과 시 LMS 로 자동 전환</span>
                    <span>선택 고객 <b>${ids.length}</b>명 · 발송 건수는 동의/번호 확인 후 결정</span>
                </div>
                <p class="form-help error" data-error style="margin:8px 0 0;display:none;"></p>
            </div>
            <footer class="modal-footer">
                <button class="tiny-btn" type="button" data-cancel>취소</button>
                <button class="tiny-btn primary" type="button" data-send>발송하기</button>
            </footer>
        </div>
    `;
    document.body.appendChild(md);

    const textarea = md.querySelector('#smsText');
    const lenEl    = md.querySelector('#smsLen');
    const errEl    = md.querySelector('[data-error]');
    const sendBtn  = md.querySelector('[data-send]');
    const cancelBtn = md.querySelector('[data-cancel]');

    const close = () => md.remove();
    cancelBtn.addEventListener('click', close);
    md.addEventListener('click', (e) => { if (e.target === md) close(); });

    textarea.addEventListener('input', () => {
        lenEl.textContent = textarea.value.length;
    });
    textarea.focus();

    sendBtn.addEventListener('click', async () => {
        const text = textarea.value.trim();
        if (text === '') { errEl.textContent = '문자 내용을 입력해주세요.'; errEl.style.display = ''; return; }
        if (!confirm('광고성 문자는 수신동의 고객에게만 발송해야 하며, 수신거부 문구가 필요합니다.\n\n계속 진행할까요?')) return;

        sendBtn.disabled = true;
        sendBtn.textContent = '발송 중…';
        errEl.style.display = 'none';
        try {
            const res = await apiRequest('sms-credentials');   // 한 번 더 확인 (방어적)
            if (!res?.configured) throw new Error('Solapi 가 해제되었습니다. 설정에서 다시 등록해 주세요.');
            const resp = await fetch('sms/send-bulk.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + (await getAccessTokenForSms()),
                },
                body: JSON.stringify({ customer_ids: ids, message: text }),
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok || !data.ok) {
                const msg = data?.error || ('HTTP ' + resp.status);
                if (data?.action === 'open_settings') {
                    if (confirm(msg + '\n\n문자 설정 페이지로 이동할까요?')) {
                        window.location.href = 'profile.html?tab=sms';
                    }
                    return;
                }
                throw new Error(msg);
            }
            close();
            showSmsResultModal(data);
        } catch (e) {
            sendBtn.disabled = false;
            sendBtn.textContent = '발송하기';
            errEl.textContent = '발송 실패: ' + (e.message || e);
            errEl.style.display = '';
        }
    });
}

async function getAccessTokenForSms() {
    const { getAccessToken } = await import('./auth-shared.js?v=20260516-bulk-bar-unify');
    return await getAccessToken();
}

function showSmsResultModal(data) {
    const md = document.createElement('div');
    md.className = 'modal-backdrop sms-modal';
    md.style.zIndex = '300';
    const failedRows = (data.failed || []).map(f => `
        <tr><td>${escapeHtml(f.phone || '')}</td><td>${escapeHtml(f.error || '')}</td></tr>
    `).join('');
    const skippedRows = (data.skipped || []).map(s => `
        <tr><td>#${s.customer_id}</td><td>${escapeHtml(s.reason || '')}</td></tr>
    `).join('');
    md.innerHTML = `
        <div class="modal-panel">
            <header class="modal-header">
                <div>
                    <h2>${data.dryRun ? '🧪 dry-run 결과' : '✅ 발송 결과'}</h2>
                    <p class="modal-subtitle">provider: ${escapeHtml(data.provider || '')}${data.dryRun ? ' · 실제 발송 안 됨' : ''}</p>
                </div>
            </header>
            <div class="modal-body sms-modal-body">
                <div class="sms-result-summary">
                    <div><b>선택</b> ${data.totalSelected || 0}명</div>
                    <div><b>동의 통과</b> ${data.afterConsent || 0}명</div>
                    <div><b>고유 번호</b> ${data.uniquePhones || 0}건</div>
                    <div class="ok"><b>성공</b> ${data.success || 0}건</div>
                    <div class="ng"><b>실패</b> ${(data.failed || []).length}건</div>
                </div>
                ${failedRows ? `
                    <h3 class="sms-table-head">실패한 번호 / 사유</h3>
                    <table class="sms-result-tbl"><thead><tr><th>번호</th><th>사유</th></tr></thead><tbody>${failedRows}</tbody></table>
                ` : ''}
                ${skippedRows ? `
                    <h3 class="sms-table-head">제외된 고객 / 사유</h3>
                    <table class="sms-result-tbl"><thead><tr><th>고객 ID</th><th>사유</th></tr></thead><tbody>${skippedRows}</tbody></table>
                ` : ''}
            </div>
            <footer class="modal-footer">
                <button class="tiny-btn primary" type="button" data-close>확인</button>
            </footer>
        </div>
    `;
    document.body.appendChild(md);
    const close = () => md.remove();
    md.querySelector('[data-close]').addEventListener('click', close);
    md.addEventListener('click', (e) => { if (e.target === md) close(); });
}
