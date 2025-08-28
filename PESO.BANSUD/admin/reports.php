<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Get statistics for notification badges
$stats = [];

// Unread messages
$query = "SELECT COUNT(*) as count FROM messages WHERE receiver_type = 'admin' AND is_read = FALSE";
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['unread_messages'] = $result ? (int)$result['count'] : 0;

// Pending account approvals
$query = "SELECT COUNT(*) as count FROM residents WHERE approved = 0";
$stmt = $db->prepare($query);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['pending_accounts'] = $result ? (int)$result['count'] : 0;

$report_type = $_GET['report'] ?? 'overview';
$search_query = $_GET['search'] ?? '';
$export = $_GET['export'] ?? '';

// No date range calculation needed for search
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-365 days')); // Default to last year for broad search

// Export functionality
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="jobmatch_report_' . $report_type . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    if ($report_type === 'residents') {
        // Export residents data
        fputcsv($output, ['Full Name', 'Email', 'Employed', 'Job Title', 'Barangay', 'Gender', 'Registration Date']);

    $query = "SELECT r.first_name, r.middle_name, r.last_name, r.email, r.employed, r.job_title, b.name as barangay_name, r.gender, r.created_at 
                  FROM residents r 
                  LEFT JOIN barangays b ON r.barangay_id = b.id 
                  WHERE r.requirements_completed = 1";
        
        // Add search condition if search query exists
        if (!empty($search_query)) {
            $query .= " AND (r.first_name LIKE :search OR r.middle_name LIKE :search OR r.last_name LIKE :search 
                           OR r.employed LIKE :search OR r.job_title LIKE :search 
                           OR b.name LIKE :search OR r.gender LIKE :search)";
        }
        
        $query .= " ORDER BY r.created_at DESC";
        $stmt = $db->prepare($query);
        
        if (!empty($search_query)) {
            $search_param = "%{$search_query}%";
            $stmt->bindParam(':search', $search_param);
        }
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name'],
                $row['email'],
                $row['employed'],
                $row['job_title'],
                $row['barangay_name'],
                $row['gender'],
                date('Y-m-d', strtotime($row['created_at']))
            ]);
        }
    } elseif ($report_type === 'jobs') {
        // Export jobs data
        fputcsv($output, ['Job Title', 'Company', 'Job Type', 'Status', 'Location', 'Posted Date']);

        $query = "SELECT j.title, j.company, j.job_type, j.status, j.location, j.created_at 
                  FROM jobs j 
                  WHERE j.created_at BETWEEN :start_date AND :end_date 
                  ORDER BY j.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['title'],
                $row['company'],
                $row['job_type'],
                $row['status'],
                $row['location'],
                date('Y-m-d', strtotime($row['created_at']))
            ]);
        }
    }

    fclose($output);
    exit;
}

