/**
 * board.js — 커뮤니티 게시판 (공지사항 / 자유게시판 / 문의게시판)
 *
 * URL 패턴:
 *   board.html?cat=notice         → 공지사항 목록
 *   board.html?cat=free&id=12     → 자유게시판 12번 글 상세
 *   board.html?cat=qna&write=1    → 문의게시판 글쓰기
 *   board.html?cat=free&id=12&edit=1 → 12번 글 수정
 */

import {
    initSupabase, getSession, apiRequest, isAdmin,
    mountAppHeader, refreshAppHeader,
} from './auth-shared.js?v=20260512-kapp-shell';

const CATEGORIES = {
    notice: { title: '공지사항',     sub: '중요한 공지를 확인하세요.',                writeAdminOnly: true  },
    free:   { title: '자유게시판',   sub: '자유롭게 의견을 나누는 공간입니다.',     writeAdminOnly: false },
    qna:    { title: '문의게시판',   sub: '궁금한 점을 남겨주세요. 운영자가 답변합니다.', writeAdminOnly: false },
};

const params = new URLSearchParams(location.search);
const cat = params.get('cat') || 'notice';
const idQ = parseInt(params.get('id'), 10) || 0;
const isWriteMode = params.get('write') === '1';
const isEditMode = params.get('edit') === '1';
const pageNum = Math.max(1, parseInt(params.get('page'), 10) || 1);

const view = {
    list: document.getElementById('board-list-view'),
    detail: document.getElementById('board-detail-view'),
    write: document.getElementById('board-write-view'),
    forbidden: document.getElementById('board-forbidden'),
};

let session = null;
let admin = false;
let myEmail = '';
let currentPost = null;  // for detail view

(async function boot() {
    mountAppHeader();
    await initSupabase();
    session = getSession();

    const meta = CATEGORIES[cat];
    if (!meta) {
        document.getElementById('board-title').textContent = '알 수 없는 게시판';
        document.getElementById('board-sub').textContent = `'${cat}' 카테고리는 존재하지 않습니다.`;
        return;
    }
    document.getElementById('board-title').textContent = meta.title;
    document.getElementById('board-sub').textContent = meta.sub;

    if (!session) {
        showForbidden('로그인이 필요합니다.');
        await refreshAppHeader();
        return;
    }
    admin = isAdmin(session);
    myEmail = (session.user?.email || '').toLowerCase();
    await refreshAppHeader();

    // 라우팅
    if (isWriteMode) return showWrite(null);
    if (idQ && isEditMode) return openEdit(idQ);
    if (idQ) return showDetail(idQ);
    return showList();
})();

/* ===================== 목록 ===================== */
async function showList() {
    swap('list');
    const meta = CATEGORIES[cat];
    const writeBtn = document.getElementById('board-write-btn');
    writeBtn.classList.toggle('hidden', meta.writeAdminOnly && !admin);
    writeBtn.onclick = () => {
        location.href = `board.html?cat=${cat}&write=1`;
    };

    try {
        const data = await apiRequest('community-posts', { query: `category=${encodeURIComponent(cat)}&page=${pageNum}&size=20` });
        const items = data.items || [];
        const total = data.total || 0;
        document.getElementById('board-count').textContent = `${total.toLocaleString('ko-KR')}건`;
        const empty = document.getElementById('board-empty');
        const tbody = document.getElementById('board-rows');
        if (items.length === 0) {
            empty.classList.remove('hidden');
            tbody.innerHTML = '';
        } else {
            empty.classList.add('hidden');
            tbody.innerHTML = items.map(p => rowHtml(p)).join('');
            tbody.querySelectorAll('[data-go]').forEach(tr => {
                tr.addEventListener('click', () => {
                    location.href = `board.html?cat=${cat}&id=${tr.dataset.go}`;
                });
            });
        }
        renderPagination(total, pageNum, 20);
    } catch (e) {
        const tbody = document.getElementById('board-rows');
        tbody.innerHTML = `<tr><td colspan="5" style="padding:48px;text-align:center;color:var(--danger);">${escape(e.message || '게시글을 불러오지 못했습니다.')}</td></tr>`;
    }
}

function rowHtml(p) {
    const date = formatDate(p.createdAt);
    const pinned = p.pinned ? '<span class="board-pin">📌</span> ' : '';
    return `
        <tr data-go="${p.id}">
            <td class="col-id">${p.pinned ? '공지' : p.id}</td>
            <td class="col-title">${pinned}<span class="board-title-link">${escape(p.title)}</span></td>
            <td class="col-author">${escape(p.authorName || authorPrefix(p.authorEmail))}</td>
            <td class="col-date">${date}</td>
            <td class="col-views">${p.viewCount.toLocaleString('ko-KR')}</td>
        </tr>`;
}

function renderPagination(total, page, size) {
    const totalPages = Math.max(1, Math.ceil(total / size));
    if (totalPages <= 1) {
        document.getElementById('board-pagination').innerHTML = '';
        return;
    }
    const win = 5;
    const start = Math.max(1, page - Math.floor(win / 2));
    const end = Math.min(totalPages, start + win - 1);
    const realStart = Math.max(1, end - win + 1);
    const buttons = [];
    if (page > 1) buttons.push(`<a class="page-btn" href="board.html?cat=${cat}&page=${page - 1}">‹ 이전</a>`);
    for (let i = realStart; i <= end; i++) {
        buttons.push(`<a class="page-btn ${i === page ? 'active' : ''}" href="board.html?cat=${cat}&page=${i}">${i}</a>`);
    }
    if (page < totalPages) buttons.push(`<a class="page-btn" href="board.html?cat=${cat}&page=${page + 1}">다음 ›</a>`);
    document.getElementById('board-pagination').innerHTML = buttons.join('');
}

