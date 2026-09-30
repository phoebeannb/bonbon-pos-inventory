// Dashboard JavaScript

let activeDashboardData = null;
let activeDashboardPeriod = 'today';
let dashboardResizeTimeout;

// Initialize charts when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    activeDashboardData = combineDashboardData(window.dashboardData || {});
    updateDashboardKpis();
    initializeCharts();
    setupNavigation();
    setupClickableCards();
    setupDashboardPeriod();
    setupStockAlertCards();
    setupStockAlertModal();
    setupRecentOrders();
    setupDashboardExport();
    setupSidebarToggle();
});

window.addEventListener('storage', event => {
    if (event.key === 'bonbonPosOrders') {
        activeDashboardData = combineDashboardData(window.dashboardData || {});
        updateDashboardKpis();
        initializeCharts();
        renderRecentOrders();
    }
});

window.addEventListener('resize', () => {
    clearTimeout(dashboardResizeTimeout);
    dashboardResizeTimeout = setTimeout(initializeCharts, 250);
});

function combineDashboardData(databaseData) {
    const combined = JSON.parse(JSON.stringify(databaseData || {}));
    combined.kpis = { ...(combined.kpis || {}) };
    combined.periods = combined.periods || {};
    combined.sales = Array.isArray(combined.sales) ? combined.sales : [];
    combined.bubbleTea = Array.isArray(combined.bubbleTea) ? combined.bubbleTea : [];
    combined.chicken = Array.isArray(combined.chicken) ? combined.chicken : [];
    combined.recentOrders = Array.isArray(combined.recentOrders) ? combined.recentOrders : [];
    combined.stockAlerts = combined.stockAlerts || { low: [], out: [] };
    ['today', 'week', 'month'].forEach(period => {
        combined.periods[period] = {
            revenue: Number(combined.periods[period]?.revenue) || 0,
            orders: Number(combined.periods[period]?.orders) || 0,
            averageOrderValue: Number(combined.periods[period]?.averageOrderValue) || 0
        };
    });

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
    const now = new Date();
    const today = toLocalDateISO(now);
    const month = today.slice(0, 7);
    const localRecentOrders = [];

    localOrders.forEach(order => {
        const items = Array.isArray(order.items) ? order.items : [];
        const amount = Number(order.total) || items.reduce((sum, item) => sum + (Number(item.price) || 0) * (Number(item.quantity) || 0), 0);
        const date = order.dateISO || '';
        allOrderValue += amount;
        allOrderCount += 1;
        const periodKey = getPeriodKeyForDate(date, now);
        if (periodKey) {
            combined.periods[periodKey].revenue += amount;
            combined.periods[periodKey].orders += 1;
        }
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

        localRecentOrders.push({
            id: `LOCAL-${order.id}`,
            date: `${date} ${order.timeDisplay || '00:00:00'}`,
            total: amount,
            status: 'paid',
            items: items.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0)
        });
    });

    Object.values(combined.periods).forEach(period => {
        period.averageOrderValue = period.orders ? period.revenue / period.orders : 0;
    });

    combined.kpis.averageOrderValue = allOrderCount ? allOrderValue / allOrderCount : 0;
    combined.sales = Array.from(salesByDate, ([date, revenue]) => ({ date, revenue }))
        .sort((a, b) => a.date.localeCompare(b.date)).slice(-31);
    combined.bubbleTea = Array.from(favorites.bubbleTea, ([name, quantity]) => ({ name, quantity }))
        .sort((a, b) => b.quantity - a.quantity).slice(0, 5);
    combined.chicken = Array.from(favorites.chicken, ([name, quantity]) => ({ name, quantity }))
        .sort((a, b) => b.quantity - a.quantity).slice(0, 5);
    combined.recentOrders = [...combined.recentOrders, ...localRecentOrders].sort(
        (a, b) => getOrderTimestamp(b) - getOrderTimestamp(a)
    ).slice(0, 5);
    return combined;
}

function getOrderTimestamp(order) {
    const value = String(order.date || '');
    const match = value.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}):(\d{2}):(\d{2})(?:\s*(AM|PM))?$/i);
    if (!match) return Date.parse(value) || 0;

    let hour = Number(match[2]);
    if (match[5]) {
        hour %= 12;
        if (match[5].toUpperCase() === 'PM') hour += 12;
    }
    return new Date(`${match[1]}T${String(hour).padStart(2, '0')}:${match[3]}:${match[4]}`).getTime();
}

function toLocalDateISO(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function getPeriodKeyForDate(dateValue, now = new Date()) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) return null;
    const today = toLocalDateISO(now);
    if (dateValue === today) return 'today';
    const date = new Date(`${dateValue}T00:00:00`);
    const weekStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    weekStart.setDate(weekStart.getDate() - ((weekStart.getDay() + 6) % 7));
    const nextWeekStart = new Date(weekStart);
    nextWeekStart.setDate(nextWeekStart.getDate() + 7);
    if (date >= weekStart && date < nextWeekStart) return 'week';
    if (dateValue.slice(0, 7) === today.slice(0, 7)) return 'month';
    return null;
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

