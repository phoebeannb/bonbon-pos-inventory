<?php
include 'db_connection.php';

$dashboardData = [
    'kpis' => [
        'todayRevenue' => 0, 'monthlyRevenue' => 0, 'averageOrderValue' => 0,
        'productsInStock' => 0, 'totalItems' => 0, 'lowStock' => 0,
        'outOfStock' => 0, 'totalValue' => 0
    ],
    'sales' => [], 'bubbleTea' => [], 'chicken' => []
];

try {
    $salesResult = $conn->query("SELECT
        COALESCE(SUM(CASE WHEN DATE(placed_at) = CURDATE() THEN total_amount ELSE 0 END), 0) AS today_revenue,
        COALESCE(SUM(CASE WHEN YEAR(placed_at) = YEAR(CURDATE()) AND MONTH(placed_at) = MONTH(CURDATE()) THEN total_amount ELSE 0 END), 0) AS monthly_revenue,
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

    $dailySales = $conn->query("SELECT DATE(placed_at) AS sale_date, SUM(total_amount) AS revenue
        FROM orders WHERE order_status = 'paid' GROUP BY DATE(placed_at)
        ORDER BY sale_date DESC LIMIT 7");
    while ($sale = $dailySales->fetch_assoc()) {
        $dashboardData['sales'][] = ['date' => $sale['sale_date'], 'revenue' => (float)$sale['revenue']];
    }
    $dashboardData['sales'] = array_reverse($dashboardData['sales']);

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
    <link rel="stylesheet" href="dashboard.css">
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
                <h2 class="page-title">Dashboard</h2>
                <div class="user-profile">
                    <div class="user-icon">
                        <img src="Images/Logo.png" alt="User avatar" data-user-avatar data-default-avatar="Images/Logo.png">
                    </div>
                    <span class="user-name" data-user-name>User Name</span>
                </div>
            </header>

            <!-- KPI Cards -->
            <section class="kpi-section">
                <div class="kpi-card">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Today's Revenue</h3>
                        <span class="kpi-icon"><i class="fas fa-peso-sign"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="todayRevenue">-</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Monthly Revenue</h3>
                        <span class="kpi-icon"><i class="fas fa-chart-line"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="monthlyRevenue">-</div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Average Order Value</h3>
                        <span class="kpi-icon"><i class="fas fa-shopping-bag"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="averageOrderValue">-</div>
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

                <div class="kpi-card clickable" data-navigate="inventory">
                    <div class="kpi-header">
                        <h3 class="kpi-title">Low Stock</h3>
                        <span class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></span>
                    </div>
                    <div class="kpi-value" data-kpi="lowStock">-</div>
                </div>

                <div class="kpi-card clickable" data-navigate="inventory">
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
            <section class="charts-section">
                <div class="chart-container">
                    <h3 class="chart-title">Sales</h3>
                    <div class="chart-wrapper">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>

                <div class="chart-container">
                    <h3 class="chart-title">Top 5 Favorite Bubble Tea</h3>
                    <div class="chart-wrapper">
                        <canvas id="bubbleTeaChart"></canvas>
                    </div>
                </div>

                <div class="chart-container">
                    <h3 class="chart-title">Top 5 Favorite Chicken Flavors</h3>
                    <div class="chart-wrapper">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="user-profile.js?v=2"></script>
    <script>window.dashboardData = <?php echo json_encode($dashboardData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;</script>
    <script src="dashboard.js?v=3"></script>
</body>
</html>
