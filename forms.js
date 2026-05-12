/**
 * forms.js — 사용자 정의 양식 (custom form builder + ledger)
 *
 * 한 페이지에 3가지 모드:
 *  1) 양식 목록 모드   : 내가 만든 양식 카드 + "+ 새 양식"
 *  2) 양식 빌더 모드  : 모달에서 항목 추가/삭제 + 양식 제목 입력 → 저장
 *  3) 양식 사용 모드  : 선택한 양식으로 ledger 행 추가/편집 (모바일 카드 패턴 그대로)
 *
 * 저장 인프라는 기존 ledger 시스템 재활용:
 *   - page_type = 'custom'
 *   - ledger-groups.settings.customFields[] 에 사용자 정의 필드 schema
 *   - ledger-records.data 에 행 데이터
 *
 * 토글 필드는 settings.customFields[i] = { key, label, type:'toggle', onLabel, offLabel, custom:true }
 */

import { initSupabase, apiRequest, getSession } from './auth-shared.js?v=20260512-nav-slots';
import { isLedgerMobile, onLedgerViewportChange, openRowAddModal } from './ledger-shared.js?v=20260512-nav-slots';

const PAGE_TYPE = 'custom';

/* 기본 필드 — 모든 양식에 자동으로 포함되는 NO / 등록일 */
const BASE_FIELDS = [
    { key: 'no',         label: 'NO',   type: 'auto_number', filterable: false },
    { key: 'created_at', label: '등록일', type: 'date',     filterable: true   },
];

const FIELD_TYPE_LABELS = {
    text: '텍스트', number: '숫자', date: '날짜', tel: '전화번호',
    textarea: '긴 텍스트', toggle: 'ON/OFF 토글', auto_number: '번호',
    select: '드롭다운', file: '첨부파일', formula: '수식', ref: '다른 양식 참조',
};

const API_URL = 'records.php';

/* 사칙연산 + 필드참조만 허용하는 안전한 수식 평가기.
   허용: 숫자 . + - * / ( ) , 공백. 그 외 문자 있으면 평가 거부. */
function evalFormula(formula, data, fields) {
    if (!formula) return '';
    // {필드라벨 또는 키} → 그 필드 값 (숫자 변환)
    const resolved = String(formula).replace(/\{([^}]+)\}/g, (_, raw) => {
        const k = raw.trim();
        let v;
        if (fields && fields.length) {
            const f = fields.find(x => x.label === k) || fields.find(x => x.key === k);
            if (f) v = data[f.key];
        } else {
            v = data[k];
        }
        if (v === undefined || v === null || v === '') return '0';
        const n = parseFloat(String(v).replace(/,/g, ''));
        return Number.isFinite(n) ? String(n) : '0';
    });
    if (!/^[\d\s+\-*/().]+$/.test(resolved)) return '';
    try {
        const result = Function(`"use strict"; return (${resolved})`)();
        if (typeof result !== 'number' || !Number.isFinite(result)) return '';
        return result;
    } catch { return ''; }
}

/* ============== State ============== */
let supabaseClient = null;
let forms = [];               // 내 양식 목록 (= ledger-groups 의 page_type=custom 들)
let activeFormId = null;       // 양식 사용 모드일 때 그 양식 id
let records = [];              // 활성 양식의 records
let editingFormId = null;      // 빌더 모달에서 편집 중인 양식 id (null = 새 양식)
let builderDraft = [];         // 빌더 모달 안의 working schema
let selectedIds = new Set();

/* ============== Boot ============== */
(async function boot() {
    try {
        const r = await initSupabase();
        supabaseClient = r?.client || null;
    } catch { supabaseClient = null; }
    const session = getSession();
    if (!supabaseClient || !session) {
        document.getElementById('content').innerHTML =
            `<div class="empty-state"><h2>로그인이 필요합니다.</h2><p><a href="index.html" style="color:var(--ledger-accent);font-weight:600;">홈으로 가서 로그인</a></p></div>`;
        return;
    }
    bindBuilderModal();
    await loadForms();
    render();
    onLedgerViewportChange(() => render());
})();

async function api(resource, opts = {}) {
    return apiRequest(resource, {
        method: opts.method || 'GET',
        body: opts.body ? JSON.stringify(opts.body) : undefined,
        query: opts.query,
    });
}

