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

$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Handle AJAX request for notification details
if ($action === 'view_details' && isset($_GET['notification_id'])) {
    $notification_id = $_GET['notification_id'];
    $query = "SELECT jn.*, r.first_name, r.middle_name, r.last_name, r.email, r.phone, r.employed, r.job_title, rb.name as resident_barangay_name,
                     j.title as job_title, j.company, j.job_type, j.location, j.description, j.deadline, jb.name as job_barangay_name
              FROM job_notifications jn
              JOIN residents r ON jn.resident_id = r.id
              JOIN jobs j ON jn.job_id = j.id
              LEFT JOIN barangays rb ON r.barangay_id = rb.id
              LEFT JOIN barangays jb ON j.barangay_id = jb.id
              WHERE jn.id = :notification_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':notification_id', $notification_id);
    $stmt->execute();
    $notification_details = $stmt->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($notification_details);
    exit; // Stop further execution of the page
}

// Handle form submissions
if ($_POST) {
    if ($action === 'send') {
        $job_id = $_POST['job_id'] ?? '';
        $resident_ids = $_POST['resident_ids'] ?? [];
        $custom_message = trim($_POST['custom_message'] ?? '');

        if (empty($job_id) || empty($resident_ids)) {
            $error = 'Please select a job and at least one resident';
        } else {
            $success_count = 0;

            foreach ($resident_ids as $resident_id) {
                // Check if notification already exists for this job and resident
                $check_query = "SELECT id FROM job_notifications WHERE job_id = :job_id AND resident_id = :resident_id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':job_id', $job_id);
                $check_stmt->bindParam(':resident_id', $resident_id);
                $check_stmt->execute();

                if ($check_stmt->rowCount() == 0) {
                    // Insert job notification
                    $insert_query = "INSERT INTO job_notifications (job_id, resident_id, status, message) 
                                   VALUES (:job_id, :resident_id, 'sent', :message)";

                    $insert_stmt = $db->prepare($insert_query);
                    $insert_stmt->bindParam(':job_id', $job_id);
                    $insert_stmt->bindParam(':resident_id', $resident_id);
                    $insert_stmt->bindParam(':message', $custom_message);

                    if ($insert_stmt->execute()) {
                        // Get job details including deadline for message
                        $job_query = "SELECT title, company, deadline FROM jobs WHERE id = :job_id";
                        $job_stmt = $db->prepare($job_query);
                        $job_stmt->bindParam(':job_id', $job_id);
                        $job_stmt->execute();
                        $job_data = $job_stmt->fetch(PDO::FETCH_ASSOC);

                        // Build notification message
                        $notification_message = "You have been selected for a job opportunity: " . $job_data['title'] . " at " . $job_data['company'];

                        if (!empty($custom_message)) {
                            $notification_message .= "\n\nAdditional message: " . $custom_message;
                        }

                        // Add deadline information if it exists
                        if ($job_data['deadline']) {
                            $deadline_date = new DateTime($job_data['deadline']);
                            $notification_message .= "\n\nApplication Deadline: " . $deadline_date->format('F j, Y g:i A');
                        }

                        $notification_message .= "\n\nPlease check your Job Notifications to respond.";

                        $msg_query = "INSERT INTO messages (sender_type, sender_id, receiver_type, receiver_id, message, message_type, job_id) 
                                    VALUES ('admin', :admin_id, 'resident', :resident_id, :message, 'job_offer', :job_id)";
                        $msg_stmt = $db->prepare($msg_query);
                        $msg_stmt->bindParam(':admin_id', $_SESSION['user_id']);
                        $msg_stmt->bindParam(':resident_id', $resident_id);
                        $msg_stmt->bindParam(':message', $notification_message);
                        $msg_stmt->bindParam(':job_id', $job_id);
                        $msg_stmt->execute();

                        $success_count++;
                    }
                }
            }

            if ($success_count > 0) {
                $message = "Job notification sent to $success_count resident(s) successfully!";
                $action = 'list';
            } else {
                $error = 'No new notifications were sent. All selected residents may have already been notified for this job.';
            }
        }
    }
}