function setupDashboardPeriod() {
    const periodSelect = document.getElementById('dashboardPeriod');
    if (!periodSelect) return;

    periodSelect.addEventListener('change', () => {
        activeDashboardPeriod = periodSelect.value;
        updateDashboardKpis();
        initializeCharts();
    });
}

function setupStockAlertCards() {
    document.querySelectorAll('[data-stock-alert]').forEach(card => {
        const open = () => openStockAlert(card.dataset.stockAlert, card);
        card.addEventListener('click', open);
        card.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
        });
    });
}

let stockAlertTrigger = null;

function setupStockAlertModal() {
    const modal = document.getElementById('inventoryAlertModal');
    const closeButton = document.getElementById('closeStockAlertBtn');
    if (!modal || !closeButton) return;

    const close = () => {
        modal.classList.remove('show');
        document.body.style.overflow = '';
        stockAlertTrigger?.focus();
    };

    closeButton.addEventListener('click', close);
    modal.addEventListener('click', event => {
        if (event.target === modal) close();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal.classList.contains('show')) close();
    });
}

function openStockAlert(type, trigger) {
    const data = activeDashboardData?.stockAlerts || {};
    const products = Array.isArray(data[type]) ? data[type] : [];
    const isLow = type === 'low';
    const title = document.getElementById('stockAlertTitle');
    const summary = document.getElementById('stockAlertSummary');
    const list = document.getElementById('stockAlertList');
    const empty = document.getElementById('stockAlertEmpty');
    const modal = document.getElementById('inventoryAlertModal');
    if (!title || !summary || !list || !empty || !modal) return;

    stockAlertTrigger = trigger;
    title.textContent = isLow ? 'Low-stock products' : 'Out-of-stock products';
    summary.textContent = `${products.length} ${products.length === 1 ? 'product needs' : 'products need'} attention`;
    list.replaceChildren();
    empty.hidden = products.length > 0;
    products.forEach(product => {
        const item = document.createElement('li');
        const details = document.createElement('div');
        const name = document.createElement('strong');
        const meta = document.createElement('span');
        const quantity = document.createElement('span');

        item.className = 'stock-alert-item';
        details.className = 'stock-alert-details';
        name.textContent = product.name;
        meta.textContent = [product.id, product.category].filter(Boolean).join(' · ');
        quantity.className = `stock-alert-quantity ${isLow ? 'is-low' : 'is-out'}`;
        quantity.textContent = isLow
            ? `${product.stock} left · reorder at ${product.reorderLevel}`
            : 'Out of stock';
        details.append(name, meta);
        item.append(details, quantity);
        list.append(item);
    });

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    document.getElementById('closeStockAlertBtn')?.focus();
}

function setupRecentOrders() {
    renderRecentOrders();
}

function renderRecentOrders() {
    const data = activeDashboardData || window.dashboardData || {};
    const body = document.getElementById('recentOrdersBody');
    const empty = document.getElementById('recentOrdersEmpty');
    const count = document.getElementById('recentOrdersCount');
    if (!body || !empty || !count) return;

    const orders = (data.recentOrders || []).slice(0, 5);
    body.replaceChildren();
    count.textContent = String(orders.length);
    empty.hidden = orders.length > 0;
    orders.forEach(order => {
        const row = document.createElement('tr');
        const orderCell = document.createElement('td');
        const itemsCell = document.createElement('td');
        const totalCell = document.createElement('td');
        const statusCell = document.createElement('td');
        const status = normalizeOrderStatus(order.status);
        const badge = document.createElement('span');

        orderCell.className = 'recent-order-id';
        orderCell.textContent = String(order.id || 'Order');
        itemsCell.textContent = `${Number(order.items) || 0}`;
        totalCell.textContent = formatCurrency(order.total);
        badge.className = `order-status-badge status-${status.key}`;
        badge.textContent = status.label;
        statusCell.append(badge);
        row.append(orderCell, itemsCell, totalCell, statusCell);
        body.append(row);
    });
}

function normalizeOrderStatus(status) {
    const key = String(status || '').toLowerCase();
    const labels = {
        paid: { key: 'completed', label: 'Completed' },
        completed: { key: 'completed', label: 'Completed' },
        pending: { key: 'pending', label: 'Pending' },
        cancelled: { key: 'cancelled', label: 'Cancelled' },
        refunded: { key: 'refunded', label: 'Refunded' }
    };
    return labels[key] || { key: 'pending', label: 'Pending' };
}

function setupDashboardExport() {
    document.getElementById('dashboardExportBtn')?.addEventListener('click', exportDashboardCsv);
}