/* ============== Forms list (ledger-groups page_type=custom) ============== */
async function loadForms() {
    try {
        const data = await api('ledger-groups', { query: 'page_type=' + PAGE_TYPE });
        forms = data.items || [];
    } catch (e) {
        console.error('[forms] load failed', e);
        forms = [];
    }
}

async function loadRecords(formId) {
    try {
        const data = await api('ledger-records', { query: `page_type=${PAGE_TYPE}&group_id=${formId}` });
        records = data.items || [];
    } catch (e) {
        console.error('[forms] records load failed', e);
        records = [];
    }
}

/* ============== Render ============== */
function render() {
    if (!activeFormId) renderFormsList();
    else renderFormUse();
}

function renderFormsList() {
    const content = document.getElementById('content');
    const cards = forms.map(f => {
        const cf = f.settings?.customFields || [];
        return `
            <div class="form-card" data-open-form="${f.id}">
                <div class="form-card-title">${escapeHtml(f.name)}</div>
                <div class="form-card-meta">항목 ${cf.length + BASE_FIELDS.length - 1}개</div>
                <div class="form-card-actions">
                    <button class="tiny-btn" type="button" data-edit-form="${f.id}">양식 편집</button>
                </div>
            </div>`;
    }).join('');

    content.innerHTML = `
        <div class="ledger-head">
            <div>
                <h1 class="ledger-title">내 양식</h1>
                <p class="ledger-sub">필요한 항목을 직접 만들고 저장한 양식을 선택해서 사용합니다.</p>
            </div>
            <button class="tiny-btn primary" type="button" id="newFormBtn">+ 새 양식 만들기</button>
        </div>
        ${forms.length === 0
            ? `<div class="empty-state">
                <h2>아직 만든 양식이 없습니다.</h2>
                <p>우상단 <b>+ 새 양식 만들기</b> 버튼으로 첫 양식을 만들어 주세요.</p>
              </div>`
            : `<div class="form-grid">${cards}</div>`}
    `;

    document.getElementById('newFormBtn').addEventListener('click', () => openBuilder(null));
    content.querySelectorAll('[data-open-form]').forEach(el => {
        el.addEventListener('click', (e) => {
            if (e.target.closest('[data-edit-form]')) return;
            const id = parseInt(el.dataset.openForm, 10);
            enterForm(id);
        });
    });
    content.querySelectorAll('[data-edit-form]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            openBuilder(parseInt(btn.dataset.editForm, 10));
        });
    });
}

async function enterForm(formId) {
    activeFormId = formId;
    selectedIds = new Set();
    await loadRecords(formId);
    render();
}

function exitForm() {
    activeFormId = null;
    records = [];
    render();
}

function renderFormUse() {
    const form = forms.find(f => f.id === activeFormId);
    if (!form) { exitForm(); return; }
    const fields = [...BASE_FIELDS, ...(form.settings?.customFields || [])];
    const content = document.getElementById('content');

    const mobile = isLedgerMobile();
    const bodyHtml = mobile
        ? renderMobileCards(form, records, fields)
        : renderTable(form, records, fields);

    content.innerHTML = `
        <div class="use-head">
            <button class="back-btn" type="button" id="backBtn">← 양식 목록</button>
            <h2>${escapeHtml(form.name)}</h2>
        </div>
        <div class="ledger-cards-toolbar" style="border:1px solid var(--ledger-line);border-radius:10px 10px 0 0;margin-bottom:0;border-bottom:0">
            <button class="tiny-btn primary" type="button" id="addRowBtn">+ 행 추가</button>
        </div>
        <div style="border:1px solid var(--ledger-line);border-radius:0 0 12px 12px;overflow:hidden;background:#fff">${bodyHtml}</div>
    `;

    document.getElementById('backBtn').addEventListener('click', exitForm);
    document.getElementById('addRowBtn').addEventListener('click', () => openRowEntry(form, fields, null));
    bindRowEvents(form, fields);
}