// Get jobs for dropdown
$jobs_query = "SELECT id, title, company, deadline FROM jobs WHERE status = 'Active' ORDER BY created_at DESC";
$jobs_stmt = $db->prepare($jobs_query);
$jobs_stmt->execute();
$jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get job data for sending notifications
$job_data = null;
$eligible_residents = [];
if ($action === 'send' && isset($_GET['job_id'])) {
    $job_id = $_GET['job_id'];
    $query = "SELECT j.*, b.name as barangay_name FROM jobs j 
              LEFT JOIN barangays b ON j.barangay_id = b.id 
              WHERE j.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $job_id);
    $stmt->execute();
    $job_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($job_data) {
        // Get eligible residents (not already notified and have completed requirements)
    $residents_query = "SELECT r.id, r.first_name, r.middle_name, r.last_name, r.employed, r.job_title, r.preferred_job, b.name as barangay_name
                          FROM residents r 
                          LEFT JOIN barangays b ON r.barangay_id = b.id 
                          WHERE r.id NOT IN (
                              SELECT resident_id FROM job_notifications WHERE job_id = :job_id
                          )
                          AND r.requirements_completed = 1";

        // Filter by barangay if job has specific barangay
        if ($job_data['barangay_id']) {
            $residents_query .= " AND (r.barangay_id = :barangay_id OR r.barangay_id IS NULL)";
        }

    $residents_query .= " ORDER BY r.last_name, r.first_name";

        $residents_stmt = $db->prepare($residents_query);
        $residents_stmt->bindParam(':job_id', $job_id);
        if ($job_data['barangay_id']) {
            $residents_stmt->bindParam(':barangay_id', $job_data['barangay_id']);
        }
        $residents_stmt->execute();
        $eligible_residents = $residents_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get notifications list with filters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$job_filter = $_GET['job'] ?? '';

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) LIKE :search OR j.title LIKE :search OR j.company LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($status_filter)) {
    $where_conditions[] = "jn.status = :status_filter";
    $params[':status_filter'] = $status_filter;
}

