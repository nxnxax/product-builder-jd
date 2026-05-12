/**
 * ledger-shared.js — 조직도 / 계약자 / 고객 관리대장 공통 UI 헬퍼
 *
 * 컬럼 헤더 클릭형 필터 dropdown 을 한 곳에서 관리.
 * 사용법:
 *   import { attachColumnFilters, getActiveFilters, applyColumnFilters } from './ledger-shared.js?v=...';
 *   attachColumnFilters({
 *       state,                       // { filters: {} } — 호출자가 보유
 *       headers: NodeList | Array,   // <th> 요소들
 *       fields: [{key, label, filterable}],   // 컬럼 정의
 *       getRows: () => Record[],     // 현재 (필터 적용 전) 레코드 배열
 *       getValue: (row, key) => string,
 *       onChange: () => void,        // 필터 바뀐 후 호출자 재렌더
 *   });
 *
 *   const filtered = applyColumnFilters(state.filters, rows, getValue);
 *
 * filters 자료구조:
 *   { [columnKey]: Set<string> }   // Set 가 비어 있거나 undefined 면 그 컬럼 필터 없음
 */

let activePop = null;
let docClickBound = false;

/* =========================================================================
   모바일 카드 펼치기/접기 토글 — head 영역 클릭 시 expanded 토글.
   체크박스/액션 버튼 클릭은 토글에서 제외.
   ========================================================================= */
if (typeof document !== 'undefined' && typeof window !== 'undefined' && !window.__ledgerCardToggleBound) {
    document.addEventListener('click', (e) => {
        const card = e.target.closest('.ledger-card');
        if (!card) return;
        // 본문 영역(.ledger-card-body) 내부 클릭은 토글 안 함
        if (e.target.closest('.ledger-card-body')) return;
        // 인터랙티브 요소 클릭은 토글 안 함
        if (e.target.closest('input, button, select, textarea, a, label, [data-no-toggle]')) {
            // 단, 카드 자체의 toggle 버튼은 토글 동작
            if (e.target.closest('.ledger-card-toggle')) {
                card.classList.toggle('expanded');
            }
            return;
        }
        card.classList.toggle('expanded');
    });
    window.__ledgerCardToggleBound = true;
}

/* =========================================================================
   모바일 viewport 변화 감지 — *.js 가 등록한 콜백을 호출해서 재렌더 유도.
   ========================================================================= */
const _ledgerViewportCallbacks = new Set();
export function onLedgerViewportChange(cb) {
    if (typeof cb !== 'function') return;
    _ledgerViewportCallbacks.add(cb);
}
if (typeof window !== 'undefined' && !window.__ledgerViewportBound) {
    try {
        const mq = window.matchMedia('(max-width: 640px)');
        const handler = () => _ledgerViewportCallbacks.forEach(cb => { try { cb(); } catch {} });
        if (mq.addEventListener) mq.addEventListener('change', handler);
        else mq.addListener(handler);  // 옛 Safari fallback
        window.__ledgerViewportBound = true;
    } catch {}
}
export function isLedgerMobile() {
    try { return window.matchMedia('(max-width: 640px)').matches; }
    catch { return false; }
}

/* =========================================================================
   사용자 정의 필드 (custom fields) — 그룹 settings.customFields 에 저장.
   { key, label, type } 형태. type 은 text / date / tel / textarea / number.
   ========================================================================= */

const FIELD_TYPE_LABELS = {
    text: '텍스트', date: '날짜', tel: '전화번호',
    textarea: '긴 텍스트', number: '숫자',
    auto_number: '번호', title_select: '직함',
    manage_switch: '관리', manager_select: '담당자',
    pay_switch: '수수료', commission_view: '수수료(자동)',
    status_switch: '계약상태',
};

export function getCustomFields(group) {
    const arr = group?.settings?.customFields;
    return Array.isArray(arr) ? arr : [];
}

/** default + custom 합쳐서 반환. 렌더에 바로 사용. */
export function getEffectiveFields(group, defaultFields) {
    return [...(defaultFields || []), ...getCustomFields(group)];
}

/** 사용자 정의 필드 관리 UI 를 컨테이너에 렌더. onChange 가 변경된 customFields 배열을 받음. */
export function mountFieldManager({ container, defaultFields, customFields, onChange }) {
    const ALLOWED_TYPES = [
        { value: 'text',     label: '텍스트' },
        { value: 'number',   label: '숫자' },
        { value: 'date',     label: '날짜' },
        { value: 'tel',      label: '전화번호' },
        { value: 'textarea', label: '긴 텍스트' },
    ];
    const state = Array.isArray(customFields) ? [...customFields] : [];

    function render() {
        const def = (defaultFields || []).filter(f => f.type !== 'auto_number');
        container.innerHTML = `
            <div class="field-mgr-list">
                ${def.map(f => `
                    <div class="field-mgr-row built-in">
                        <span class="field-mgr-name">${escapeHtml(f.label)}</span>
                        <span class="field-mgr-type">${FIELD_TYPE_LABELS[f.type] || f.type}</span>
                        <span class="field-mgr-tag">기본</span>
                    </div>
                `).join('')}
                ${state.map((f, i) => `
                    <div class="field-mgr-row custom" data-i="${i}">
                        <span class="field-mgr-name">${escapeHtml(f.label)}</span>
                        <span class="field-mgr-type">${FIELD_TYPE_LABELS[f.type] || f.type}</span>
                        <button type="button" class="field-mgr-del" data-i="${i}" title="삭제">×</button>
                    </div>
                `).join('')}
            </div>
            <div class="field-mgr-add">
                <input type="text" placeholder="새 필드 이름 (예: 특기, 비상연락처)" data-add-label maxlength="30">
                <select data-add-type>
                    ${ALLOWED_TYPES.map(t => `<option value="${t.value}">${t.label}</option>`).join('')}
                </select>
                <button type="button" class="tiny-btn primary" data-add-go>+ 추가</button>
            </div>
            <p class="field-mgr-help">기본 필드는 삭제할 수 없습니다. 추가한 필드는 × 로 삭제 가능합니다.</p>
        `;

        container.querySelectorAll('[data-i]').forEach(btn => {
            if (btn.classList.contains('field-mgr-del')) {
                btn.addEventListener('click', () => {
                    const i = parseInt(btn.dataset.i, 10);
                    if (!isNaN(i)) {
                        if (!confirm(`"${state[i]?.label}" 필드를 삭제할까요? (입력된 데이터는 그대로 남지만 표에 안 보입니다)`)) return;
                        state.splice(i, 1);
                        notify();
                        render();
                    }
                });
            }
        });

        const addBtn = container.querySelector('[data-add-go]');
        const labelInput = container.querySelector('[data-add-label]');
        const typeSelect = container.querySelector('[data-add-type]');

        function tryAdd() {
            const label = (labelInput.value || '').trim();
            const type = typeSelect.value || 'text';
            if (!label) { labelInput.focus(); return; }
            if (label.length > 30) return;
            // 중복 라벨 차단
            const existing = [...(defaultFields || []), ...state].some(f => f.label === label);
            if (existing) {
                alert('이미 같은 이름의 필드가 있습니다.');
                return;
            }
            const key = `cf_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 6)}`;
            state.push({ key, label, type, custom: true });
            labelInput.value = '';
            notify();
            render();
        }

        addBtn.addEventListener('click', tryAdd);
        labelInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); tryAdd(); }
        });
    }

    function notify() { onChange?.(state); }

    render();
    return {
        getFields: () => [...state],
    };
}