/* 데스크탑 표 */
function renderTable(form, rows, fields) {
    const cells = fields.filter(f => f.type !== 'auto_number');
    return `
        <div class="tbl-wrap">
            <table class="ledger-tbl">
                <thead>
                    <tr>
                        <th class="col-no">NO</th>
                        ${cells.map(f => `<th>${escapeHtml(f.label)}</th>`).join('')}
                        <th class="col-action"></th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.length === 0
                        ? `<tr><td colspan="${cells.length + 2}" style="text-align:center;color:#8a847e;padding:24px;font-size:13px;">표시할 항목이 없습니다.</td></tr>`
                        : rows.map((r, i) => `
                            <tr data-id="${r.id}">
                                <td class="col-no">${i + 1}</td>
                                ${cells.map(f => `<td>${renderCellInner(f, r.data?.[f.key], r.data || {}, fields)}</td>`).join('')}
                                <td class="col-action">
                                    <button class="row-action-btn" data-edit-row="${r.id}" title="수정"><span class="ico">✎</span><span class="lbl">수정</span></button>
                                    <button class="row-action-btn danger" data-delete-row="${r.id}" title="삭제"><span class="ico">×</span><span class="lbl">삭제</span></button>
                                </td>
                            </tr>`).join('')}
                </tbody>
            </table>
        </div>`;
}

