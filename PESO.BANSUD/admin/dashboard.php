<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Get admin profile
$admin_id = $_SESSION['user_id'];
$admin_query = "SELECT * FROM admins WHERE id = :id";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->bindParam(':id', $admin_id);
$admin_stmt->execute();
$admin_profile = $admin_stmt->fetch(PDO::FETCH_ASSOC);

$admin_message = '';
$admin_error = '';

// Handle profile picture upload
if (isset($_POST['upload_admin_pic']) && isset($_FILES['admin_profile_picture']) && $_FILES['admin_profile_picture']['error'] == UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['admin_profile_picture']['tmp_name'];
    $fileName = $_FILES['admin_profile_picture']['name'];
    $fileNameCmps = explode('.', $fileName);
    $fileExtension = strtolower(end($fileNameCmps));
    $allowedfileExtensions = array('jpg', 'jpeg', 'png', 'gif');
    if (in_array($fileExtension, $allowedfileExtensions)) {
        $newFileName = 'admin_' . $admin_id . '_' . time() . '.' . $fileExtension;
        $uploadFileDir = '../images/';
        $dest_path = $uploadFileDir . $newFileName;
        if(move_uploaded_file($fileTmpPath, $dest_path)) {
            // Update admin profile picture in DB
            $update_query = "UPDATE admins SET profile_picture = :profile_picture WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':profile_picture', $newFileName);
            $update_stmt->bindParam(':id', $admin_id);
            if ($update_stmt->execute()) {
                $admin_message = 'Profile picture updated!';
                $admin_profile['profile_picture'] = $newFileName;
            } else {
                $admin_error = 'Failed to update profile picture.';
            }
        } else {
            $admin_error = 'Error uploading file.';
        }
    } else {
        $admin_error = 'Invalid file type. Only jpg, jpeg, png, gif allowed.';
    }
}

// Get statistics
$stats = [];

