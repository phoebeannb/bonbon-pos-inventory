// Dashboard JavaScript

let activeDashboardData = null;
let dashboardResizeTimeout;

// Initialize charts when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    activeDashboardData = combineDashboardData(window.dashboardData || {});
    updateDashboardKpis();
    initializeCharts();
    setupNavigation();
    setupClickableCards();
    setupSidebarToggle();
});

window.addEventListener('storage', event => {
    if (event.key === 'bonbonPosOrders') {
        activeDashboardData = combineDashboardData(window.dashboardData || {});
        updateDashboardKpis();
        initializeCharts();
    }
});

window.addEventListener('resize', () => {
    clearTimeout(dashboardResizeTimeout);
    dashboardResizeTimeout = setTimeout(initializeCharts, 250);
});

function combineDashboardData(databaseData) {
    const combined = JSON.parse(JSON.stringify(databaseData || {}));
    combined.kpis = { ...(combined.kpis || {}) };
    combined.sales = Array.isArray(combined.sales) ? combined.sales : [];
    combined.bubbleTea = Array.isArray(combined.bubbleTea) ? combined.bubbleTea : [];
    combined.chicken = Array.isArray(combined.chicken) ? combined.chicken : [];

    let localOrders = [];
    try {
        const stored = JSON.parse(localStorage.getItem('bonbonPosOrders') || '[]');
        if (Array.isArray(stored)) localOrders = stored;
    } catch (error) {
        console.warn('Could not read POS orders from browser storage.', error);
    }

    const databaseOrderCount = Number(combined.kpis.paidOrderCount) || 0;
    let allOrderValue = (Number(combined.kpis.averageOrderValue) || 0) * databaseOrderCount;
    let allOrderCount = databaseOrderCount;
    const salesByDate = new Map(combined.sales.map(row => [row.date, Number(row.revenue) || 0]));
    const favorites = {
        bubbleTea: new Map(combined.bubbleTea.map(row => [row.name, Number(row.quantity) || 0])),
        chicken: new Map(combined.chicken.map(row => [row.name, Number(row.quantity) || 0]))
    };
    const today = new Date().toISOString().slice(0, 10);
    const month = today.slice(0, 7);

    localOrders.forEach(order => {
        const items = Array.isArray(order.items) ? order.items : [];
        const amount = Number(order.total) || items.reduce((sum, item) => sum + (Number(item.price) || 0) * (Number(item.quantity) || 0), 0);
        const date = order.dateISO || '';
        allOrderValue += amount;
        allOrderCount += 1;
        if (date === today) combined.kpis.todayRevenue = (Number(combined.kpis.todayRevenue) || 0) + amount;
        if (date.slice(0, 7) === month) combined.kpis.monthlyRevenue = (Number(combined.kpis.monthlyRevenue) || 0) + amount;
        if (date) salesByDate.set(date, (salesByDate.get(date) || 0) + amount);

        items.forEach(item => {
            const quantity = Number(item.quantity) || 0;
            const category = item.category || ((Number(item.id) >= 12 && Number(item.id) <= 20) ? 'bubbletea' : 'chicken');
            const key = category === 'bubbletea' ? 'bubbleTea' : (category === 'chicken' ? 'chicken' : null);
            if (!key || quantity <= 0) return;
            const name = item.baseName || String(item.name || '').replace(/\s+\([^)]*\)$/, '');
            favorites[key].set(name, (favorites[key].get(name) || 0) + quantity);
        });
    });

    combined.kpis.averageOrderValue = allOrderCount ? allOrderValue / allOrderCount : 0;
    combined.sales = Array.from(salesByDate, ([date, revenue]) => ({ date, revenue }))
        .sort((a, b) => a.date.localeCompare(b.date)).slice(-7);
    combined.bubbleTea = Array.from(favorites.bubbleTea, ([name, quantity]) => ({ name, quantity }))
        .sort((a, b) => b.quantity - a.quantity).slice(0, 5);
    combined.chicken = Array.from(favorites.chicken, ([name, quantity]) => ({ name, quantity }))
        .sort((a, b) => b.quantity - a.quantity).slice(0, 5);
    return combined;
}

// Setup responsive sidebar toggle
function setupSidebarToggle() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.add('show');
            sidebarOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', function() {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
            document.body.style.overflow = '';
        });
    }

    // Close sidebar when clicking on nav items (mobile)
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
                document.body.style.overflow = '';
            }
        });
    });
}

// Setup navigation functionality
function setupNavigation() {
    const navItems = document.querySelectorAll('.nav-item[data-page]');
    
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            
            // Only prevent default if there's no valid href (like #)
            if (!href || href === '#') {
                e.preventDefault();
                
                // Remove active class from all items
                navItems.forEach(nav => nav.classList.remove('active'));
                
                // Add active class to clicked item
                this.classList.add('active');
                
                const page = this.getAttribute('data-page');
                console.log(`Navigating to: ${page}`);
            }
            // If href exists and is valid, let the browser handle navigation naturally
        });
    });
}

// Setup clickable KPI cards to navigate to Inventory
function setupClickableCards() {
    const clickableCards = document.querySelectorAll('.kpi-card.clickable[data-navigate]');
    
    clickableCards.forEach(card => {
        card.addEventListener('click', function() {
            const targetPage = this.getAttribute('data-navigate');
            
            // Find and click the inventory nav item
            const inventoryNav = document.querySelector(`.nav-item[data-page="${targetPage}"]`);
            
            if (inventoryNav) {
                // Remove active from all nav items
                document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
                
                // Add active to inventory
                inventoryNav.classList.add('active');
                
                window.location.href = `${targetPage}.php`;
            }
        });
        
        // Add keyboard accessibility
        card.setAttribute('tabindex', '0');
        card.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });
}