/** 한국 휴대폰 번호 포맷 — 숫자만 남기고 010-XXXX-XXXX 형태로. */
export function formatKoreanPhone(raw) {
    const digits = String(raw || '').replace(/\D/g, '').slice(0, 11);
    if (!digits) return '';
    if (digits.length <= 3) return digits;
    if (digits.length <= 7) return digits.slice(0, 3) + '-' + digits.slice(3);
    return digits.slice(0, 3) + '-' + digits.slice(3, 7) + '-' + digits.slice(7);
}

/** 숫자에 천단위 쉼표 — '1000000' → '1,000,000'. */
export function formatThousand(val) {
    const digits = String(val ?? '').replace(/[^\d]/g, '');
    if (!digits) return '';
    return parseInt(digits, 10).toLocaleString('en-US');
}

/** 쉼표 포함된 문자열 → 정수. '1,000,000' → 1000000. */
export function unformatThousand(val) {
    const n = parseInt(String(val ?? '').replace(/[^\d]/g, ''), 10);
    return isNaN(n) ? 0 : n;
}

/** input[data-thousand] 에 입력 중 자동 쉼표 삽입. 초기값도 포맷. 재렌더 후 매번 호출. */
export function attachThousandFormat(root) {
    const scope = root || document;
    scope.querySelectorAll('input[data-thousand]').forEach(input => {
        if (input.dataset.thousandBound) return;
        input.dataset.thousandBound = '1';
        if (input.value) input.value = formatThousand(input.value);
        input.addEventListener('input', () => {
            const before = input.value;
            const cursor = input.selectionStart || 0;
            const formatted = formatThousand(before);
            input.value = formatted;
            // 단순 cursor 처리 — 쉼표 추가/제거된 만큼 보정.
            const diff = formatted.length - before.length;
            try { input.setSelectionRange(cursor + diff, cursor + diff); } catch {}
        });
        input.addEventListener('focus', () => {
            // 0 인 채로 있으면 통째 선택해서 바로 입력하기 쉽게.
            if (input.value === '0') input.select();
        });
    });
}

/** 모든 tel input 에 자동 포맷 + '010-' 기본값 바인딩. 재렌더 후 매번 호출 가능. */
export function attachPhoneAutoFormat(root) {
    const scope = root || document;
    scope.querySelectorAll('input[type=tel]').forEach(input => {
        if (input.dataset.phoneFmtBound) return;
        input.dataset.phoneFmtBound = '1';
        input.addEventListener('focus', () => {
            if (!input.value.trim()) input.value = '010-';
        });
        input.addEventListener('input', () => {
            input.value = formatKoreanPhone(input.value);
        });
        input.addEventListener('blur', () => {
            if (input.value === '010-' || input.value === '010') input.value = '';
        });
        // 기존 값이 포맷 안 돼 있으면 한 번 정리.
        if (input.value && /\d/.test(input.value)) input.value = formatKoreanPhone(input.value);
    });
}

/** 외부 클릭 시 popup 닫기 — 한번만 등록. */
function ensureOutsideClickHandler() {
    if (docClickBound) return;
    docClickBound = true;
    document.addEventListener('click', (e) => {
        if (!activePop) return;
        if (activePop.contains(e.target)) return;
        if (e.target.closest('[data-filter-trigger]')) return;
        closePopup();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closePopup();
    });
    window.addEventListener('resize', closePopup);
    window.addEventListener('scroll', closePopup, true);
}

function closePopup() {
    if (activePop) { activePop.remove(); activePop = null; }
}

/** state.filters 를 row 배열에 적용. 빈 Set / undefined 는 통과. */
export function applyColumnFilters(filters, rows, getValue) {
    if (!filters) return rows;
    const activeKeys = Object.keys(filters).filter(k => filters[k] && filters[k].size > 0);
    if (activeKeys.length === 0) return rows;
    return rows.filter(r => {
        for (const k of activeKeys) {
            const v = String(getValue(r, k) ?? '').trim();
            if (!filters[k].has(v)) return false;
        }
        return true;
    });
}