// Get overview statistics
$overview_stats = [];
if ($report_type === 'overview') {
    $stats_query = "SELECT 
                    (SELECT COUNT(*) FROM residents WHERE requirements_completed = 1) as total_residents,
                    (SELECT COUNT(*) FROM residents WHERE requirements_completed = 1 AND created_at BETWEEN :start_date AND :end_date) as new_residents,
                    (SELECT COUNT(*) FROM residents WHERE requirements_completed = 0) as pending_accounts,
                    (SELECT COUNT(*) FROM jobs) as total_jobs,
                    (SELECT COUNT(*) FROM jobs WHERE status = 'Active') as active_jobs,
                    (SELECT COUNT(*) FROM job_notifications) as total_notifications,
                    (SELECT COUNT(*) FROM job_notifications WHERE status = 'accepted') as accepted_notifications,
                    (SELECT COUNT(*) FROM job_notifications WHERE status = 'declined') as declined_notifications,
                    (SELECT COUNT(*) FROM messages) as total_messages";

    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':start_date', $start_date);
    $stats_stmt->bindParam(':end_date', $end_date);
    $stats_stmt->execute();
    $overview_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Get male and female resident counts
    $male_residents_query = "SELECT COUNT(*) as count FROM residents WHERE gender = 'Male' AND requirements_completed = 1";
    $male_residents_stmt = $db->prepare($male_residents_query);
    $male_residents_stmt->execute();
    $overview_stats['total_male_residents'] = $male_residents_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $female_residents_query = "SELECT COUNT(*) as count FROM residents WHERE gender = 'Female' AND requirements_completed = 1";
    $female_residents_stmt = $db->prepare($female_residents_query);
    $female_residents_stmt->execute();
    $overview_stats['total_female_residents'] = $female_residents_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Get employed distribution
    $employment_query = "SELECT 
                            CASE 
                                WHEN employed = 'Yes' OR employed = 'Employed' OR employed = '1' OR employed = 'yes' THEN 'Yes'
                                WHEN employed = 'No' OR employed = 'Unemployed' OR employed = '0' OR employed = 'no' THEN 'No'
                                ELSE 'No'
                            END as employed,
                            COUNT(*) as count 
                        FROM residents 
                        WHERE requirements_completed = 1
                        GROUP BY 
                            CASE 
                                WHEN employed = 'Yes' OR employed = 'Employed' OR employed = '1' OR employed = 'yes' THEN 'Yes'
                                WHEN employed = 'No' OR employed = 'Unemployed' OR employed = '0' OR employed = 'no' THEN 'No'
                                ELSE 'No'
                            END
                        ORDER BY count DESC";
    $employment_stmt = $db->prepare($employment_query);
    $employment_stmt->execute();
    $employment_distribution = $employment_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get job type distribution
    $job_type_query = "SELECT job_type, COUNT(*) as count 
                      FROM jobs 
                      WHERE created_at BETWEEN :start_date AND :end_date 
                      GROUP BY job_type 
                      ORDER BY count DESC";
    $job_type_stmt = $db->prepare($job_type_query);
    $job_type_stmt->bindParam(':start_date', $start_date);
    $job_type_stmt->bindParam(':end_date', $end_date);
    $job_type_stmt->execute();
    $job_type_distribution = $job_type_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get monthly registration trends
    $trends_query = "SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as registrations
                    FROM residents 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    AND requirements_completed = 1
                    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                    ORDER BY month";
    $trends_stmt = $db->prepare($trends_query);
    $trends_stmt->execute();
    $registration_trends = $trends_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get residents report data
$residents_data = [];
if ($report_type === 'residents') {
    $residents_query = "SELECT r.*, b.name as barangay_name,
                       (SELECT COUNT(*) FROM job_notifications WHERE resident_id = r.id) as total_notifications,
                       (SELECT COUNT(*) FROM job_notifications WHERE resident_id = r.id AND status = 'accepted') as accepted_jobs
                       FROM residents r 
                       LEFT JOIN barangays b ON r.barangay_id = b.id 
                       WHERE r.requirements_completed = 1";
    
    // Add search condition if search query exists
    if (!empty($search_query)) {
        $residents_query .= " AND (r.first_name LIKE :search OR r.middle_name LIKE :search OR r.last_name LIKE :search 
                              OR r.employed LIKE :search OR r.job_title LIKE :search 
                              OR b.name LIKE :search OR r.gender LIKE :search)";
    }
    
    $residents_query .= " ORDER BY r.created_at DESC";
    $residents_stmt = $db->prepare($residents_query);
    
    if (!empty($search_query)) {
        $search_param = "%{$search_query}%";
        $residents_stmt->bindParam(':search', $search_param);
    }
    $residents_stmt->execute();
    $residents_data = $residents_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get jobs report data
