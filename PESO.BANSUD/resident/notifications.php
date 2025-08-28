<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireResident();

// Check if requirements are completed
$requirements_completed = $_SESSION['requirements_completed'] ?? 0;
if ($requirements_completed == 0) {
    header('Location: requirements.php?from=notifications');
    exit();
}

// Set timezone to ensure consistent time handling
date_default_timezone_set('Asia/Manila');

$database = new Database();
$db = $database->getConnection();

$resident_id = $_SESSION['user_id'];

// Get statistics for badges
$stats = [];

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

// Fetch resident profile data for header (profile picture)
$resident_data = [];
$resident_query = $db->prepare('SELECT * FROM residents WHERE id = :id');
$resident_query->bindParam(':id', $resident_id);
$resident_query->execute();
$resident_data = $resident_query->fetch(PDO::FETCH_ASSOC);
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Function to check if deadline has passed
function isDeadlinePassed($deadline) {
    if (!$deadline) {
        return false; // No deadline set
    }
    
    $deadline_timestamp = strtotime($deadline);
    $current_timestamp = time();
    
    return $deadline_timestamp < $current_timestamp;
}

// Handle form submissions
if ($_POST) {
    if ($action === 'respond') {
        $notification_id = $_POST['notification_id'] ?? '';
        $response = $_POST['response'] ?? '';
        $response_message = trim($_POST['response_message'] ?? '');

        if (empty($notification_id) || empty($response)) {
            $error = 'Please select a response';
        } else {
            // Get notification and job details to check deadline
            $check_query = "SELECT jn.*, j.deadline FROM job_notifications jn 
                           JOIN jobs j ON jn.job_id = j.id 
                           WHERE jn.id = :id AND jn.resident_id = :resident_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':id', $notification_id);
            $check_stmt->bindParam(':resident_id', $resident_id);
            $check_stmt->execute();
            $notification_check = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$notification_check) {
                $error = 'Notification not found';
            } elseif ($notification_check['status'] !== 'sent') {
                $error = 'This notification has already been responded to';
            } elseif (isDeadlinePassed($notification_check['deadline'])) {
                $error = 'The deadline for this job application has passed. You can no longer respond to this notification.';
            } else {
                // Before updating notification status, check if job has reached max positions (for accepted responses)
                if ($response === 'accepted') {
                    // Check current accepted count vs max positions
                    $limit_check_query = "SELECT j.max_positions, 
                                         (SELECT COUNT(*) FROM job_notifications 
                                          WHERE job_id = j.id AND status = 'accepted') as current_accepted
                                         FROM jobs j WHERE j.id = :job_id";
                    $limit_check_stmt = $db->prepare($limit_check_query);
                    $limit_check_stmt->bindParam(':job_id', $notification_check['job_id']);
                    $limit_check_stmt->execute();
                    $limit_data = $limit_check_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($limit_data && $limit_data['current_accepted'] >= $limit_data['max_positions']) {
                        $error = 'Sorry, this job has reached its maximum number of accepted applications (' . $limit_data['max_positions'] . ' positions). You can no longer accept this offer.';
                    } else {
                        // Proceed with acceptance - Update notification status
                        $update_query = "UPDATE job_notifications SET status = :status, response_message = :response_message, updated_at = NOW() 
                                       WHERE id = :id AND resident_id = :resident_id";
                        
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->bindParam(':status', $response);
                        $update_stmt->bindParam(':response_message', $response_message);
                        $update_stmt->bindParam(':id', $notification_id);
                        $update_stmt->bindParam(':resident_id', $resident_id);

                        if ($update_stmt->execute()) {
                            // Get job and notification details for message
                            $details_query = "SELECT jn.*, j.title, j.company FROM job_notifications jn 
                                            JOIN jobs j ON jn.job_id = j.id 
                                            WHERE jn.id = :id";
                            $details_stmt = $db->prepare($details_query);
                            $details_stmt->bindParam(':id', $notification_id);
                            $details_stmt->execute();
                            $details = $details_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            // Send response message to admin
                            $admin_message = "Resident " . $_SESSION['username'] . " has " . $response . " the job offer for: " . $details['title'] . " at " . $details['company'];
                            if (!empty($response_message)) {
                                $admin_message .= "\n\nResident's message: " . $response_message;
                            }
                            
                            $msg_query = "INSERT INTO messages (sender_type, sender_id, receiver_type, receiver_id, message, message_type, job_id) 
                                        VALUES ('resident', :resident_id, 'admin', 1, :message, 'notification', :job_id)";
                            $msg_stmt = $db->prepare($msg_query);
                            $msg_stmt->bindParam(':resident_id', $resident_id);
                            $msg_stmt->bindParam(':message', $admin_message);
                            $msg_stmt->bindParam(':job_id', $details['job_id']);
                            $msg_stmt->execute();
                            
                            $message = 'Your response has been sent successfully!';
                            $action = 'list';
                        } else {
                            $error = 'Failed to send response. Please try again.';
                        }
                    }
                } else {
                    // For declined responses, no limit check needed - proceed normally
                    $update_query = "UPDATE job_notifications SET status = :status, response_message = :response_message, updated_at = NOW() 
                                   WHERE id = :id AND resident_id = :resident_id";
                    
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':status', $response);
                    $update_stmt->bindParam(':response_message', $response_message);
                    $update_stmt->bindParam(':id', $notification_id);
                    $update_stmt->bindParam(':resident_id', $resident_id);

                    if ($update_stmt->execute()) {
                        // Get job and notification details for message
                        $details_query = "SELECT jn.*, j.title, j.company FROM job_notifications jn 
                                        JOIN jobs j ON jn.job_id = j.id 
                                        WHERE jn.id = :id";
                        $details_stmt = $db->prepare($details_query);
                        $details_stmt->bindParam(':id', $notification_id);
                        $details_stmt->execute();
                        $details = $details_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        // Send response message to admin
                        $admin_message = "Resident " . $_SESSION['username'] . " has " . $response . " the job offer for: " . $details['title'] . " at " . $details['company'];
                        if (!empty($response_message)) {
                            $admin_message .= "\n\nResident's message: " . $response_message;
                        }
                        
                        $msg_query = "INSERT INTO messages (sender_type, sender_id, receiver_type, receiver_id, message, message_type, job_id) 
                                    VALUES ('resident', :resident_id, 'admin', 1, :message, 'notification', :job_id)";
                        $msg_stmt = $db->prepare($msg_query);
                        $msg_stmt->bindParam(':resident_id', $resident_id);
                        $msg_stmt->bindParam(':message', $admin_message);
                        $msg_stmt->bindParam(':job_id', $details['job_id']);
                        $msg_stmt->execute();
                        
                        $message = 'Your response has been sent successfully!';
                        $action = 'list';
                    } else {
                        $error = 'Failed to send response. Please try again.';
                    }
                }
            }
        }
    }
}