/** 컬럼별 필터 dropdown 을 헤더 셀에 부착. */
export function attachColumnFilters({ state, headers, fields, getRows, getValue, onChange, labelFor }) {
    ensureOutsideClickHandler();
    state.filters = state.filters || {};

    const fieldByKey = new Map(fields.map(f => [f.key, f]));

    headers.forEach(th => {
        const key = th.dataset.colKey;
        if (!key) return;
        const f = fieldByKey.get(key);
        if (!f || !f.filterable) return;

        // 헤더 클릭 가능 + 필터 활성 시 강조.
        th.classList.add('col-clickable');
        const active = state.filters[key] && state.filters[key].size > 0;
        if (active) th.classList.add('col-filtered');

        // 화살표 표시 (없으면 추가)
        if (!th.querySelector('.col-arrow')) {
            const arr = document.createElement('span');
            arr.className = 'col-arrow';
            arr.textContent = active ? '▼' : '▾';
            th.appendChild(arr);
        }

        th.dataset.filterTrigger = '1';
        th.addEventListener('click', (e) => {
            e.stopPropagation();
            // 같은 컬럼 다시 클릭 = 닫기
            if (activePop && activePop.dataset.col === key) { closePopup(); return; }
            openFilterPopup(th, key, f, state, getRows, getValue, onChange, labelFor);
        });
    });
}

function openFilterPopup(th, key, field, state, getRows, getValue, onChange, labelFor) {
    closePopup();
    const rows = getRows() || [];
    // 컬럼의 모든 unique 값 + 건수.
    const counts = new Map();
    rows.forEach(r => {
        const v = String(getValue(r, key) ?? '').trim();
        counts.set(v, (counts.get(v) || 0) + 1);
    });
    const fmtLabel = (raw) => {
        if (typeof labelFor === 'function') {
            const out = labelFor(field, raw);
            if (out !== undefined && out !== null) return out;
        }
        return raw;
    };
    const sorted = [...counts.entries()].sort((a, b) => {
        // 빈 값은 맨 아래
        if (a[0] === '' && b[0] !== '') return 1;
        if (b[0] === '' && a[0] !== '') return -1;
        return String(fmtLabel(a[0])).localeCompare(String(fmtLabel(b[0])), 'ko');
    });

    const current = state.filters[key] instanceof Set ? state.filters[key] : new Set();
    const noneActive = current.size === 0;   // 필터 없음 = 모두 통과 = 모두 선택된 상태로 간주

    const pop = document.createElement('div');
    pop.className = 'filter-pop';
    pop.dataset.col = key;
    pop.innerHTML = `
        <div class="filter-pop-head">${escapeHtml(field.label)}<button class="filter-pop-close" type="button" aria-label="닫기">×</button></div>
        <input type="search" class="filter-pop-search" placeholder="값 검색...">
        <label class="filter-pop-toggle"><input type="checkbox" data-fall> 전체 선택</label>
        <div class="filter-pop-list">
            ${sorted.map(([v, c]) => {
                const checked = noneActive || current.has(v);
                const labeled = fmtLabel(v);
                const display = (labeled === '' || labeled === null || labeled === undefined)
                    ? '<i style="color:#a3a39a;">(빈 값)</i>'
                    : escapeHtml(String(labeled));
                return `<label><input type="checkbox" data-val="${escapeAttr(v)}" ${checked ? 'checked' : ''}>${display}<span class="count">${c}</span></label>`;
            }).join('')}
        </div>
        <div class="filter-pop-actions">
            <button class="tiny-btn" data-action="clear" type="button">필터 해제</button>
            <button class="tiny-btn primary" data-action="apply" type="button">적용</button>
        </div>
    `;

    document.body.appendChild(pop);
    const rect = th.getBoundingClientRect();
    const popRect = pop.getBoundingClientRect();
    let left = rect.left;
    if (left + popRect.width > window.innerWidth - 12) left = window.innerWidth - popRect.width - 12;
    pop.style.left = Math.max(8, left) + 'px';
    pop.style.top = (rect.bottom + 4) + 'px';

    activePop = pop;

    // 전체 선택 체크박스 동기화
    const fallEl = pop.querySelector('[data-fall]');
    const itemEls = [...pop.querySelectorAll('input[data-val]')];
    const syncFall = () => {
        const all = itemEls.every(i => i.checked);
        fallEl.checked = all;
        fallEl.indeterminate = !all && itemEls.some(i => i.checked);
    };
    syncFall();

    fallEl.addEventListener('change', () => {
        itemEls.forEach(i => { i.checked = fallEl.checked; });
        syncFall();
    });
    itemEls.forEach(i => i.addEventListener('change', syncFall));

    // 검색
    pop.querySelector('.filter-pop-search').addEventListener('input', (e) => {
        const q = (e.target.value || '').toLowerCase().trim();
        pop.querySelectorAll('.filter-pop-list label').forEach(l => {
            const txt = (l.textContent || '').toLowerCase();
            l.style.display = !q || txt.includes(q) ? '' : 'none';
        });
    });

    pop.querySelector('.filter-pop-close').addEventListener('click', closePopup);
    pop.querySelector('[data-action="clear"]').addEventListener('click', () => {
        delete state.filters[key];
        closePopup();
        onChange();
    });
    pop.querySelector('[data-action="apply"]').addEventListener('click', () => {
        const all = itemEls.every(i => i.checked);
        if (all) {
            // 전부 선택 = 필터 없음 과 동일
            delete state.filters[key];
        } else {
            const sel = new Set();
            itemEls.forEach(i => { if (i.checked) sel.add(i.dataset.val); });
            if (sel.size === 0) {
                // 0개 선택 = 모두 숨김 (사용자가 명시적으로 모두 끔). 빈 Set 으로 두면 모두 매칭되므로
                // 실제로 "0개 매칭" 이 되도록 sentinel 값을 넣거나 별도 표시. 현재는 "전체 해제 = 필터 없음" 동작으로 정리.
                delete state.filters[key];
            } else {
                state.filters[key] = sel;
            }
        }
        closePopup();
        onChange();
    });
}