if (!empty($job_filter)) {
    $where_conditions[] = "jn.job_id = :job_filter";
    $params[':job_filter'] = $job_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $query = "SELECT jn.*, r.first_name, r.middle_name, r.last_name, r.email, j.title as job_title, j.company, j.job_type, j.deadline 
          FROM job_notifications jn 
          JOIN residents r ON jn.resident_id = r.id 
          JOIN jobs j ON jn.job_id = j.id 
          $where_clause 
          ORDER BY jn.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Notifications - JobMatch Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin/anotifications.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="../assets/css/table-sort.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
                    <li><a href="notifications.php" class="active"><i class="fas fa-bell"></i> Job Notifications</a></li>
                    <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-bell"></i> Job Notifications</h1>
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

        <?php if ($message): ?>
            <div class="success-message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($action === 'list'): ?>
            <!-- Notifications List -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Job Notifications</h3>
                    <div>
                        <select onchange="sendNotificationForJob(this.value)" class="btn-secondary" style="margin-right: 1rem;">
                            <option value="">Send Notification for Job...</option>
                            <?php foreach ($jobs as $job): ?>
                                <option value="<?php echo $job['id']; ?>">
                                    <?php echo htmlspecialchars($job['title'] . ' - ' . $job['company']); ?>
                                    <?php if ($job['deadline']): ?>
                                        <?php
                                        $deadline_date = new DateTime($job['deadline']);
                                        $now = new DateTime();
                                        $is_expired = $deadline_date < $now;
                                        ?>
                                        (Deadline: <?php echo $deadline_date->format('M j, Y'); ?><?php echo $is_expired ? ' - EXPIRED' : ''; ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" style="margin-bottom: 1rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                        <div class="form-group">
                            <label for="search">Name</label>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Resident name">
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status">
                                <option value="">All Status</option>
                                <option value="sent" <?php echo ($status_filter === 'sent') ? 'selected' : ''; ?>>Sent</option>
                                <option value="accepted" <?php echo ($status_filter === 'accepted') ? 'selected' : ''; ?>>Accepted</option>
                                <option value="declined" <?php echo ($status_filter === 'declined') ? 'selected' : ''; ?>>Declined</option>
                                <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="job">Job</label>
                            <select name="job" id="job">
                                <option value="">All Jobs</option>
                                <?php foreach ($jobs as $job): ?>
                                    <option value="<?php echo $job['id']; ?>" <?php echo ($job_filter == $job['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($job['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-secondary">Search</button>
                    </div>
                </form>

                <div class="table-container">
                    <div class="table-responsive">
                        <table id="notificationsTable" class="auto-sort" data-exclude-columns='[7]'>
                            <thead>
                                <tr>
                                    <th>Resident Name</th>
                                    <th>Job Title</th>
                                    <th>Company</th>
                                    <th>Status</th>
                                    <th>Sent Date</th>
                                    <th>Job Deadline</th>
                                    <th>Response Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($notifications)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center;">No notifications found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <tr>
                                            <td data-label="Resident Name"><?php echo htmlspecialchars($notification['first_name'] . ' ' . ($notification['middle_name'] ? $notification['middle_name'] . ' ' : '') . $notification['last_name']); ?></td>
                                            <td data-label="Job Title"><?php echo htmlspecialchars($notification['job_title']); ?></td>
                                            <td data-label="Company"><?php echo htmlspecialchars($notification['company']); ?></td>
                                            <td data-label="Status">
                                                <span class="status-badge status-<?php echo $notification['status']; ?>">
                                                    <?php echo ucfirst($notification['status']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Sent Date" data-sort="<?php echo strtotime($notification['created_at']); ?>"><?php echo date('M j, Y', strtotime($notification['created_at'])); ?></td>
                                            <td data-label="Job Deadline" data-sort="<?php echo $notification['deadline'] ? strtotime($notification['deadline']) : '0'; ?>">
                                                <?php if ($notification['deadline']): ?>
                                                    <?php
                                                    $deadline_date = new DateTime($notification['deadline']);
                                                    $now = new DateTime();
                                                    $is_expired = $deadline_date < $now;
                                                    ?>
                                                    <span class="deadline-date <?php echo $is_expired ? 'expired' : ''; ?>">
                                                        <?php echo $deadline_date->format('M j, Y g:i A'); ?>
                                                        <?php if ($is_expired): ?>
                                                            <span class="expired-label">EXPIRED</span>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="no-deadline">No deadline</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Response Date" data-sort="<?php echo ($notification['updated_at'] != $notification['created_at']) ? strtotime($notification['updated_at']) : '0'; ?>">
                                                <?php if ($notification['updated_at'] != $notification['created_at']): ?>
                                                    <?php echo date('M j, Y', strtotime($notification['updated_at'])); ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Actions">
                                                <button onclick="viewNotification(<?php echo $notification['id']; ?>)" class="btn-secondary btn-small">View</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'send'): ?>
            <!-- Send Notifications -->
            <div class="dashboard-card">
                <?php if ($job_data): ?>
                    <h3>Send Job Notification</h3>

                    <!-- Job Information -->
                    <div class="job-info-card">
                        <h4>Job Details</h4>
                        <div class="job-details">
                            <div class="detail-row">
                                <strong>Title:</strong> <?php echo htmlspecialchars($job_data['title']); ?>
                            </div>
                            <div class="detail-row">
                                <strong>Company:</strong> <?php echo htmlspecialchars($job_data['company']); ?>
                            </div>
                            <div class="detail-row">
                                <strong>Type:</strong> <?php echo htmlspecialchars($job_data['job_type']); ?>
                            </div>
                            <div class="detail-row">
                                <strong>Location:</strong> <?php echo htmlspecialchars($job_data['location'] ?: $job_data['barangay_name'] ?: 'Any location'); ?>
                            </div>
                            <?php if ($job_data['deadline']): ?>
                                <div class="detail-row">
                                    <strong>Application Deadline:</strong>
                                    <?php
                                    $deadline_date = new DateTime($job_data['deadline']);
                                    $now = new DateTime();
                                    $is_expired = $deadline_date < $now;
                                    ?>
                                    <span class="<?php echo $is_expired ? 'expired-text' : 'deadline-text'; ?>">
                                        <?php echo $deadline_date->format('F j, Y g:i A'); ?>
                                        <?php if ($is_expired): ?>
                                            <span class="expired-indicator">(EXPIRED)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if ($job_data['description']): ?>
                                <div class="detail-row">
                                    <strong>Description:</strong> <?php echo nl2br(htmlspecialchars($job_data['description'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (empty($eligible_residents)): ?>
                        <div class="no-residents">
                            <h4>No Eligible Residents</h4>
                            <p>All residents have already been notified for this job, or there are no residents matching the criteria.</p>
                            <a href="notifications.php" class="btn-secondary">Back to Notifications</a>
                        </div>
                    <?php else: ?>
                        <!-- Send Notification Form -->
                        <form method="POST" action="">
                            <input type="hidden" name="job_id" value="<?php echo $job_data['id']; ?>">

                            <div class="form-group">
                                <label>Select Residents to Notify:</label>
                                
                                <!-- Search Bar -->
                                <div class="search-container" style="margin-bottom: 1rem;">
                                    <div style="position: relative;">
                                        <input type="text" 
                                               id="residentSearch" 
                                               placeholder="Search by job title or preferred job..." 
                                               style="width: 100%; padding: 10px 40px 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;"
                                               onkeyup="filterResidents(this.value)">
                                        <i class="fas fa-search" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #666;"></i>
                                    </div>
                                    <div id="searchResults" style="font-size: 12px; margin-top: 5px; color: #666;"></div>
                                </div>
                                
                                <div class="residents-selection">
                                    <div class="selection-controls">
                                        <button type="button" onclick="selectAll()" class="btn-secondary btn-small">Select All</button>
                                        <button type="button" onclick="selectNone()" class="btn-secondary btn-small">Select None</button>
                                        <span class="selection-count">0 selected</span>
                                    </div>

                                    <div class="residents-list">
                                        <?php foreach ($eligible_residents as $resident): ?>
                                            <div class="resident-item">
                                                <label class="resident-checkbox">
                                                    <input type="checkbox" name="resident_ids[]" value="<?php echo $resident['id']; ?>" onchange="updateSelectionCount()">
                                                    <div class="resident-info">
                                                        <strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . $resident['last_name']); ?></strong>
                                                        <div class="resident-details">
                                                            <span class="employment-status"><?php echo htmlspecialchars($resident['employed']); ?></span>
                                                            <?php if ($resident['job_title']): ?>
                                                                <span class="job_title">Current: <?php echo htmlspecialchars($resident['job_title']); ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($resident['preferred_job']): ?>
                                                                <span class="preferred_job">Preferred: <?php echo htmlspecialchars($resident['preferred_job']); ?></span>
                                                            <?php endif; ?>
                                                            <span class="barangay"><?php echo htmlspecialchars($resident['barangay_name']); ?></span>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="custom_message">Additional Message (Optional):</label>
                                <textarea name="custom_message" id="custom_message" rows="4" placeholder="Add any additional information about this job opportunity..."><?php echo htmlspecialchars($_POST['custom_message'] ?? ''); ?></textarea>
                                <?php if ($job_data['deadline']): ?>
                                    <small class="field-help">Note: The job application deadline will be automatically included in the notification message.</small>
                                <?php endif; ?>
                            </div>

                            <div style="margin-top: 1rem;">
                                <button type="submit" class="btn-primary">Send Notifications</button>
                                <a href="notifications.php" class="btn-secondary">Cancel</a>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="error-message">Job not found</div>
                    <a href="notifications.php" class="btn-secondary">Back to Notifications</a>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- Notification Details Modal -->
    <div id="notificationModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Notification Details</h3>
                <span class="close" onclick="closeNotificationModal()">&times;</span>
            </div>
            <div class="modal-body" id="notificationModalBody">
                <!-- Notification details will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        function sendNotificationForJob(jobId) {
            if (jobId) {
                window.location.href = '?action=send&job_id=' + jobId;
            }
        }

        function selectAll() {
            const checkboxes = document.querySelectorAll('input[name="resident_ids[]"]');
            checkboxes.forEach(checkbox => {
                const residentItem = checkbox.closest('.resident-item');
                // Only select if the resident item is visible (not filtered out)
                if (residentItem && residentItem.style.display !== 'none') {
                    checkbox.checked = true;
                }
            });
            updateSelectionCount();
        }

        function selectNone() {
            const checkboxes = document.querySelectorAll('input[name="resident_ids[]"]');
            checkboxes.forEach(checkbox => {
                const residentItem = checkbox.closest('.resident-item');
                // Only deselect if the resident item is visible (not filtered out)
                if (residentItem && residentItem.style.display !== 'none') {
                    checkbox.checked = false;
                }
            });
            updateSelectionCount();
        }

        function updateSelectionCount() {
            const checkboxes = document.querySelectorAll('input[name="resident_ids[]"]:checked');
            const count = checkboxes.length;
            const countElement = document.querySelector('.selection-count');
            if (countElement) {
                countElement.textContent = count + ' selected';
            }
        }

        function filterResidents(searchTerm) {
            const residents = document.querySelectorAll('.resident-item');
            const searchResults = document.getElementById('searchResults');
            let visibleCount = 0;
            let totalCount = residents.length;

            searchTerm = searchTerm.toLowerCase().trim();

            residents.forEach(function(resident) {
                const residentInfo = resident.querySelector('.resident-info');
                const job_titleElement = residentInfo.querySelector('.job_title');
                const job_title = job_titleElement ? job_titleElement.textContent.toLowerCase() : '';
                const preferred_jobElement = residentInfo.querySelector('.preferred_job');
                const preferred_job = preferred_jobElement ? preferred_jobElement.textContent.toLowerCase() : '';

                // Check if search term matches job_title or preferred_job only
                const matches = job_title.includes(searchTerm) || preferred_job.includes(searchTerm);

                if (matches || searchTerm === '') {
                    resident.style.display = 'block';
                    visibleCount++;
                } else {
                    resident.style.display = 'none';
                }
            });

            // Update search results info
            if (searchTerm === '') {
                searchResults.textContent = `Showing all ${totalCount} residents`;
            } else if (visibleCount === 0) {
                searchResults.textContent = 'No residents found matching your search';
                searchResults.style.color = '#dc3545';
            } else {
                searchResults.textContent = `Showing ${visibleCount} of ${totalCount} residents`;
                searchResults.style.color = '#666';
            }

            // Update selection count after filtering
            updateSelectionCount();
        }

        function viewNotification(notificationId) {
            const modal = document.getElementById('notificationModal');
            const modalBody = document.getElementById('notificationModalBody');

            modalBody.innerHTML = '<p>Loading notification details...</p>';
            modal.style.display = 'block';

            fetch(`notifications.php?action=view_details&notification_id=${notificationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data) {
                        let deadlineInfo = '';
                        if (data.deadline) {
                            const deadlineDate = new Date(data.deadline);
                            const now = new Date();
                            const isExpired = deadlineDate < now;
                            deadlineInfo = `<p><strong>Job Application Deadline:</strong> <span class="${isExpired ? 'expired-text' : 'deadline-text'}">${deadlineDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' })}${isExpired ? ' <span class="expired-indicator">(EXPIRED)</span>' : ''}</span></p>`;
                        }

                        modalBody.innerHTML = `
                            <div class="notification-detail-section">
                                <h4>Notification Information</h4>
                                <p><strong>Status:</strong> <span class="status-badge status-${data.status}">${data.status.charAt(0).toUpperCase() + data.status.slice(1)}</span></p>
                                <p><strong>Sent Date:</strong> ${new Date(data.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                                <p><strong>Response Date:</strong> ${data.updated_at !== data.created_at ? new Date(data.updated_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '-'}</p>
                                ${data.message ? `<p><strong>Admin Message:</strong> ${data.message.replace(/\n/g, '<br>')}</p>` : ''}
                            </div>
                            <div class="notification-detail-section">
                                <h4>Resident Details</h4>
                                <p><strong>Name:</strong> ${data.first_name} ${data.middle_name ? data.middle_name + ' ' : ''}${data.last_name}</p>
                                <p><strong>Email:</strong> ${data.email || 'N/A'}</p>
                                <p><strong>Phone:</strong> ${data.phone || 'N/A'}</p>
                                <p><strong>Barangay:</strong> ${data.resident_barangay_name || 'N/A'}</p>
                                <p><strong>Employed:</strong> ${data.employed || 'N/A'}</p>
                                <p><strong>Job Title:</strong> ${data.job_title || 'N/A'}</p>
                            </div>
                            <div class="notification-detail-section">
                                <h4>Job Details</h4>
                                <p><strong>Title:</strong> ${data.job_title}</p>
                                <p><strong>Company:</strong> ${data.company}</p>
                                <p><strong>Type:</strong> ${data.job_type || 'N/A'}</p>
                                <p><strong>Location:</strong> ${data.location || data.job_barangay_name || 'Any'}</p>
                                ${deadlineInfo}
                                ${data.description ? `<p><strong>Description:</strong> ${data.description.replace(/\n/g, '<br>')}</p>` : ''}
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = '<p>Notification details not found.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error fetching notification details:', error);
                    modalBody.innerHTML = '<p>Error loading notification details. Please try again.</p>';
                });
        }

        function closeNotificationModal() {
            document.getElementById('notificationModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('notificationModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Initialize selection count on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectionCount();
        });

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

    <script src="../assets/js/table-sort.js"></script>
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

</html>