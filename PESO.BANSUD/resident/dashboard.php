<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireResident();

$database = new Database();
$db = $database->getConnection();

$resident_id = $_SESSION['user_id'];

// Check if requirements are completed
$requirements_completed = $_SESSION['requirements_completed'] ?? 0;

// Get requirements list from database
try {
    $requirements_query = "SELECT requirement_type, status, file_path FROM resident_requirements WHERE resident_id = :resident_id";
    $requirements_stmt = $db->prepare($requirements_query);
    $requirements_stmt->bindParam(':resident_id', $resident_id);
    $requirements_stmt->execute();
    $requirements_list = $requirements_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet, set empty requirements list
    $requirements_list = [];
}

// Convert to associative array for easier checking
$requirements_status = [];
foreach ($requirements_list as $req) {
    $requirements_status[$req['requirement_type']] = $req;
}

// Define required requirements
$required_requirements = ['resume'];

// Check if all requirements are completed
$requirements_completed = true;
$missing_requirements = [];

foreach ($required_requirements as $req_type) {
    if (!isset($requirements_status[$req_type]) || 
        !in_array($requirements_status[$req_type]['status'], ['approved'])) {
        $requirements_completed = false;
        $missing_requirements[] = ucfirst(str_replace('_', ' ', $req_type));
    }
}

// Update session
$_SESSION['requirements_completed'] = $requirements_completed ? 1 : 0;

// Get resident profile
$profile_query = "SELECT r.*, b.name as barangay_name FROM residents r 
                  LEFT JOIN barangays b ON r.barangay_id = b.id 
                  WHERE r.id = :id";
$profile_stmt = $db->prepare($profile_query);
$profile_stmt->bindParam(':id', $resident_id);
$profile_stmt->execute();
$profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];