function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s) { return String(s ?? '').replace(/"/g, '&quot;'); }

/* =========================================================================
   행 추가 모달 (3 페이지 공통)
   사용:
     openRowAddModal({
         title: '새 계약 추가',
         fields: DEFAULT_FIELDS,
         defaults: { paid_unpaid: true, status: 'active' },
         customRender: (field, defaults) => '<div class="modal-row">...</div>' | null,
         onSubmit: async (data) => { ... }   // 실패 시 throw 하면 모달이 에러 표시.
     });
   ========================================================================= */
export function openRowAddModal(opts) {
    closeRowAddModal();
    const fields = opts.fields || [];
    const defaults = opts.defaults || {};
    const md = document.createElement('div');
    md.className = 'modal-backdrop row-add-modal';
    md.style.zIndex = '300';
    md.innerHTML = `
        <div class="modal-panel">
            <header class="modal-header">
                <div>
                    <h2>${escapeHtml(opts.title || '새 행 추가')}</h2>
                    <p class="modal-subtitle">필요한 항목만 입력하셔도 됩니다. 빈 칸은 나중에 표에서 채울 수 있습니다.</p>
                </div>
            </header>
            <div class="modal-body">
                <form data-form>${fields.map(f => renderEntryField(f, defaults, opts.customRender)).join('')}</form>
                <p class="form-help error" data-error style="margin-top:10px;display:none;"></p>
            </div>
            <footer class="modal-footer">
                <button class="tiny-btn" type="button" data-cancel>취소</button>
                <button class="tiny-btn primary" type="button" data-confirm>${escapeHtml(opts.confirmLabel || '확인')}</button>
            </footer>
        </div>
    `;
    document.body.appendChild(md);

    const close = () => closeRowAddModal();
    md.querySelector('[data-cancel]').addEventListener('click', close);
    md.addEventListener('click', (e) => { if (e.target === md) close(); });
    md.querySelector('[data-confirm]').addEventListener('click', async () => {
        const data = collectEntry(fields, defaults, md);
        const errEl = md.querySelector('[data-error]');
        const btn = md.querySelector('[data-confirm]');
        btn.disabled = true; errEl.style.display = 'none';
        try {
            await opts.onSubmit(data);
            close();
        } catch (e) {
            errEl.textContent = (e && e.message) ? e.message : String(e);
            errEl.style.display = '';
            btn.disabled = false;
        }
    });

    // Enter 누르면 확인. textarea 안에서는 줄바꿈 허용.
    md.querySelector('[data-form]').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            md.querySelector('[data-confirm]').click();
        }
    });
    attachPhoneAutoFormat(md);
    attachThousandFormat(md);
    if (typeof opts.afterRender === 'function') {
        try { opts.afterRender(md); } catch (e) { console.error('[openRowAddModal afterRender]', e); }
    }
    setTimeout(() => md.querySelector('input,select,textarea')?.focus(), 30);
}

function closeRowAddModal() {
    document.querySelectorAll('.row-add-modal').forEach(m => m.remove());
}

function renderEntryField(f, defaults, customRender) {
    if (f.type === 'auto_number') return '';
    if (customRender) {
        const ch = customRender(f, defaults);
        if (ch !== undefined && ch !== null) return ch;
    }
    if (f.type === 'commission_view') return '';   // 페이지 customRender 가 처리 안 했으면 모달에서 생략
    const v = defaults[f.key] ?? '';
    const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
    const wrap = (control, extra = '') => `<div class="modal-row" ${extra}>${lbl}<div class="row-control">${control}</div></div>`;

    if (f.type === 'date')     return wrap(`<input type="date" data-field="${f.key}" value="${escapeAttr(v)}">`);
    if (f.type === 'tel')      return wrap(`<input type="tel"  data-field="${f.key}" value="${escapeAttr(v)}" placeholder="010-...">`);
    if (f.type === 'textarea') return wrap(`<textarea data-field="${f.key}" rows="3" placeholder="${escapeAttr(f.label)}">${escapeHtml(v)}</textarea>`, 'style="align-items:start;"');
    if (f.type === 'number')   return wrap(`<input type="text" inputmode="numeric" data-thousand data-field="${f.key}" value="${escapeAttr(v)}" placeholder="0">`);
    if (f.type === 'title_select') return wrap(`<select data-field="${f.key}"><option value="">-</option>${['본부장','팀장','팀원'].map(t => `<option value="${t}" ${v === t ? 'selected' : ''}>${t}</option>`).join('')}</select>`);
    if (f.type === 'manager_title') {
        // 자동 도출 — 모달에서는 read-only 표시 (담당자 선택에 따라 자동)
        return wrap(`<span class="row-static" data-field="${f.key}" data-readonly>${escapeHtml(v) || '<i style="color:#a3a39a;">담당자 선택 시 자동</i>'}</span>`);
    }
    return wrap(`<input type="text" data-field="${f.key}" value="${escapeAttr(v)}" placeholder="${escapeAttr(f.label)}">`);
}

function collectEntry(fields, defaults, md) {
    const data = { ...defaults };
    fields.forEach(f => {
        if (f.type === 'auto_number' || f.type === 'commission_view') return;
        const el = md.querySelector(`[data-field="${f.key}"]`);
        if (!el) return;
        if (el.dataset && el.dataset.readonly !== undefined) {
            // span 등 read-only — defaults 값 그대로 유지 (변경 없음)
            return;
        }
        if (el.type === 'checkbox') {
            data[f.key] = el.checked;
        } else if (el.dataset && el.dataset.thousand !== undefined) {
            // 천단위 쉼표 input — 숫자만 추출
            const digits = String(el.value || '').replace(/[^\d]/g, '');
            data[f.key] = digits === '' ? '' : parseInt(digits, 10);
        } else {
            const val = el.value;
            data[f.key] = val === '' ? '' : val;
        }
    });
    return data;
}

/* =========================================================================
   엑셀 다운로드 / 업로드
   - SheetJS (xlsx) 를 CDN 에서 동적 로드. 한 번 로드되면 재사용.
   - PII 격리: 업로드 파일은 클라이언트에서만 파싱. 서버로는 매핑된
     레코드 데이터만 ledger-records POST 로 전달.
   ========================================================================= */

const SHEETJS_URL = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
let sheetJsPromise = null;

export function loadSheetJS() {
    if (typeof window !== 'undefined' && window.XLSX) return Promise.resolve(window.XLSX);
    if (sheetJsPromise) return sheetJsPromise;
    sheetJsPromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = SHEETJS_URL;
        s.async = true;
        s.onload = () => resolve(window.XLSX);
        s.onerror = () => { sheetJsPromise = null; reject(new Error('엑셀 라이브러리(SheetJS) 로드 실패')); };
        document.head.appendChild(s);
    });
    return sheetJsPromise;
}