/* 모바일 카드 */
function renderMobileCards(form, rows, fields) {
    if (rows.length === 0) {
        return `<div class="ledger-cards-empty">표시할 항목이 없습니다.</div>`;
    }
    const dataFields = fields.filter(f => f.type !== 'auto_number');
    const primary = dataFields[0];
    const subFields = dataFields.slice(1, 3);   // 2개까지만 접힘 상태에 노출
    const detailFields = dataFields.filter(f => f.key !== primary?.key);

    return `<div class="ledger-cards">${rows.map((r, i) => {
        const d = r.data || {};
        const title = primary ? cellDisplay(primary, d[primary.key], d, fields) : '-';
        const subParts = subFields.map(f => {
            const v = d[f.key];
            const disp = cellDisplay(f, v, d, fields);
            return `
                <div class="ledger-card-sub-item">
                    <span class="ledger-card-sub-label">${escapeHtml(f.label)}</span>
                    <span class="ledger-card-sub-val">${escapeHtml(disp || '-')}</span>
                </div>`;
        }).join('');
        const detailHtml = detailFields.map(f => `
            <div class="ledger-card-field">
                <span class="ledger-card-label">${escapeHtml(f.label)}</span>
                <span class="ledger-card-value">${renderCellInner(f, d[f.key], d, fields)}</span>
            </div>`).join('');
        return `
            <div class="ledger-card" data-id="${r.id}">
                <div class="ledger-card-head">
                    <input type="checkbox" class="ledger-card-check" data-select="${r.id}">
                    <div class="ledger-card-summary">
                        <div class="ledger-card-title">${escapeHtml(title || '-')}</div>
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
    }).join('')}</div>`;
}

function renderCellInner(f, v, fullData, allFields) {
    if (f.type === 'toggle') {
        const on = !!v;
        const onLabel = f.onLabel || 'ON';
        const offLabel = f.offLabel || 'OFF';
        return `<span class="toggle-cell ${on ? 'on' : 'off'}">${escapeHtml(on ? onLabel : offLabel)}</span>`;
    }
    if (f.type === 'formula') {
        const result = evalFormula(f.formula, fullData || {}, allFields);
        if (result === '') return `<span class="cell-empty">-</span>`;
        return `<span class="cell-text" style="color:#1d5da3;font-weight:600">${escapeHtml(Number(result).toLocaleString('ko-KR'))}</span>`;
    }
    if (f.type === 'file') {
        if (!v || !v.url) return `<span class="cell-empty">-</span>`;
        return `<a href="${escapeAttr(v.url)}" target="_blank" rel="noopener" class="cell-text" style="color:#1d5da3;text-decoration:underline">${escapeHtml(v.name || '파일')}</a>`;
    }
    if (f.type === 'ref') {
        if (!v || !v.label) return `<span class="cell-empty">-</span>`;
        return `<span class="cell-text">${escapeHtml(v.label)}</span>`;
    }
    if (v == null || v === '') return `<span class="cell-empty">-</span>`;
    if (f.type === 'date') return `<span class="cell-text">${escapeHtml(String(v).replace(/-/g, '.'))}</span>`;
    if (f.type === 'textarea') return `<span class="cell-text cell-multiline">${escapeHtml(v)}</span>`;
    if (f.type === 'number') return `<span class="cell-text">${escapeHtml(Number(v).toLocaleString('ko-KR'))}</span>`;
    return `<span class="cell-text">${escapeHtml(v)}</span>`;
}

function cellDisplay(f, v, fullData, allFields) {
    if (f.type === 'toggle') return v ? (f.onLabel || 'ON') : (f.offLabel || 'OFF');
    if (f.type === 'formula') {
        const r = evalFormula(f.formula, fullData || {}, allFields);
        return r === '' ? '' : Number(r).toLocaleString('ko-KR');
    }
    if (f.type === 'file') return v?.name || '';
    if (f.type === 'ref') return v?.label || '';
    if (v == null || v === '') return '';
    if (f.type === 'date') return String(v).replace(/-/g, '.');
    if (f.type === 'number') return Number(v).toLocaleString('ko-KR');
    return String(v);
}

function escapeAttr(s) { return String(s ?? '').replace(/"/g, '&quot;'); }

/* ============== Row events ============== */
function bindRowEvents(form, fields) {
    document.querySelectorAll('[data-edit-row]').forEach(b => {
        b.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = parseInt(b.dataset.editRow, 10);
            const row = records.find(r => r.id === id);
            if (row) openRowEntry(form, fields, row);
        });
    });
    document.querySelectorAll('[data-delete-row]').forEach(b => {
        b.addEventListener('click', async (e) => {
            e.stopPropagation();
            const id = parseInt(b.dataset.deleteRow, 10);
            if (!confirm('이 행을 삭제할까요?')) return;
            try {
                await api('ledger-records', { method: 'DELETE', body: { id } });
                records = records.filter(r => r.id !== id);
                render();
            } catch (err) { alert('삭제 실패: ' + err.message); }
        });
    });
}

async function openRowEntry(form, fields, existing) {
    const dataFields = fields.filter(f => f.type !== 'auto_number');
    const defaults = existing?.data || { created_at: new Date().toISOString().slice(0, 10) };

    // ref 필드에서 참조할 양식 데이터 미리 로드
    const refData = {};
    const refFields = dataFields.filter(f => f.type === 'ref' && f.refFormId);
    for (const rf of refFields) {
        try {
            const res = await api('ledger-records', { query: `group_id=${rf.refFormId}` });
            refData[rf.key] = res.items || [];
        } catch { refData[rf.key] = []; }
    }

    openRowAddModal({
        title: existing ? '행 수정' : '새 행 추가',
        confirmLabel: existing ? '수정' : '추가',
        fields: dataFields,
        defaults,
        customRender: (field, defs) => {
            if (field.type === 'toggle') {
                const v = !!defs[field.key];
                return `
                    <div class="modal-row">
                        <label>${escapeHtml(field.label)}</label>
                        <div>
                            <button type="button" class="tiny-btn ${v ? 'primary' : ''}" data-toggle-field="${field.key}" data-toggle-val="${v ? '1' : '0'}">${escapeHtml(v ? (field.onLabel || 'ON') : (field.offLabel || 'OFF'))}</button>
                        </div>
                    </div>`;
            }
            if (field.type === 'select') {
                const cur = defs[field.key] || '';
                const opts = (field.options || []).map(o =>
                    `<option value="${escapeAttr(o)}" ${o === cur ? 'selected' : ''}>${escapeHtml(o)}</option>`).join('');
                return `
                    <div class="modal-row">
                        <label>${escapeHtml(field.label)}</label>
                        <select data-select-field="${field.key}" style="width:100%;padding:9px 12px;border:1px solid var(--ledger-line);border-radius:8px;font-size:14px;background:#fff;font-family:inherit">
                            <option value="">-- 선택 --</option>
                            ${opts}
                        </select>
                    </div>`;
            }
            if (field.type === 'file') {
                const cur = defs[field.key];
                const curInfo = cur && cur.url ? `<a href="${escapeAttr(cur.url)}" target="_blank" rel="noopener" style="color:#1d5da3;font-size:13px">${escapeHtml(cur.name || '파일')}</a>` : '<span style="color:#8a847e;font-size:13px">첨부 없음</span>';
                return `
                    <div class="modal-row">
                        <label>${escapeHtml(field.label)}</label>
                        <div style="display:flex;flex-direction:column;gap:6px">
                            <div data-file-current="${field.key}">${curInfo}</div>
                            <input type="file" data-file-field="${field.key}" style="font-size:13px">
                            <div data-file-status="${field.key}" style="font-size:12px;color:#8a847e"></div>
                        </div>
                    </div>`;
            }
            if (field.type === 'formula') {
                return `
                    <div class="modal-row">
                        <label>${escapeHtml(field.label)} <span style="font-size:11px;color:#8a847e">(자동 계산)</span></label>
                        <div data-formula-preview="${field.key}" data-formula="${escapeAttr(field.formula || '')}" style="padding:9px 12px;background:#f4efe7;border-radius:8px;font-size:14px;color:#1d5da3;font-weight:600">-</div>
                    </div>`;
            }
            if (field.type === 'ref') {
                const cur = defs[field.key]?.id || '';
                const refRows = refData[field.key] || [];
                const opts = refRows.map(r => {
                    const label = r.data?.[field.refLabelKey] || `행 #${r.id}`;
                    return `<option value="${r.id}" data-label="${escapeAttr(label)}" ${String(r.id) === String(cur) ? 'selected' : ''}>${escapeHtml(label)}</option>`;
                }).join('');
                return `
                    <div class="modal-row">
                        <label>${escapeHtml(field.label)}</label>
                        <select data-ref-field="${field.key}" style="width:100%;padding:9px 12px;border:1px solid var(--ledger-line);border-radius:8px;font-size:14px;background:#fff;font-family:inherit">
                            <option value="">-- 선택 --</option>
                            ${opts}
                        </select>
                    </div>`;
            }
            return null;
        },
        afterRender: (md) => {
            // 토글 버튼
            md.querySelectorAll('[data-toggle-field]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const cur = btn.dataset.toggleVal === '1';
                    const next = !cur;
                    const f = dataFields.find(x => x.key === btn.dataset.toggleField);
                    btn.dataset.toggleVal = next ? '1' : '0';
                    btn.textContent = next ? (f.onLabel || 'ON') : (f.offLabel || 'OFF');
                    btn.classList.toggle('primary', next);
                });
            });
            // 파일 업로드
            md.querySelectorAll('[data-file-field]').forEach(input => {
                const key = input.dataset.fileField;
                input.addEventListener('change', async (e) => {
                    const file = e.target.files?.[0];
                    if (!file) return;
                    const statusEl = md.querySelector(`[data-file-status="${key}"]`);
                    const curEl = md.querySelector(`[data-file-current="${key}"]`);
                    statusEl.textContent = '업로드 중...';
                    try {
                        const fd = new FormData();
                        fd.append('file', file);
                        const { getAccessToken } = await import('./auth-shared.js?v=20260512-mobile-cards5');
                        const tok = await getAccessToken();
                        const res = await fetch('upload.php', {
                            method: 'POST',
                            headers: tok ? { Authorization: 'Bearer ' + tok } : {},
                            body: fd,
                        });
                        const json = await res.json();
                        if (!res.ok || !json.ok) throw new Error(json.error || '업로드 실패');
                        const fileInfo = { name: json.name || file.name, url: json.url || `/uploads/${json.userDir}/${encodeURIComponent(json.name || file.name)}` };
                        input.dataset.uploaded = JSON.stringify(fileInfo);
                        curEl.innerHTML = `<a href="${fileInfo.url}" target="_blank" rel="noopener" style="color:#1d5da3;font-size:13px">${file.name}</a>`;
                        statusEl.textContent = '업로드 완료';
                    } catch (err) {
                        statusEl.textContent = '실패: ' + err.message;
                    }
                });
            });
            // 수식 자동 평가 — 모달 안 input 들 변할 때마다 재계산
            const formulaPreviews = md.querySelectorAll('[data-formula-preview]');
            if (formulaPreviews.length) {
                const recalc = () => {
                    const cur = collectModalRawData(md, dataFields, defaults);
                    formulaPreviews.forEach(el => {
                        const r = evalFormula(el.dataset.formula, cur, dataFields);
                        el.textContent = r === '' ? '-' : Number(r).toLocaleString('ko-KR');
                    });
                };
                md.querySelectorAll('input, select, textarea').forEach(el => {
                    el.addEventListener('input', recalc);
                    el.addEventListener('change', recalc);
                });
                recalc();
            }
        },
        onSubmit: async (data) => {
            const md = document.querySelector('.row-add-modal');
            // toggle
            md.querySelectorAll('[data-toggle-field]').forEach(btn => {
                data[btn.dataset.toggleField] = btn.dataset.toggleVal === '1';
            });
            // select
            md.querySelectorAll('[data-select-field]').forEach(s => {
                data[s.dataset.selectField] = s.value;
            });
            // file — 업로드한 게 있으면 그걸, 없으면 기존 값 유지
            md.querySelectorAll('[data-file-field]').forEach(input => {
                const key = input.dataset.fileField;
                if (input.dataset.uploaded) {
                    try { data[key] = JSON.parse(input.dataset.uploaded); } catch {}
                } else if (defaults[key]) {
                    data[key] = defaults[key];
                }
            });
            // ref
            md.querySelectorAll('[data-ref-field]').forEach(s => {
                const key = s.dataset.refField;
                const opt = s.options[s.selectedIndex];
                if (!s.value) { delete data[key]; return; }
                data[key] = { id: parseInt(s.value, 10), label: opt?.dataset.label || '' };
            });
            // formula 는 저장 안 함 (렌더 시점에 평가)
            dataFields.forEach(f => { if (f.type === 'formula') delete data[f.key]; });

            if (existing) {
                await api('ledger-records', { method: 'PATCH', body: { id: existing.id, data } });
            } else {
                await api('ledger-records', { method: 'POST', body: { page_type: PAGE_TYPE, group_id: activeFormId, data } });
            }
            await loadRecords(activeFormId);
            render();
        }
    });
}

