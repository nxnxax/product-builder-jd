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