/** 토글/스위치 등 boolean 값을 사람이 읽을 수 있는 한국어로 변환. */
function exportCellValue(field, raw) {
    if (raw === null || raw === undefined || raw === '') return '';
    if (field.type === 'manage_switch') return raw ? '관리중' : '비관리중';
    if (field.type === 'pay_switch')    return raw ? '지급'   : '미지급';
    if (field.type === 'status_switch') {
        // contracts.js 의 status 값: 'active' / 'cancelled' 등 자유. 그대로 표시.
        return String(raw);
    }
    if (typeof raw === 'boolean') return raw ? 'O' : 'X';
    return String(raw);
}

/**
 * 그룹 → 시트 형태로 .xlsx 저장.
 * @param {Object} opts
 *  - sheets: [{ name, fields, rows: [{data}] }]
 *  - fileName: 'foo.xlsx'
 */
export async function exportRecordsToExcel({ sheets, fileName }) {
    const XLSX = await loadSheetJS();
    const wb = XLSX.utils.book_new();
    sheets.forEach(s => {
        // auto_number / commission_view 는 자동 계산 컬럼이라 export 제외.
        const cols = (s.fields || []).filter(f => f.type !== 'auto_number' && f.type !== 'commission_view');
        const headers = cols.map(f => f.label);
        const aoa = [headers];
        (s.rows || []).forEach(r => {
            const d = (r && typeof r === 'object' && r.data) ? r.data : (r || {});
            aoa.push(cols.map(f => exportCellValue(f, d[f.key])));
        });
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        // 컬럼 폭: 한글 가독성을 위해 라벨 + 데이터 길이 기준으로 적당히.
        ws['!cols'] = cols.map((f, ci) => {
            let max = String(f.label || '').length;
            for (let r = 1; r < aoa.length; r++) {
                const v = aoa[r][ci];
                const len = String(v ?? '').length;
                if (len > max) max = len;
            }
            return { wch: Math.min(40, Math.max(8, max + 2)) };
        });
        // 시트 이름은 31자 제한 + 금지 문자.
        const safeName = String(s.name || 'Sheet').replace(/[\\/?*[\]:]/g, '_').slice(0, 31) || 'Sheet';
        // 중복 시트명 회피.
        let nm = safeName, n = 2;
        while (wb.SheetNames.includes(nm)) { nm = (safeName + ' ' + n).slice(0, 31); n++; }
        XLSX.utils.book_append_sheet(wb, ws, nm);
    });
    XLSX.writeFile(wb, fileName || 'export.xlsx');
}

/** 파일 선택 dialog 띄우고 File 반환. 취소하면 null. */
export function pickExcelFile() {
    return new Promise((resolve) => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.xlsx,.xls,.csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        input.style.display = 'none';
        let settled = false;
        input.addEventListener('change', () => {
            settled = true;
            const f = input.files && input.files[0] ? input.files[0] : null;
            input.remove();
            resolve(f);
        });
        // 취소 감지: focus 돌아온 뒤 일정 시간 안에 change 안 오면 null.
        const onFocus = () => {
            setTimeout(() => {
                if (!settled) { input.remove(); resolve(null); }
                window.removeEventListener('focus', onFocus);
            }, 500);
        };
        window.addEventListener('focus', onFocus, { once: true });
        document.body.appendChild(input);
        input.click();
    });
}

/** 엑셀 셀 값을 문자열로. SheetJS Date 객체도 처리. NBSP/ZWSP 등 정규화. */
function cellToString(v) {
    if (v === null || v === undefined) return '';
    if (v instanceof Date) {
        // YYYY-MM-DD
        const y = v.getFullYear();
        const m = String(v.getMonth() + 1).padStart(2, '0');
        const d = String(v.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }
    return String(v)
        .replace(/[ ​‌‍﻿]/g, ' ')   // NBSP, ZWSP, BOM 을 공백으로
        .trim();
}

/** "의미있는 값" 판정. 빈 문자열 / '-' / 'N/A' 등 placeholder 도 빈 값 취급. */
function isMeaningful(v) {
    const s = cellToString(v);
    if (!s) return false;
    if (/^[-–—_~・·.\s]+$/.test(s)) return false;            // '-', '_', 점만 있는 셀
    if (/^(n\/?a|na|none|null|nil|undefined)$/i.test(s)) return false;
    return true;
}

/**
 * 사용자 명시 정책: "숫자만 있고 텍스트가 없는 행" 은 빈 데이터로 취급.
 * 한글 / 영문 알파벳 / 한자 같은 "글자" 가 1개라도 있어야 진짜 데이터로 판정.
 * 숫자, 콤마, 점, 하이픈, 공백, 통화기호, 단위 같은 건 텍스트 없음으로 본다.
 */
function hasUserText(v) {
    const s = cellToString(v);
    if (!s) return false;
    if (/^[-–—_~・·.\s]+$/.test(s)) return false;
    if (/^(n\/?a|na|none|null|nil|undefined)$/i.test(s)) return false;
    // 한글 / 영문 알파벳 / CJK 한자 1글자라도 있으면 텍스트 있음.
    return /[가-힣ㄱ-ㅎㅏ-ㅣA-Za-z一-鿿]/.test(s);
}

/** mapped row (key→value 객체) 안에 텍스트 값이 1개라도 있는지. */
export function mappedHasUserText(data) {
    if (!data || typeof data !== 'object') return false;
    for (const k of Object.keys(data)) {
        const v = data[k];
        if (typeof v === 'boolean' || typeof v === 'number') continue;   // default 토글값은 무시
        if (hasUserText(v)) return true;
    }
    return false;
}

/** 페이지 진입 시 자동 호출 권장: 그룹 records 중 텍스트 없는 것들 일괄 식별. */
export function findBlankRecordIds(records) {
    return (records || [])
        .filter(r => !mappedHasUserText(r?.data || {}))
        .map(r => r.id)
        .filter(Boolean);
}

/** 자동 정리 토스트 (페이지 상단 우측, 2.5초 후 사라짐). */
export function showSweepToast(count) {
    if (!count) return;
    const t = document.createElement('div');
    t.className = 'sweep-toast';
    t.textContent = `${count}건의 빈 행을 자동 정리했습니다`;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('show'));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 250); }, 2500);
}

