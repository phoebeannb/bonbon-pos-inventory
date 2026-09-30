<?php
include 'db_connection.php';

$dashboardData = [
    'kpis' => [
        'todayRevenue' => 0, 'monthlyRevenue' => 0, 'averageOrderValue' => 0,
        'productsInStock' => 0, 'totalItems' => 0, 'lowStock' => 0,
        'outOfStock' => 0, 'totalValue' => 0
    ],
    'periods' => [], 'sales' => [], 'bubbleTea' => [], 'chicken' => [],
    'recentOrders' => [], 'stockAlerts' => ['low' => [], 'out' => []]
];

try {
    $salesResult = $conn->query("SELECT
        COALESCE(SUM(CASE WHEN DATE(placed_at) = CURDATE() THEN total_amount ELSE 0 END), 0) AS today_revenue,
        COUNT(CASE WHEN DATE(placed_at) = CURDATE() THEN 1 END) AS today_orders,
        COALESCE(SUM(CASE WHEN YEARWEEK(placed_at, 1) = YEARWEEK(CURDATE(), 1) THEN total_amount ELSE 0 END), 0) AS week_revenue,
        COUNT(CASE WHEN YEARWEEK(placed_at, 1) = YEARWEEK(CURDATE(), 1) THEN 1 END) AS week_orders,
        COALESCE(SUM(CASE WHEN YEAR(placed_at) = YEAR(CURDATE()) AND MONTH(placed_at) = MONTH(CURDATE()) THEN total_amount ELSE 0 END), 0) AS monthly_revenue,
        COUNT(CASE WHEN YEAR(placed_at) = YEAR(CURDATE()) AND MONTH(placed_at) = MONTH(CURDATE()) THEN 1 END) AS month_orders,
        COALESCE(AVG(total_amount), 0) AS average_order_value, COUNT(*) AS paid_order_count
        FROM orders WHERE order_status = 'paid'");
    $salesSummary = $salesResult->fetch_assoc();

    $stockResult = $conn->query("SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(CASE WHEN p.stock_quantity > 0 THEN 1 ELSE 0 END), 0) AS products_in_stock,
        COALESCE(SUM(CASE WHEN p.stock_quantity > 0 AND p.stock_quantity <= p.reorder_level
            AND COALESCE(c.slug, '') NOT IN ('chicken', 'bubbletea') THEN 1 ELSE 0 END), 0) AS low_stock,
        COALESCE(SUM(CASE WHEN p.stock_quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
        COALESCE(SUM(p.stock_quantity * p.selling_price), 0) AS total_value
        FROM products p LEFT JOIN product_categories c ON c.category_id = p.category_id
        WHERE p.is_active = 1 AND COALESCE(c.slug, '') <> 'cups'");
    $stockSummary = $stockResult->fetch_assoc();

    $dashboardData['kpis'] = [
        'todayRevenue' => (float)$salesSummary['today_revenue'],
        'monthlyRevenue' => (float)$salesSummary['monthly_revenue'],
        'averageOrderValue' => (float)$salesSummary['average_order_value'],
        'paidOrderCount' => (int)$salesSummary['paid_order_count'],
        'productsInStock' => (int)$stockSummary['products_in_stock'],
        'totalItems' => (int)$stockSummary['total_items'],
        'lowStock' => (int)$stockSummary['low_stock'],
        'outOfStock' => (int)$stockSummary['out_of_stock'],
        'totalValue' => (float)$stockSummary['total_value']
    ];

    $todayOrderCount = (int)$salesSummary['today_orders'];
    $weekOrderCount = (int)$salesSummary['week_orders'];
    $monthOrderCount = (int)$salesSummary['month_orders'];
    $dashboardData['periods'] = [
        'today' => [
            'revenue' => (float)$salesSummary['today_revenue'],
            'orders' => $todayOrderCount,
            'averageOrderValue' => $todayOrderCount ? (float)$salesSummary['today_revenue'] / $todayOrderCount : 0
        ],
        'week' => [
            'revenue' => (float)$salesSummary['week_revenue'],
            'orders' => $weekOrderCount,
            'averageOrderValue' => $weekOrderCount ? (float)$salesSummary['week_revenue'] / $weekOrderCount : 0
        ],
        'month' => [
            'revenue' => (float)$salesSummary['monthly_revenue'],
            'orders' => $monthOrderCount,
            'averageOrderValue' => $monthOrderCount ? (float)$salesSummary['monthly_revenue'] / $monthOrderCount : 0
        ]
    ];

    $dailySales = $conn->query("SELECT DATE(placed_at) AS sale_date, SUM(total_amount) AS revenue
        FROM orders WHERE order_status = 'paid' GROUP BY DATE(placed_at)
        ORDER BY sale_date DESC LIMIT 31");
    while ($sale = $dailySales->fetch_assoc()) {
        $dashboardData['sales'][] = ['date' => $sale['sale_date'], 'revenue' => (float)$sale['revenue']];
    }
    $dashboardData['sales'] = array_reverse($dashboardData['sales']);

    foreach ([
        'low' => "p.stock_quantity > 0 AND p.stock_quantity <= p.reorder_level
            AND COALESCE(c.slug, '') NOT IN ('chicken', 'bubbletea')",
        'out' => 'p.stock_quantity = 0'
    ] as $alertType => $stockCondition) {
        $stockAlertsResult = $conn->query("SELECT p.client_product_id, p.name, p.stock_quantity,
                p.reorder_level, c.name AS category_name
            FROM products p LEFT JOIN product_categories c ON c.category_id = p.category_id
            WHERE p.is_active = 1 AND COALESCE(c.slug, '') <> 'cups' AND {$stockCondition}
            ORDER BY p.stock_quantity ASC, p.name ASC");
        while ($product = $stockAlertsResult->fetch_assoc()) {
            $dashboardData['stockAlerts'][$alertType][] = [
                'id' => $product['client_product_id'],
                'name' => $product['name'],
                'stock' => (int)$product['stock_quantity'],
                'reorderLevel' => (int)$product['reorder_level'],
                'category' => $product['category_name']
            ];
        }
    }

    $recentOrders = $conn->query("SELECT o.order_number, o.placed_at, o.total_amount, o.order_status,
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.order_id), 0) AS item_count
        FROM orders o ORDER BY o.placed_at DESC, o.order_id DESC LIMIT 5");
    while ($order = $recentOrders->fetch_assoc()) {
        $dashboardData['recentOrders'][] = [
            'id' => $order['order_number'],
            'date' => $order['placed_at'],
            'total' => (float)$order['total_amount'],
            'status' => $order['order_status'],
            'items' => (int)$order['item_count']
        ];
    }

    foreach (['bubbleTea' => 'bubbletea', 'chicken' => 'chicken'] as $key => $category) {
        $stmt = $conn->prepare("SELECT oi.item_name AS name, SUM(oi.quantity) AS quantity
            FROM order_items oi JOIN orders o ON o.order_id = oi.order_id
            JOIN products p ON p.product_id = oi.product_id
            JOIN product_categories c ON c.category_id = p.category_id
            WHERE o.order_status = 'paid' AND c.slug = ?
            GROUP BY oi.item_name ORDER BY quantity DESC, name ASC LIMIT 5");
        $stmt->bind_param('s', $category);
        $stmt->execute();
        $items = $stmt->get_result();
        while ($item = $items->fetch_assoc()) {
            $dashboardData[$key][] = ['name' => $item['name'], 'quantity' => (int)$item['quantity']];
        }
        $stmt->close();
    }
} catch (Throwable $error) {
    error_log('Dashboard data query failed: ' . $error->getMessage());
    $dashboardData['error'] = 'Dashboard data could not be loaded. Check the database schema and connection.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Bonbon Kitchen</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css?v=6">
    <link rel="stylesheet" href="system-theme.css?v=6">
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <button class="sidebar-close" id="sidebarClose">
                <i class="fas fa-times"></i>
            </button>
            <div class="sidebar-user-info">
                <img src="Images/Logo.png" alt="User avatar" class="sidebar-user-avatar" data-user-avatar data-default-avatar="Images/Logo.png">
                <div class="sidebar-user-text">
                    <span class="sidebar-user-name" data-user-name>Bonbon User</span>
                </div>
            </div>
            
            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-item active" data-page="dashboard">
                    <span class="nav-icon"><i class="fas fa-th-large"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="pos.php" class="nav-item" data-page="pos">
                    <span class="nav-icon"><i class="fas fa-shopping-cart"></i></span>
                    <span class="nav-text">POS</span>
                </a>
                <a href="inventory.php" class="nav-item" data-page="inventory">
                    <span class="nav-icon"><i class="fas fa-box"></i></span>
                    <span class="nav-text">Inventory</span>
                </a>
                    <a href="settings.php" class="nav-item" data-page="settings">
                    <span class="nav-icon"><i class="fas fa-cog"></i></span>
                    <span class="nav-text">Settings</span>
                </a>
            </nav>
            
            <div class="logout">
                <a href="#" class="nav-item">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Log Out</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="dashboard-heading-group">
                    <h2 class="page-title">Dashboard</h2>
                    <button class="dashboard-export" id="dashboardExportBtn" type="button">
                        <i class="fas fa-file-export" aria-hidden="true"></i>
                        Export CSV
                    </button>
                </div>
                <div class="dashboard-header-tools">
                    <label class="date-range-control">
                        <span class="sr-only">Dashboard date range</span>
                        <select id="dashboardPeriod" aria-label="Dashboard date range">
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month">This Month</option>
                        </select>
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </label>
                    <div class="user-profile">
                        <div class="user-icon">
                            <img src="Images/Logo.png" alt="User avatar" data-user-avatar data-default-avatar="Images/Logo.png">
                        </div>
                        <span class="user-name" data-user-name>User Name</span>
                    </div>
                </div>
            </header>

            <!-- KPI Cards -->
            <section class="kpi-section">
                <div class="kpi-card">
                    <div class="kpi-header">
                            <h3 class="kpi-title" data-period-label="revenue">Revenue Today</h3>
                        <span class="kpi-icon"><i class="fas fa-peso-sign"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="periodRevenue">-</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-header">
                            <h3 class="kpi-title" data-period-label="orders">Paid Orders Today</h3>
                        <span class="kpi-icon"><i class="fas fa-chart-line"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="periodOrders">-</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Average Order Value</h3>
                        <span class="kpi-icon"><i class="fas fa-shopping-bag"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="periodAverageOrderValue">-</div>
                </div>

                <div class="kpi-card clickable" data-navigate="inventory">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Products in Stock</h3>
                        <span class="kpi-icon"><i class="fas fa-boxes"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="productsInStock">-</div>
                </div>

                <div class="kpi-card clickable" data-navigate="inventory">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Total Items</h3>
                        <span class="kpi-icon"><i class="fas fa-box"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="totalItems">-</div>
                </div>

                <div class="kpi-card clickable stock-alert-card" data-stock-alert="low" role="button" tabindex="0" aria-haspopup="dialog" aria-controls="inventoryAlertModal">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Low Stock</h3>
                        <span class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="lowStock">-</div>
                </div>

                <div class="kpi-card clickable stock-alert-card" data-stock-alert="out" role="button" tabindex="0" aria-haspopup="dialog" aria-controls="inventoryAlertModal">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Out of Stock</h3>
                        <span class="kpi-icon"><i class="fas fa-ban"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="outOfStock">-</div>
                </div>

                <div class="kpi-card clickable" data-navigate="inventory">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Total Value</h3>
                        <span class="kpi-icon"><i class="fas fa-peso-sign"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="totalValue">-</div>
                </div>
            </section>

            <!-- Charts Section -->
            <section class="dashboard-content">
                <div class="charts-section">
                    <div class="chart-container sales-chart-card">
                        <h3 class="chart-title">Sales</h3>
                        <div class="chart-wrapper">
                            <canvas id="salesChart"></canvas>
                            <div class="chart-empty-state" hidden>
                                <svg viewBox="0 0 112 112" width="108" height="108" aria-hidden="true">
                                    <circle class="empty-chart-ring" cx="56" cy="56" r="40" fill="none" stroke="#e7e2dc" stroke-width="8"></circle>
                                    <path class="empty-chart-bag" d="M43 48h26l-2.5 27h-21L43 48Z" fill="#fff8e1" stroke="#d7c9b4" stroke-width="2" stroke-linejoin="round"></path>
                                    <path class="empty-chart-handle" d="M49 49v-5a7 7 0 0 1 14 0v5" fill="none" stroke="#b7a894" stroke-width="2.5" stroke-linecap="round"></path>
                                </svg>
                                <span>No sales in this period</span>
                            </div>
                        </div>
                    </div>

                    <div class="chart-container">
                        <h3 class="chart-title">Top 5 Favorite Bubble Tea</h3>
                        <div class="chart-wrapper">
                            <canvas id="bubbleTeaChart"></canvas>
                            <div class="chart-empty-state" hidden>
                                <svg viewBox="0 0 112 112" width="108" height="108" aria-hidden="true">
                                    <circle class="empty-chart-ring" cx="56" cy="56" r="40" fill="none" stroke="#e7e2dc" stroke-width="8"></circle>
                                    <path class="empty-chart-bag" d="M43 48h26l-2.5 27h-21L43 48Z" fill="#fff8e1" stroke="#d7c9b4" stroke-width="2" stroke-linejoin="round"></path>
                                    <path class="empty-chart-handle" d="M49 49v-5a7 7 0 0 1 14 0v5" fill="none" stroke="#b7a894" stroke-width="2.5" stroke-linecap="round"></path>
                                </svg>
                                <span>No product sales yet</span>
                            </div>
                        </div>
                    </div>

                    <div class="chart-container">
                        <h3 class="chart-title">Top 5 Favorite Chicken Flavors</h3>
                        <div class="chart-wrapper">
                            <canvas id="pieChart"></canvas>
                            <div class="chart-empty-state" hidden>
                                <svg viewBox="0 0 112 112" width="108" height="108" aria-hidden="true">
                                    <circle class="empty-chart-ring" cx="56" cy="56" r="40" fill="none" stroke="#e7e2dc" stroke-width="8"></circle>
                                    <path class="empty-chart-bag" d="M43 48h26l-2.5 27h-21L43 48Z" fill="#fff8e1" stroke="#d7c9b4" stroke-width="2" stroke-linejoin="round"></path>
                                    <path class="empty-chart-handle" d="M49 49v-5a7 7 0 0 1 14 0v5" fill="none" stroke="#b7a894" stroke-width="2.5" stroke-linecap="round"></path>
                                </svg>
                                <span>No product sales yet</span>
                            </div>
                        </div>
                    </div>
                </div>

                <section class="recent-orders-panel" aria-labelledby="recentOrdersTitle">
                    <div class="recent-orders-heading">
                        <div>
                            <h3 id="recentOrdersTitle">Recent Orders</h3>
                            <p>Latest transactions</p>
                        </div>
                        <span class="recent-orders-count" id="recentOrdersCount">0</span>
                    </div>
                    <div class="recent-orders-table-wrap">
                        <table class="recent-orders-table">
                            <thead>
                                <tr><th>Order</th><th>Items</th><th>Total</th><th>Status</th></tr>
                            </thead>
                            <tbody id="recentOrdersBody"></tbody>
                        </table>
                        <p class="recent-orders-empty" id="recentOrdersEmpty" hidden>No transactions yet.</p>
                    </div>
                </section>
            </section>
        </main>
    </div>

    <div class="modal-overlay stock-alert-modal" id="inventoryAlertModal" role="presentation">
        <section class="modal-content stock-alert-dialog" role="dialog" aria-modal="true" aria-labelledby="stockAlertTitle">
            <header class="modal-header">
                <div>
                    <h3 id="stockAlertTitle">Stock alerts</h3>
                    <p id="stockAlertSummary"></p>
                </div>
                <button class="close-btn" id="closeStockAlertBtn" type="button" aria-label="Close stock alerts">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </header>
            <div class="modal-body">
                <ul class="stock-alert-list" id="stockAlertList"></ul>
                <p class="stock-alert-empty" id="stockAlertEmpty" hidden>No products match this stock alert.</p>
                <a class="stock-alert-inventory-link" href="inventory.php">Open Inventory <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </div>
        </section>
    </div>

    <script src="user-profile.js?v=2"></script>
    <script>window.dashboardData = <?php echo json_encode($dashboardData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;</script>
    <script src="dashboard.js?v=5"></script>
</body>
</html>