// 모달 입력 현재값 수집 (수식 평가용 — defaults 위에 사용자 입력으로 덮어쓰기)
function collectModalRawData(md, fields, defaults) {
    const cur = { ...defaults };
    fields.forEach(f => {
        if (f.type === 'auto_number' || f.type === 'formula') return;
        const input = md.querySelector(`[name="${f.key}"], [data-field-key="${f.key}"]`);
        if (input && input.value !== undefined) {
            cur[f.label] = input.value;
            cur[f.key] = input.value;
        }
        // 토글
        const tBtn = md.querySelector(`[data-toggle-field="${f.key}"]`);
        if (tBtn) cur[f.key] = tBtn.dataset.toggleVal === '1';
        // select
        const sel = md.querySelector(`[data-select-field="${f.key}"]`);
        if (sel) { cur[f.key] = sel.value; cur[f.label] = sel.value; }
    });
    // 수식이 라벨로 참조하니 라벨 키도 채움
    fields.forEach(f => {
        if (cur[f.key] !== undefined && cur[f.label] === undefined) cur[f.label] = cur[f.key];
    });
    return cur;
}

/* ============== Builder ============== */
function openBuilder(formId) {
    editingFormId = formId;
    const form = formId ? forms.find(f => f.id === formId) : null;
    builderDraft = form?.settings?.customFields ? JSON.parse(JSON.stringify(form.settings.customFields)) : [];
    document.getElementById('builderTitle').textContent = form ? '양식 편집' : '새 양식 만들기';
    document.getElementById('formTitleInput').value = form?.name || '';
    document.getElementById('builderError').classList.add('hidden');
    document.getElementById('builderDelete').style.display = form ? '' : 'none';
    // 빌더 모달 안 type select 초기화 + extra row 동기화
    const modal = document.getElementById('builderModal');
    const typeSelect = modal.querySelector('[data-add-type]');
    typeSelect.value = 'text';
    modal.querySelectorAll('[data-extra-toggle], [data-extra-select], [data-extra-formula], [data-extra-ref]')
        .forEach(el => el.classList.add('hidden'));
    renderFieldList();
    document.getElementById('builderModal').classList.remove('hidden');
}