/**
 * .xlsx / .csv 파싱.
 * @returns Promise<[{ name, headers: string[], rows: string[][] }]>
 */
export async function parseExcelFile(file) {
    const XLSX = await loadSheetJS();
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array', cellDates: true });
    const out = [];
    wb.SheetNames.forEach(nm => {
        const ws = wb.Sheets[nm];
        if (!ws) return;
        const aoa = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '', blankrows: false });
        if (!aoa.length) return;
        // 첫 비어있지 않은 행을 헤더로. 그 위는 무시.
        let headerIdx = aoa.findIndex(r => r.some(c => String(c ?? '').trim() !== ''));
        if (headerIdx < 0) return;
        const headers = aoa[headerIdx].map(c => cellToString(c));

        // 카운터 (NO/번호/#) 컬럼 인덱스 식별 — 이 컬럼만 채워진 행은 데이터 없음으로 간주.
        const counterCols = identifyCounterColumns(headers, aoa.slice(headerIdx + 1, headerIdx + 16));

        const rows = [];
        let skipped = 0;
        for (let i = headerIdx + 1; i < aoa.length; i++) {
            const r = aoa[i];
            if (!r) continue;
            // 사용자 명시: "숫자만 있고 텍스트가 없는 행은 가져오지 마" — 한글/영문 텍스트가
            // 카운터 외 컬럼에 1개라도 있어야 채택. 숫자만/placeholder만 있는 행은 스킵.
            const hasText = (counterCols.size < headers.length)
                ? headers.some((_, ci) => !counterCols.has(ci) && hasUserText(r[ci]))
                : headers.some((_, ci) => hasUserText(r[ci]));
            if (!hasText) { skipped++; continue; }
            rows.push(headers.map((_, ci) => cellToString(r[ci])));
        }
        out.push({ name: nm, headers, rows, skippedBlank: skipped });
    });
    return out;
}

/**
 * NO / 번호 / # / 순번 등 카운터 컬럼 식별.
 * 1) 헤더가 알려진 카운터 단어이면 즉시 카운터.
 * 2) 첫 컬럼 (CI=0) 의 샘플이 1, 2, 3, ... 처럼 단조 증가 정수면 카운터.
 * 3) 헤더 비어있고 샘플이 모두 1~4자리 정수만이면 카운터로 추정.
 */
const COUNTER_HEADER_NORMS = new Set([
    'no', 'no.', 'num', 'number', 'idx', 'index', '#',
    '번호', '순번', '순서', '순', '연번', '항번', '번',
]);
function identifyCounterColumns(headers, sampleRows) {
    const out = new Set();
    headers.forEach((h, ci) => {
        const norm = normalizeHeader(h);
        if (COUNTER_HEADER_NORMS.has(norm)) { out.add(ci); return; }

        const samples = sampleRows.map(r => cellToString(r?.[ci] ?? '')).filter(Boolean);
        if (samples.length < 2) return;
        // 모두 1~5자리 정수만이면 카운터 후보
        if (!samples.every(s => /^\d{1,5}$/.test(s))) return;
        const nums = samples.map(Number);
        // 단조 증가 (또는 1씩 증가) 면 카운터로 확정 — 첫 컬럼/헤더 문구와 무관하게.
        const monotonic = nums.every((n, i) => i === 0 || n > nums[i - 1]);
        if (monotonic) { out.add(ci); return; }
        // 헤더 비어있고 정수만 있으면 카운터로 추정.
        if (!norm) out.add(ci);
    });
    return out;
}

/* ---- 헤더 유사도 매핑 ---------------------------------------------------- */

