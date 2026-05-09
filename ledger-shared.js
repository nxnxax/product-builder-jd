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
export function attachColumnFilters({ state, headers, fields, getRows, getValue, onChange }) {
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
            openFilterPopup(th, key, f, state, getRows, getValue, onChange);
        });
    });
}

function openFilterPopup(th, key, field, state, getRows, getValue, onChange) {
    closePopup();
    const rows = getRows() || [];
    // 컬럼의 모든 unique 값 + 건수.
    const counts = new Map();
    rows.forEach(r => {
        const v = String(getValue(r, key) ?? '').trim();
        counts.set(v, (counts.get(v) || 0) + 1);
    });
    const sorted = [...counts.entries()].sort((a, b) => {
        // 빈 값은 맨 아래
        if (a[0] === '' && b[0] !== '') return 1;
        if (b[0] === '' && a[0] !== '') return -1;
        return a[0].localeCompare(b[0], 'ko');
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
                const display = v === '' ? '<i style="color:#a3a39a;">(빈 값)</i>' : escapeHtml(v);
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
                <button class="tiny-btn primary" type="button" data-confirm>확인</button>
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
    setTimeout(() => md.querySelector('input,select,textarea')?.focus(), 30);
}

function closeRowAddModal() {
    document.querySelectorAll('.row-add-modal').forEach(m => m.remove());
}

function renderEntryField(f, defaults, customRender) {
    if (f.type === 'auto_number' || f.type === 'commission_view') return '';
    if (customRender) {
        const ch = customRender(f, defaults);
        if (ch !== undefined && ch !== null) return ch;
    }
    const v = defaults[f.key] ?? '';
    const lbl = `<label class="row-label">${escapeHtml(f.label)}</label>`;
    const wrap = (control, extra = '') => `<div class="modal-row" ${extra}>${lbl}<div class="row-control">${control}</div></div>`;

    if (f.type === 'date')     return wrap(`<input type="date" data-field="${f.key}" value="${escapeAttr(v)}">`);
    if (f.type === 'tel')      return wrap(`<input type="tel"  data-field="${f.key}" value="${escapeAttr(v)}" placeholder="010-...">`);
    if (f.type === 'textarea') return wrap(`<textarea data-field="${f.key}" rows="3" placeholder="${escapeAttr(f.label)}">${escapeHtml(v)}</textarea>`, 'style="align-items:start;"');
    if (f.type === 'title_select') return wrap(`<select data-field="${f.key}"><option value="">-</option>${['본부장','팀장','팀원'].map(t => `<option value="${t}" ${v === t ? 'selected' : ''}>${t}</option>`).join('')}</select>`);
    return wrap(`<input type="text" data-field="${f.key}" value="${escapeAttr(v)}" placeholder="${escapeAttr(f.label)}">`);
}

function collectEntry(fields, defaults, md) {
    const data = { ...defaults };
    fields.forEach(f => {
        if (f.type === 'auto_number' || f.type === 'commission_view') return;
        const el = md.querySelector(`[data-field="${f.key}"]`);
        if (!el) return;
        if (el.type === 'checkbox') {
            data[f.key] = el.checked;
        } else {
            const val = el.value;
            data[f.key] = val === '' ? '' : val;
        }
    });
    return data;
}