// Get notification data for responding
$notification_data = null;
if ($action === 'respond' && isset($_GET['id'])) {
    $notification_id = $_GET['id'];
    $query = "SELECT jn.*, j.title, j.company, j.job_type, j.location, j.description, j.requirements, j.deadline,
              b.name as barangay_name
              FROM job_notifications jn 
              JOIN jobs j ON jn.job_id = j.id 
              LEFT JOIN barangays b ON j.barangay_id = b.id 
              WHERE jn.id = :id AND jn.resident_id = :resident_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $notification_id);
    $stmt->bindParam(':resident_id', $resident_id);
    $stmt->execute();
    $notification_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification_data) {
        $error = 'Notification not found';
        $action = 'list';
    } elseif ($notification_data['status'] !== 'sent') {
        $error = 'This notification has already been responded to';
        $action = 'list';
    } elseif (isDeadlinePassed($notification_data['deadline'])) {
        $error = 'The deadline for this job application has passed. You can no longer respond to this notification.';
        $action = 'list';
    }
}

// Get notifications list with filters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

$where_conditions = ["jn.resident_id = :resident_id"];
$params = [':resident_id' => $resident_id];

if ($filter === 'pending') {
    $where_conditions[] = "jn.status = 'sent'";
} elseif ($filter === 'responded') {
    $where_conditions[] = "jn.status IN ('accepted', 'declined')";
} elseif ($filter === 'accepted') {
    $where_conditions[] = "jn.status = 'accepted'";
} elseif ($filter === 'declined') {
    $where_conditions[] = "jn.status = 'declined'";
}

