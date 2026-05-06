
// Theme Toggle Logic
const themeBtn = document.getElementById('theme-btn');
const currentTheme = localStorage.getItem('theme');

if (currentTheme) {
    document.documentElement.setAttribute('data-theme', currentTheme);
    if (currentTheme === 'light') {
        themeBtn.textContent = '다크 모드';
    }
}

themeBtn.addEventListener('click', () => {
    let theme = document.documentElement.getAttribute('data-theme');
    if (theme === 'light') {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('theme', 'dark');
        themeBtn.textContent = '라이트 모드';
    } else {
        document.documentElement.setAttribute('data-theme', 'light');
        localStorage.setItem('theme', 'light');
        themeBtn.textContent = '다크 모드';
    }
});

class LottoBall extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
    }

    connectedCallback() {
        const number = this.getAttribute('number');
        const colorClass = this.getAttribute('color-class');
        
        const lottoBall = document.createElement('div');
        lottoBall.classList.add('lotto-ball', colorClass);
        lottoBall.textContent = number;

        const style = document.createElement('style');
        style.textContent = `
            .lotto-ball {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                display: flex;
                justify-content: center;
                align-items: center;
                font-size: 1.5rem;
                font-weight: bold;
                color: white;
                box-shadow: 0 4px 8px rgba(0,0,0,0.3), inset 0 2px 3px rgba(255,255,255,0.2);
                text-shadow: 0 1px 2px rgba(0,0,0,0.5);
                animation: bounce-in 0.5s ease;
            }

            @keyframes bounce-in {
                0% {
                    transform: scale(0.5);
                    opacity: 0;
                }
                100% {
                    transform: scale(1);
                    opacity: 1;
                }
            }
            .color-1 { background: linear-gradient(135deg, #f368e0, #ff9f43); }
            .color-2 { background: linear-gradient(135deg, #54a0ff, #5f27cd); }
            .color-3 { background: linear-gradient(135deg, #ff6b6b, #ee5253); }
            .color-4 { background: linear-gradient(135deg, #48dbfb, #1dd1a1); }
            .color-5 { background: linear-gradient(135deg, #feca57, #ff9f43); }
            .color-6 { background: linear-gradient(135deg, #ff9ff3, #cf6a87); }
        `;

        this.shadowRoot.append(style, lottoBall);
    }
}

customElements.define('lotto-ball', LottoBall);


document.getElementById('generate-btn').addEventListener('click', () => {
    const lottoNumbersContainer = document.getElementById('lotto-numbers');
    lottoNumbersContainer.innerHTML = '';
    const numbers = generateLottoNumbers();

    numbers.forEach((number, index) => {
        const lottoBall = document.createElement('lotto-ball');
        lottoBall.setAttribute('number', number);
        lottoBall.setAttribute('color-class', `color-${index + 1}`);
        lottoNumbersContainer.appendChild(lottoBall);
    });
});

function generateLottoNumbers() {
    const numbers = new Set();
    while (numbers.size < 6) {
        numbers.add(Math.floor(Math.random() * 45) + 1);
    }
    return Array.from(numbers);
}