function exportDashboardCsv() {
    const data = activeDashboardData || window.dashboardData || {};
    const kpis = data.kpis || {};
    const period = data.periods?.[activeDashboardPeriod] || {};
    const label = getPeriodLabel(activeDashboardPeriod);
    const rows = [
        ['Section', 'Metric', 'Value'],
        ['Summary', `Revenue (${label})`, formatCurrency(period.revenue)],
        ['Summary', `Paid orders (${label})`, String(Number(period.orders) || 0)],
        ['Summary', `Average order value (${label})`, formatCurrency(period.averageOrderValue)],
        ['Inventory', 'Products in stock', String(Number(kpis.productsInStock) || 0)],
        ['Inventory', 'Total products', String(Number(kpis.totalItems) || 0)],
        ['Inventory', 'Low stock', String(Number(kpis.lowStock) || 0)],
        ['Inventory', 'Out of stock', String(Number(kpis.outOfStock) || 0)],
        ['Inventory', 'Total value', formatCurrency(kpis.totalValue)],
        [],
        ['Recent orders', 'Order', 'Items', 'Total', 'Status']
    ];

    (data.recentOrders || []).slice(0, 5).forEach(order => {
        rows.push([
            'Recent orders',
            order.id || '',
            String(Number(order.items) || 0),
            formatCurrency(order.total),
            normalizeOrderStatus(order.status).label
        ]);
    });

    const csv = '\uFEFF' + rows.map(row => row.map(escapeCsvValue).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `bonbon-dashboard-${activeDashboardPeriod}-${toLocalDateISO(new Date())}.csv`;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function escapeCsvValue(value) {
    return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

function formatCurrency(value) {
    return `₱${(Number(value) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function getPeriodLabel(period) {
    return { today: 'Today', week: 'This Week', month: 'This Month' }[period] || 'Today';
}

function updateDashboardKpis() {
    const data = activeDashboardData || window.dashboardData || {};
    const kpis = data.kpis || {};
    const period = data.periods?.[activeDashboardPeriod] || {};
    const periodValues = {
        periodRevenue: period.revenue,
        periodOrders: period.orders,
        periodAverageOrderValue: period.averageOrderValue
    };
    const moneyKeys = new Set(['periodRevenue', 'periodAverageOrderValue', 'totalValue']);
    document.querySelectorAll('[data-kpi]').forEach(element => {
        const key = element.dataset.kpi;
        const value = Number(Object.hasOwn(periodValues, key) ? periodValues[key] : kpis[key]) || 0;
        element.textContent = moneyKeys.has(key)
            ? formatCurrency(value)
            : value.toLocaleString('en-PH');
    });

    document.querySelectorAll('[data-period-label]').forEach(element => {
        const label = getPeriodLabel(activeDashboardPeriod);
        element.textContent = element.dataset.periodLabel === 'orders' ? `Paid Orders ${label}` : `Revenue ${label}`;
    });

    const kpiSection = document.querySelector('.kpi-section');
    if (kpiSection) {
        kpiSection.classList.remove('is-updating');
        void kpiSection.offsetWidth;
        kpiSection.classList.add('is-updating');
    }

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
    if (salesCanvas) drawSalesChart(salesCanvas, filterSalesForPeriod(data.sales || []));
    if (teaCanvas) drawFavoriteChart(teaCanvas, data.bubbleTea || []);
    if (chickenCanvas) drawFavoriteChart(chickenCanvas, data.chicken || []);

}

function filterSalesForPeriod(sales) {
    const now = new Date();
    const today = toLocalDateISO(now);
    if (activeDashboardPeriod === 'today') return sales.filter(row => row.date === today);
    if (activeDashboardPeriod === 'month') return sales.filter(row => row.date?.slice(0, 7) === today.slice(0, 7));
    return sales.filter(row => getPeriodKeyForDate(row.date, now) === 'week' || row.date === today);
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
    const emptyState = canvas.parentElement.querySelector('.chart-empty-state');
    if (!sales.length) {
        canvas.hidden = true;
        if (emptyState) emptyState.hidden = false;
        return;
    }
    canvas.hidden = false;
    if (emptyState) emptyState.hidden = true;
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
    const items = rows.slice(0, 5).filter(row => Number(row.quantity) > 0);
    const emptyState = canvas.parentElement.querySelector('.chart-empty-state');
    if (!items.length) {
        canvas.hidden = true;
        if (emptyState) emptyState.hidden = false;
        return;
    }
    canvas.hidden = false;
    if (emptyState) emptyState.hidden = true;
    const size = sizeCanvas(canvas);
    if (!size) return;
    const { ctx, width, height } = size;
    ctx.clearRect(0, 0, width, height);
    const colors = ['#ff8c00', '#8b0000', '#ffd700', '#7fbf7f', '#6b8eae'];
    const centerX = width / 2;
    const centerY = Math.min(112, height * 0.38);
    const radius = Math.min(82, width * 0.22, height * 0.28);
    const total = items.reduce((sum, row) => sum + Number(row.quantity), 0);

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