if (!empty($search)) {
    $where_conditions[] = "(j.title LIKE :search OR j.company LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

$query = "SELECT jn.*, j.title, j.company, j.job_type, j.location, j.deadline, b.name as barangay_name 
          FROM job_notifications jn 
          JOIN jobs j ON jn.job_id = j.id 
          LEFT JOIN barangays b ON j.barangay_id = b.id 
          $where_clause 
          ORDER BY jn.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for filter tabs
$counts_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN jn.status = 'sent' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN jn.status IN ('accepted', 'declined') THEN 1 ELSE 0 END) as responded,
                SUM(CASE WHEN jn.status = 'accepted' THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN jn.status = 'declined' THEN 1 ELSE 0 END) as declined
                FROM job_notifications jn 
                WHERE jn.resident_id = :resident_id";
$counts_stmt = $db->prepare($counts_query);
$counts_stmt->bindParam(':resident_id', $resident_id);
$counts_stmt->execute();
$counts = $counts_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Notifications - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/resident/rnotifications.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="/assets/css/resident/requirements-sidebar.css">
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
                <h2><i class="fas fa-user-circle"></i> JobMatch</h2>
                <span class="resident-label">Resident Portal</span>
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                     <li><a href="requirements.php"><i class="fas fa-file-upload"></i> Complete Requirements <span class="badge completed"><i class="fas fa-check"></i></span></a></li>
                    <li><a href="jobs.php"><i class="fas fa-briefcase"></i> Jobs</a></li>
                    <li><a href="notifications.php" class="active"><i class="fas fa-bell"></i> Job Notifications
                        <?php if($stats['pending_responses'] > 0): ?>
                            <span class="notification-badge"><?php echo $stats['pending_responses']; ?></span>
                        <?php endif; ?>
                    </a></li>
                    <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages
                        <?php if($stats['unread_messages'] > 0): ?>
                            <span class="notification-badge"><?php echo $stats['unread_messages']; ?></span>
                        <?php endif; ?>
                    </a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1>Job Notifications</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <div class="profile-dropdown">
                        <img src="<?php echo !empty($resident_data['profile_picture']) ? '../images/' . htmlspecialchars($resident_data['profile_picture']) : '../images/PesoLogo.jpg'; ?>" class="profile-pic" id="profilePic" alt="Profile Picture">
                        <div class="dropdown-content" id="profileDropdown">
                            <a href="profile.php" class="dropdown-btn"><i class="fas fa-user-edit"></i> Update Profile</a>
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
                    <div class="notification-summary">
                        <?php if ($counts['pending'] > 0): ?>
                            <span class="pending-count"><?php echo $counts['pending']; ?> pending response(s)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="filter-tabs-container">
                    <div class="filter-tabs">
                        <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            All (<?php echo $counts['total']; ?>)
                        </a>
                        <a href="?filter=pending" class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">
                            Pending (<?php echo $counts['pending']; ?>)
                        </a>
                        <a href="?filter=responded" class="filter-tab <?php echo $filter === 'responded' ? 'active' : ''; ?>">
                            Responded (<?php echo $counts['responded']; ?>)
                        </a>
                        <a href="?filter=accepted" class="filter-tab <?php echo $filter === 'accepted' ? 'active' : ''; ?>">
                            Accepted (<?php echo $counts['accepted']; ?>)
                        </a>
                        <a href="?filter=declined" class="filter-tab <?php echo $filter === 'declined' ? 'active' : ''; ?>">
                            Declined (<?php echo $counts['declined']; ?>)
                        </a>
                    </div>
                    
                    <form method="GET" class="search-form">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search job notifications...">
                        <button type="submit" class="btn-secondary">Search</button>
                    </form>
                </div>

                <!-- Notifications Grid -->
                <?php if (empty($notifications)): ?>
                    <div class="no-notifications">
                        <h4>No job notifications found</h4>
                        <p>You haven't received any job notifications yet. Check back later for new opportunities!</p>
                        <div style="margin-bottom: 1.5rem;"></div>
                        <a href="jobs.php" class="btn-primary">Browse Available Jobs</a>
                    </div>
                <?php else: ?>
                    <div class="notifications-grid">
                        <?php foreach ($notifications as $notification): ?>
                        <?php 
                        $is_expired = isDeadlinePassed($notification['deadline']);
                        $deadline_passed = $is_expired && $notification['status'] === 'sent';
                        ?>
                        <div class="notification-card status-<?php echo $notification['status']; ?> <?php echo $deadline_passed ? 'deadline-expired' : ''; ?>">
                            <div class="notification-header">
                                <h4><?php echo htmlspecialchars($notification['title']); ?></h4>
                                <div class="notification-badges">
                                    <span class="status-badge status-<?php echo $notification['status']; ?>">
                                        <?php echo ucfirst($notification['status']); ?>
                                    </span>
                                    <?php if ($deadline_passed): ?>
                                        <span class="expired-badge">DEADLINE EXPIRED</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="notification-company">
                                <strong><?php echo htmlspecialchars($notification['company']); ?></strong>
                            </div>
                            
                            <div class="notification-details">
                                <div class="detail-item">
                                    <span class="detail-label">Type:</span>
                                    <span><?php echo htmlspecialchars($notification['job_type']); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Location:</span>
                                    <span><?php echo htmlspecialchars($notification['location'] ?: $notification['barangay_name'] ?: 'Any location'); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Received:</span>
                                    <span><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></span>
                                </div>
                                <?php if ($notification['deadline']): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Application Deadline:</span>
                                    <span class="<?php echo $is_expired ? 'expired-text' : 'deadline-text'; ?>">
                                        <?php echo date('M j, Y g:i A', strtotime($notification['deadline'])); ?>
                                        <?php if ($is_expired): ?>
                                            <span class="expired-indicator">(EXPIRED)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if ($notification['status'] !== 'sent'): ?>
                                <div class="detail-item">
                                    <span class="detail-label">Responded:</span>
                                    <span><?php echo date('M j, Y g:i A', strtotime($notification['updated_at'])); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($notification['message']): ?>
                            <div class="notification-message">
                                <strong>Message from Admin:</strong>
                                <p><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($notification['response_message']): ?>
                            <div class="response-message">
                                <strong>Your Response:</strong>
                                <p><?php echo nl2br(htmlspecialchars($notification['response_message'])); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="notification-actions">
                                <button onclick="viewNotificationDetails(<?php echo $notification['id']; ?>)" class="btn-secondary btn-small">View Details</button>
                                <?php if ($notification['status'] === 'sent' && !$deadline_passed): ?>
                                    <a href="?action=respond&id=<?php echo $notification['id']; ?>" class="btn-primary btn-small">Respond</a>
                                <?php elseif ($deadline_passed): ?>
                                    <span class="expired-notice">Deadline Expired</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($action === 'respond'): ?>
            <!-- Respond to Notification -->
            <div class="dashboard-card">
                <h3>Respond to Job Notification</h3>
                
                <?php if ($notification_data): ?>
                    <?php 
                    $is_expired = isDeadlinePassed($notification_data['deadline']);
                    $deadline_passed = $is_expired;
                    ?>
                    
                    <?php if ($deadline_passed): ?>
                        <!-- Deadline Expired Notice -->
                        <div class="deadline-expired-notice">
                            <h4>⚠️ Application Deadline Has Passed</h4>
                            <p>The deadline for this job application was <?php echo date('F j, Y g:i A', strtotime($notification_data['deadline'])); ?>.</p>
                            <p>Current time: <?php echo date('F j, Y g:i A'); ?></p>
                            <p>You can no longer respond to this job notification.</p>
                            <a href="notifications.php" class="btn-secondary">Back to Notifications</a>
                        </div>
                    <?php else: ?>
                        <!-- Job Details -->
                        <div class="job-offer-card">
                            <div class="job-offer-header">
                                <h4><?php echo htmlspecialchars($notification_data['title']); ?></h4>
                                <span class="company-name"><?php echo htmlspecialchars($notification_data['company']); ?></span>
                            </div>
                            
                            <div class="job-offer-details">
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <strong>Job Type:</strong>
                                        <span><?php echo htmlspecialchars($notification_data['job_type']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <strong>Location:</strong>
                                        <span><?php echo htmlspecialchars($notification_data['location'] ?: $notification_data['barangay_name'] ?: 'Any location'); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <strong>Notification Received:</strong>
                                        <span><?php echo date('F j, Y g:i A', strtotime($notification_data['created_at'])); ?></span>
                                    </div>
                                    <?php if ($notification_data['deadline']): ?>
                                    <div class="detail-item">
                                        <strong>Application Deadline:</strong>
                                        <span class="deadline-text">
                                            <?php echo date('F j, Y g:i A', strtotime($notification_data['deadline'])); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="detail-item">
                                        <strong>Current Time:</strong>
                                        <span><?php echo date('F j, Y g:i A'); ?></span>
                                    </div>
                                </div>
                                
                                <?php if ($notification_data['description']): ?>
                                <div class="job-description">
                                    <strong>Job Description:</strong>
                                    <p><?php echo nl2br(htmlspecialchars($notification_data['description'])); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($notification_data['requirements']): ?>
                                <div class="job-requirements">
                                    <strong>Requirements:</strong>
                                    <p><?php echo nl2br(htmlspecialchars($notification_data['requirements'])); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($notification_data['message']): ?>
                                <div class="admin-message">
                                    <strong>Message from Admin:</strong>
                                    <p><?php echo nl2br(htmlspecialchars($notification_data['message'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Response Form -->
                        <div class="response-form-card">
                            <h4 >Your Response</h4>
                            <p style="color: #666; margin-bottom: 1.5rem;">Please respond to this job notification. Your response will be sent to the PESO admin.</p>
                            
                            <form method="POST" action="">
                                <input type="hidden" name="notification_id" value="<?php echo $notification_data['id']; ?>">
                                
                                <div class="form-group">
                                    <label style="color: black">Response *:</label>
                                    <div class="response-options">
                                        <label class="response-option accept">
                                            <input type="radio" name="response" value="accepted" required>
                                            <div class="option-content">
                                                <div class="option-icon">✓</div>
                                                <div class="option-text">
                                                    <strong style="color: black">Accept</strong>
                                                    <span>I am interested in this job opportunity</span>
                                                </div>
                                            </div>
                                        </label>
                                        
                                        <label class="response-option decline">
                                            <input type="radio" name="response" value="declined" required>
                                            <div class="option-content">
                                                <div class="option-icon">✗</div>
                                                <div class="option-text">
                                                    <strong style="color: black">Decline</strong>
                                                    <span>I am not interested in this job opportunity</span>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="response_message" style="color: black">Additional Message (Optional):</label>
                                    <textarea name="response_message" id="response_message" rows="4" placeholder="Add any additional comments or questions about this job opportunity..."><?php echo htmlspecialchars($_POST['response_message'] ?? ''); ?></textarea>
                                </div>

                                <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eee;">
                                    <button type="submit" class="btn-primary">Send Response</button>
                                    <a href="notifications.php" class="btn-secondary">Cancel</a>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>

    <!-- Notification Details Modal -->
    <div id="notificationModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"></h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Notification details will be loaded here -->
            </div>
        </div>
    </div>
    
    <div style="margin-bottom: 1.5rem;"></div>

    <script>
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
        function viewNotificationDetails(notificationId) {
            // Find notification data
            const notifications = <?php echo json_encode($notifications); ?>;
            const notification = notifications.find(n => n.id == notificationId);
            
            if (notification) {
                document.getElementById('modalTitle').textContent = notification.title;
                
                let deadlineInfo = '';
                if (notification.deadline) {
                    const deadlineDate = new Date(notification.deadline);
                    const now = new Date();
                    const isExpired = deadlineDate.getTime() < now.getTime();
                    deadlineInfo = `
                        <div class="detail-section">
                            <h4>Application Deadline</h4>
                            <p class="${isExpired ? 'expired-text' : 'deadline-text'}">
                                ${deadlineDate.toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'long', 
                                    day: 'numeric',
                                    hour: 'numeric',
                                    minute: '2-digit'
                                })}
                                ${isExpired ? '<span class="expired-indicator">(EXPIRED)</span>' : ''}
                            </p>
                        </div>
                    `;
                }
                
                let modalContent = `
                    <div class="notification-detail">
                        <div class="detail-section">
                            <h4>Company</h4>
                            <p>${notification.company}</p>
                        </div>
                        
                        <div class="detail-section">
                            <h4>Job Type</h4>
                            <p>${notification.job_type}</p>
                        </div>
                        
                        <div class="detail-section">
                            <h4>Location</h4>
                            <p>${notification.location || notification.barangay_name || 'Any location'}</p>
                        </div>
                        
                        <div class="detail-section">
                            <h4>Status</h4>
                            <p><span class="status-badge status-${notification.status}">${notification.status.charAt(0).toUpperCase() + notification.status.slice(1)}</span></p>
                        </div>
                        
                        <div class="detail-section">
                            <h4>Notification Received</h4>
                            <p>${new Date(notification.created_at).toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric',
                                hour: 'numeric',
                                minute: '2-digit'
                            })}</p>
                        </div>
                        
                        ${deadlineInfo}
                        
                        ${notification.status !== 'sent' ? `
                        <div class="detail-section">
                            <h4>Response Date</h4>
                            <p>${new Date(notification.updated_at).toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric',
                                hour: 'numeric',
                                minute: '2-digit'
                            })}</p>
                        </div>
                        ` : ''}
                        
                        ${notification.message ? `
                        <div class="detail-section">
                            <h4>Message from Admin</h4>
                            <p>${notification.message.replace(/\n/g, '<br>')}</p>
                        </div>
                        ` : ''}
                        
                        ${notification.response_message ? `
                        <div class="detail-section">
                            <h4>Your Response Message</h4>
                            <p>${notification.response_message.replace(/\n/g, '<br>')}</p>
                        </div>
                        ` : ''}
                        
                        ${notification.status === 'sent' && (!notification.deadline || new Date(notification.deadline).getTime() > new Date().getTime()) ? `
                        <div class="detail-section">
                            <a href="?action=respond&id=${notification.id}" class="btn-primary">Respond to this Notification</a>
                        </div>
                        ` : ''}
                    </div>
                `;
                
                document.getElementById('modalBody').innerHTML = modalContent;
                document.getElementById('notificationModal').style.display = 'block';
            }
        }
        
        function closeModal() {
            document.getElementById('notificationModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('notificationModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>

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
            </div>
        </div>
    </div>

</body>
</html>