function updateDashboardKpis() {
    const data = activeDashboardData || window.dashboardData || {};
    const kpis = data.kpis || {};
    const moneyKeys = new Set(['todayRevenue', 'monthlyRevenue', 'averageOrderValue', 'totalValue']);
    document.querySelectorAll('[data-kpi]').forEach(element => {
        const key = element.dataset.kpi;
        const value = Number(kpis[key]) || 0;
        element.textContent = moneyKeys.has(key)
            ? `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
            : value.toLocaleString('en-PH');
    });

    const oldError = document.querySelector('.dashboard-data-error');
    if (oldError) oldError.remove();
    if (data.error) {
        const message = document.createElement('p');
        message.className = 'dashboard-data-error';
        message.setAttribute('role', 'alert');
        message.textContent = data.error;
        document.querySelector('.main-content').prepend(message);
    }
}

// Render charts from the sales and order-item data supplied by dashboard.php.
function initializeCharts() {
    const data = activeDashboardData || window.dashboardData || {};
    const salesCanvas = document.getElementById('salesChart');
    const teaCanvas = document.getElementById('bubbleTeaChart');
    const chickenCanvas = document.getElementById('pieChart');
    if (salesCanvas) drawSalesChart(salesCanvas, data.sales || []);
    if (teaCanvas) drawFavoriteChart(teaCanvas, data.bubbleTea || []);
    if (chickenCanvas) drawFavoriteChart(chickenCanvas, data.chicken || []);

}

function sizeCanvas(canvas) {
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    if (!width || !height) return null;
    canvas.width = width;
    canvas.height = height;
    return { ctx: canvas.getContext('2d'), width, height };
}

function drawSalesChart(canvas, sales) {
    const size = sizeCanvas(canvas);
    if (!size) return;
    const { ctx, width, height } = size;
    ctx.clearRect(0, 0, width, height);
    const pad = { left: 48, right: 16, top: 20, bottom: 42 };
    const chartWidth = width - pad.left - pad.right;
    const chartHeight = height - pad.top - pad.bottom;
    const maxValue = Math.max(1, ...sales.map(row => Number(row.revenue) || 0));
    const tick = maxValue / 4;

    ctx.font = '12px sans-serif';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = pad.top + chartHeight * i / 4;
        ctx.strokeStyle = '#e5e5e5';
        ctx.beginPath();
        ctx.moveTo(pad.left, y);
        ctx.lineTo(width - pad.right, y);
        ctx.stroke();
        ctx.fillStyle = '#777';
        ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxValue - tick * i).toLocaleString('en-PH'), pad.left - 8, y + 4);
    }

    if (!sales.length) {
        ctx.fillStyle = '#999';
        ctx.textAlign = 'center';
        ctx.fillText('No paid sales recorded', width / 2, height / 2);
        return;
    }

    const slot = chartWidth / sales.length;
    const barWidth = Math.min(54, slot * 0.62);
    sales.forEach((row, index) => {
        const value = Number(row.revenue) || 0;
        const barHeight = (value / maxValue) * chartHeight;
        const x = pad.left + slot * index + (slot - barWidth) / 2;
        const y = pad.top + chartHeight - barHeight;
        ctx.fillStyle = '#ff8c00';
        ctx.fillRect(x, y, barWidth, barHeight);
        ctx.fillStyle = '#555';
        ctx.textAlign = 'center';
        const date = new Date(`${row.date}T00:00:00`);
        ctx.fillText(Number.isNaN(date.getTime()) ? row.date : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }), x + barWidth / 2, height - 15);
    });
}

function drawFavoriteChart(canvas, rows) {
    const size = sizeCanvas(canvas);
    if (!size) return;
    const { ctx, width, height } = size;
    ctx.clearRect(0, 0, width, height);
    const colors = ['#ff8c00', '#8b0000', '#ffd700', '#7fbf7f', '#6b8eae'];
    const items = rows.slice(0, 5).filter(row => Number(row.quantity) > 0);
    const centerX = width / 2;
    const centerY = Math.min(112, height * 0.38);
    const radius = Math.min(82, width * 0.22, height * 0.28);
    const total = items.reduce((sum, row) => sum + Number(row.quantity), 0);

    if (!total) {
        ctx.strokeStyle = '#e2e2e2';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
        ctx.stroke();
        ctx.fillStyle = '#999';
        ctx.font = '16px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('No paid sales recorded', centerX, centerY + 5);
        return;
    }

    let angle = -Math.PI / 2;
    items.forEach((row, index) => {
        const slice = Number(row.quantity) / total * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(centerX, centerY);
        ctx.arc(centerX, centerY, radius, angle, angle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[index % colors.length];
        ctx.fill();
        angle += slice;
    });

    const legendTop = Math.min(height - 22, centerY + radius + 26);
    items.forEach((row, index) => {
        const column = index % 2;
        const line = Math.floor(index / 2);
        const x = Math.max(12, centerX - 132 + column * 150);
        const y = legendTop + line * 19;
        if (y > height - 5) return;
        ctx.fillStyle = colors[index % colors.length];
        ctx.fillRect(x, y - 10, 12, 12);
        ctx.fillStyle = '#555';
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'left';
        const name = row.name.length > 18 ? `${row.name.slice(0, 17)}…` : row.name;
        ctx.fillText(`${name} (${row.quantity})`, x + 18, y);
    });
}

// Smooth scroll behavior
document.documentElement.style.scrollBehavior = 'smooth';
