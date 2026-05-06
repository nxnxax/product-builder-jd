/**
 * Customer Management System - Main Logic
 */

// --- State Management ---
let customers = JSON.parse(localStorage.getItem('customers')) || [];
let currentFilter = '';

// --- DOM Elements ---
const customerList = document.getElementById('customer-list');
const emptyState = document.getElementById('empty-state');
const customerModal = document.getElementById('customer-modal');
const customerForm = document.getElementById('customer-form');
const modalTitle = document.getElementById('modal-title');
const searchInput = document.getElementById('search-input');
const themeBtn = document.getElementById('theme-btn');

// --- Initialization ---
document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    renderCustomers();
});

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

function saveCustomers() {
    localStorage.setItem('customers', JSON.stringify(customers));
}

function addCustomer(customer) {
    customers.unshift({
        id: Date.now().toString(),
        createdAt: new Date().toLocaleDateString(),
        ...customer
    });
    saveCustomers();
    renderCustomers();
}

function updateCustomer(id, updatedData) {
    customers = customers.map(c => c.id === id ? { ...c, ...updatedData } : c);
    saveCustomers();
    renderCustomers();
}

function deleteCustomer(id) {
    if (confirm('정말 이 고객 정보를 삭제하시겠습니까?')) {
        customers = customers.filter(c => c.id !== id);
        saveCustomers();
        renderCustomers();
    }
}

// --- UI Rendering ---

function renderCustomers() {
    const filtered = customers.filter(c => 
        c.name.toLowerCase().includes(currentFilter.toLowerCase()) ||
        c.email.toLowerCase().includes(currentFilter.toLowerCase())
    );

    customerList.innerHTML = '';
    
    if (filtered.length === 0) {
        emptyState.classList.remove('hidden');
    } else {
        emptyState.classList.add('hidden');
        filtered.forEach(customer => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${customer.name}</strong></td>
                <td>${customer.email}</td>
                <td>${customer.phone || '-'}</td>
                <td>${customer.createdAt}</td>
                <td class="action-btns">
                    <button class="edit-btn" data-id="${customer.id}">수정</button>
                    <button class="delete-btn" data-id="${customer.id}">삭제</button>
                </td>
            `;
            customerList.appendChild(tr);
        });
    }

    // Attach event listeners to new buttons
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.dataset.id));
    });
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', () => deleteCustomer(btn.dataset.id));
    });
}

// --- Event Listeners ---

// Search
searchInput.addEventListener('input', (e) => {
    currentFilter = e.target.value;
    renderCustomers();
});

// Modal Logic
document.getElementById('add-customer-btn').addEventListener('click', () => openModal());
document.getElementById('close-modal').addEventListener('click', closeModal);
document.getElementById('cancel-btn').addEventListener('click', closeModal);

function openModal(id = null) {
    customerForm.reset();
    document.getElementById('customer-id').value = id || '';
    
    if (id) {
        const customer = customers.find(c => c.id === id);
        if (customer) {
            modalTitle.textContent = '고객 정보 수정';
            document.getElementById('name').value = customer.name;
            document.getElementById('email').value = customer.email;
            document.getElementById('phone').value = customer.phone;
            document.getElementById('notes').value = customer.notes;
        }
    } else {
        modalTitle.textContent = '새 고객 등록';
    }
    
    customerModal.classList.remove('hidden');
}

function closeModal() {
    customerModal.classList.add('hidden');
}

customerForm.addEventListener('submit', (e) => {
    e.preventDefault();
    
    const id = document.getElementById('customer-id').value;
    const customerData = {
        name: document.getElementById('name').value,
        email: document.getElementById('email').value,
        phone: document.getElementById('phone').value,
        notes: document.getElementById('notes').value
    };

    if (id) {
        updateCustomer(id, customerData);
    } else {
        addCustomer(customerData);
    }
    
    closeModal();
});

// Close modal when clicking outside
window.addEventListener('click', (e) => {
    if (e.target === customerModal) closeModal();
});
