/**
 * org.js — 조직도 페이지 (Phase 2)
 *
 * page_type='org' 의 ledger-groups / ledger-records API 위에서 동작.
 * 그룹 / 멀티선택 / 팀별 테이블 / 필터 / 선택삭제 / 설정(활성 팀 + 직급별·타입별 수수료).
 * Phase 3 의 계약자 관리대장이 이 그룹의 settings.commissions 를 읽어 정산.
 */

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260517-session-persist';
import { attachColumnFilters, applyColumnFilters, openRowAddModal, attachPhoneAutoFormat, attachThousandFormat, formatThousand, unformatThousand, getEffectiveFields, mountFieldManager,
         exportRecordsToExcel, pickExcelFile, parseExcelFile, suggestFieldMapping, openImportPreviewModal,
         saveImportSession, loadImportSession, clearImportSession,
         findBlankRecordIds, showSweepToast,
         attachCellClickHandlers,
         isLedgerMobile, onLedgerViewportChange } from './ledger-shared.js?v=20260516-nickname-header';

const PAGE_TYPE = 'org';

const TITLE_OPTIONS = ['본부장', '팀장', '팀원'];

const DEFAULT_FIELD_SCHEMA = {
    // team 컬럼은 섹션 헤더 (1팀/2팀/...) 가 이미 표시하므로 row 에서는 빼고
    // data.team 에만 저장. 행 추가 시 자동 채움.
    fields: [
        { key: 'no',      label: 'NO',     type: 'auto_number', filterable: false },
        { key: 'joined',  label: '투입일', type: 'date',        filterable: true  },
        { key: 'title',   label: '직함',   type: 'title_select',filterable: true  },
        { key: 'name',    label: '이름',   type: 'text',        filterable: true  },
        { key: 'rrn',     label: '주민번호', type: 'resident_id', filterable: false },
        { key: 'phone',   label: '연락처', type: 'tel',         filterable: false },
        { key: 'account', label: '계좌번호', type: 'text',      filterable: true  },
        { key: 'memo',    label: '비고',   type: 'text',        filterable: false },
    ],
};

const FIELD_SYNONYMS = {
    joined:  ['투입일', '입사일', '시작일', '가입일', '입사년월일', '입사일자', '등록일', '시작'],
    title:   ['직함', '직급', '직위', '직책'],
    name:    ['이름', '성명', '직원명', '사원명', '담당자명', '담당자', '본부장', '팀장', '팀원'],
    rrn:     ['주민번호', '주민등록번호', '주민', 'rrn'],
    phone:   ['연락처', '휴대폰', '휴대폰번호', '핸드폰', '핸드폰번호', '전화번호', '전화', '모바일', 'HP', 'tel', 'phone'],
    account: ['계좌', '계좌번호', '입금계좌', '통장번호', '입금정보', '입금'],
    memo:    ['비고', '메모', '특이사항', '참고', '기타', 'note', 'remarks'],
    // team 은 row 에서 빠져있지만 import 시 엑셀에 '팀' 컬럼이 있으면 인식해서 data.team 채움.
    team:    ['팀', '소속팀', '소속', '팀번호'],
};
const FALLBACK_FIELD_KEY = 'memo';

const DEFAULT_SETTINGS = {
    active_teams: [1, 2, 3],
    default_commissions: { '본부장': 0, '팀장': 0, '팀원': 0 },
    type_commissions: [],
    owner_role: 'head',  // 'head' = 본부장 (전체 위계 사용) | 'lead' = 팀장 (본부장 정보 안 씀)
};

// 그룹 owner_role 에 따른 사용 가능 직급
function rolesAllowedFor(ownerRole) {
    return ownerRole === 'lead' ? ['팀장', '팀원'] : ['본부장', '팀장', '팀원'];
}
function ownerRoleOf(group) {
    return (group?.settings?.owner_role === 'lead') ? 'lead' : 'head';
}

