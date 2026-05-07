/**
 * ERP Integrated Management System - Main Logic
 */

// --- State Management ---
const API_URL = 'records.php';
const MIGRATION_FLAG = 'erpDbMigrationComplete';

let customers = [];
let employees = [];
let currentView = 'customers'; // 'customers', 'employees', or 'marketing'
let customerFilter = '';
let employeeFilter = '';
let isLoading = false;

// --- DOM Elements ---
const navCustomers = document.getElementById('nav-customers');
const navEmployees = document.getElementById('nav-employees');
const navMarketing = document.getElementById('nav-marketing');

const customerSection = document.getElementById('customer-section');
const employeeSection = document.getElementById('employee-section');
const marketingSection = document.getElementById('marketing-section');

const customerList = document.getElementById('customer-list');
const employeeList = document.getElementById('employee-list');
const customerEmpty = document.getElementById('customer-empty');
const employeeEmpty = document.getElementById('employee-empty');

const appModal = document.getElementById('app-modal');
const appForm = document.getElementById('app-form');
const formFields = document.getElementById('form-fields');
const modalTitle = document.getElementById('modal-title');
const saveBtn = document.getElementById('save-btn');

const customerSearch = document.getElementById('customer-search');
const employeeSearch = document.getElementById('employee-search');

// --- Initialization ---
document.addEventListener('DOMContentLoaded', initApp);

async function initApp() {
    renderLoading();
    try {
        await loadAll();
        await migrateLocalStorageIfNeeded();
        renderAll();
    } catch (error) {
        showError(error);
    }
}

