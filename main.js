/**
 * ERP Integrated Management System - Main Logic
 */

// --- State Management ---
let customers = JSON.parse(localStorage.getItem('customers')) || [];
let employees = JSON.parse(localStorage.getItem('employees')) || [];
let currentView = 'customers'; // 'customers' or 'employees'
let customerFilter = '';
let employeeFilter = '';

// --- DOM Elements ---
const navCustomers = document.getElementById('nav-customers');
const navEmployees = document.getElementById('nav-employees');
const customerSection = document.getElementById('customer-section');
const employeeSection = document.getElementById('employee-section');

const customerList = document.getElementById('customer-list');
const employeeList = document.getElementById('employee-list');
const customerEmpty = document.getElementById('customer-empty');
const employeeEmpty = document.getElementById('employee-empty');

const appModal = document.getElementById('app-modal');
const appForm = document.getElementById('app-form');
const formFields = document.getElementById('form-fields');
const modalTitle = document.getElementById('modal-title');
const themeBtn = document.getElementById('theme-btn');

const customerSearch = document.getElementById('customer-search');
const employeeSearch = document.getElementById('employee-search');

// --- Initialization ---
document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    renderAll();
});

// --- Navigation Logic ---
function switchView(view) {
    currentView = view;
    if (view === 'customers') {
        navCustomers.classList.add('active');
        navEmployees.classList.remove('active');
        customerSection.classList.remove('hidden');
        employeeSection.classList.add('hidden');
    } else {
        navCustomers.classList.remove('active');
        navEmployees.classList.add('active');
        customerSection.classList.add('hidden');
        employeeSection.classList.remove('hidden');
    }
}

navCustomers.addEventListener('click', () => switchView('customers'));
navEmployees.addEventListener('click', () => switchView('employees'));

// --- Theme Logic ---
function initTheme() {
    const savedTheme = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeBtnText(savedTheme);
}

themeBtn.addEventListener('click', () => {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeBtnText(newTheme);
});

function updateThemeBtnText(theme) {
    themeBtn.textContent = theme === 'light' ? '🌙 다크 모드' : '☀️ 라이트 모드';
}

// --- CRUD Operations ---

function saveData(type) {
    if (type === 'customer') {
        localStorage.setItem('customers', JSON.stringify(customers));
    } else {
        localStorage.setItem('employees', JSON.stringify(employees));
    }
}

function addItem(type, data) {
    const newItem = {
        id: Date.now().toString(),
        createdAt: new Date().toLocaleDateString(),
        ...data
    };
    if (type === 'customer') {
        customers.unshift(newItem);
    } else {
        employees.unshift(newItem);
    }
    saveData(type);
    renderAll();
}

function updateItem(type, id, data) {
    if (type === 'customer') {
        customers = customers.map(item => item.id === id ? { ...item, ...data } : item);
    } else {
        employees = employees.map(item => item.id === id ? { ...item, ...data } : item);
    }
    saveData(type);
    renderAll();
}

function deleteItem(type, id) {
    const msg = type === 'customer' ? '이 고객 정보를 삭제하시겠습니까?' : '이 직원 정보를 삭제하시겠습니까?';
    if (confirm(msg)) {
        if (type === 'customer') {
            customers = customers.filter(item => item.id !== id);
        } else {
            employees = employees.filter(item => item.id !== id);
        }
        saveData(type);
        renderAll();
    }
}

// --- UI Rendering ---

function renderAll() {
    renderCustomers();
    renderEmployees();
}

function renderCustomers() {
    const filtered = customers.filter(c => 
        c.name.toLowerCase().includes(customerFilter.toLowerCase()) ||
        c.email.toLowerCase().includes(customerFilter.toLowerCase())
    );

    customerList.innerHTML = '';
    if (filtered.length === 0) {
        customerEmpty.classList.remove('hidden');
    } else {
        customerEmpty.classList.add('hidden');
        filtered.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="이름"><strong>${item.name}</strong></td>
                <td data-label="이메일">${item.email}</td>
                <td data-label="전화번호">${item.phone || '-'}</td>
                <td data-label="가입일">${item.createdAt}</td>
                <td class="action-btns">
                    <button class="edit-btn" onclick="openAppModal('customer', '${item.id}')">수정</button>
                    <button class="delete-btn" onclick="deleteAppItem('customer', '${item.id}')">삭제</button>
                </td>
            `;
            customerList.appendChild(tr);
        });
    }
}

function renderEmployees() {
    const filtered = employees.filter(e => 
        e.name.toLowerCase().includes(employeeFilter.toLowerCase()) ||
        e.title.toLowerCase().includes(employeeFilter.toLowerCase())
    );

    employeeList.innerHTML = '';
    if (filtered.length === 0) {
        employeeEmpty.classList.remove('hidden');
    } else {
        employeeEmpty.classList.add('hidden');
        filtered.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td data-label="이름"><strong>${item.name}</strong></td>
                <td data-label="직함">${item.title}</td>
                <td data-label="연락처">${item.contact || '-'}</td>
                <td data-label="투입일">${item.startDate || '-'}</td>
                <td class="action-btns">
                    <button class="edit-btn" onclick="openAppModal('employee', '${item.id}')">수정</button>
                    <button class="delete-btn" onclick="deleteAppItem('employee', '${item.id}')">삭제</button>
                </td>
            `;
            employeeList.appendChild(tr);
        });
    }
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
                <input type="text" id="name" required value="${item ? item.name : ''}" placeholder="성함을 입력하세요">
            </div>
            <div class="form-group">
                <label for="email">이메일</label>
                <input type="email" id="email" required value="${item ? item.email : ''}" placeholder="example@email.com">
            </div>
            <div class="form-group">
                <label for="phone">전화번호</label>
                <input type="tel" id="phone" value="${item ? item.phone : ''}" placeholder="010-0000-0000">
            </div>
            <div class="form-group">
                <label for="notes">메모</label>
                <textarea id="notes" rows="3" placeholder="추가 사항">${item ? item.notes : ''}</textarea>
            </div>
        `;
    } else {
        const item = id ? employees.find(e => e.id === id) : null;
        modalTitle.textContent = id ? '직원 정보 수정' : '새 직원 등록';
        fieldsHtml = `
            <div class="form-group">
                <label for="name">이름</label>
                <input type="text" id="name" required value="${item ? item.name : ''}" placeholder="성함을 입력하세요">
            </div>
            <div class="form-group">
                <label for="title">직함</label>
                <input type="text" id="title" required value="${item ? item.title : ''}" placeholder="예: 과장, 개발자">
            </div>
            <div class="form-group">
                <label for="contact">연락처</label>
                <input type="tel" id="contact" value="${item ? item.contact : ''}" placeholder="010-0000-0000">
            </div>
            <div class="form-group">
                <label for="startDate">투입일</label>
                <input type="date" id="startDate" value="${item ? item.startDate : ''}">
            </div>
        `;
    }
    
    formFields.innerHTML = fieldsHtml;
    appModal.classList.remove('hidden');
}

function closeModal() {
    appModal.classList.add('hidden');
}

appForm.addEventListener('submit', (e) => {
    e.preventDefault();
    
    const id = document.getElementById('item-id').value;
    const type = document.getElementById('item-type').value;
    
    const data = {};
    const inputs = formFields.querySelectorAll('input, textarea');
    inputs.forEach(input => {
        data[input.id] = input.value;
    });

    if (id) {
        updateItem(type, id, data);
    } else {
        addItem(type, data);
    }
    
    closeModal();
});

window.addEventListener('click', (e) => {
    if (e.target === appModal) closeModal();
});