function closeBuilder() {
    document.getElementById('builderModal').classList.add('hidden');
    editingFormId = null;
    builderDraft = [];
}

function renderFieldList() {
    const list = document.getElementById('fieldList');
    const fixed = BASE_FIELDS.filter(f => f.type !== 'auto_number').map(f => `
        <div class="field-item">
            <div>
                <span class="field-item-name">${escapeHtml(f.label)}</span>
            </div>
            <span class="field-item-type">${FIELD_TYPE_LABELS[f.type]}</span>
            <span style="font-size:11px;color:#8a847e;padding:3px 7px;background:#f4efe7;border-radius:99px">기본</span>
            <span></span>
        </div>
    `).join('');
    const custom = builderDraft.map((f, i) => {
        let info = '';
        if (f.type === 'toggle') {
            info = `<div class="field-item-toggle-labels">ON: ${escapeHtml(f.onLabel || 'ON')} / OFF: ${escapeHtml(f.offLabel || 'OFF')}</div>`;
        } else if (f.type === 'select') {
            info = `<div class="field-item-toggle-labels">옵션: ${escapeHtml((f.options || []).join(', '))}</div>`;
        } else if (f.type === 'formula') {
            info = `<div class="field-item-toggle-labels">수식: ${escapeHtml(f.formula || '')}</div>`;
        } else if (f.type === 'ref') {
            const target = forms.find(x => x.id === f.refFormId);
            info = `<div class="field-item-toggle-labels">참조: ${escapeHtml(target?.name || '(삭제된 양식)')}</div>`;
        }
        return `
            <div class="field-item" data-i="${i}">
                <div>
                    <span class="field-item-name">${escapeHtml(f.label)}</span>
                    ${info}
                </div>
                <span class="field-item-type">${FIELD_TYPE_LABELS[f.type] || f.type}</span>
                <button class="icon-btn" type="button" data-move-up="${i}" title="위로">↑</button>
                <button class="icon-btn danger" type="button" data-del="${i}" title="삭제">×</button>
            </div>`;
    }).join('');
    list.innerHTML = fixed + custom;
    list.querySelectorAll('[data-del]').forEach(btn => {
        btn.addEventListener('click', () => {
            const i = parseInt(btn.dataset.del, 10);
            if (!confirm(`"${builderDraft[i]?.label}" 항목을 삭제할까요?`)) return;
            builderDraft.splice(i, 1);
            renderFieldList();
        });
    });
    list.querySelectorAll('[data-move-up]').forEach(btn => {
        btn.addEventListener('click', () => {
            const i = parseInt(btn.dataset.moveUp, 10);
            if (i <= 0) return;
            [builderDraft[i - 1], builderDraft[i]] = [builderDraft[i], builderDraft[i - 1]];
            renderFieldList();
        });
    });
}