/* ===================== 상세 ===================== */
async function showDetail(id) {
    swap('detail');
    try {
        const data = await apiRequest('community-posts', { query: `id=${id}` });
        const p = data.post;
        currentPost = p;
        document.getElementById('detail-title').textContent = p.title;
        document.getElementById('detail-author').textContent = p.authorName || authorPrefix(p.authorEmail);
        document.getElementById('detail-date').textContent = formatDate(p.createdAt) + (p.updatedAt && p.updatedAt !== p.createdAt ? ` (수정 ${formatDate(p.updatedAt)})` : '');
        document.getElementById('detail-views').textContent = p.viewCount.toLocaleString('ko-KR');
        document.getElementById('detail-body').innerHTML = renderBody(p.body);

        const isMine = (p.authorEmail || '').toLowerCase() === myEmail;
        const canEdit = isMine || admin;
        document.getElementById('detail-edit-btn').classList.toggle('hidden', !canEdit);
        document.getElementById('detail-delete-btn').classList.toggle('hidden', !canEdit);

        document.getElementById('detail-list-btn').onclick = () => { location.href = `board.html?cat=${cat}`; };
        document.getElementById('detail-edit-btn').onclick = () => openEdit(id);
        document.getElementById('detail-delete-btn').onclick = async () => {
            if (!confirm('이 글을 삭제하시겠습니까?')) return;
            try {
                await apiRequest('community-posts', { method: 'DELETE', body: JSON.stringify({ id }) });
                location.href = `board.html?cat=${cat}`;
            } catch (e) {
                alert('삭제 실패: ' + (e.message || ''));
            }
        };
    } catch (e) {
        document.getElementById('detail-title').textContent = '게시글을 불러오지 못했습니다';
        document.getElementById('detail-body').textContent = e.message || '';
    }
}

/* ===================== 글쓰기 / 수정 ===================== */
function showWrite(editId) {
    const meta = CATEGORIES[cat];
    if (!editId && meta.writeAdminOnly && !admin) {
        showForbidden('공지사항은 관리자만 작성할 수 있습니다.');
        return;
    }
    swap('write');
    document.getElementById('write-error').textContent = '';
    document.getElementById('write-title').value = '';
    document.getElementById('write-body').value = '';

    document.getElementById('board-write-form').onsubmit = async (e) => {
        e.preventDefault();
        const title = document.getElementById('write-title').value.trim();
        const body = document.getElementById('write-body').value;
        if (!title) {
            document.getElementById('write-error').textContent = '제목을 입력해주세요.';
            return;
        }
        const saveBtn = document.getElementById('write-save-btn');
        saveBtn.disabled = true;
        try {
            if (editId) {
                await apiRequest('community-posts', {
                    method: 'PATCH',
                    body: JSON.stringify({ id: editId, title, body }),
                });
                location.href = `board.html?cat=${cat}&id=${editId}`;
            } else {
                const r = await apiRequest('community-posts', {
                    method: 'POST',
                    body: JSON.stringify({
                        category: cat,
                        title,
                        body,
                        authorName: getCachedDisplayName(),
                    }),
                });
                location.href = `board.html?cat=${cat}&id=${r.id}`;
            }
        } catch (err) {
            document.getElementById('write-error').textContent = err.message || '저장 실패';
            saveBtn.disabled = false;
        }
    };

    document.getElementById('write-cancel-btn').onclick = () => {
        history.back();
    };
}

async function openEdit(id) {
    const meta = CATEGORIES[cat];
    try {
        const data = await apiRequest('community-posts', { query: `id=${id}` });
        const p = data.post;
        const isMine = (p.authorEmail || '').toLowerCase() === myEmail;
        if (!isMine && !admin) {
            showForbidden('수정 권한이 없습니다.');
            return;
        }
        showWrite(id);
        document.getElementById('write-title').value = p.title || '';
        document.getElementById('write-body').value = p.body || '';
    } catch (e) {
        showForbidden('게시글을 불러오지 못했습니다.');
    }
}

function showForbidden(msg) {
    swap('forbidden');
    document.querySelector('#board-forbidden p').textContent = msg;
}

/* ===================== utils ===================== */
function swap(name) {
    Object.entries(view).forEach(([k, el]) => el?.classList.toggle('hidden', k !== name));
}

function escape(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function renderBody(text) {
    // 단순 줄바꿈 → <br>. 추후 마크다운 / HTML 렌더로 확장.
    return escape(text || '').replace(/\n/g, '<br>');
}

function formatDate(s) {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T') + (String(s).includes('T') ? '' : 'Z'));
    if (isNaN(d.getTime())) return s;
    const today = new Date();
    const sameDay = d.toDateString() === today.toDateString();
    if (sameDay) return d.toLocaleTimeString('ko-KR', { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('ko-KR', { year: 'numeric', month: '2-digit', day: '2-digit' }).replace(/\.\s?$/, '');
}

function authorPrefix(email) {
    if (!email) return '익명';
    const at = String(email).indexOf('@');
    return at > 0 ? email.slice(0, at) : email;
}

function getCachedDisplayName() {
    try { return sessionStorage.getItem('yman_display_name') || ''; } catch { return ''; }
}
