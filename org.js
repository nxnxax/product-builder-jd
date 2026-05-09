/**
 * org.js — 조직도 페이지 (Phase 2)
 *
 * page_type='org' 의 ledger-groups / ledger-records API 위에서 동작.
 * 그룹 / 멀티선택 / 팀별 테이블 / 필터 / 선택삭제 / 설정(활성 팀 + 직급별·타입별 수수료).
 * Phase 3 의 계약자 관리대장이 이 그룹의 settings.commissions 를 읽어 정산.
 */

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260509-phone-toggle';
import { attachColumnFilters, applyColumnFilters, openRowAddModal, attachPhoneAutoFormat } from './ledger-shared.js?v=20260509-phone-toggle';

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
        { key: 'phone',   label: '연락처', type: 'tel',         filterable: false },
        { key: 'account', label: '계좌번호', type: 'text',      filterable: true  },
        { key: 'memo',    label: '비고',   type: 'text',        filterable: false },
    ],
};

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
let typeCommissionRows = [];      // working copy in settings modal

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
                상단의 <b>+ 새 그룹</b> 버튼을 눌러 첫 그룹을 만들어 주세요.
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
    if (!name) {
        document.getElementById('groupErrorMsg').textContent = '그룹 이름을 입력해주세요.';
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
    document.getElementById('commHQ').value = dc['본부장'] ?? 0;
    document.getElementById('commTL').value = dc['팀장']   ?? 0;
    document.getElementById('commTM').value = dc['팀원']   ?? 0;
    // owner_role 에 따라 본부장 칸 숨김.
    const role = s.owner_role === 'lead' ? 'lead' : 'head';
    document.getElementById('defaultCommGrid').classList.toggle('role-lead', role === 'lead');
    typeCommissionRows = JSON.parse(JSON.stringify(s.type_commissions || []));
    renderTypeCommList(role);
    document.getElementById('settingsErrorMsg').textContent = '';
    document.getElementById('settingsModal').classList.remove('hidden');
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
            <input type="number" placeholder="본부장" data-f="본부장" data-role-cell="head" value="${r['본부장'] ?? 0}" min="0" step="10000">
            <input type="number" placeholder="팀장" data-f="팀장" value="${r['팀장'] ?? 0}" min="0" step="10000">
            <input type="number" placeholder="팀원" data-f="팀원" value="${r['팀원'] ?? 0}" min="0" step="10000">
            <button class="x" type="button" data-del>×</button>
        </div>`).join('');
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
            typeCommissionRows[i][f] = (f === 'type') ? inp.value : (parseInt(inp.value, 10) || 0);
        });
    });
}

async function saveSettings() {
    const g = groups.find(x => x.id === settingsGroupId);
    if (!g) return;
    const active_teams = Array.from(document.querySelectorAll('#activeTeamsBox input[data-team]:checked'))
        .map(cb => parseInt(cb.value, 10));
    const default_commissions = {
        '본부장': parseInt(document.getElementById('commHQ').value, 10) || 0,
        '팀장':   parseInt(document.getElementById('commTL').value, 10) || 0,
        '팀원':   parseInt(document.getElementById('commTM').value, 10) || 0,
    };
    const type_commissions = typeCommissionRows.filter(r => r.type && r.type.trim());

    const newSettings = { ...g.settings, active_teams, default_commissions, type_commissions };
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
            <div class="extra-head">
                <button class="extra-toggle" data-toggle-extra type="button">
                    <span class="extra-arrow">▶</span>
                    <h4>그룹목록</h4>
                    <span class="count-pill">${others.length}개${showing > 0 ? ` · ${showing}개 표시 중` : ''}</span>
                </button>
            </div>
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

function renderGroupCard(group) {
    const groupRecs = records.filter(r => r.groupId === group.id);

    // 활성 팀 (이 그룹의 settings)
    const s = { ...DEFAULT_SETTINGS, ...(group.settings || {}) };
    const activeTeams = new Set(s.active_teams && s.active_teams.length > 0 ? s.active_teams : [1, 2, 3]);

    // 필터는 모든 그룹에 동일 적용
    const filtered = applyColumnFilters(filterState.filters, groupRecs, (r, k) => r.data?.[k]);

    const byTeam = {};
    [...activeTeams].sort((a, b) => a - b).forEach(t => byTeam[t] = []);
    byTeam.unassigned = [];
    filtered.forEach(r => {
        const t = parseInt(r.data?.team, 10);
        if (activeTeams.has(t)) byTeam[t].push(r);
        else byTeam.unassigned.push(r);
    });

    let bodyHtml = [...activeTeams].sort((a, b) => a - b).map(t => renderTeamSection(t + '팀', t, byTeam[t], group)).join('');
    if (byTeam.unassigned.length > 0) bodyHtml += renderTeamSection('미지정', null, byTeam.unassigned, group);

    const role = ownerRoleOf(group);
    return `
        <div class="accordion-card open" data-gid="${group.id}">
            <div class="accordion-head">
                <h3>${escapeHtml(group.name)}</h3>
                <select class="owner-role-select" data-set-role="${group.id}" title="내 직책">
                    <option value="head" ${role === 'head' ? 'selected' : ''}>본부장</option>
                    <option value="lead" ${role === 'lead' ? 'selected' : ''}>팀장</option>
                </select>
                <label class="main-checkbox" title="이 그룹을 메인으로 설정">
                    <input type="checkbox" data-set-main="${group.id}" ${group.isDefault ? 'checked' : ''}>
                    <span>메인그룹</span>
                </label>
                <span class="count-pill">${groupRecs.length}명</span>
                <div class="head-actions">
                    <button type="button" data-edit-gid="${group.id}">편집</button>
                    <button type="button" data-settings-gid="${group.id}">⚙ 설정</button>
                </div>
            </div>
            <div class="accordion-body">
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

function renderTeamSection(title, teamNo, rows, group) {
    const fields = DEFAULT_FIELD_SCHEMA.fields;
    const allowedTitles = rolesAllowedFor(ownerRoleOf(group));
    return `
        <section class="team-section" data-team="${teamNo ?? ''}">
            <div class="team-head">
                <div>
                    <h3>${escapeHtml(title)}</h3>
                    <span class="count">${rows.length}명</span>
                </div>
                <div class="actions">
                    <button class="tiny-btn primary" type="button" data-add-row data-team="${teamNo ?? ''}">+ 직원 추가</button>
                </div>
            </div>
            <div class="tbl-wrap">
                <table class="ledger-tbl">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" data-select-all="${teamNo ?? ''}"></th>
                            <th class="col-no">NO</th>
                            ${fields.filter(f => f.key !== 'no').map(f => `<th data-col-key="${f.key}">${escapeHtml(f.label)}</th>`).join('')}
                            <th class="col-action"></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.length === 0
                            ? `<tr><td colspan="${fields.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">${title}에 등록된 인원이 없습니다.</td></tr>`
                            : rows.map((r, idx) => renderRow(r, idx + 1, allowedTitles)).join('')}
                    </tbody>
                </table>
            </div>
        </section>`;
}

function renderRow(r, displayNo, allowedTitles) {
    const d = r.data || {};
    const checked = selectedIds.has(r.id) ? 'checked' : '';
    const titles = allowedTitles || TITLE_OPTIONS;
    return `
        <tr data-id="${r.id}" class="${selectedIds.has(r.id) ? 'selected' : ''}">
            <td class="col-check"><input type="checkbox" data-select="${r.id}" ${checked}></td>
            <td class="col-no">${displayNo}</td>
            <td><input type="date" data-field="joined" data-id="${r.id}" value="${escapeAttr(d.joined || '')}"></td>
            <td><select data-field="title" data-id="${r.id}">
                <option value="">-</option>
                ${titles.map(t => `<option value="${t}" ${d.title === t ? 'selected' : ''}>${t}</option>`).join('')}
            </select></td>
            <td><input type="text" data-field="name" data-id="${r.id}" value="${escapeAttr(d.name || '')}" placeholder="이름"></td>
            <td><input type="tel"  data-field="phone" data-id="${r.id}" value="${escapeAttr(d.phone || '')}" placeholder="010-0000-0000"></td>
            <td><input type="text" data-field="account" data-id="${r.id}" value="${escapeAttr(d.account || '')}" placeholder="계좌"></td>
            <td><input type="text" data-field="memo" data-id="${r.id}" value="${escapeAttr(d.memo || '')}" placeholder="비고"></td>
            <td class="col-action"><button class="row-action-btn" data-delete-row="${r.id}" title="삭제">×</button></td>
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
            addRow(gid, teamNo);
        });
    });
    // 셀 인라인 편집 (blur 시 저장)
    document.querySelectorAll('[data-field][data-id]').forEach(el => {
        el.addEventListener('change', () => updateRowField(parseInt(el.dataset.id, 10), el.dataset.field, el.value));
    });
    // 개별 삭제
    document.querySelectorAll('[data-delete-row]').forEach(b => {
        b.addEventListener('click', () => deleteRow(parseInt(b.dataset.deleteRow, 10)));
    });
    attachPhoneAutoFormat();
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

function addRow(gid, teamNo) {
    if (!gid) return;
    const group = groups.find(g => g.id === gid);
    const allowedTitles = rolesAllowedFor(ownerRoleOf(group));
    openRowAddModal({
        title: `${teamNo || '?'}팀 새 직원 추가`,
        fields: DEFAULT_FIELD_SCHEMA.fields,
        defaults: { title: '팀원' },
        customRender: (f, defaults) => {
            if (f.type !== 'title_select') return null;
            const v = defaults[f.key] ?? '';
            const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
            const opts = ['<option value="">-</option>']
                .concat(allowedTitles.map(t => `<option value="${t}" ${v === t ? 'selected' : ''}>${t}</option>`))
                .join('');
            return `<div class="modal-row">${lbl}<div class="row-control"><select data-field="${f.key}">${opts}</select></div></div>`;
        },
        onSubmit: async (data) => {
            data.team = teamNo || 1;
            await api('ledger-records', { method: 'POST', body: { groupId: gid, data, source: 'web' } });
            await loadRecords();
        },
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