function bindBuilderModal() {
    const modal = document.getElementById('builderModal');
    const addBtn = modal.querySelector('[data-add-go]');
    const labelInput = modal.querySelector('[data-add-label]');
    const typeSelect = modal.querySelector('[data-add-type]');
    const extraToggle = modal.querySelector('[data-extra-toggle]');
    const extraSelect = modal.querySelector('[data-extra-select]');
    const extraFormula = modal.querySelector('[data-extra-formula]');
    const extraRef = modal.querySelector('[data-extra-ref]');
    const onInput = modal.querySelector('[data-add-on]');
    const offInput = modal.querySelector('[data-add-off]');
    const optionsInput = modal.querySelector('[data-add-options]');
    const formulaInput = modal.querySelector('[data-add-formula]');
    const refFormSelect = modal.querySelector('[data-add-ref-form]');
    const refLabelKeySelect = modal.querySelector('[data-add-ref-label-key]');

    function syncExtraRows() {
        const t = typeSelect.value;
        extraToggle.classList.toggle('hidden', t !== 'toggle');
        extraSelect.classList.toggle('hidden', t !== 'select');
        extraFormula.classList.toggle('hidden', t !== 'formula');
        extraRef.classList.toggle('hidden', t !== 'ref');
        if (t === 'ref') populateRefFormOptions();
    }
    function populateRefFormOptions() {
        const cur = refFormSelect.value;
        refFormSelect.innerHTML = `<option value="">참조할 양식을 선택하세요…</option>` +
            forms.filter(f => f.id !== editingFormId).map(f =>
                `<option value="${f.id}" ${String(f.id) === cur ? 'selected' : ''}>${escapeHtml(f.name)}</option>`).join('');
    }
    refFormSelect.addEventListener('change', () => {
        const fid = parseInt(refFormSelect.value, 10);
        const target = forms.find(f => f.id === fid);
        if (!target) {
            refLabelKeySelect.innerHTML = `<option value="">참조 양식 선택 후 표시할 항목 선택</option>`;
            refLabelKeySelect.disabled = true;
            return;
        }
        const cf = (target.settings?.customFields || []).filter(f => ['text','number','date'].includes(f.type));
        if (cf.length === 0) {
            refLabelKeySelect.innerHTML = `<option value="">참조 양식에 표시 가능한 항목이 없습니다</option>`;
            refLabelKeySelect.disabled = true;
            return;
        }
        refLabelKeySelect.innerHTML = cf.map(f => `<option value="${escapeAttr(f.key)}">${escapeHtml(f.label)}</option>`).join('');
        refLabelKeySelect.disabled = false;
    });

    typeSelect.addEventListener('change', syncExtraRows);

    addBtn.addEventListener('click', () => {
        const label = (labelInput.value || '').trim();
        const type = typeSelect.value;
        if (!label) { labelInput.focus(); return; }
        if ([...BASE_FIELDS, ...builderDraft].some(f => f.label === label)) {
            alert('이미 같은 이름의 항목이 있습니다.');
            return;
        }
        const newField = {
            key: `cf_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 6)}`,
            label,
            type,
            custom: true,
        };
        if (type === 'toggle') {
            newField.onLabel = (onInput.value || '').trim() || 'ON';
            newField.offLabel = (offInput.value || '').trim() || 'OFF';
        } else if (type === 'select') {
            const lines = (optionsInput.value || '').split('\n').map(s => s.trim()).filter(Boolean);
            if (lines.length === 0) { alert('드롭다운 옵션을 한 줄에 하나씩 입력해주세요.'); return; }
            newField.options = lines;
        } else if (type === 'formula') {
            const f = (formulaInput.value || '').trim();
            if (!f) { alert('수식을 입력해주세요. 예: {수량} * {단가}'); return; }
            newField.formula = f;
        } else if (type === 'ref') {
            if (!refFormSelect.value) { alert('참조할 양식을 선택해주세요.'); return; }
            if (!refLabelKeySelect.value) { alert('표시할 항목을 선택해주세요.'); return; }
            newField.refFormId = parseInt(refFormSelect.value, 10);
            newField.refLabelKey = refLabelKeySelect.value;
        }
        builderDraft.push(newField);
        labelInput.value = '';
        onInput.value = '';
        offInput.value = '';
        optionsInput.value = '';
        formulaInput.value = '';
        refFormSelect.value = '';
        refLabelKeySelect.innerHTML = `<option value="">참조 양식 선택 후 표시할 항목 선택</option>`;
        refLabelKeySelect.disabled = true;
        renderFieldList();
    });

    document.getElementById('builderCancel').addEventListener('click', closeBuilder);
    document.getElementById('builderSave').addEventListener('click', saveBuilder);
    document.getElementById('builderDelete').addEventListener('click', deleteForm);
}