// Total available jobs
$query = "SELECT COUNT(*) as count FROM jobs WHERE status = 'Active'";
$stmt = $db->prepare($query);
$stmt->execute();
$stats['available_jobs'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Job notifications received
$query = "SELECT COUNT(*) as count FROM job_notifications WHERE resident_id = :resident_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':resident_id', $resident_id);
$stmt->execute();
$stats['job_notifications'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Unread messages
$query = "SELECT COUNT(*) as count FROM messages WHERE receiver_type = 'resident' AND receiver_id = :resident_id AND is_read = FALSE";
$stmt = $db->prepare($query);
$stmt->bindParam(':resident_id', $resident_id);
$stmt->execute();
$stats['unread_messages'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending job responses
$query = "SELECT COUNT(*) as count FROM job_notifications WHERE resident_id = :resident_id AND status = 'sent'";
$stmt = $db->prepare($query);
$stmt->bindParam(':resident_id', $resident_id);
$stmt->execute();
$stats['pending_responses'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Recent job notifications (last 5)
$query = "SELECT jn.*, j.title, j.company, j.job_type, j.location FROM job_notifications jn 
          JOIN jobs j ON jn.job_id = j.id 
          WHERE jn.resident_id = :resident_id 
          ORDER BY jn.created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(':resident_id', $resident_id);
$stmt->execute();
$recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent messages (last 5)
$query = "SELECT * FROM messages WHERE receiver_type = 'resident' AND receiver_id = :resident_id 
          ORDER BY created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(':resident_id', $resident_id);
$stmt->execute();
$recent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Dashboard - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/resident/rdashboard.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="/assets/css/resident/requirements-sidebar.css">
    <link rel="stylesheet" href="../assets/css/resident/card_icon.css">
    <link rel="stylesheet" href="../assets/css/resident/complete_requirements.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
       
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
                <h2><i class="fas fa-user-circle"></i> JobMatch</h2>
                <span class="resident-label">Resident Portal</span>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <?php if ($requirements_completed == 0): ?>
                        <!-- Show only essential links when requirements not completed -->
                        <li><a href="dashboard.php" class="active disabled-partial"><i class="fas fa-tachometer-alt"></i> Dashboard <span class="warning-badge">Limited Access</span></a></li>
                        <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                        <li><a href="requirements.php" class="highlight"><i class="fas fa-file-upload"></i> Complete Requirements <span class="badge required">Required</span></a></li>
                        <li><a href="jobs.php" class="disabled" onclick="return false;"><i class="fas fa-briefcase"></i> Jobs <span class="locked-icon"><i class="fas fa-lock"></i></span></a></li>
                        <li><a href="notifications.php" class="disabled" onclick="return false;"><i class="fas fa-bell"></i> Job Notifications <span class="locked-icon"><i class="fas fa-lock"></i></span></a></li>
                        <li><a href="messages.php" class="disabled" onclick="return false;"><i class="fas fa-envelope"></i> Messages <span class="locked-icon"><i class="fas fa-lock"></i></span></a></li>
                        <li><a href="settings.php" class="disabled" onclick="return false;"><i class="fas fa-cog"></i> Settings <span class="locked-icon"><i class="fas fa-lock"></i></span></a></li>
                    <?php else: ?>
                        <!-- Show all links when requirements completed -->
                        <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                        <li><a href="requirements.php"><i class="fas fa-file-upload"></i> Complete Requirements <span class="badge completed"><i class="fas fa-check"></i></span></a></li>
                        <li><a href="jobs.php"><i class="fas fa-briefcase"></i> Jobs</a></li>
                        <li>
                            <a href="notifications.php">
                                <i class="fas fa-bell"></i> Job Notifications
                                <?php if($stats['pending_responses'] > 0): ?>
                                    <span class="notification-badge"><?php echo $stats['pending_responses']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li>
                            <a href="messages.php">
                                <i class="fas fa-envelope"></i> Messages
                                <?php if($stats['unread_messages'] > 0): ?>
                                    <span class="notification-badge"><?php echo $stats['unread_messages']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <div class="profile-dropdown">
                        <img src="<?php echo !empty($profile['profile_picture']) ? '../images/' . htmlspecialchars($profile['profile_picture']) : '../images/PesoLogo.jpg'; ?>" class="profile-pic" id="profilePic" alt="Profile Picture">
                        <div class="dropdown-content" id="profileDropdown">
                            <a href="profile.php" class="dropdown-btn"><i class="fas fa-user-edit"></i> Update Profile</a>
                            <a href="../auth/logout.php" class="dropdown-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-container">

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card <?php echo $requirements_completed == 0 ? 'stat-card-disabled' : 'clickable-card'; ?>" 
                 <?php echo $requirements_completed == 0 ? 'onclick="showRequirementsAlert()"' : 'onclick="window.location.href=\'jobs.php\'"'; ?>>
                <i class="fas fa-briefcase stat-icon"></i>
                <div class="stat-number"><?php echo $stats['available_jobs']; ?></div>
                <div class="stat-label">Available Jobs</div>
                <?php if ($requirements_completed == 0): ?>
                    <div class="lock-overlay"><i class="fas fa-lock"></i></div>
                <?php endif; ?>
            </div>
            <div class="stat-card <?php echo $requirements_completed == 0 ? 'stat-card-disabled' : 'clickable-card'; ?>" 
                 <?php echo $requirements_completed == 0 ? 'onclick="showRequirementsAlert()"' : 'onclick="window.location.href=\'notifications.php\'"'; ?>>
                <i class="fas fa-bell stat-icon"></i>
                <div class="stat-number"><?php echo $stats['job_notifications']; ?></div>
                <div class="stat-label">Job Notifications</div>
                <?php if ($requirements_completed == 0): ?>
                    <div class="lock-overlay"><i class="fas fa-lock"></i></div>
                <?php endif; ?>
            </div>
            <div class="stat-card <?php echo $requirements_completed == 0 ? 'stat-card-disabled' : 'clickable-card'; ?>" 
                 <?php echo $requirements_completed == 0 ? 'onclick="showRequirementsAlert()"' : 'onclick="window.location.href=\'messages.php\'"'; ?>>
                <i class="fas fa-envelope stat-icon"></i>
                <div class="stat-number"><?php echo $stats['unread_messages']; ?></div>
                <div class="stat-label">Unread Messages</div>
                <?php if ($requirements_completed == 0): ?>
                    <div class="lock-overlay"><i class="fas fa-lock"></i></div>
                <?php endif; ?>
            </div>
            <div class="stat-card <?php echo $requirements_completed == 0 ? 'stat-card-disabled' : 'clickable-card'; ?>" 
                 <?php echo $requirements_completed == 0 ? 'onclick="showRequirementsAlert()"' : 'onclick="window.location.href=\'notifications.php\'"'; ?>>
                <i class="fas fa-clock stat-icon"></i>
                <div class="stat-number"><?php echo $stats['pending_responses']; ?></div>
                <div class="stat-label">Pending Responses</div>
                <?php if ($requirements_completed == 0): ?>
                    <div class="lock-overlay"><i class="fas fa-lock"></i></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Summary -->
        <div class="dashboard-card">
            <h3>Profile Summary</h3>
            <div class="profile-summary">
                <div class="profile-info">
                    <div class="info-row">
                        <strong>Name:</strong> <?php echo htmlspecialchars($profile['first_name'] . ' ' . ($profile['middle_name'] ? $profile['middle_name'] . ' ' : '') . $profile['last_name']); ?>
                    </div>
                    <div class="info-row">
                        <strong>Age:</strong> <?php echo htmlspecialchars($profile['age']); ?>
                    </div>
                    <div class="info-row">
                        <strong>Employed:</strong> 
                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $profile['employed'])); ?>">
                            <?php echo htmlspecialchars($profile['employed']); ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <strong>Barangay:</strong> <?php echo htmlspecialchars($profile['barangay_name']); ?>
                    </div>
                    <div class="info-row">
                        <strong>Job Title:</strong> <?php echo htmlspecialchars($profile['job_title'] ?: 'Not specified'); ?>
                    </div>
                    <div class="info-row">
                        <strong>Education:</strong> <?php echo htmlspecialchars($profile['educational_attainment'] ?: 'Not specified'); ?>
                    </div>
                </div>
                <div class="profile-actions">
                    <a href="profile.php" class="btn-primary">Update Profile</a>
                </div>
            </div>
        </div>
        

        <!-- Dashboard Content -->
        <div style="margin-bottom: 1.5rem;"></div>
        
        <div class="dashboard-grid">
            
            <!-- Recent Job Notifications -->
            <div class="dashboard-card">
                <h3>Recent Job Notifications</h3>
                <?php if (empty($recent_notifications)): ?>
                    <p>No job notifications yet.</p>
                    <div style="margin-top: 1rem;">
                        <a href="jobs.php" class="btn-secondary">Browse Available Jobs</a>
                    </div>
                <?php else: ?>
                    <div class="notifications-list">
                        <?php foreach ($recent_notifications as $notification): ?>
                        <div class="notification-item">
                            <div class="notification-header">
                                <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                                <span class="notification-status status-<?php echo $notification['status']; ?>">
                                    <?php echo ucfirst($notification['status']); ?>
                                </span>
                            </div>
                            <div class="notification-details">
                                <p><strong>Company:</strong> <?php echo htmlspecialchars($notification['company']); ?></p>
                                <p><strong>Type:</strong> <?php echo htmlspecialchars($notification['job_type']); ?></p>
                                <p><strong>Location:</strong> <?php echo htmlspecialchars($notification['location'] ?: 'Not specified'); ?></p>
                                <p><strong>Received:</strong> <?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></p>
                            </div>
                            <?php if ($notification['status'] === 'sent'): ?>
                            <div class="notification-actions">
                                <a href="notifications.php?action=respond&id=<?php echo $notification['id']; ?>" class="btn-primary btn-small">Respond</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: 1rem;">
                        <a href="notifications.php" class="btn-secondary">View All Notifications</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Messages -->
            <div class="dashboard-card">
                <h3>Recent Messages</h3>
                <?php if (empty($recent_messages)): ?>
                    <p>No messages yet.</p>
                    <div style="margin-top: 1rem;">
                        <a href="messages.php?action=compose" class="btn-secondary">Send Message to Admin</a>
                    </div>
                <?php else: ?>
                    <div class="messages-list">
                        <?php foreach ($recent_messages as $message): ?>
                        <div class="message-item <?php echo $message['is_read'] ? '' : 'unread'; ?>">
                            <div class="message-header">
                                <strong>From Admin</strong>
                                <span class="message-time"><?php echo date('M j, Y g:i A', strtotime($message['created_at'])); ?></span>
                            </div>
                            <div class="message-content">
                                <?php echo nl2br(htmlspecialchars(substr($message['message'], 0, 100))); ?>
                                <?php if (strlen($message['message']) > 100): ?>...<?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: 1rem;">
                        <a href="messages.php" class="btn-secondary">View All Messages</a>
                        <a href="messages.php?action=compose" class="btn-primary">Send Message</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="dashboard-card">
            <h3>Quick Actions</h3>
            <div class="dashboard-grid">
                <div>
                    <h4>Profile Management</h4>
                    <p>Keep your profile updated for better job matching</p>
                    <a href="profile.php" class="btn-primary">Update Profile</a>
                </div>
                <div>
                    <h4>Job Search</h4>
                    <p>Browse available job opportunities</p>
                    <?php if ($requirements_completed == 0): ?>
                        <button class="btn-primary btn-disabled" onclick="showRequirementsAlert()">
                            <i class="fas fa-lock"></i> Browse Jobs
                        </button>
                    <?php else: ?>
                        <a href="jobs.php" class="btn-primary">Browse Jobs</a>
                    <?php endif; ?>
                </div>
                <div>
                    <h4>Communication</h4>
                    <p>Stay in touch with PESO admin</p>
                    <?php if ($requirements_completed == 0): ?>
                        <button class="btn-primary btn-disabled" onclick="showRequirementsAlert()">
                            <i class="fas fa-lock"></i> Send Message
                        </button>
                    <?php else: ?>
                        <a href="messages.php?action=compose" class="btn-primary">Send Message</a>
                    <?php endif; ?>
                </div>
                <div>
                    <h4>Account Settings</h4>
                    <p>Manage your account preferences</p>
                    <?php if ($requirements_completed == 0): ?>
                        <button class="btn-primary btn-disabled" onclick="showRequirementsAlert()">
                            <i class="fas fa-lock"></i> Settings
                        </button>
                    <?php else: ?>
                        <a href="settings.php" class="btn-primary">Settings</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
            </div>
        </div>
    </div>

    <script>
    // Show requirements alert for disabled features
    function showRequirementsAlert() {
        const missingRequirements = <?php echo json_encode($missing_requirements); ?>;
        let requirementsList = '';
        
        missingRequirements.forEach(req => {
            requirementsList += `<li>${req}</li>`;
        });

        const alertPopup = document.createElement('div');
        alertPopup.className = 'requirements-alert-overlay';
        alertPopup.innerHTML = `
            <div class="requirements-alert">
                <div class="requirements-alert-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Requirements Not Complete</h3>
                </div>
                <div class="requirements-alert-body">
                    <p><strong>This feature is currently locked.</strong></p>
                    <p>You need to complete your requirements first to access this feature.</p>
                    <p><strong>Missing Documents:</strong></p>
                    <ul>${requirementsList}</ul>
                </div>
                <div class="requirements-alert-footer">
                    <a href="requirements.php" class="popup-btn popup-btn-primary">
                        <i class="fas fa-upload"></i> Complete Requirements
                    </a>
                    <button onclick="closeRequirementsAlert()" class="popup-btn popup-btn-secondary">
                        Cancel
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(alertPopup);
        document.body.style.overflow = 'hidden';
    }

    function closeRequirementsAlert() {
        const alert = document.querySelector('.requirements-alert-overlay');
        if (alert) {
            alert.remove();
            document.body.style.overflow = 'auto';
        }
    }

    // Profile dropdown functionality
    const profilePic = document.getElementById('profilePic');
    const profileDropdown = document.getElementById('profileDropdown');
    if (profilePic && profileDropdown) {
        profilePic.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdown.style.display = profileDropdown.style.display === 'block' ? 'none' : 'block';
        });
        document.addEventListener('click', function(e) {
            if (!profileDropdown.contains(e.target) && e.target !== profilePic) {
                profileDropdown.style.display = 'none';
            }
        });
    }
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

    // Show requirements popup if not completed
    <?php if ($requirements_completed == 0): ?>
    window.onload = function() {
        showRequirementsPopup();
    };

    function showRequirementsPopup() {
        const missingRequirements = <?php echo json_encode($missing_requirements); ?>;
        let requirementsList = '';
        
        missingRequirements.forEach(req => {
            requirementsList += `<li>${req}</li>`;
        });

        const popup = document.createElement('div');
        popup.className = 'requirements-popup-overlay';
        popup.innerHTML = `
            <div class="requirements-popup">
                <div class="requirements-popup-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Complete Requirements Required</h3>
                </div>
                <div class="requirements-popup-body">
                    <p><strong>Welcome to JobMatch!</strong></p>
                    <p>To access all features and apply for jobs, you need to complete your requirements first.</p>
                    <p><strong>Required Documents:</strong></p>
                    <ul>${requirementsList}</ul>
                    <p>Once you complete these requirements, you'll have full access to:</p>
                    <ul>
                        <li>Browse and apply for jobs</li>
                        <li>Receive job notifications</li>
                        <li>Message with employers</li>
                        <li>Access all portal features</li>
                    </ul>
                </div>
                <div class="requirements-popup-footer">
                    <a href="requirements.php" class="popup-btn popup-btn-primary">
                        <i class="fas fa-upload"></i> Complete Requirements Now
                    </a>
                    <button onclick="closeRequirementsPopup()" class="popup-btn popup-btn-secondary">
                        Later
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(popup);
        document.body.style.overflow = 'hidden';
    }

    function closeRequirementsPopup() {
        const popup = document.querySelector('.requirements-popup-overlay');
        if (popup) {
            popup.remove();
            document.body.style.overflow = 'auto';
        }
    }
    <?php endif; ?>
    </script>

</body>
</html>