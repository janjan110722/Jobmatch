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
$filter = $_GET['filter'] ?? 'all'; // Initialize $filter here
$search = $_GET['search'] ?? ''; // Initialize $search here
// Handle form submissions
if ($_POST) {
    if ($action === 'compose' || $action === 'conversation') { // 'conversation' action now handles replies
        $recipient_id = $_POST['recipient_id'] ?? '';
        $subject = trim($_POST['subject'] ?? '');
        $message_text = trim($_POST['message'] ?? '');
        $message_type = $_POST['message_type'] ?? 'general';
        $job_id = !empty($_POST['job_id']) ? $_POST['job_id'] : null;
        if (empty($recipient_id) || empty($message_text)) {
            $error = 'Please fill in all required fields';
        } else {
            // Generate subject if not provided for new conversations
            if (empty($subject) && $action === 'compose') {
                $subject = ($message_type === 'job_offer') ? 'Job Opportunity' : 'Message from PESO Office';
            } elseif ($action === 'conversation' && empty($subject)) {
                // For replies, if subject is empty, try to get original subject or default
                $original_subject_query = "SELECT subject FROM messages WHERE (sender_id = :admin_id AND receiver_id = :recipient_id AND sender_type = 'admin' AND receiver_type = 'resident') OR (sender_id = :recipient_id AND receiver_id = :admin_id AND sender_type = 'resident' AND receiver_type = 'admin') ORDER BY created_at DESC LIMIT 1";
                $original_subject_stmt = $db->prepare($original_subject_query);
                $original_subject_stmt->bindParam(':admin_id', $_SESSION['user_id']);
                $original_subject_stmt->bindParam(':recipient_id', $recipient_id);
                $original_subject_stmt->execute();
                $original_subject = $original_subject_stmt->fetchColumn();
                $subject = 'Re: ' . ($original_subject ?: 'Message');
            }
            // Insert message
            $insert_query = "INSERT INTO messages (sender_type, sender_id, receiver_type, receiver_id, subject, message, message_type, job_id) 
                           VALUES ('admin', :sender_id, 'resident', :recipient_id, :subject, :message, :message_type, :job_id)";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':sender_id', $_SESSION['user_id']);
            $insert_stmt->bindParam(':recipient_id', $recipient_id);
            $insert_stmt->bindParam(':subject', $subject);
            $insert_stmt->bindParam(':message', $message_text);
            $insert_stmt->bindParam(':message_type', $message_type);
            $insert_stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
            if ($insert_stmt->execute()) {
                $message = 'Message sent successfully!';
                // Redirect to the conversation after sending
                header("Location: messages.php?action=conversation&resident_id=" . $recipient_id . "&job_id=" . ($job_id ?? ''));
                exit();
            } else {
                $error = 'Failed to send message. Please try again.';
            }
        }
    }
}
// Get residents for dropdown
$residents_query = "SELECT id, first_name, middle_name, last_name, email FROM residents ORDER BY last_name, first_name";
$residents_stmt = $db->prepare($residents_query);
$residents_stmt->execute();
$residents = $residents_stmt->fetchAll(PDO::FETCH_ASSOC);
// Get jobs for dropdown
$jobs_query = "SELECT id, title, company FROM jobs WHERE status = 'Active' ORDER BY title";
$jobs_stmt = $db->prepare($jobs_query);
$jobs_stmt->execute();
$jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
// Get unread count for admin's received messages
$unread_query = "SELECT COUNT(*) as unread_count FROM messages WHERE receiver_type = 'admin' AND is_read = FALSE";
$unread_stmt = $db->prepare($unread_query);
$unread_stmt->execute();
$unread_count = $unread_stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
// --- Conversation View Logic ---
$conversation_messages = [];
$current_resident_id = null;
$current_resident_name = '';
$current_job_id = null;
$current_job_title = '';
if ($action === 'conversation' && isset($_GET['resident_id'])) {
    $current_resident_id = $_GET['resident_id'];
    $current_job_id = $_GET['job_id'] ?? null; // Optional job ID for specific threads
    // Mark all messages in this conversation as read for the admin
    $mark_read_query = "UPDATE messages SET is_read = TRUE WHERE receiver_type = 'admin' AND sender_id = :resident_id";
    $mark_read_stmt = $db->prepare($mark_read_query);
    $mark_read_stmt->bindParam(':resident_id', $current_resident_id);
    $mark_read_stmt->execute();
    // Fetch all messages in this conversation
    $conversation_query = "SELECT m.*, 
                           CASE 
                             WHEN m.sender_type = 'admin' THEN 'You'
                             ELSE CONCAT(rs.first_name, ' ', IFNULL(rs.middle_name, ''), ' ', rs.last_name) 
                           END as sender_name,
                           CASE 
                             WHEN m.receiver_type = 'admin' THEN 'You'
                             ELSE CONCAT(rr.first_name, ' ', IFNULL(rr.middle_name, ''), ' ', rr.last_name) 
                           END as receiver_name,
                           rs.email as sender_email,
                           rr.email as receiver_email,
                           j.title as job_title,
                           j.company as job_company
                           FROM messages m 
                           LEFT JOIN residents rs ON m.sender_id = rs.id AND m.sender_type = 'resident'
                           LEFT JOIN residents rr ON m.receiver_id = rr.id AND m.receiver_type = 'resident'
                           LEFT JOIN jobs j ON m.job_id = j.id
                           WHERE (
                               (m.sender_type = 'admin' AND m.receiver_type = 'resident' AND m.receiver_id = :resident_id) OR
                               (m.sender_type = 'resident' AND m.sender_id = :resident_id AND m.receiver_type = 'admin')
                           )";
    
    if ($current_job_id) {
        $conversation_query .= " AND m.job_id = :job_id";
    }
    $conversation_query .= " ORDER BY m.created_at ASC";
    $conversation_stmt = $db->prepare($conversation_query);
    $conversation_stmt->bindParam(':resident_id', $current_resident_id);
    if ($current_job_id) {
        $conversation_stmt->bindParam(':job_id', $current_job_id, PDO::PARAM_INT);
    }
    $conversation_stmt->execute();
    $conversation_messages = $conversation_stmt->fetchAll(PDO::FETCH_ASSOC);
    // Get resident name for conversation header
    $resident_name_query = "SELECT first_name, middle_name, last_name FROM residents WHERE id = :resident_id";
    $resident_name_stmt = $db->prepare($resident_name_query);
    $resident_name_stmt->bindParam(':resident_id', $current_resident_id);
    $resident_name_stmt->execute();
    $resident_name_row = $resident_name_stmt->fetch(PDO::FETCH_ASSOC);
    $current_resident_name = $resident_name_row['first_name'] . ' ' . ($resident_name_row['middle_name'] ? $resident_name_row['middle_name'] . ' ' : '') . $resident_name_row['last_name'];
    // Get job title for conversation header if applicable
    if ($current_job_id) {
        $job_title_query = "SELECT title FROM jobs WHERE id = :job_id";
        $job_title_stmt = $db->prepare($job_title_query);
        $job_title_stmt->bindParam(':job_id', $current_job_id);
        $job_title_stmt->execute();
        $current_job_title = $job_title_stmt->fetchColumn();
    }
    if (empty($conversation_messages)) {
        $error = 'No conversation found with this resident.';
        $action = 'list'; // Fallback to list if no messages
    }
}
// --- List View Logic ---
$conversations = [];
if ($action === 'list') {
    $list_query = "SELECT m.*, 
                                     CASE 
                                         WHEN m.sender_type = 'admin' THEN 'You'
                                         ELSE CONCAT(rs.first_name, ' ', IFNULL(rs.middle_name, ''), ' ', rs.last_name) 
                                     END as sender_name,
                                     CASE 
                                         WHEN m.receiver_type = 'admin' THEN 'You'
                                         ELSE CONCAT(rr.first_name, ' ', IFNULL(rr.middle_name, ''), ' ', rr.last_name) 
                                     END as receiver_name,
                   COALESCE(rs.id, rr.id) as participant_resident_id,
                   CONCAT(COALESCE(rs.first_name, ''), ' ', COALESCE(rs.middle_name, ''), ' ', COALESCE(rs.last_name, '')) as participant_resident_name,
                   COALESCE(rs.email, rr.email) as participant_resident_email,
                   j.title as job_title,
                   j.company as job_company
                   FROM messages m 
                   LEFT JOIN residents rs ON m.sender_id = rs.id AND m.sender_type = 'resident'
                   LEFT JOIN residents rr ON m.receiver_id = rr.id AND m.receiver_type = 'resident'
                   LEFT JOIN jobs j ON m.job_id = j.id
                   WHERE (m.receiver_type = 'admin' OR m.sender_type = 'admin')
                   ORDER BY m.created_at DESC";
    $list_stmt = $db->prepare($list_query);
    $list_stmt->execute();
    $all_messages = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
    $seen_conversations = [];
    foreach ($all_messages as $msg) {
        $participant_id = $msg['participant_resident_id'];
        $thread_key = $participant_id . '_' . ($msg['job_id'] ?? 'general'); // Group by resident and optionally job
        
        if (!isset($seen_conversations[$thread_key])) {
            $conversations[] = $msg;
            $seen_conversations[$thread_key] = true;
        }
    }
    // Filter conversations based on search
    if (!empty($search)) {
        $filtered_conversations = [];
        foreach ($conversations as $conv) {
            if (stripos($conv['participant_resident_name'] ?? '', $search) !== false ||
                stripos($conv['message'] ?? '', $search) !== false ||
                stripos($conv['subject'] ?? '', $search) !== false ||
                stripos($conv['job_title'] ?? '', $search) !== false) {
                $filtered_conversations[] = $conv;
            }
        }
        $conversations = $filtered_conversations;
    }
    // Filter conversations based on type (unread, sent, received)
    if ($filter === 'unread') {
        $conversations = array_filter($conversations, function($conv) {
            return !$conv['is_read'] && $conv['receiver_type'] === 'admin';
        });
    } elseif ($filter === 'sent') {
        $conversations = array_filter($conversations, function($conv) {
            return $conv['sender_type'] === 'admin';
        });
    } elseif ($filter === 'received') {
        $conversations = array_filter($conversations, function($conv) {
            return $conv['receiver_type'] === 'admin';
        });
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - JobMatch Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin/amessages.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
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
                        <a href="messages.php" class="active">
                            <i class="fas fa-envelope"></i> Messages 
                            <?php if($unread_count > 0): ?>
                                <span class="notification-badge"><?php echo $unread_count; ?></span>
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
        <div class="main-content">
            <div class="header">
                <h1><i class="fas fa-envelope"></i> Messages</h1>
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
            <!-- Conversations List -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Conversations <?php if ($unread_count > 0): ?><span class="unread-indicator">(<?php echo $unread_count; ?> unread)</span><?php endif; ?></h3>
                    <a href="?action=compose" class="btn-primary">Create New Message</a>
                </div>
                <!-- Filters -->
                <div class="message-filters">
                    <div class="filter-tabs">
                        <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">All Conversations</a>
                        <a href="?filter=unread" class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">Unread (<?php echo $unread_count; ?>)</a>
                        <a href="?filter=received" class="filter-tab <?php echo $filter === 'received' ? 'active' : ''; ?>">Received</a>
                        <a href="?filter=sent" class="filter-tab <?php echo $filter === 'sent' ? 'active' : ''; ?>">Sent</a>
                    </div>
                    
                    <form method="GET" class="search-form">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search conversations...">
                        <button type="submit" class="btn-secondary">Search</button>
                    </form>
                </div>
                <!-- Conversations List -->
                <div class="messages-container">
                    <?php if (empty($conversations)): ?>
                        <div class="no-messages">
                            <div class="empty-icon"><i class="fas fa-comment-slash" style="font-size: 4rem; color: #6c757d;"></i></div>
                            <h4>No conversations found</h4>
                            <p>Start a conversation by creating a new message to residents.</p>
                            <div style="margin-bottom: 1.5rem;"></div>
                            <a href="?action=compose" class="btn-primary">Create Message</a>
                        </div>
                    <?php else: ?>
                        <div class="messages-list">
                            <?php foreach ($conversations as $conv): ?>
                            <div class="message-item <?php echo (!$conv['is_read'] && $conv['receiver_type'] === 'admin') ? 'unread' : ''; ?>">
                                <div class="message-header">
                                    <div class="message-participants">
                                        <strong>
                                            <?php if ($conv['sender_type'] === 'admin'): ?>
                                                To: <?php echo htmlspecialchars($conv['receiver_name'] ?? 'Unknown'); ?>
                                            <?php else: ?>
                                                From: <?php echo htmlspecialchars($conv['sender_name'] ?? 'Unknown'); ?>
                                            <?php endif; ?>
                                        </strong>
                                        <?php if ($conv['message_type'] !== 'general'): ?>
                                            <span class="message-type-badge"><?php echo ucfirst(str_replace('_', ' ', $conv['message_type'])); ?></span>
                                        <?php endif; ?>
                                        <?php if ($conv['job_title']): ?>
                                            <span class="job-badge">Job: <?php echo htmlspecialchars($conv['job_title']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-meta">
                                        <span class="message-time"><?php echo date('M j, Y g:i A', strtotime($conv['created_at'])); ?></span>
                                        <?php if (!$conv['is_read'] && $conv['receiver_type'] === 'admin'): ?>
                                            <span class="unread-indicator">●</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if ($conv['subject']): ?>
                                <div class="message-subject">
                                    <strong><?php echo htmlspecialchars($conv['subject']); ?></strong>
                                </div>
                                <?php endif; ?>
                                
                                <div class="message-preview">
                                    <?php echo htmlspecialchars(substr($conv['message'], 0, 150)); ?>
                                    <?php if (strlen($conv['message']) > 150): ?>...<?php endif; ?>
                                </div>
                                <div class="message-actions">
                                    <a href="?action=conversation&resident_id=<?php echo htmlspecialchars($conv['participant_resident_id']); ?><?php echo $conv['job_id'] ? '&job_id=' . htmlspecialchars($conv['job_id']) : ''; ?>" class="btn-secondary btn-small">View Conversation</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($action === 'compose'): ?>
            <!-- Compose Message -->
            <div class="dashboard-card">
                <h3>Create New Message</h3>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="compose">
                    <div class="form-group">
                        <label for="recipient_id">Send to Resident *:</label>
                        <select name="recipient_id" id="recipient_id" required>
                            <option value="">Select Resident</option>
                            <?php foreach ($residents as $resident): ?>
                                <option value="<?php echo $resident['id']; ?>" <?php echo (isset($_POST['recipient_id']) && $_POST['recipient_id'] == $resident['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($resident['first_name'] . ' ' . ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . $resident['last_name'] . ' (' . $resident['email'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="message_type">Message Type:</label>
                        <select name="message_type" id="message_type">
                            <option value="general" <?php echo (isset($_POST['message_type']) && $_POST['message_type'] === 'general') ? 'selected' : ''; ?>>General Message</option>
                            <option value="job_offer" <?php echo (isset($_POST['message_type']) && $_POST['message_type'] === 'job_offer') ? 'selected' : ''; ?>>Job Offer</option>
                            <option value="notification" <?php echo (isset($_POST['message_type']) && $_POST['message_type'] === 'notification') ? 'selected' : ''; ?>>Notification</option>
                            <option value="job_response" <?php echo (isset($_POST['message_type']) && $_POST['message_type'] === 'job_response') ? 'selected' : ''; ?>>Job Response Follow-up</option>
                        </select>
                    </div>
                    <div class="form-group" id="job_selection" style="display: none;">
                        <label for="job_id">Related Job:</label>
                        <select name="job_id" id="job_id">
                            <option value="">Select Job (Optional)</option>
                            <?php foreach ($jobs as $job): ?>
                                <option value="<?php echo $job['id']; ?>" <?php echo (isset($_POST['job_id']) && $_POST['job_id'] == $job['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($job['title'] . ' - ' . $job['company']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="subject">Subject:</label>
                        <input type="text" name="subject" id="subject" value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" placeholder="Message subject (optional - will be auto-generated if empty)">
                    </div>
                    <div class="form-group">
                        <label for="message">Message *:</label>
                        <textarea name="message" id="message" rows="6" required placeholder="Type your message here..."><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    </div>
                    <div style="margin-top: 1rem;">
                        <button type="submit" class="btn-primary">Send Message</button>
                        <a href="messages.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        <?php elseif ($action === 'conversation'): ?>
            <!-- Conversation View -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Conversation with <?php echo htmlspecialchars($current_resident_name ?? 'Resident'); ?>
                        <?php if ($current_job_title): ?>
                            <span class="job-badge" style="margin-left: 10px;">Job: <?php echo htmlspecialchars($current_job_title); ?></span>
                        <?php endif; ?>
                    </h3>
                    <a href="messages.php" class="btn-secondary">Back to Conversations</a>
                </div>
                <div class="conversation-container">
                    <?php if (empty($conversation_messages)): ?>
                        <div class="no-messages">
                            <h4>No messages in this conversation yet.</h4>
                            <p>Start by sending a message below.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversation_messages as $msg): ?>
                            <div class="chat-bubble <?php echo ($msg['sender_type'] === 'admin') ? 'sent' : 'received'; ?>">
                                <div class="chat-header">
                                    <strong><?php echo htmlspecialchars($msg['sender_name'] ?? 'Unknown'); ?></strong>
                                    <span class="chat-time"><?php echo date('M j, Y g:i A', strtotime($msg['created_at'])); ?></span>
                                </div>
                                <?php if ($msg['subject']): ?>
                                    <div class="chat-subject">
                                        <strong>Subject:</strong> <?php echo htmlspecialchars($msg['subject']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($msg['message_type'] !== 'general'): ?>
                                    <div class="chat-type">
                                        <span class="message-type-badge"><?php echo ucfirst(str_replace('_', ' ', $msg['message_type'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="chat-content">
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <!-- Reply Form -->
                <div class="reply-form-container">
                    <h4>Reply to <?php echo htmlspecialchars($current_resident_name ?? 'Resident'); ?></h4>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="conversation">
                        <input type="hidden" name="recipient_id" value="<?php echo htmlspecialchars($current_resident_id); ?>">
                        <?php if ($current_job_id): ?>
                            <input type="hidden" name="job_id" value="<?php echo htmlspecialchars($current_job_id); ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="message_type" style="color: black;">Message Type:</label>
                            <select name="message_type" id="message_type_reply">
                                <option value="general">General Message</option>
                                <option value="job_offer">Job Offer</option>
                                <option value="notification">Notification</option>
                                <option value="job_response">Job Response Follow-up</option>
                            </select>
                        </div>
                        <div class="form-group" id="job_selection_reply" style="display: none;">
                            <label for="job_id_reply">Related Job:</label>
                            <select name="job_id" id="job_id_reply">
                                <option value="">Select Job (Optional)</option>
                                <?php foreach ($jobs as $job): ?>
                                    <option value="<?php echo $job['id']; ?>" <?php echo ($current_job_id == $job['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($job['title'] . ' - ' . $job['company']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="subject_reply" style="color: black;">Subject:</label>
                            <input type="text" name="subject" id="subject_reply" value="Re: <?php echo htmlspecialchars($conversation_messages[0]['subject'] ?? 'Message'); ?>" placeholder="Reply subject (optional)">
                        </div>
                        <div class="form-group">
                            <label for="message_reply" style="color: black;">Your Reply *:</label>
                            <textarea name="message" id="message_reply" rows="4" required placeholder="Type your reply here..."></textarea>
                        </div>
                        <div style="margin-top: 1rem;">
                            <button type="submit" class="btn-primary">Send Reply</button>
                            <a href="messages.php?action=conversation&resident_id=<?php echo htmlspecialchars($current_resident_id); ?><?php echo $current_job_id ? '&job_id=' . htmlspecialchars($current_job_id) : ''; ?>" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div style="margin-bottom: 1.5rem;"></div>
    <script>
        // Show/hide job selection based on message type for Compose form
        document.getElementById('message_type').addEventListener('change', function() {
            const jobSelection = document.getElementById('job_selection');
            if (this.value === 'job_offer' || this.value === 'job_response') {
                jobSelection.style.display = 'block';
            } else {
                jobSelection.style.display = 'none';
                document.getElementById('job_id').value = '';
            }
        });
        
        // Trigger change event on page load for Compose form
        document.getElementById('message_type').dispatchEvent(new Event('change'));
        // Show/hide job selection based on message type for Reply form (if it exists)
        const messageTypeReply = document.getElementById('message_type_reply');
        if (messageTypeReply) {
            messageTypeReply.addEventListener('change', function() {
                const jobSelectionReply = document.getElementById('job_selection_reply');
                if (this.value === 'job_offer' || this.value === 'job_response') {
                    jobSelectionReply.style.display = 'block';
                } else {
                    jobSelectionReply.style.display = 'none';
                    document.getElementById('job_id_reply').value = '';
                }
            });
            // Trigger change event on page load for Reply form
            messageTypeReply.dispatchEvent(new Event('change'));
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