// --- API ---
async function apiRequest(resource, options = {}) {
    const response = await fetch(`${API_URL}?resource=${encodeURIComponent(resource)}`, {
        headers: { 'Content-Type': 'application/json' },
        ...options
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok === false) {
        throw new Error(payload?.error || `요청 실패 (${response.status})`);
    }
    return payload;
}

async function loadResource(resource) {
    const payload = await apiRequest(resource);
    return Array.isArray(payload.items) ? payload.items : [];
}

async function loadAll() {
    isLoading = true;
    [customers, employees] = await Promise.all([
        loadResource('customers'),
        loadResource('employees')
    ]);
    isLoading = false;
}

async function migrateLocalStorageIfNeeded() {
    if (localStorage.getItem(MIGRATION_FLAG) === '1') return;

    const localCustomers = parseLocalArray('customers');
    const localEmployees = parseLocalArray('employees');
    const hasLocalData = localCustomers.length > 0 || localEmployees.length > 0;
    const hasRemoteData = customers.length > 0 || employees.length > 0;

    if (!hasLocalData || hasRemoteData) {
        localStorage.setItem(MIGRATION_FLAG, '1');
        return;
    }

    await Promise.all([
        ...localCustomers.map(item => apiRequest('customers', {
            method: 'POST',
            body: JSON.stringify({ resource: 'customers', data: item })
        })),
        ...localEmployees.map(item => apiRequest('employees', {
            method: 'POST',
            body: JSON.stringify({ resource: 'employees', data: item })
        }))
    ]);

    localStorage.setItem(MIGRATION_FLAG, '1');
    await loadAll();
}

function parseLocalArray(key) {
    try {
        const value = JSON.parse(localStorage.getItem(key) || '[]');
        return Array.isArray(value) ? value : [];
    } catch {
        return [];
    }
}

// --- Navigation Logic ---
function switchView(view) {
    currentView = view;
    
    // Update Nav Buttons
    [navCustomers, navEmployees, navMarketing].forEach(btn => {
        if (btn) btn.classList.remove('active');
    });
    
    // Update Sections
    [customerSection, employeeSection, marketingSection].forEach(sec => {
        if (sec) sec.classList.add('hidden');
    });

    if (view === 'customers') {
        navCustomers.classList.add('active');
        customerSection.classList.remove('hidden');
    } else if (view === 'employees') {
        navEmployees.classList.add('active');
        employeeSection.classList.remove('hidden');
    } else if (view === 'marketing') {
        navMarketing.classList.add('active');
        marketingSection.classList.remove('hidden');
    }
}

navCustomers.addEventListener('click', () => switchView('customers'));
navEmployees.addEventListener('click', () => switchView('employees'));
if (navMarketing) {
    navMarketing.addEventListener('click', () => switchView('marketing'));
}

// --- CRUD Operations ---

async function addItem(type, data) {
    const resource = type === 'customer' ? 'customers' : 'employees';
    await apiRequest(resource, {
        method: 'POST',
        body: JSON.stringify({ resource, data })
    });
    await refreshResource(resource);
}

async function updateItem(type, id, data) {
    const resource = type === 'customer' ? 'customers' : 'employees';
    await apiRequest(resource, {
        method: 'PUT',
        body: JSON.stringify({ resource, id, data })
    });
    await refreshResource(resource);
}

async function deleteItem(type, id) {
    const msg = type === 'customer' ? '이 고객 정보를 삭제하시겠습니까?' : '이 직원 정보를 삭제하시겠습니까?';
    if (!confirm(msg)) return;

    try {
        const resource = type === 'customer' ? 'customers' : 'employees';
        await apiRequest(resource, {
            method: 'DELETE',
            body: JSON.stringify({ resource, id })
        });
        await refreshResource(resource);
    } catch (error) {
        showError(error);
    }
}

async function refreshResource(resource) {
    if (resource === 'customers') {
        customers = await loadResource('customers');
        renderCustomers();
    } else {
        employees = await loadResource('employees');
        renderEmployees();
    }
}

// --- UI Rendering ---

function renderLoading() {
    isLoading = true;
    customerList.innerHTML = '<tr><td colspan="4">데이터를 불러오는 중입니다...</td></tr>';
    employeeList.innerHTML = '<tr><td colspan="5">데이터를 불러오는 중입니다...</td></tr>';
    customerEmpty.classList.add('hidden');
    employeeEmpty.classList.add('hidden');
}

function renderAll() {
    isLoading = false;
    renderCustomers();
    renderEmployees();
}

function renderCustomers() {
    const filtered = customers.filter(c => 
        (c.name || '').toLowerCase().includes(customerFilter.toLowerCase())
    );

    customerList.innerHTML = '';
    if (!isLoading && filtered.length === 0) {
        customerEmpty.classList.remove('hidden');
    } else {
        customerEmpty.classList.add('hidden');
        filtered.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="이름"><strong>${escapeHtml(item.name)}</strong></td>
                <td data-label="전화번호">${escapeHtml(item.phone || '-')}</td>
                <td data-label="가입일">${escapeHtml(item.createdAt || '-')}</td>
                <td class="action-btns">
                    <button class="edit-btn" onclick="openAppModal('customer', '${escapeAttr(item.id)}')">수정</button>
                    <button class="delete-btn" onclick="deleteAppItem('customer', '${escapeAttr(item.id)}')">삭제</button>
                </td>
            `;
            customerList.appendChild(tr);
        });
    }
}

function renderEmployees() {
    const filtered = employees.filter(e => 
        (e.name || '').toLowerCase().includes(employeeFilter.toLowerCase()) ||
        (e.title || '').toLowerCase().includes(employeeFilter.toLowerCase())
    );

    employeeList.innerHTML = '';
    if (!isLoading && filtered.length === 0) {
        employeeEmpty.classList.remove('hidden');
    } else {
        employeeEmpty.classList.add('hidden');
        filtered.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="이름"><strong>${escapeHtml(item.name)}</strong></td>
                <td data-label="직함">${escapeHtml(item.title)}</td>
                <td data-label="연락처">${escapeHtml(item.contact || '-')}</td>
                <td data-label="투입일">${escapeHtml(item.startDate || '-')}</td>
                <td class="action-btns">
                    <button class="edit-btn" onclick="openAppModal('employee', '${escapeAttr(item.id)}')">수정</button>
                    <button class="delete-btn" onclick="deleteAppItem('employee', '${escapeAttr(item.id)}')">삭제</button>
                </td>
            `;
            employeeList.appendChild(tr);
        });
    }
}

