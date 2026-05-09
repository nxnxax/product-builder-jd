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

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260508-tight';
import { attachColumnFilters, applyColumnFilters, openRowAddModal } from './ledger-shared.js?v=20260509-filter2';

const PAGE_TYPE = 'customer';

const DEFAULT_FIELDS = [
    { key: 'no',       label: 'NO',     type: 'auto_number',  filterable: false, width: 48 },
    { key: 'managed',  label: '관리',   type: 'manage_switch',filterable: true,  width: 80 },
    { key: 'date',     label: '날짜',   type: 'date',         filterable: true,  width: 130 },
    { key: 'customer', label: '고객명', type: 'text',         filterable: true,  width: 110 },
    { key: 'phone',    label: '연락처', type: 'tel',          filterable: false, width: 130 },
    { key: 'region',   label: '거주지역',type:'text',          filterable: true,  width: 130 },
    { key: 'content',  label: '내용',   type: 'textarea',     filterable: true,  width: 280 },
    { key: 'memo',     label: '비고',   type: 'text',         filterable: false, width: 140 },
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
        renderGroupBar();
        document.getElementById('content').innerHTML = `
            <div class="empty">
                <b>아직 등록된 그룹이 없습니다.</b><br>
                상단의 <b>+ 새 그룹</b> 버튼으로 첫 그룹을 만들어 주세요.
            </div>`;
        return;
    }
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
    document.getElementById('groupCancelBtn').addEventListener('click', () => closeModal('groupModal'));
    document.getElementById('groupSaveBtn').addEventListener('click', saveGroup);
    document.getElementById('groupDeleteBtn').addEventListener('click', deleteGroup);

    document.getElementById('bulkClearBtn').addEventListener('click', () => { selectedIds.clear(); renderRecords(); });
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
    if (!name) { document.getElementById('groupErrorMsg').textContent = '그룹 이름을 입력해주세요.'; return; }
    try {
        if (editingGroupId) {
            await api('ledger-groups', { method: 'PATCH', body: { id: editingGroupId, name, isDefault } });
        } else {
            const created = await api('ledger-groups', {
                method: 'POST',
                body: { pageType: PAGE_TYPE, name, isDefault, fieldSchema: { fields: DEFAULT_FIELDS }, settings: {} },
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

function applyFilters(rows) {
    return applyColumnFilters(filterState.filters, rows, (r, k) => r.data?.[k]);
}

function renderRecords() {
    const content = document.getElementById('content');
    if (activeGroupIds.length === 0) { content.innerHTML = `<div class="empty">그룹을 선택해주세요.</div>`; return; }

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

function renderSection(group, rows) {
    if (!group) return '';
    return `
        <section class="cust-section" data-gid="${group.id}">
            <div class="cust-head">
                <div>
                    <h3>${escapeHtml(group.name)}</h3>
                    <span class="count">${rows.length}건</span>
                </div>
                <div>
                    <button class="tiny-btn primary" type="button" data-add-row="${group.id}">+ 행 추가</button>
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
                            ? `<tr><td colspan="${DEFAULT_FIELDS.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">등록된 행이 없습니다.</td></tr>`
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
    if (f.type === 'manage_switch') {
        const on = !!d.managed;
        return `<button class="manage-switch ${on ? 'on' : 'off'}" data-manage-switch data-id="${id}">${on ? '관리중' : '관리X'}</button>`;
    }
    if (f.type === 'date') return `<input type="date" data-field="${f.key}" data-id="${id}" value="${escapeAttr(d[f.key] || '')}">`;
    if (f.type === 'tel')  return `<input type="tel"  data-field="${f.key}" data-id="${id}" value="${escapeAttr(d[f.key] || '')}" placeholder="010-...">`;
    if (f.type === 'textarea') return `<textarea data-field="${f.key}" data-id="${id}" rows="1" placeholder="${escapeAttr(f.label)}">${escapeHtml(d[f.key] || '')}</textarea>`;
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
    document.querySelectorAll('[data-manage-switch]').forEach(b => {
        b.addEventListener('click', () => toggleManaged(parseInt(b.dataset.id, 10)));
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
    const today = new Date().toISOString().slice(0, 10);
    openRowAddModal({
        title: '새 고객 추가',
        fields: DEFAULT_FIELDS,
        defaults: { date: today, managed: true },
        customRender: (f) => {
            const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
            if (f.type === 'manage_switch') {
                return `<div class="modal-row">${lbl}<div class="row-control"><label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#4f4943;font-weight:400;cursor:pointer;margin:0;">
                    <input type="checkbox" data-field="managed" checked style="width:auto;accent-color:#c8362c;"> 관리 대상
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