$jobs_data = [];
if ($report_type === 'jobs') {
    $jobs_query = "SELECT j.*, b.name as barangay_name,
                  (SELECT COUNT(*) FROM job_notifications WHERE job_id = j.id) as total_notifications,
                  (SELECT COUNT(*) FROM job_notifications WHERE job_id = j.id AND status = 'accepted') as accepted_count,
                  (SELECT COUNT(*) FROM job_notifications WHERE job_id = j.id AND status = 'declined') as declined_count
                  FROM jobs j 
                  LEFT JOIN barangays b ON j.barangay_id = b.id 
                  WHERE j.created_at BETWEEN :start_date AND :end_date 
                  ORDER BY j.created_at DESC";
    $jobs_stmt = $db->prepare($jobs_query);
    $jobs_stmt->bindParam(':start_date', $start_date);
    $jobs_stmt->bindParam(':end_date', $end_date);
    $jobs_stmt->execute();
    $jobs_data = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get notifications report data
$notifications_data = [];
if ($report_type === 'notifications') {
    $notifications_query = "SELECT jn.*, r.first_name, r.middle_name, r.last_name, j.title as job_title, j.company 
                           FROM job_notifications jn 
                           JOIN residents r ON jn.resident_id = r.id 
                           JOIN jobs j ON jn.job_id = j.id 
                           WHERE jn.created_at BETWEEN :start_date AND :end_date 
                           ORDER BY jn.created_at DESC";
    $notifications_stmt = $db->prepare($notifications_query);
    $notifications_stmt->bindParam(':start_date', $start_date);
    $notifications_stmt->bindParam(':end_date', $end_date);
    $notifications_stmt->execute();
    $notifications_data = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - JobMatch Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="/assets/css/admin/reports.css">
    <link rel="stylesheet" href="../assets/css/admin/adashboardbadge.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="/assets/css/admin/card_icon.css">
    <link rel="stylesheet" href="../assets/css/table-sort.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.lineicons.com/4.0/lineicons.css">
    
    <style>
        .search-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .search-input {
            flex: 1;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .search-btn, .clear-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .search-btn {
            background-color: #007bff;
            color: white;
        }
        
        .search-btn:hover {
            background-color: #0056b3;
        }
        
        .clear-btn {
            background-color: #6c757d;
            color: white;
        }
        
        .clear-btn:hover {
            background-color: #545b62;
        }
        
        .search-info {
            margin-top: 0.25rem;
            color: #666;
        }
    </style>
</head>

<body>
    <!-- Hamburger Toggle Button -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <div class="hamburger-icon">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar mobile-hidden" id="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-briefcase"></i> JobMatch</h2>
                <span class="admin-label">Admin Panel</span>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="residents.php"><i class="fas fa-users"></i> Manage Residents</a></li>
                    <li>
                        <a href="pending-accounts.php">
                            <i class="fas fa-user-clock"></i> Pending Accounts
                            <?php if(isset($stats['pending_accounts']) && $stats['pending_accounts'] > 0): ?>
                                <span class="notification-badge"><?php echo $stats['pending_accounts']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                      <li>
                        <a href="requirements-review.php">
                            <i class="fas fa-file-circle-check"></i> Review Requirements
                            <?php 
                            // Get pending requirements count
                            $pending_req_query = "SELECT COUNT(*) as count FROM resident_requirements WHERE status = 'pending'";
                            $pending_req_stmt = $db->prepare($pending_req_query);
                            $pending_req_stmt->execute();
                            $pending_req_result = $pending_req_stmt->fetch(PDO::FETCH_ASSOC);
                            $pending_requirements = $pending_req_result ? (int)$pending_req_result['count'] : 0;
                            if($pending_requirements > 0): ?>
                                <span class="notification-badge"><?php echo $pending_requirements; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    
                    <li><a href="jobs.php"><i class="fas fa-briefcase"></i> Manage Jobs</a></li>
                    <li>
                        <a href="messages.php">
                            <i class="fas fa-envelope"></i> Messages
                            <?php if(isset($stats['unread_messages']) && $stats['unread_messages'] > 0): ?>
                                <span class="notification-badge"><?php echo $stats['unread_messages']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="notifications.php"><i class="fas fa-bell"></i> Job Notifications</a></li>
                    <li><a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-chart-bar"></i> Reports & Analytics</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <div class="profile-dropdown">
                        <?php
                        require_once '../config/database.php';
                        $database = new Database();
                        $db = $database->getConnection();
                        $admin_id = $_SESSION['user_id'];
                        $admin_query = "SELECT profile_picture FROM admins WHERE id = :id";
                        $admin_stmt = $db->prepare($admin_query);
                        $admin_stmt->bindParam(':id', $admin_id);
                        $admin_stmt->execute();
                        $admin_profile = $admin_stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <img src="<?php echo !empty($admin_profile['profile_picture']) ? '../images/' . htmlspecialchars($admin_profile['profile_picture']) : '../images/PesoLogo.jpg'; ?>" class="profile-pic" id="adminProfilePic" alt="Profile Picture">
                        <div class="dropdown-content" id="adminProfileDropdown">
                            <a href="settings.php" class="dropdown-btn"><i class="fas fa-user-edit"></i> Update Profile</a>
                            <a href="../auth/logout.php" class="dropdown-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-container">

                <!-- Report Controls -->
                <div class="dashboard-card">
                    <div class="report-controls">
                        <div class="control-section">
                            <h3>Report Type</h3>
                            <div class="report-tabs">
                                <a href="?report=overview" class="report-tab <?php echo $report_type === 'overview' ? 'active' : ''; ?>">Overview</a>
                                <a href="?report=residents" class="report-tab <?php echo $report_type === 'residents' ? 'active' : ''; ?>">Residents</a>
                                <a href="?report=jobs" class="report-tab <?php echo $report_type === 'jobs' ? 'active' : ''; ?>">Jobs</a>
                                <a href="?report=notifications" class="report-tab <?php echo $report_type === 'notifications' ? 'active' : ''; ?>">Notifications</a>
                            </div>
                        </div>


                        <div class="control-section">
                            <h3>Search Filter</h3>
                            <div class="search-controls">
                                <input type="text" 
                                       id="searchInput" 
                                       class="search-input" 
                                       placeholder="Search by name, employed status, job title, barangay, or gender..." 
                                       value="<?php echo htmlspecialchars($search_query); ?>"
                                       onkeypress="handleSearchKeyPress(event)">
                                <button onclick="performSearch()" class="btn-primary search-btn">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button onclick="clearSearch()" class="btn-secondary clear-btn">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                            <?php if (!empty($search_query)): ?>
                                <div class="search-info">
                                    <small>Showing results for: "<strong><?php echo htmlspecialchars($search_query); ?></strong>"</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="control-section">
                            <h3>Export</h3>
                            <div class="export-controls">
                                <button onclick="window.print()" class="btn-secondary">Print Report</button>

                            </div>
                        </div>
                    </div>
                </div>


                <?php if ($report_type === 'overview'): ?>

                    <!-- Overview Report -->
                    <div style="margin-bottom: 1.5rem; margin-top: 1.5rem;">
                        <div class="dashboard-card">
                            <h3>System Overview</h3>
                            <div class="overview-stats">
                                <div class="stats-grid">
                                    <a href="residents.php" class="stat-card clickable-card">
                                        <div class="stat-number"><?php echo number_format($overview_stats['total_residents']); ?></div>
                                        <div class="stat-label">Total Residents</div>
                                        <div class="stat-change">+<?php echo $overview_stats['new_residents']; ?> this period</div>
                                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                                    </a>
                                    <a href="residents.php?filter=male" class="stat-card clickable-card">
                                        <div class="stat-number"><?php echo number_format($overview_stats['total_male_residents']); ?></div>
                                        <div class="stat-label">Male Residents</div>
                                        <div class="stat-sublabel">Total registered males</div>
                                        <div class="stat-icon"><i class="fas fa-mars"></i></div>
                                    </a>
                                    <a href="residents.php?filter=female" class="stat-card clickable-card">
                                        <div class="stat-number"><?php echo number_format($overview_stats['total_female_residents']); ?></div>
                                        <div class="stat-label">Female Residents</div>
                                        <div class="stat-sublabel">Total registered females</div>
                                        <div class="stat-icon"><i class="fas fa-venus"></i></div>
                                    </a>
                                    <a href="jobs.php" class="stat-card clickable-card">
                                        <div class="stat-number"><?php echo number_format($overview_stats['active_jobs']); ?></div>
                                        <div class="stat-label">Active Jobs</div>
                                        <div class="stat-sublabel"><?php echo $overview_stats['total_jobs']; ?> total jobs</div>
                                        <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
                                    </a>
                                    <a href="notifications.php" class="stat-card clickable-card">
                                        <div class="stat-number"><?php echo number_format($overview_stats['total_notifications']); ?></div>
                                        <div class="stat-label">Job Notifications</div>
                                        <div class="stat-sublabel"><?php echo round(($overview_stats['accepted_notifications'] / max($overview_stats['total_notifications'], 1)) * 100, 1); ?>% acceptance rate</div>
                                        <div class="stat-icon"><i class="fas fa-bell"></i></div>
                                    </a>
                                    <a href="messages.php" class="stat-card clickable-card">
                                        <div class="stat-number"><?php echo number_format($overview_stats['total_messages']); ?></div>
                                        <div class="stat-label">Total Messages</div>
                                        <div class="stat-sublabel">Communication activity</div>
                                        <div class="stat-icon"><i class="fas fa-envelope"></i></div>
                                    </a>
                                    <a href="pending-accounts.php" class="stat-card clickable-card">
                                        <div class="stat-number"><?php echo number_format($overview_stats['pending_accounts']); ?></div>
                                        <div class="stat-label">Pending Accounts</div>
                                        <div class="stat-sublabel">Awaiting requirements review</div>
                                        <div class="stat-icon"><i class="fas fa-clock"></i></div>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Charts Section -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; margin-top: 1.5rem;">
                            <!-- Employed Distribution -->
                            <div class="dashboard-card">
                                <h3><i class="fas fa-chart-pie"></i> Employed Distribution</h3>
                                <div class="chart-container">
                                    <canvas id="employmentChart" width="400" height="300"></canvas>
                                </div>
                            </div>

                            <!-- Job Type Distribution -->
                            <div class="dashboard-card">
                                <h3><i class="fas fa-chart-bar"></i> Job Types Distribution</h3>
                                <div class="chart-container">
                                    <canvas id="jobTypeChart" width="400" height="300"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Registration Trends -->
                        <div class="dashboard-card">
                            <h3><i class="fas fa-chart-line"></i> Registration Trends (Last 6 Months)</h3>
                            <div class="chart-container">
                                <canvas id="trendsChart" width="800" height="400"></canvas>
                            </div>
                        </div>

                    <?php elseif ($report_type === 'residents'): ?>

                        <!-- Residents Report -->
                        <div style="margin-bottom: 1.5rem;"></div>
                        <div class="dashboard-card">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h3>Residents Report (<?php echo count($residents_data); ?> residents)</h3>
                                <div class="report-summary">
                                    <span class="summary-item">Period: <?php echo date('M j', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date)); ?></span>
                                </div>
                            </div>

                            <div class="table-container">
                                <div class="table-responsive">
                                    <table id="residentsReportTable" class="auto-sort" data-exclude-columns='[]'>
                                        <thead>
                                            <tr>
                                                <th>Full Name</th>
                                                <th>Gender</th>
                                                <th>Employed</th>
                                                <th>Job Title</th>
                                                <th>Preferred Job</th>
                                                <th>Barangay</th>
                                                <th>Email</th>
                                                <th>Notifications</th>
                                                <th>Accepted Jobs</th>
                                                <th>Registration Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($residents_data)): ?>
                                                <tr>
                                                    <td colspan="9" style="text-align: center;">No residents found for the selected period</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($residents_data as $resident): ?>
                                                    <tr>
                                                        <td data-label="Full Name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . $resident['last_name']); ?></td>
                                                        <td data-label="Gender"><?php echo htmlspecialchars($resident['gender'] ?: 'Not specified'); ?></td>
                                                        <td data-label="Employed">
                                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $resident['employed'])); ?>">
                                                                <?php echo htmlspecialchars($resident['employed']); ?>
                                                            </span>
                                                        </td>
                                                        <td data-label="Job Title"><?php echo htmlspecialchars(isset($resident['job_title']) ? $resident['job_title'] : 'Not specified'); ?></td>
                                                        <td data-label="Preferred Job"><?php echo htmlspecialchars($resident['preferred_job']); ?></td>
                                                        <td data-label="Barangay"><?php echo htmlspecialchars($resident['barangay_name'] ?: 'Not specified'); ?></td>
                                                        <td data-label="Email"><?php echo htmlspecialchars($resident['email']); ?></td>
                                                        <td data-label="Notifications" data-sort="<?php echo $resident['total_notifications']; ?>"><?php echo $resident['total_notifications']; ?></td>
                                                        <td data-label="Accepted Jobs" data-sort="<?php echo $resident['accepted_jobs']; ?>"><?php echo $resident['accepted_jobs']; ?></td>
                                                        <td data-label="Registration Date" data-sort="<?php echo strtotime($resident['created_at']); ?>"><?php echo date('M j, Y', strtotime($resident['created_at'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div style="margin-bottom: 1.5rem;"></div>

                    <?php elseif ($report_type === 'jobs'): ?>

                        <!-- Jobs Report -->

                        <div style="margin-top: 1.5rem;">
                            <div class="dashboard-card">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                    <h3>Jobs Report (<?php echo count($jobs_data); ?> jobs)</h3>
                                    <div class="report-summary">
                                        <span class="summary-item">Period: <?php echo date('M j', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date)); ?></span>
                                    </div>
                                </div>

                                <div class="table-container">
                                    <div class="table-responsive">
                                        <table id="jobsReportTable" class="auto-sort" data-exclude-columns='[]'>
                                            <thead>
                                                <tr>
                                                    <th>Job Title</th>
                                                    <th>Company</th>
                                                    <th>Type</th>
                                                    <th>Status</th>
                                                    <th>Location</th>
                                                    <th>Notifications Sent</th>
                                                    <th>Accepted</th>
                                                    <th>Declined</th>
                                                    <th>Posted Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($jobs_data)): ?>
                                                    <tr>
                                                        <td colspan="9" style="text-align: center;">No jobs found for the selected period</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($jobs_data as $job): ?>
                                                        <tr>
                                                            <td data-label="Job Title"><?php echo htmlspecialchars($job['title']); ?></td>
                                                            <td data-label="Company"><?php echo htmlspecialchars($job['company']); ?></td>
                                                            <td data-label="Type"><?php echo htmlspecialchars($job['job_type']); ?></td>
                                                            <td data-label="Status">
                                                                <span class="status-badge status-<?php echo strtolower($job['status']); ?>">
                                                                    <?php echo htmlspecialchars($job['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td data-label="Location"><?php echo htmlspecialchars($job['location'] ?: $job['barangay_name'] ?: 'Any location'); ?></td>
                                                            <td data-label="Notifications Sent" data-sort="<?php echo $job['total_notifications']; ?>"><?php echo $job['total_notifications']; ?></td>
                                                            <td data-label="Accepted" class="text-success" data-sort="<?php echo $job['accepted_count']; ?>"><?php echo $job['accepted_count']; ?></td>
                                                            <td data-label="Declined" class="text-danger" data-sort="<?php echo $job['declined_count']; ?>"><?php echo $job['declined_count']; ?></td>
                                                            <td data-label="Posted Date" data-sort="<?php echo strtotime($job['created_at']); ?>"><?php echo date('M j, Y', strtotime($job['created_at'])); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div style="margin-bottom: 1.5rem;">

                            <?php elseif ($report_type === 'notifications'): ?>

                                <!-- Notifications Report -->
                                <div style="margin-top: 1.5rem;">
                                    <div class="dashboard-card">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                            <h3>Job Notifications Report (<?php echo count($notifications_data); ?> notifications)</h3>
                                            <div class="report-summary">
                                                <span class="summary-item">Period: <?php echo date('M j', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date)); ?></span>
                                            </div>
                                        </div>

                                        <div class="table-container">
                                            <div class="table-responsive">
                                                <table id="notificationsReportTable" class="auto-sort" data-exclude-columns='[]'>
                                                    <thead>
                                                        <tr>
                                                            <th>Resident</th>
                                                            <th>Job Title</th>
                                                            <th>Company</th>
                                                            <th>Status</th>
                                                            <th>Sent Date</th>
                                                            <th>Response Date</th>
                                                            <th>Response Time</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($notifications_data)): ?>
                                                            <tr>
                                                                <td colspan="7" style="text-align: center;">No notifications found for the selected period</td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <?php foreach ($notifications_data as $notification): ?>
                                                                <tr>
                                                                    <td data-label="Resident"><?php echo htmlspecialchars($notification['first_name'] . ' ' . ($notification['middle_name'] ? $notification['middle_name'] . ' ' : '') . $notification['last_name']); ?></td>
                                                                    <td data-label="Job Title"><?php echo htmlspecialchars($notification['job_title']); ?></td>
                                                                    <td data-label="Company"><?php echo htmlspecialchars($notification['company']); ?></td>
                                                                    <td data-label="Status">
                                                                        <span class="status-badge status-<?php echo $notification['status']; ?>">
                                                                            <?php echo ucfirst($notification['status']); ?>
                                                                        </span>
                                                                    </td>
                                                                    <td data-label="Sent Date" data-sort="<?php echo strtotime($notification['created_at']); ?>"><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></td>
                                                                    <td data-label="Response Date" data-sort="<?php echo ($notification['updated_at'] != $notification['created_at']) ? strtotime($notification['updated_at']) : '0'; ?>">
                                                                        <?php if ($notification['updated_at'] != $notification['created_at']): ?>
                                                                            <?php echo date('M j, Y g:i A', strtotime($notification['updated_at'])); ?>
                                                                        <?php else: ?>
                                                                            -
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td data-label="Response Time" data-sort="<?php if ($notification['updated_at'] != $notification['created_at']): $sent_time = strtotime($notification['created_at']); $response_time = strtotime($notification['updated_at']); echo round(($response_time - $sent_time) / 3600, 1); else: echo '0'; endif; ?>">
                                                                        <?php if ($notification['updated_at'] != $notification['created_at']): ?>
                                                                            <?php
                                                                            $sent_time = strtotime($notification['created_at']);
                                                                            $response_time = strtotime($notification['updated_at']);
                                                                            $diff_hours = round(($response_time - $sent_time) / 3600, 1);
                                                                            echo $diff_hours . ' hours';
                                                                            ?>
                                                                        <?php else: ?>
                                                                            -
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="margin-bottom: 1.5rem;">

                                    <?php endif; ?>
                                    </div>

                                    <script>
                                        function performSearch() {
                                            const searchInput = document.getElementById('searchInput');
                                            const searchValue = searchInput.value.trim();
                                            const currentUrl = new URL(window.location);
                                            
                                            if (searchValue) {
                                                currentUrl.searchParams.set('search', searchValue);
                                            } else {
                                                currentUrl.searchParams.delete('search');
                                            }
                                            
                                            window.location.href = currentUrl.toString();
                                        }

                                        function clearSearch() {
                                            const currentUrl = new URL(window.location);
                                            currentUrl.searchParams.delete('search');
                                            window.location.href = currentUrl.toString();
                                        }

                                        function handleSearchKeyPress(event) {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                performSearch();
                                            }
                                        }

                                        function exportReport(format) {
                                            const currentUrl = new URL(window.location);
                                            currentUrl.searchParams.set('export', format);
                                            window.open(currentUrl.toString(), '_blank');
                                        }

                                        function printReport() {
                                            window.print();
                                        }

                                        // Initialize charts for overview report
                                        <?php if ($report_type === 'overview'): ?>

                                            // Employed Chart
                                            const employmentCtx = document.getElementById('employmentChart').getContext('2d');
                                            new Chart(employmentCtx, {
                                                type: 'doughnut',
                                                data: {
                                                    labels: [<?php echo implode(',', array_map(function ($item) {
                                                                    return '"' . $item['employed'] . '"';
                                                                }, $employment_distribution)); ?>],
                                                    datasets: [{
                                                        data: [<?php echo implode(',', array_column($employment_distribution, 'count')); ?>],
                                                        backgroundColor: [
                                                            '#667eea',
                                                            '#764ba2',
                                                            '#f093fb',
                                                            '#f5576c',
                                                            '#4facfe',
                                                            '#00f2fe'
                                                        ]
                                                    }]
                                                },
                                                options: {
                                                    responsive: true,
                                                    maintainAspectRatio: false,
                                                    plugins: {
                                                        legend: {
                                                            position: 'bottom'
                                                        }
                                                    }
                                                }
                                            });

                                            // Job Type Chart
                                            const jobTypeCtx = document.getElementById('jobTypeChart').getContext('2d');
                                            new Chart(jobTypeCtx, {
                                                type: 'bar',
                                                data: {
                                                    labels: [<?php echo implode(',', array_map(function ($item) {
                                                                    return '"' . $item['job_type'] . '"';
                                                                }, $job_type_distribution)); ?>],
                                                    datasets: [{
                                                        label: 'Number of Jobs',
                                                        data: [<?php echo implode(',', array_column($job_type_distribution, 'count')); ?>],
                                                        backgroundColor: '#667eea'
                                                    }]
                                                },
                                                options: {
                                                    responsive: true,
                                                    maintainAspectRatio: false,
                                                    plugins: {
                                                        legend: {
                                                            display: false
                                                        }
                                                    },
                                                    scales: {
                                                        y: {
                                                            beginAtZero: true
                                                        }
                                                    }
                                                }
                                            });

                                            // Registration Trends Chart
                                            const trendsCtx = document.getElementById('trendsChart').getContext('2d');
                                            new Chart(trendsCtx, {
                                                type: 'line',
                                                data: {
                                                    labels: [<?php echo implode(',', array_map(function ($item) {
                                                                    return '"' . date('M Y', strtotime($item['month'] . '-01')) . '"';
                                                                }, $registration_trends)); ?>],
                                                    datasets: [{
                                                        label: 'New Registrations',
                                                        data: [<?php echo implode(',', array_column($registration_trends, 'registrations')); ?>],
                                                        borderColor: '#667eea',
                                                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                                        fill: true,
                                                        tension: 0.4
                                                    }]
                                                },
                                                options: {
                                                    responsive: true,
                                                    maintainAspectRatio: false,
                                                    plugins: {
                                                        legend: {
                                                            display: false
                                                        }
                                                    },
                                                    scales: {
                                                        y: {
                                                            beginAtZero: true
                                                        }
                                                    }
                                                }
                                            });

                                        <?php endif; ?>

                                        // Hamburger Navigation Functionality
                                        const sidebarToggle = document.getElementById('sidebarToggle');
                                        const sidebar = document.getElementById('sidebar');
                                        const sidebarOverlay = document.getElementById('sidebarOverlay');
                                        const mainContent = document.getElementById('mainContent');

                                        function toggleSidebar() {
                                            sidebarToggle.classList.toggle('active');
                                            sidebar.classList.toggle('mobile-active');
                                            sidebarOverlay.classList.toggle('active');
                                            document.body.classList.toggle('sidebar-mobile-open');
                                        }

                                        function closeSidebar() {
                                            sidebarToggle.classList.remove('active');
                                            sidebar.classList.remove('mobile-active');
                                            sidebarOverlay.classList.remove('active');
                                            document.body.classList.remove('sidebar-mobile-open');
                                        }

                                        // Event listeners
                                        if (sidebarToggle) {
                                            sidebarToggle.addEventListener('click', toggleSidebar);
                                        }

                                        if (sidebarOverlay) {
                                            sidebarOverlay.addEventListener('click', closeSidebar);
                                        }

                                        // Close sidebar when clicking on nav links (mobile only)
                                        const sidebarLinks = document.querySelectorAll('.sidebar-nav a');
                                        sidebarLinks.forEach(link => {
                                            link.addEventListener('click', () => {
                                                if (window.innerWidth <= 768) {
                                                    closeSidebar();
                                                }
                                            });
                                        });

                                        // Handle window resize
                                        window.addEventListener('resize', () => {
                                            if (window.innerWidth > 768) {
                                                closeSidebar();
                                            }
                                        });

                                        // Close with Escape key
                                        document.addEventListener('keydown', (e) => {
                                            if (e.key === 'Escape' && sidebar.classList.contains('mobile-active')) {
                                                closeSidebar();
                                            }
                                        });
                                    </script>
                                </div>
                            </div>
                        </div>
</body>

<script>
// Admin profile dropdown functionality
const adminProfilePic = document.getElementById('adminProfilePic');
const adminProfileDropdown = document.getElementById('adminProfileDropdown');
if (adminProfilePic && adminProfileDropdown) {
    adminProfilePic.addEventListener('click', function(e) {
        e.stopPropagation();
        adminProfileDropdown.style.display = adminProfileDropdown.style.display === 'block' ? 'none' : 'block';
    });
    document.addEventListener('click', function(e) {
        if (!adminProfileDropdown.contains(e.target) && e.target !== adminProfilePic) {
            adminProfileDropdown.style.display = 'none';
        }
    });
}
</script>

<script src="../assets/js/table-sort.js"></script>
</html>