// Total residents
$query = "SELECT COUNT(*) as count FROM residents";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['total_residents'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total jobs
$query = "SELECT COUNT(*) as count FROM jobs WHERE status = 'Active'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['active_jobs'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending job notifications
$query = "SELECT COUNT(*) as count FROM job_notifications WHERE status = 'pending'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['pending_notifications'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

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

// Recent residents (last 5)
$query = "SELECT r.*, b.name as barangay_name FROM residents r 
          LEFT JOIN barangays b ON r.barangay_id = b.id 
          WHERE r.requirements_completed = 1
          ORDER BY r.created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$recent_residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent jobs (last 5)
$query = "SELECT j.*, b.name as barangay_name FROM jobs j 
          LEFT JOIN barangays b ON j.barangay_id = b.id 
          ORDER BY j.created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$recent_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Chart data for dashboard
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

// Get job type distribution (last 30 days)
$job_type_query = "SELECT job_type, COUNT(*) as count 
                  FROM jobs 
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  GROUP BY job_type 
                  ORDER BY count DESC";
$job_type_stmt = $db->prepare($job_type_query);
$job_type_stmt->execute();
$job_type_distribution = $job_type_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly registration trends (last 6 months for dashboard)
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin/adashboardbadge.css ">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="/assets/css/admin/card_icon.css">
    <link rel="stylesheet" href="../assets/css/table-sort.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                    <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
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
                    <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                    <h1> <i class="fas fa-tachometer-alt"></i> Dashboard</h1>
                    <div class="user-info">
                        <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        <div class="profile-dropdown">
                            <img src="<?php echo !empty($admin_profile['profile_picture']) ? '../images/' . htmlspecialchars($admin_profile['profile_picture']) : '../images/PesoLogo.jpg'; ?>" class="profile-pic" id="adminProfilePic" alt="Profile Picture">
                            <div class="dropdown-content" id="adminProfileDropdown">
                                <a href="settings.php" class="dropdown-btn"><i class="fas fa-user-edit"></i> Update Profile</a>
                                <a href="../auth/logout.php" class="dropdown-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                    <?php if ($admin_message): ?>
                        <div class="success-message" style="margin-top:10px;"> <?php echo htmlspecialchars($admin_message); ?> </div>
                    <?php endif; ?>
                    <?php if ($admin_error): ?>
                        <div class="error-message" style="margin-top:10px;"> <?php echo htmlspecialchars($admin_error); ?> </div>
                    <?php endif; ?>
            </div>

            <div class="dashboard-container">

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <a href="residents.php" class="stat-card clickable-card">
                <div class="stat-number"><?php echo $stats['total_residents']; ?></div>
                <div class="stat-label">Total Residents</div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </a>
            <a href="jobs.php" class="stat-card clickable-card">
                <div class="stat-number"><?php echo $stats['active_jobs']; ?></div>
                <div class="stat-label">Active Jobs</div>
                <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
            </a>
            <a href="notifications.php" class="stat-card clickable-card">
                <div class="stat-number"><?php echo $stats['pending_notifications']; ?></div>
                <div class="stat-label">Pending Notifications</div>
                <div class="stat-icon"><i class="fas fa-bell"></i></div>
            </a>
            <a href="messages.php" class="stat-card clickable-card">
                <div class="stat-number"><?php echo $stats['unread_messages']; ?></div>
                <div class="stat-label">Unread Messages</div>
                <div class="stat-icon"><i class="fas fa-envelope"></i></div>
            </a>
            <a href="pending-accounts.php" class="stat-card clickable-card">
                <div class="stat-number"><?php echo $stats['pending_accounts']; ?></div>
                <div class="stat-label">Pending Accounts</div>
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
            </a>
        </div>

        <!-- Charts Section -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
            <!-- Employed Distribution -->
            <div class="dashboard-card">
                <h3><i class="fas fa-chart-pie"></i> Employed Distribution</h3>
                <div class="chart-container" style="height: 300px; position: relative;">
                    <canvas id="employmentChart"></canvas>
                </div>
            </div>

            <!-- Job Type Distribution -->
            <div class="dashboard-card">
                <h3><i class="fas fa-chart-bar"></i> Job Types (Last 30 Days)</h3>
                <div class="chart-container" style="height: 300px; position: relative;">
                    <canvas id="jobTypeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Registration Trends -->
        <div class="dashboard-card" style="margin-bottom: 1.5rem;">
            <h3><i class="fas fa-chart-line"></i> Registration Trends (Last 6 Months)</h3>
            <div class="chart-container" style="height: 300px; position: relative;">
                <canvas id="trendsChart"></canvas>
            </div>
        </div>

        <!-- Dashboard Content -->
        <div class="dashboard-grid">

            <!-- Recent Residents -->
            <div class="dashboard-card">
                <h3>Recent Residents Added</h3>
                <?php if (empty($recent_residents)): ?>
                    <p>No residents registered yet.</p>
                <?php else: ?>
                    <div style="position: relative; height: 300px; margin-bottom: 1rem;">
                        <canvas id="recentResidentsChart"></canvas>
                    </div>
                    <div style="margin-top: 1rem;">
                        <a href="residents.php" class="btn-secondary">View All Residents</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Jobs -->
            <div class="dashboard-card">
                <h3>Recent Jobs Added</h3>
                <?php if (empty($recent_jobs)): ?>
                    <p>No jobs posted yet.</p>
                    <div style="margin-top: 1rem;">
                        <a href="jobs.php?action=add" class="btn-primary">Post First Job</a>
                    </div>
                <?php else: ?>
                    <div style="position: relative; height: 300px; margin-bottom: 1rem;">
                        <canvas id="recentJobsChart"></canvas>
                    </div>
                    <div style="margin-top: 1rem;">
                        <a href="jobs.php" class="btn-secondary">View All Jobs</a>
                        <a href="jobs.php?action=add" class="btn-primary">Post New Job</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>


        <!-- Quick Actions -->
        <div class="dashboard-card">
            <h3>Quick Actions</h3>
            <div class="dashboard-grid">
                <div>
                    <h4>Resident Management</h4>
                    <p>Manage resident profiles and registrations</p>
                    <a href="residents.php?action=add" class="btn-primary">Add New Resident</a>
                </div>
                <div>
                    <h4>Job Management</h4>
                    <p>Post new jobs and manage existing ones</p>
                    <a href="jobs.php?action=add" class="btn-primary">Post New Job</a>
                </div>
                <div>
                    <h4>Communication</h4>
                    <p>Send messages and notifications to residents</p>
                    <a href="messages.php?action=compose" class="btn-primary">Send Message</a>
                </div>
                <div>
                    <h4>Reports</h4>
                    <p>View employment statistics and reports</p>
                    <a href="reports.php" class="btn-primary">View Reports</a>
                </div>
            </div>
        </div>
            </div>
        </div>
    </div>

    <script>
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

    <script>
    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
        // Employed Chart
        const employmentCtx = document.getElementById('employmentChart').getContext('2d');
        new Chart(employmentCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php echo implode(',', array_map(function ($item) {
                                return '"' . addslashes($item['employed']) . '"';
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
                                return '"' . addslashes($item['job_type']) . '"';
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
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
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
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    });
    </script>

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

<?php if (!empty($recent_residents)): ?>
// Recent Residents Chart
const residentsCtx = document.getElementById('recentResidentsChart');
if (residentsCtx) {
    // Group residents by registration date
    const residentsData = <?php 
        $dates = [];
        $counts = [];
        $grouped = [];
        foreach ($recent_residents as $resident) {
            $date = date('M j', strtotime($resident['created_at']));
            if (!isset($grouped[$date])) {
                $grouped[$date] = 0;
            }
            $grouped[$date]++;
        }
        echo json_encode(['dates' => array_keys($grouped), 'counts' => array_values($grouped)]);
    ?>;
    
    new Chart(residentsCtx, {
        type: 'bar',
        data: {
            labels: residentsData.dates,
            datasets: [{
                label: 'Residents Registered',
                data: residentsData.counts,
                backgroundColor: '#667eea',
                borderColor: '#764ba2',
                borderWidth: 1,
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                title: {
                    display: true,
                    text: 'Daily Registration Activity',
                    font: {
                        size: 14,
                        weight: 'bold'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
<?php endif; ?>

<?php if (!empty($recent_jobs)): ?>
// Recent Jobs Chart
const jobsCtx = document.getElementById('recentJobsChart');
if (jobsCtx) {
    // Group jobs by posted date
    const jobsData = <?php 
        $job_dates = [];
        $job_counts = [];
        $job_grouped = [];
        foreach ($recent_jobs as $job) {
            $date = date('M j', strtotime($job['created_at']));
            if (!isset($job_grouped[$date])) {
                $job_grouped[$date] = 0;
            }
            $job_grouped[$date]++;
        }
        echo json_encode(['dates' => array_keys($job_grouped), 'counts' => array_values($job_grouped)]);
    ?>;
    
    new Chart(jobsCtx, {
        type: 'line',
        data: {
            labels: jobsData.dates,
            datasets: [{
                label: 'Jobs Posted',
                data: jobsData.counts,
                borderColor: '#4facfe',
                backgroundColor: 'rgba(79, 172, 254, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#4facfe',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 6,
                pointHoverRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                title: {
                    display: true,
                    text: 'Job Posting Trend',
                    font: {
                        size: 14,
                        weight: 'bold'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            elements: {
                point: {
                    hoverBackgroundColor: '#4facfe'
                }
            }
        }
    });
}
<?php endif; ?>
</script>

<script src="../assets/js/table-sort.js"></script>
</html>