/** 비교용 정규화. 공백/괄호/특수문자 제거, 소문자, 한자/영문 대소문자 무시. */
function normalizeHeader(s) {
    return String(s || '')
        .toLowerCase()
        .replace(/[\s　_\-./()\[\]{}·.,'":;!?]+/g, '')
        .replace(/번호/g, '번호'); // placeholder for future normalization
}

/**
 * 엑셀 헤더 → 우리 필드 key 매핑 제안.
 * @param headers 엑셀 첫 행 (string[])
 * @param fields  우리 필드 [{key, label, type}]
 * @param synonyms { [fieldKey]: string[] }   각 필드의 후보 한국어 동의어
 * @returns string[]   headers 와 같은 길이. 각 원소 = 매핑된 fieldKey 또는 null.
 */
export function suggestFieldMapping(headers, fields, synonyms) {
    // auto_number / commission_view 는 매핑 대상 아님 (자동 계산).
    const targets = (fields || []).filter(f => f.type !== 'auto_number' && f.type !== 'commission_view');

    // 각 field 에 대해 후보 키워드 집합.
    const fieldCandidates = targets.map(f => {
        const list = new Set();
        list.add(normalizeHeader(f.label));
        list.add(normalizeHeader(f.key));
        (synonyms?.[f.key] || []).forEach(s => list.add(normalizeHeader(s)));
        return { field: f, keys: [...list].filter(Boolean) };
    });

    const used = new Set();
    return headers.map(h => {
        const norm = normalizeHeader(h);
        if (!norm) return null;
        // 1) 정확 일치 우선 (아직 안 쓰인 필드 중)
        for (const fc of fieldCandidates) {
            if (used.has(fc.field.key)) continue;
            if (fc.keys.includes(norm)) { used.add(fc.field.key); return fc.field.key; }
        }
        // 2) 한쪽이 다른 쪽을 포함 (헤더가 후보 키워드 포함, 또는 키워드가 헤더 포함)
        for (const fc of fieldCandidates) {
            if (used.has(fc.field.key)) continue;
            for (const k of fc.keys) {
                if (k.length < 2) continue;
                if (norm === k) { used.add(fc.field.key); return fc.field.key; }
                if (norm.includes(k) || k.includes(norm)) { used.add(fc.field.key); return fc.field.key; }
            }
        }
        return null;
    });
}

/** 엑셀 셀 값 → 우리 필드 타입에 맞춰 변환. */
export function coerceCellForField(field, raw) {
    const v = String(raw ?? '').trim();
    if (!v) return '';
    if (field.type === 'date') {
        // 이미 YYYY-MM-DD 면 그대로. 한국식 'YYYY.MM.DD' / 'YYYY/MM/DD' 도 처리.
        const m = v.match(/^(\d{4})[.\-\/년 ]+(\d{1,2})[.\-\/월 ]+(\d{1,2})/);
        if (m) {
            const y = m[1]; const mo = m[2].padStart(2, '0'); const d = m[3].padStart(2, '0');
            return `${y}-${mo}-${d}`;
        }
        // 엑셀 시리얼 수 (1900-01-01 기준)
        if (/^\d+(\.\d+)?$/.test(v)) {
            const serial = parseFloat(v);
            if (serial > 59 && serial < 80000) {
                const ms = (serial - 25569) * 86400 * 1000;
                const d = new Date(ms);
                if (!isNaN(d.getTime())) {
                    const y = d.getUTCFullYear();
                    const mo = String(d.getUTCMonth() + 1).padStart(2, '0');
                    const da = String(d.getUTCDate()).padStart(2, '0');
                    return `${y}-${mo}-${da}`;
                }
            }
        }
        return v;
    }
    if (field.type === 'tel') {
        const digits = v.replace(/\D/g, '').slice(0, 11);
        if (!digits) return '';
        if (digits.length <= 3) return digits;
        if (digits.length <= 7) return digits.slice(0, 3) + '-' + digits.slice(3);
        return digits.slice(0, 3) + '-' + digits.slice(3, 7) + '-' + digits.slice(7);
    }
    if (field.type === 'manage_switch')  return /관리중|true|y|o|1/i.test(v) && !/비관리|false|n|x|0/i.test(v);
    if (field.type === 'pay_switch')     return /지급|true|y|o|1/i.test(v) && !/미지급|false|n|x|0/i.test(v);
    if (field.type === 'number')         return parseInt(v.replace(/[^\d-]/g, ''), 10) || 0;
    return v;
}

/**
 * 엑셀 import 미리보기 모달. 사용자가 헤더-필드 매핑 수정 후 확인 누르면 onConfirm.
 *
 * @param opts
 *  - title         모달 제목
 *  - sheetName     엑셀 시트명
 *  - headers       엑셀 헤더 string[]
 *  - rows          엑셀 데이터 string[][]
 *  - fields        우리 필드 [{key,label,type}]
 *  - suggested     suggestFieldMapping 결과 (string|null)[]
 *  - fallbackKey   매핑 안 된 컬럼들의 fallback 필드 key (예: 'memo' / 'content')
 *  - confirmLabel  확인 버튼 라벨 (기본: 'N건 추가하기'). 재수정 모드는 '재적용' 등으로 호출자가 지정.
 *  - skippedBlank  parseExcelFile 가 NO 만 있고 다른 칸 비어있어 자동 제외한 행 수 (정보용).
 *  - extraDanger   { label, onClick }  — 푸터 좌측에 위험 액션 버튼 (예: "기록 폐기").
 *  - onConfirm     async (mappedRows, mapping, ctx) => void
 *                  ctx.setProgress(done, total, label?) 로 진행률 표시 가능.
 */
export function openImportPreviewModal(opts) {
    const fieldByKey = new Map((opts.fields || []).map(f => [f.key, f]));
    // 매핑 가능한 필드 (auto/commission 제외).
    const optionFields = (opts.fields || []).filter(f => f.type !== 'auto_number' && f.type !== 'commission_view');
    let mapping = (opts.suggested || []).slice();
    const md = document.createElement('div');
    md.className = 'modal-backdrop import-preview-modal';
    md.style.zIndex = '300';
    const PREVIEW_LIMIT = 5;
    const previewRows = (opts.rows || []).slice(0, PREVIEW_LIMIT);
    md.innerHTML = `
        <div class="modal-panel" style="max-width:900px;">
            <header class="modal-header">
                <div>
                    <h2>${escapeHtml(opts.title || '엑셀 가져오기')}</h2>
                    <p class="modal-subtitle">엑셀의 각 열이 우리 양식의 어느 필드로 들어갈지 확인해주세요. 매핑 안 된 열은 ${escapeHtml(fieldByKey.get(opts.fallbackKey)?.label || '비고')} 에 합쳐서 들어갑니다.</p>
                </div>
            </header>
            <div class="modal-body">
                <div class="import-meta">
                    <span><b>시트:</b> ${escapeHtml(opts.sheetName || '-')}</span>
                    <span><b>가져올 행:</b> ${(opts.rows || []).length}건</span>
                    ${opts.skippedBlank > 0 ? `<span class="im-skipped">NO 만 있고 비어있는 ${opts.skippedBlank}건은 자동 제외됨</span>` : ''}
                </div>
                <div class="import-mapping">
                    <div class="im-head">
                        <span>엑셀 열 (헤더)</span>
                        <span>샘플</span>
                        <span>우리 양식 필드</span>
                    </div>
                    ${(opts.headers || []).map((h, i) => {
                        const sample = previewRows.map(r => String(r[i] ?? '').trim()).filter(Boolean).slice(0, 2).join(' / ');
                        const sel = mapping[i] || '__fallback__';
                        return `
                            <div class="im-row">
                                <span class="im-h">${escapeHtml(h || '(빈 헤더)')}</span>
                                <span class="im-s">${escapeHtml(sample) || '<i style="color:#a3a39a;">-</i>'}</span>
                                <select data-mi="${i}">
                                    <option value="__ignore__" ${sel === '__ignore__' ? 'selected' : ''}>— 무시 —</option>
                                    <option value="__fallback__" ${sel === '__fallback__' ? 'selected' : ''}>↳ ${escapeHtml(fieldByKey.get(opts.fallbackKey)?.label || '비고')} 에 합쳐서</option>
                                    <optgroup label="우리 필드">
                                        ${optionFields.map(f => `<option value="${f.key}" ${sel === f.key ? 'selected' : ''}>${escapeHtml(f.label)}</option>`).join('')}
                                    </optgroup>
                                </select>
                            </div>
                        `;
                    }).join('')}
                </div>
                <details class="import-sample" ${previewRows.length > 0 ? 'open' : ''}>
                    <summary>샘플 ${previewRows.length}건 미리보기</summary>
                    <div class="im-sample-wrap">
                        <table class="im-sample-tbl">
                            <thead><tr>${(opts.headers || []).map(h => `<th>${escapeHtml(h || '-')}</th>`).join('')}</tr></thead>
                            <tbody>
                                ${previewRows.map(r => `<tr>${(opts.headers || []).map((_, i) => `<td>${escapeHtml(String(r[i] ?? ''))}</td>`).join('')}</tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </details>
                <p class="form-help error" data-error style="margin-top:10px;display:none;"></p>
                <div class="im-progress" data-progress hidden>
                    <span class="im-spinner" aria-hidden="true"></span>
                    <span class="im-progress-text" data-progress-text>준비 중...</span>
                    <div class="im-progress-bar"><span data-progress-fill></span></div>
                </div>
            </div>
            <footer class="modal-footer">
                ${opts.extraDanger ? `<button class="tiny-btn danger modal-footer-spacer" type="button" data-extra>${escapeHtml(opts.extraDanger.label)}</button>` : ''}
                <button class="tiny-btn" type="button" data-cancel>취소</button>
                <button class="tiny-btn primary" type="button" data-confirm>${escapeHtml(opts.confirmLabel || ((opts.rows || []).length + '건 추가하기'))}</button>
            </footer>
        </div>
    `;
    document.body.appendChild(md);

    const close = () => md.remove();
    md.querySelector('[data-cancel]').addEventListener('click', close);
    md.addEventListener('click', (e) => { if (e.target === md) close(); });
    md.querySelectorAll('select[data-mi]').forEach(sel => {
        sel.addEventListener('change', () => {
            const i = parseInt(sel.dataset.mi, 10);
            mapping[i] = sel.value;
        });
    });

    md.querySelector('[data-confirm]').addEventListener('click', async () => {
        const errEl = md.querySelector('[data-error]');
        const btn = md.querySelector('[data-confirm]');
        const cancelBtn = md.querySelector('[data-cancel]');
        const extraBtn = md.querySelector('[data-extra]');
        const progEl = md.querySelector('[data-progress]');
        const progText = md.querySelector('[data-progress-text]');
        const progFill = md.querySelector('[data-progress-fill]');
        const selects = md.querySelectorAll('select[data-mi]');

        const setBusy = (busy) => {
            btn.disabled = busy;
            cancelBtn.disabled = busy;
            if (extraBtn) extraBtn.disabled = busy;
            selects.forEach(s => s.disabled = busy);
            progEl.hidden = !busy;
        };
        const setProgress = (done, total, label) => {
            const pct = total > 0 ? Math.round((done / total) * 100) : 0;
            progFill.style.width = pct + '%';
            progText.textContent = label || `데이터 추가 중... ${done} / ${total} (${pct}%)`;
        };

        errEl.style.display = 'none';
        setBusy(true);
        setProgress(0, (opts.rows || []).length, '데이터 추가 준비 중...');

        try {
            const rows = buildRowsFromMapping(opts.headers, opts.rows, mapping, optionFields, fieldByKey, opts.fallbackKey);
            await opts.onConfirm(rows, mapping.slice(), { setProgress });
            close();
        } catch (e) {
            errEl.textContent = (e && e.message) ? e.message : String(e);
            errEl.style.display = '';
            setBusy(false);
        }
    });

    if (opts.extraDanger) {
        md.querySelector('[data-extra]').addEventListener('click', async () => {
            const errEl = md.querySelector('[data-error]');
            try {
                await opts.extraDanger.onClick();
                close();
            } catch (e) {
                errEl.textContent = (e && e.message) ? e.message : String(e);
                errEl.style.display = '';
            }
        });
    }
}

/* ---- 마지막 import 세션 보관 (localStorage) ----------------------------- */
const IMPORT_SESSION_PREFIX = 'ledger:lastImport:';

/** 세션 = { sheet:{name,headers,rows}, mapping, recordIds, importedAt } */
export function saveImportSession(pageType, groupId, session) {
    try {
        localStorage.setItem(IMPORT_SESSION_PREFIX + pageType + ':' + groupId,
            JSON.stringify({ ...session, importedAt: Date.now() }));
    } catch (e) { console.warn('import 세션 저장 실패', e); }
}

export function loadImportSession(pageType, groupId) {
    try {
        const raw = localStorage.getItem(IMPORT_SESSION_PREFIX + pageType + ':' + groupId);
        return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
}

export function clearImportSession(pageType, groupId) {
    try { localStorage.removeItem(IMPORT_SESSION_PREFIX + pageType + ':' + groupId); } catch (e) {}
}

function buildRowsFromMapping(headers, rows, mapping, _optionFields, fieldByKey, fallbackKey) {
    const fallbackField = fieldByKey.get(fallbackKey) || null;
    const out = [];
    (rows || []).forEach(r => {
        const data = {};
        const fallbackParts = [];
        (headers || []).forEach((h, i) => {
            const target = mapping[i];
            const cell = String(r[i] ?? '').trim();
            if (!cell) return;
            if (!target || target === '__ignore__') return;
            if (target === '__fallback__') {
                fallbackParts.push(`[${h || '-'}] ${cell}`);
                return;
            }
            const f = fieldByKey.get(target);
            if (!f) return;
            data[target] = coerceCellForField(f, cell);
        });
        if (fallbackParts.length && fallbackField) {
            const existing = data[fallbackField.key] ? String(data[fallbackField.key]) + '\n' : '';
            data[fallbackField.key] = existing + fallbackParts.join('\n');
        }
        // 매핑 적용 후에도 텍스트 값이 1개도 없으면 (숫자만 들어간 매핑 결과) 스킵.
        if (!mappedHasUserText(data)) return;
        out.push(data);
    });
    return out;
}