async function saveBuilder() {
    const name = document.getElementById('formTitleInput').value.trim();
    const errEl = document.getElementById('builderError');
    const setErr = (msg) => { errEl.textContent = msg; errEl.classList.remove('hidden'); };
    if (!name) return setErr('양식 제목을 입력해주세요.');
    if (builderDraft.length === 0) return setErr('최소 1개 이상의 항목을 추가해주세요.');
    const dup = forms.find(f => f.name === name && f.id !== editingFormId);
    if (dup) return setErr(`이미 같은 이름의 양식이 있습니다 ("${name}").`);

    try {
        if (editingFormId) {
            const settings = { customFields: builderDraft };
            await api('ledger-groups', { method: 'PATCH', body: { id: editingFormId, name, settings } });
        } else {
            await api('ledger-groups', {
                method: 'POST',
                body: {
                    pageType: PAGE_TYPE,
                    name,
                    isDefault: false,
                    settings: { customFields: builderDraft },
                },
            });
        }
        closeBuilder();
        await loadForms();
        render();
    } catch (e) {
        setErr('저장 실패: ' + (e.message || ''));
    }
}

async function deleteForm() {
    if (!editingFormId) return;
    const f = forms.find(x => x.id === editingFormId);
    if (!f) return;
    if (!confirm(`"${f.name}" 양식과 그 안의 모든 행을 영구 삭제합니다. 진행하시겠습니까?`)) return;
    try {
        await api('ledger-groups', { method: 'DELETE', body: { id: editingFormId } });
        closeBuilder();
        if (activeFormId === editingFormId) exitForm();
        await loadForms();
        render();
    } catch (e) {
        alert('삭제 실패: ' + (e.message || ''));
    }
}

/* ============== Util ============== */
function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