/* ============== State ============== */
let supabaseClient = null;
let groups = [];                  // all groups for this page+user
let expandedGroupIds = new Set(); // 그룹 생성/삭제 추적용 (UI 토글 X)
let selectedExtraIds = new Set(); // 기본 외 추가 표시 중인 그룹들
let extraPanelOpen = false;       // "다른 그룹" picker 열림 여부
let records = [];                 // 모든 그룹의 records 한꺼번에
let editingGroupId = null;        // group being edited in modal
let settingsGroupId = null;       // settings 모달이 열려있는 그룹
let filterState = { filters: {} };// { [key]: Set<value> }
let selectedIds = new Set();
let searchByGroup = {};   // groupId → 그룹 카드 내부 검색어
let typeCommissionRows = [];      // working copy in settings modal
let settingsFieldDraft = [];      // working copy of custom fields

/* ============== Boot ============== */
(async function boot() {
    try {
        const result = await initSupabase();
        supabaseClient = result?.client || null;
    } catch (e) {
        supabaseClient = null;
    }
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

/* ============== API helper (auth-shared 의 apiRequest 래핑) ============== */
async function api(resource, opts = {}) {
    return apiRequest(resource, {
        method: opts.method || 'GET',
        body: opts.body ? JSON.stringify(opts.body) : undefined,
        query: opts.query,
    });
}

/* ============== Group accordion ============== */
async function loadGroups() {
    try {
        const data = await api('ledger-groups', { query: 'page_type=' + PAGE_TYPE });
        groups = data.items || [];
    } catch (e) {
        showError('그룹을 불러오지 못했습니다: ' + e.message);
        return;
    }

    if (groups.length === 0) {
        document.getElementById('content').innerHTML = `
            <div class="empty">
                <b>아직 조직도 그룹이 없습니다.</b><br>
                상단의 <b>+ 새 현장 추가</b> 버튼을 눌러 첫 현장을 만들어 주세요.
            </div>`;
        return;
    }

    // 기본 그룹이 있으면 자동 펼침. 없으면 첫 그룹.
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
    document.getElementById('settingsDeleteBtn').addEventListener('click', deleteGroupFromSettings);
    document.getElementById('settingsSaveBtn').addEventListener('click', saveSettings);
    document.getElementById('addTypeCommBtn').addEventListener('click', () => {
        typeCommissionRows.push({ type: '', '본부장': 0, '팀장': 0, '팀원': 0 });
        const g = groups.find(x => x.id === settingsGroupId);
        renderTypeCommList(ownerRoleOf(g));
    });

    document.getElementById('bulkClearBtn').addEventListener('click', () => {
        selectedIds.clear();
        renderRecords();
    });
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
    if (!name) {
        document.getElementById('groupErrorMsg').textContent = '현장 이름을 입력해주세요.';
        return;
    }
    const dup = groups.find(g => g.name === name && g.id !== editingGroupId);
    if (dup) {
        document.getElementById('groupErrorMsg').textContent = `이미 같은 이름의 현장이 있습니다 ("${name}"). 다른 이름을 사용해 주세요.`;
        return;
    }
    try {
        if (editingGroupId) {
            await api('ledger-groups', { method: 'PATCH', body: { id: editingGroupId, name, isDefault } });
        } else {
            const created = await api('ledger-groups', {
                method: 'POST',
                body: {
                    pageType: PAGE_TYPE, name, isDefault,
                    fieldSchema: DEFAULT_FIELD_SCHEMA,
                    settings: DEFAULT_SETTINGS,
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
    if (!confirm('이 현장과 그 안의 모든 행을 영구 삭제합니다. 진행하시겠습니까?')) return;
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
    if (!confirm(`"${g.name}" 현장과 그 안의 모든 행을 영구 삭제합니다. 진행하시겠습니까?`)) return;
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
function openSettingsModal(groupId) {
    settingsGroupId = groupId;
    const g = groups.find(x => x.id === groupId);
    if (!g) return;
    const s = { ...DEFAULT_SETTINGS, ...(g.settings || {}) };
    document.getElementById('settingsGroupName').textContent = g.name;
    document.querySelectorAll('#activeTeamsBox input[data-team]').forEach(cb => {
        cb.checked = (s.active_teams || []).includes(parseInt(cb.value, 10));
    });
    const dc = s.default_commissions || {};
    document.getElementById('commHQ').value = formatThousand(dc['본부장'] ?? 0);
    document.getElementById('commTL').value = formatThousand(dc['팀장']   ?? 0);
    document.getElementById('commTM').value = formatThousand(dc['팀원']   ?? 0);
    // owner_role 에 따라 본부장 칸 숨김 + 활성팀 row 숨김.
    const role = s.owner_role === 'lead' ? 'lead' : 'head';
    document.getElementById('defaultCommGrid').classList.toggle('role-lead', role === 'lead');
    document.getElementById('activeTeamsRow').classList.toggle('hidden', role === 'lead');
    typeCommissionRows = JSON.parse(JSON.stringify(s.type_commissions || []));
    renderTypeCommList(role);
    settingsFieldDraft = JSON.parse(JSON.stringify(s.customFields || []));
    mountFieldManager({
        container: document.getElementById('fieldManagerBox'),
        defaultFields: DEFAULT_FIELD_SCHEMA.fields,
        customFields: settingsFieldDraft,
        onChange: (next) => { settingsFieldDraft = next; },
    });
    document.getElementById('settingsErrorMsg').textContent = '';
    document.getElementById('settingsModal').classList.remove('hidden');
    attachThousandFormat(document.getElementById('settingsModal'));
}

function renderTypeCommList(role) {
    const el = document.getElementById('typeCommList');
    el.classList.toggle('role-lead', role === 'lead');
    if (typeCommissionRows.length === 0) {
        el.innerHTML = '<div style="text-align:center;padding:16px;color:#8a847e;font-size:12.5px;">등록된 타입별 수수료가 없습니다.</div>';
        return;
    }
    el.innerHTML = typeCommissionRows.map((r, i) => `
        <div class="type-comm-row ${role === 'lead' ? 'role-lead' : ''}" data-i="${i}">
            <input type="text" placeholder="타입(예: 59A)" data-f="type" value="${escapeAttr(r.type || '')}">
            <input type="text" inputmode="numeric" data-thousand placeholder="본부장" data-f="본부장" data-role-cell="head" value="${formatThousand(r['본부장'] ?? 0)}">
            <input type="text" inputmode="numeric" data-thousand placeholder="팀장" data-f="팀장" data-role-cell="lead" value="${formatThousand(r['팀장'] ?? 0)}">
            <input type="text" inputmode="numeric" data-thousand placeholder="팀원" data-f="팀원" value="${formatThousand(r['팀원'] ?? 0)}">
            <button class="x" type="button" data-del>×</button>
        </div>`).join('');
    attachThousandFormat(el);
    el.querySelectorAll('[data-del]').forEach(b => {
        b.addEventListener('click', () => {
            const i = parseInt(b.closest('.type-comm-row').dataset.i, 10);
            typeCommissionRows.splice(i, 1);
            renderTypeCommList();
        });
    });
    el.querySelectorAll('input').forEach(inp => {
        inp.addEventListener('input', () => {
            const i = parseInt(inp.closest('.type-comm-row').dataset.i, 10);
            const f = inp.dataset.f;
            typeCommissionRows[i][f] = (f === 'type') ? inp.value : unformatThousand(inp.value);
        });
    });
}

async function saveSettings() {
    const g = groups.find(x => x.id === settingsGroupId);
    if (!g) return;
    const active_teams = Array.from(document.querySelectorAll('#activeTeamsBox input[data-team]:checked'))
        .map(cb => parseInt(cb.value, 10));
    const default_commissions = {
        '본부장': unformatThousand(document.getElementById('commHQ').value),
        '팀장':   unformatThousand(document.getElementById('commTL').value),
        '팀원':   unformatThousand(document.getElementById('commTM').value),
    };
    const type_commissions = typeCommissionRows.filter(r => r.type && r.type.trim());

    const newSettings = { ...g.settings, active_teams, default_commissions, type_commissions, customFields: settingsFieldDraft };
    try {
        await api('ledger-groups', { method: 'PATCH', body: { id: g.id, settings: newSettings } });
        g.settings = newSettings;
        closeModal('settingsModal');
        renderRecords();
    } catch (e) {
        document.getElementById('settingsErrorMsg').textContent = e.message;
    }
}

/* ============== Records ============== */
let _sweptOnce = false;
async function loadRecords() {
    if (groups.length === 0) {
        records = [];
        renderRecords();
        return;
    }
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

/* 검색 → 기존 DOM 행 hide/show. renderRecords() 호출 안 함 → input 보존 → IME 안 깨짐. */
function filterDOMRowsBySearch(gid, query) {
    const card = document.querySelector(`.accordion-card[data-gid="${gid}"]`);
    if (!card) return;
    const q = (query || '').trim().toLowerCase();
    const rows = card.querySelectorAll('.ledger-cards > .ledger-card, .ledger-tbl tbody tr');
    rows.forEach(el => {
        if (el.querySelector('td[colspan]')) return;
        const matched = !q || el.textContent.toLowerCase().includes(q);
        el.style.display = matched ? '' : 'none';
    });
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
    const groupRecs = records.filter(r => r.groupId === group.id);

    // 활성 팀 (이 그룹의 settings)
    const s = { ...DEFAULT_SETTINGS, ...(group.settings || {}) };
    const isLeadMode = ownerRoleOf(group) === 'lead';
    const activeTeams = new Set(s.active_teams && s.active_teams.length > 0 ? s.active_teams : [1, 2, 3]);

    // 컬럼 헤더 필터 + 그룹별 텍스트 검색 (input data-search-gid)
    let filtered = applyColumnFilters(filterState.filters, groupRecs, (r, k) => r.data?.[k]);
    const q = (searchByGroup[group.id] || '').trim().toLowerCase();
    if (q !== '') {
        filtered = filtered.filter(r => {
            const d = r.data || {};
            return Object.values(d).some(v => {
                if (v == null || v === '') return false;
                return String(v).toLowerCase().includes(q);
            });
        });
    }

    // 본부장은 어느 팀에도 속하지 않는 별도 셀.
    const heads = filtered.filter(r => r.data?.title === '본부장');
    const others = filtered.filter(r => r.data?.title !== '본부장');

    const byTeam = {};
    [...activeTeams].sort((a, b) => a - b).forEach(t => byTeam[t] = []);
    byTeam.unassigned = [];
    others.forEach(r => {
        const t = parseInt(r.data?.team, 10);
        if (activeTeams.has(t)) byTeam[t].push(r);
        else byTeam.unassigned.push(r);
    });

    const open = expandedGroupIds.has(group.id);
    let bodyHtml = '';
    if (open) {
        // 팀장 모드가 아니면 본부장 섹션을 가장 상단에 노출 (비어있어도 추가 버튼 노출).
        if (!isLeadMode) {
            bodyHtml += renderTeamSection('본부장', 0, heads, group, { hq: true });
        }
        bodyHtml += [...activeTeams].sort((a, b) => a - b).map(t => renderTeamSection(t + '팀', t, byTeam[t], group)).join('');
        if (byTeam.unassigned.length > 0) bodyHtml += renderTeamSection('미지정', null, byTeam.unassigned, group);
    }

    const role = ownerRoleOf(group);
    return `
        <div class="accordion-card ${open ? 'open' : ''}" data-gid="${group.id}">
            <div class="accordion-head">
                <h3>${escapeHtml(group.name)} <span class="head-count">(${groupRecs.length}명)</span></h3>
                <span class="role-label">내 직책</span>
                <select class="owner-role-select" data-set-role="${group.id}" title="내 직책">
                    <option value="head" ${role === 'head' ? 'selected' : ''}>본부장</option>
                    <option value="lead" ${role === 'lead' ? 'selected' : ''}>팀장</option>
                </select>
                <label class="main-checkbox" title="이 현장을 메인으로 설정">
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
            <div class="accordion-body">
                ${open ? `<div class="ledger-card-toolbar">
                    <div class="ledger-search-wrap">
                        <input type="search" class="ledger-search-input" data-search-gid="${group.id}" value="${escapeAttr(searchByGroup[group.id] || '')}" placeholder="🔍 행 안에서 검색…" autocomplete="off">
                        ${(searchByGroup[group.id] || '') ? `<button type="button" class="ledger-search-clear" data-search-clear-gid="${group.id}" aria-label="검색 지우기">×</button>` : ''}
                    </div>
                </div>` : ''}
                ${bodyHtml || '<div style="padding:30px 24px;text-align:center;color:#8a847e;font-size:13px;">등록된 인원이 없습니다.</div>'}
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
            else cb.checked = true;
        });
    });
    document.querySelectorAll('[data-set-role]').forEach(sel => {
        sel.addEventListener('change', () => setOwnerRole(parseInt(sel.dataset.setRole, 10), sel.value));
    });
}

async function setOwnerRole(gid, newRole) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const newSettings = { ...(g.settings || {}), owner_role: newRole === 'lead' ? 'lead' : 'head' };
    try {
        await api('ledger-groups', { method: 'PATCH', body: { id: gid, settings: newSettings } });
        g.settings = newSettings;
        renderRecords();
    } catch (e) {
        showError('직책 변경 실패: ' + (e.message || ''));
    }
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

const MOBILE_PRIMARY_KEYS_ORG = ['name', 'title', 'phone'];

function renderTeamSection(title, teamNo, rows, group, opts) {
    const fields = getEffectiveFields(group, DEFAULT_FIELD_SCHEMA.fields);
    const allowedTitles = rolesAllowedFor(ownerRoleOf(group));
    const isHq = !!(opts && opts.hq);
    const bodyHtml = isLedgerMobile()
        ? renderMobileTeamCards(rows, title, group, fields, allowedTitles)
        : `
            <div class="tbl-wrap">
                <table class="ledger-tbl">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" data-select-all="${teamNo ?? ''}"></th>
                            ${fields.map(f => f.type === 'auto_number'
                                ? `<th class="col-no">NO</th>`
                                : `<th data-col-key="${f.key}">${escapeHtml(f.label)}</th>`).join('')}
                            <th class="col-action"></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.length === 0
                            ? `<tr><td colspan="${fields.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">${title}에 등록된 인원이 없습니다.</td></tr>`
                            : rows.map((r, idx) => renderRow(r, idx + 1, allowedTitles, fields)).join('')}
                    </tbody>
                </table>
            </div>`;
    return `
        <section class="team-section ${isHq ? 'team-section-hq' : ''}" data-team="${teamNo ?? ''}" ${isHq ? 'data-hq="1"' : ''}>
            <div class="team-head">
                <div>
                    <h3>${escapeHtml(title)}</h3>
                    <span class="count">${rows.length}명</span>
                </div>
                <div class="actions">
                    <button class="tiny-btn primary" type="button" data-add-row data-team="${teamNo ?? ''}" ${isHq ? 'data-hq="1"' : ''}>+ 직원 추가</button>
                </div>
            </div>
            ${bodyHtml}
        </section>`;
}

function renderMobileTeamCards(rows, title, group, fields, allowedTitles) {
    if (!rows.length) {
        return `<div class="ledger-cards-empty">${escapeHtml(title)}에 등록된 인원이 없습니다.</div>`;
    }
    return `<div class="ledger-cards">${rows.map((r, i) => renderMobileCardOrg(r, i + 1, group, fields, allowedTitles)).join('')}</div>`;
}

function renderMobileCardOrg(r, displayNo, group, fields, allowedTitles) {
    const d = r.data || {};
    const cls = ['ledger-card', selectedIds.has(r.id) ? 'selected' : ''].filter(Boolean).join(' ');
    const primaryField = fields.find(f => f.key === 'name') || fields.find(f => f.type !== 'auto_number');
    const titleVal = primaryField ? (d[primaryField.key] || '-') : '-';
    const subKeys = MOBILE_PRIMARY_KEYS_ORG.filter(k => k !== primaryField?.key);
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
    const detailHtml = detailFields.map(f => {
        const v = d[f.key];
        let inner;
        if (f.type === 'textarea') {
            inner = v ? `<span class="cell-text cell-multiline">${escapeHtml(v)}</span>` : `<span class="cell-empty">-</span>`;
        } else if (f.type === 'date') {
            inner = v ? `<span class="cell-text">${escapeHtml(String(v).replace(/-/g, '.'))}</span>` : `<span class="cell-empty">-</span>`;
        } else {
            inner = v ? `<span class="cell-text">${escapeHtml(v)}</span>` : `<span class="cell-empty">-</span>`;
        }
        return `
            <div class="ledger-card-field">
                <span class="ledger-card-label">${escapeHtml(f.label || '')}</span>
                <span class="ledger-card-value">${inner}</span>
            </div>`;
    }).join('');
    return `
        <div class="${cls}" data-id="${r.id}">
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

function renderRow(r, displayNo, allowedTitles, fields) {
    const d = r.data || {};
    const checked = selectedIds.has(r.id) ? 'checked' : '';
    const fs = fields || DEFAULT_FIELD_SCHEMA.fields;
    const primaryIdx = fs.findIndex(f => f.type !== 'auto_number');
    const cellHtml = (f, i) => {
        const v = d[f.key];
        const cls = [
            f.type === 'auto_number' ? 'col-no' : '',
            i === primaryIdx ? 'col-primary' : '',
        ].filter(Boolean).join(' ');
        const clsAttr = cls ? ` class="${cls}"` : '';
        const labelAttr = f.type === 'auto_number' ? '' : (f.label ? ` data-label="${escapeHtml(f.label)}"` : '');
        if (f.type === 'auto_number') return `<td${clsAttr}>${displayNo}</td>`;
        if (f.type === 'textarea') {
            return v ? `<td${clsAttr}${labelAttr}><span class="cell-text cell-multiline">${escapeHtml(v)}</span></td>` : `<td${clsAttr}${labelAttr}><span class="cell-empty">-</span></td>`;
        }
        if (f.type === 'date') {
            return v ? `<td${clsAttr}${labelAttr}><span class="cell-text">${escapeHtml(String(v).replace(/-/g, '.'))}</span></td>` : `<td${clsAttr}${labelAttr}><span class="cell-empty">-</span></td>`;
        }
        if (f.type === 'toggle') {
            const on = !!v;
            return `<td${clsAttr}${labelAttr}><span class="toggle-cell ${on ? 'on' : 'off'}" data-cell-toggle data-id="${r.id}" data-field="${escapeAttr(f.key)}" data-value="${on ? '1' : '0'}" title="클릭하여 토글">${escapeHtml(on ? (f.onLabel || 'ON') : (f.offLabel || 'OFF'))}</span></td>`;
        }
        if (f.type === 'switch') {
            const on = !!v;
            const lbl = on ? (f.onLabel || 'ON') : (f.offLabel || 'OFF');
            return `<td${clsAttr}${labelAttr}><span class="switch-cell ${on ? 'on' : 'off'}" data-cell-switch data-id="${r.id}" data-field="${escapeAttr(f.key)}" data-value="${on ? '1' : '0'}" aria-label="${escapeAttr(lbl)}" title="클릭하여 토글"><span class="switch-track"><span class="switch-thumb"></span></span><span class="switch-label">${escapeHtml(lbl)}</span></span></td>`;
        }
        // resident_id 포함 — 모두 read-only span (편집은 ✎ 버튼 → 모달)
        return v ? `<td${clsAttr}${labelAttr}><span class="cell-text">${escapeHtml(v)}</span></td>` : `<td${clsAttr}${labelAttr}><span class="cell-empty">-</span></td>`;
    };
    return `
        <tr data-id="${r.id}" class="${selectedIds.has(r.id) ? 'selected' : ''}">
            <td class="col-check"><input type="checkbox" data-select="${r.id}" ${checked}></td>
            ${fs.map((f, i) => cellHtml(f, i)).join('')}
            <td class="col-action">
                <button class="row-action-btn" data-edit-row="${r.id}" title="수정"><span class="ico">✎</span><span class="lbl">수정</span></button>
                <button class="row-action-btn danger" data-delete-row="${r.id}" title="삭제"><span class="ico">×</span><span class="lbl">삭제</span></button>
            </td>
        </tr>`;
}

function bindTableEvents() {
    // 컬럼 헤더 클릭 → unique 값 multi-select dropdown 필터
    attachColumnFilters({
        state: filterState,
        headers: document.querySelectorAll('.ledger-tbl thead th[data-col-key]'),
        fields: DEFAULT_FIELD_SCHEMA.fields,
        getRows: () => records,
        getValue: (r, k) => r.data?.[k],
        onChange: () => renderRecords(),
    });
    // 행 추가
    document.querySelectorAll('[data-add-row]').forEach(b => {
        b.addEventListener('click', () => {
            // 가장 가까운 accordion-card 의 data-gid 로 그룹 식별
            const gid = parseInt(b.closest('.accordion-card')?.dataset.gid, 10);
            const teamNo = b.dataset.team ? parseInt(b.dataset.team, 10) : null;
            const isHq = b.dataset.hq === '1';
            addRow(gid, teamNo, isHq);
        });
    });
    // 행 수정
    document.querySelectorAll('[data-edit-row]').forEach(b => {
        b.addEventListener('click', () => editRow(parseInt(b.dataset.editRow, 10)));
    });
    // 개별 삭제
    document.querySelectorAll('[data-delete-row]').forEach(b => {
        b.addEventListener('click', () => deleteRow(parseInt(b.dataset.deleteRow, 10)));
    });
    // 그룹별 검색 — DOM 재생성 없이 기존 행만 hide/show. 한글 IME 조합 보존.
    document.querySelectorAll('[data-search-gid]').forEach(input => {
        const gid = parseInt(input.dataset.searchGid, 10);
        let timer = null;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                searchByGroup[gid] = input.value;
                filterDOMRowsBySearch(gid, input.value);
            }, 80);
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
    // 체크박스
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
            const teamRaw = cb.dataset.selectAll;
            const teamNo = teamRaw === '' ? null : parseInt(teamRaw, 10);
            const gid = parseInt(cb.closest('.accordion-card')?.dataset.gid, 10);
            const targets = records.filter(r => {
                if (gid && r.groupId !== gid) return false;
                const t = parseInt(r.data?.team, 10);
                return teamNo === null ? !(t >= 1 && t <= 5) : t === teamNo;
            });
            targets.forEach(r => cb.checked ? selectedIds.add(r.id) : selectedIds.delete(r.id));
            renderRecords();
        });
    });
}

function addRow(gid, teamNo, isHq) {
    if (!gid) return;
    const group = groups.find(g => g.id === gid);
    const allowedTitles = isHq ? ['본부장'] : rolesAllowedFor(ownerRoleOf(group)).filter(t => t !== '본부장');
    const titleDefault = isHq ? '본부장' : '팀원';
    const modalTitle = isHq ? '본부장 추가' : `${teamNo || '?'}팀 새 직원 추가`;
    openOrgEntryModal({
        title: modalTitle,
        confirmLabel: '추가',
        defaults: { title: titleDefault },
        group,
        allowedTitles,
        onSubmit: async (data) => {
            if (data.title === '본부장') data.team = 0;
            else data.team = teamNo || 1;
            await api('ledger-records', { method: 'POST', body: { groupId: gid, data, source: 'web' } });
            await loadRecords();
        },
    });
}

function editRow(id) {
    const r = records.find(x => x.id === id);
    if (!r) return;
    const group = groups.find(g => g.id === r.groupId);
    const isHq = (r.data?.title === '본부장');
    const allowedTitles = isHq ? ['본부장'] : rolesAllowedFor(ownerRoleOf(group)).filter(t => t !== '본부장');
    openOrgEntryModal({
        title: '직원 정보 수정',
        confirmLabel: '저장',
        defaults: { ...r.data },
        group,
        allowedTitles,
        onSubmit: async (data) => {
            // team 은 기존 값 유지 (본부장 → 0)
            if (data.title === '본부장') data.team = 0;
            else if (!data.team) data.team = r.data?.team || 1;
            await api('ledger-records', { method: 'PATCH', body: { id, data } });
            await loadRecords();
        },
    });
}

function openOrgEntryModal({ title, confirmLabel, defaults, group, allowedTitles, onSubmit }) {
    openRowAddModal({
        title,
        confirmLabel,
        fields: getEffectiveFields(group, DEFAULT_FIELD_SCHEMA.fields),
        defaults,
        customRender: (f, defs) => {
            if (f.type !== 'title_select') return null;
            const v = defs[f.key] ?? '';
            const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
            const opts = ['<option value="">-</option>']
                .concat(allowedTitles.map(t => `<option value="${t}" ${v === t ? 'selected' : ''}>${t}</option>`))
                .join('');
            return `<div class="modal-row">${lbl}<div class="row-control"><select data-field="${f.key}">${opts}</select></div></div>`;
        },
        onSubmit,
    });
}

async function updateRowField(id, field, value) {
    try {
        await api('ledger-records', {
            method: 'PATCH',
            body: { id, data: { [field]: value } },
        });
        // 로컬 캐시도 업데이트 (재렌더 없이)
        const r = records.find(x => x.id === id);
        if (r) { r.data = r.data || {}; r.data[field] = value; }
    } catch (e) {
        showError('저장 실패: ' + e.message);
        await loadRecords();
    }
}

async function deleteRow(id) {
    if (!confirm('이 행을 삭제할까요?')) return;
    try {
        await api('ledger-records', { method: 'DELETE', body: { id } });
        await loadRecords();
    } catch (e) {
        showError('삭제 실패: ' + e.message);
    }
}

/* ============== 엑셀 다운로드 / 업로드 ============== */

// '팀' 컬럼을 export/import 모두에 포함시킨 임시 fields. team 은 row 에서 빠져있지만
// 엑셀에서는 1열 자리를 차지함 — 행 추가/수정 시 사용자가 팀을 인식할 수 있도록.
function fieldsWithTeam(group) {
    const base = getEffectiveFields(group, DEFAULT_FIELD_SCHEMA.fields);
    // NO 다음에 팀 삽입.
    const noIdx = base.findIndex(f => f.type === 'auto_number');
    const insertAt = noIdx >= 0 ? noIdx + 1 : 0;
    const out = [...base];
    out.splice(insertAt, 0, { key: 'team', label: '팀', type: 'text', filterable: false });
    return out;
}

function buildExportRowsOrg(group) {
    return records.filter(r => r.groupId === group.id).map(r => {
        const d = r.data || {};
        const out = { ...d };
        // 팀 표시: 본부장은 '본부' / 미지정은 빈값 / 그 외 'N팀'.
        if (d.title === '본부장') out.team = '본부';
        else if (d.team) out.team = String(parseInt(d.team, 10) || '') ? String(parseInt(d.team, 10)) + '팀' : '';
        else out.team = '';
        return { data: out };
    });
}

async function exportGroup(gid) {
    const g = groups.find(x => x.id === gid);
    if (!g) return;
    const fields = fieldsWithTeam(g);
    const rows = buildExportRowsOrg(g);
    if (rows.length === 0) {
        if (!confirm(`"${g.name}" 그룹에 인원이 없습니다. 빈 양식만 다운로드할까요?`)) return;
    }
    try {
        await exportRecordsToExcel({
            sheets: [{ name: g.name, fields, rows }],
            fileName: `조직도_${g.name}_${todayStamp()}.xlsx`,
        });
    } catch (e) { showError('엑셀 다운로드 실패: ' + e.message); }
}

async function exportAllGroups() {
    if (groups.length === 0) { alert('내보낼 그룹이 없습니다.'); return; }
    const sheets = groups.map(g => ({
        name: g.name,
        fields: fieldsWithTeam(g),
        rows: buildExportRowsOrg(g),
    }));
    try {
        await exportRecordsToExcel({ sheets, fileName: `조직도_전체_${todayStamp()}.xlsx` });
    } catch (e) { showError('엑셀 다운로드 실패: ' + e.message); }
}

function teamFromString(v) {
    const s = String(v || '').trim();
    if (!s) return null;
    if (/본부/.test(s)) return 0;     // 본부장은 team=0 으로 처리 (data.team 비움)
    const m = s.match(/(\d+)/);
    return m ? parseInt(m[1], 10) : null;
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
    const fields = fieldsWithTeam(g);   // team 컬럼 인식 가능하도록
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
        if ('team' in data) {
            const t = teamFromString(data.team);
            if (data.title === '본부장' || t === 0) delete data.team;
            else if (t) data.team = t;
            else delete data.team;
        }
        if (!data.title && data.name) data.title = '팀원';
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
    const fields = fieldsWithTeam(g);
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
    } catch (e) {
        showError('일괄 삭제 실패: ' + e.message);
    }
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