function showError(error) {
    const message = escapeHtml(error?.message || '알 수 없는 오류가 발생했습니다.');
    customerList.innerHTML = `<tr><td colspan="4">DB 연결 오류: ${message}</td></tr>`;
    employeeList.innerHTML = `<tr><td colspan="5">DB 연결 오류: ${message}</td></tr>`;
    customerEmpty.classList.add('hidden');
    employeeEmpty.classList.add('hidden');
    alert(`DB 작업 실패: ${error?.message || error}`);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[ch]));
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
}

// Global scope for onclick handlers in generated HTML
window.openAppModal = openAppModal;
window.deleteAppItem = deleteItem;

// --- Event Listeners ---

customerSearch.addEventListener('input', (e) => {
    customerFilter = e.target.value;
    renderCustomers();
});

employeeSearch.addEventListener('input', (e) => {
    employeeFilter = e.target.value;
    renderEmployees();
});

document.getElementById('add-customer-btn').addEventListener('click', () => openAppModal('customer'));
document.getElementById('add-employee-btn').addEventListener('click', () => openAppModal('employee'));
document.getElementById('close-modal').addEventListener('click', closeModal);
document.getElementById('cancel-btn').addEventListener('click', closeModal);

function openAppModal(type, id = null) {
    appForm.reset();
    document.getElementById('item-id').value = id || '';
    document.getElementById('item-type').value = type;
    
    let fieldsHtml = '';
    if (type === 'customer') {
        const item = id ? customers.find(c => c.id === id) : null;
        modalTitle.textContent = id ? '고객 정보 수정' : '새 고객 등록';
        fieldsHtml = `
            <div class="form-group">
                <label for="name">이름</label>
                <input type="text" id="name" required value="${escapeAttr(item?.name || '')}" placeholder="성함을 입력하세요">
            </div>
            <div class="form-group">
                <label for="phone">전화번호</label>
                <input type="tel" id="phone" value="${escapeAttr(item?.phone || '')}" placeholder="010-0000-0000">
            </div>
            <div class="form-group">
                <label for="notes">메모</label>
                <textarea id="notes" rows="3" placeholder="추가 사항">${escapeHtml(item?.notes || '')}</textarea>
            </div>
        `;
    } else {
        const item = id ? employees.find(e => e.id === id) : null;
        modalTitle.textContent = id ? '직원 정보 수정' : '새 직원 등록';
        fieldsHtml = `
            <div class="form-group">
                <label for="name">이름</label>
                <input type="text" id="name" required value="${escapeAttr(item?.name || '')}" placeholder="성함을 입력하세요">
            </div>
            <div class="form-group">
                <label for="title">직함</label>
                <input type="text" id="title" required value="${escapeAttr(item?.title || '')}" placeholder="예: 과장, 개발자">
            </div>
            <div class="form-group">
                <label for="contact">연락처</label>
                <input type="tel" id="contact" value="${escapeAttr(item?.contact || '')}" placeholder="010-0000-0000">
            </div>
            <div class="form-group">
                <label for="startDate">투입일</label>
                <input type="date" id="startDate" value="${escapeAttr(item?.startDate || '')}">
            </div>
        `;
    }
    
    formFields.innerHTML = fieldsHtml;
    appModal.classList.remove('hidden');
}

function closeModal() {
    appModal.classList.add('hidden');
}

appForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const id = document.getElementById('item-id').value;
    const type = document.getElementById('item-type').value;
    
    const data = {};
    const inputs = formFields.querySelectorAll('input, textarea');
    inputs.forEach(input => {
        data[input.id] = input.value.trim();
    });

    saveBtn.disabled = true;
    saveBtn.textContent = '저장 중...';

    try {
        if (id) {
            await updateItem(type, id, data);
        } else {
            await addItem(type, data);
        }
        closeModal();
    } catch (error) {
        showError(error);
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = '데이터 저장';
    }
});

window.addEventListener('click', (e) => {
    if (e.target === appModal) closeModal();
});
