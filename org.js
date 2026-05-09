/**
 * org.js — 조직도 페이지 (Phase 2)
 *
 * page_type='org' 의 ledger-groups / ledger-records API 위에서 동작.
 * 그룹 / 멀티선택 / 팀별 테이블 / 필터 / 선택삭제 / 설정(활성 팀 + 직급별·타입별 수수료).
 * Phase 3 의 계약자 관리대장이 이 그룹의 settings.commissions 를 읽어 정산.
 */

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260508-tight';
import { attachColumnFilters, applyColumnFilters } from './ledger-shared.js?v=20260509-filter1';

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
};

/* ============== State ============== */
let supabaseClient = null;
let groups = [];                  // all groups for this page+user
let activeGroupIds = [];          // selected group(s) — single by default
let multiMode = false;
let records = [];                 // records for activeGroupIds
let editingGroupId = null;        // group being edited in modal
let filterState = { filters: {} };// { [key]: Set<value> }  — ledger-shared 가 관리
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

/* ============== Group bar ============== */
async function loadGroups() {
    try {
        const data = await api('ledger-groups', { query: 'page_type=' + PAGE_TYPE });
        groups = data.items || [];
    } catch (e) {
        showError('그룹을 불러오지 못했습니다: ' + e.message);
        return;
    }

    if (groups.length === 0) {
        renderGroupBar();
        document.getElementById('content').innerHTML = `
            <div class="empty">
                <b>아직 조직도 그룹이 없습니다.</b><br>
                상단의 <b>+ 새 그룹</b> 버튼을 눌러 첫 그룹을 만들어 주세요.
            </div>`;
        return;
    }

    // 기본 그룹이 있으면 활성화. 없으면 첫 그룹.
    const def = groups.find(g => g.isDefault) || groups[0];
    activeGroupIds = [def.id];
    multiMode = false;
    renderGroupBar();
    await loadRecords();
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
        renderGroupBar();
        await loadRecords();
    });
    document.getElementById('newGroupBtn').addEventListener('click', () => openGroupModal(null));
    document.getElementById('editGroupBtn').addEventListener('click', () => {
        if (activeGroupIds.length === 1) openGroupModal(activeGroupIds[0]);
        else alert('편집할 그룹 하나만 선택해주세요.');
    });
    document.getElementById('settingsBtn').addEventListener('click', () => openSettingsModal());

    document.getElementById('groupCancelBtn').addEventListener('click', () => closeModal('groupModal'));
    document.getElementById('groupSaveBtn').addEventListener('click', saveGroup);
    document.getElementById('groupDeleteBtn').addEventListener('click', deleteGroup);

    document.getElementById('settingsCancelBtn').addEventListener('click', () => closeModal('settingsModal'));
    document.getElementById('settingsSaveBtn').addEventListener('click', saveSettings);
    document.getElementById('addTypeCommBtn').addEventListener('click', () => {
        typeCommissionRows.push({ type: '', '본부장': 0, '팀장': 0, '팀원': 0 });
        renderTypeCommList();
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
    if (!confirm('이 그룹과 그 안의 모든 행을 영구 삭제합니다. 진행하시겠습니까?')) return;
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
    if (activeGroupIds.length !== 1) {
        alert('설정은 그룹 하나에 적용됩니다. 그룹 한 개만 선택한 상태에서 열어주세요.');
        return;
    }
    const g = groups.find(x => x.id === activeGroupIds[0]);
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
    typeCommissionRows = JSON.parse(JSON.stringify(s.type_commissions || []));
    renderTypeCommList();
    document.getElementById('settingsErrorMsg').textContent = '';
    document.getElementById('settingsModal').classList.remove('hidden');
}

function renderTypeCommList() {
    const el = document.getElementById('typeCommList');
    if (typeCommissionRows.length === 0) {
        el.innerHTML = '<div style="text-align:center;padding:16px;color:#8a847e;font-size:12.5px;">등록된 타입별 수수료가 없습니다.</div>';
        return;
    }
    el.innerHTML = typeCommissionRows.map((r, i) => `
        <div class="type-comm-row" data-i="${i}">
            <input type="text" placeholder="타입(예: 59A)" data-f="type" value="${escapeAttr(r.type || '')}">
            <input type="number" placeholder="본부장" data-f="본부장" value="${r['본부장'] ?? 0}" min="0" step="10000">
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
    const g = groups.find(x => x.id === activeGroupIds[0]);
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
    if (activeGroupIds.length === 0) {
        records = [];
        renderRecords();
        return;
    }
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
    if (activeGroupIds.length === 0) {
        content.innerHTML = `<div class="empty">그룹을 선택해주세요.</div>`;
        return;
    }

    // 활성 팀 = 선택된 그룹들의 settings.active_teams 합집합
    const activeTeams = new Set();
    activeGroupIds.forEach(gid => {
        const g = groups.find(x => x.id === gid);
        const s = { ...DEFAULT_SETTINGS, ...(g?.settings || {}) };
        (s.active_teams || []).forEach(t => activeTeams.add(t));
    });
    if (activeTeams.size === 0) [1, 2, 3].forEach(t => activeTeams.add(t));

    // 필터 적용 — ledger-shared 의 컬럼별 unique 값 multi-select
    const filtered = applyColumnFilters(filterState.filters, records, (r, k) => r.data?.[k]);

    // 팀별 그룹핑
    const byTeam = {};
    [...activeTeams].sort((a, b) => a - b).forEach(t => byTeam[t] = []);
    byTeam.unassigned = [];
    filtered.forEach(r => {
        const t = parseInt(r.data?.team, 10);
        if (activeTeams.has(t)) byTeam[t].push(r);
        else byTeam.unassigned.push(r);
    });

    let html = '';
    [...activeTeams].sort((a, b) => a - b).forEach(t => {
        html += renderTeamSection(t + '팀', t, byTeam[t]);
    });
    if (byTeam.unassigned.length > 0) {
        html += renderTeamSection('미지정', null, byTeam.unassigned);
    }
    content.innerHTML = html;
    bindTableEvents();
    updateBulkBar();
}

function renderTeamSection(title, teamNo, rows) {
    const fields = DEFAULT_FIELD_SCHEMA.fields;
    return `
        <section class="team-section" data-team="${teamNo ?? ''}">
            <div class="team-head">
                <div>
                    <h3>${escapeHtml(title)}</h3>
                    <span class="count">${rows.length}명</span>
                </div>
                <div class="actions">
                    <button class="tiny-btn primary" type="button" data-add-row="${teamNo ?? ''}">+ 행 추가</button>
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
                            : rows.map((r, idx) => renderRow(r, idx + 1)).join('')}
                    </tbody>
                </table>
            </div>
        </section>`;
}

function renderRow(r, displayNo) {
    const d = r.data || {};
    const checked = selectedIds.has(r.id) ? 'checked' : '';
    return `
        <tr data-id="${r.id}" class="${selectedIds.has(r.id) ? 'selected' : ''}">
            <td class="col-check"><input type="checkbox" data-select="${r.id}" ${checked}></td>
            <td class="col-no">${displayNo}</td>
            <td><input type="date" data-field="joined" data-id="${r.id}" value="${escapeAttr(d.joined || '')}"></td>
            <td><select data-field="title" data-id="${r.id}">
                <option value="">-</option>
                ${TITLE_OPTIONS.map(t => `<option value="${t}" ${d.title === t ? 'selected' : ''}>${t}</option>`).join('')}
            </select></td>
            <td><input type="text" data-field="name" data-id="${r.id}" value="${escapeAttr(d.name || '')}" placeholder="이름"></td>
            <td><input type="tel"  data-field="phone" data-id="${r.id}" value="${escapeAttr(d.phone || '')}" placeholder="010-..."></td>
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
        b.addEventListener('click', () => addRow(b.dataset.addRow ? parseInt(b.dataset.addRow, 10) : null));
    });
    // 셀 인라인 편집 (blur 시 저장)
    document.querySelectorAll('[data-field][data-id]').forEach(el => {
        el.addEventListener('change', () => updateRowField(parseInt(el.dataset.id, 10), el.dataset.field, el.value));
    });
    // 개별 삭제
    document.querySelectorAll('[data-delete-row]').forEach(b => {
        b.addEventListener('click', () => deleteRow(parseInt(b.dataset.deleteRow, 10)));
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
            const targets = records.filter(r => {
                const t = parseInt(r.data?.team, 10);
                return teamNo === null ? !(t >= 1 && t <= 5) : t === teamNo;
            });
            targets.forEach(r => cb.checked ? selectedIds.add(r.id) : selectedIds.delete(r.id));
            renderRecords();
        });
    });
}

async function addRow(teamNo) {
    if (activeGroupIds.length !== 1) {
        alert('행은 그룹 하나에만 추가할 수 있습니다. 멀티 모드를 끄거나 그룹 하나만 선택해주세요.');
        return;
    }
    try {
        await api('ledger-records', {
            method: 'POST',
            body: { groupId: activeGroupIds[0], data: { team: teamNo || 1, title: '팀원' }, source: 'web' },
        });
        await loadRecords();
    } catch (e) {
        showError('행 추가 실패: ' + e.message);
    }
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
