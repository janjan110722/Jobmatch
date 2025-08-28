<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireResident();

// Check if requirements are completed
$requirements_completed = $_SESSION['requirements_completed'] ?? 0;
if ($requirements_completed == 0) {
    header('Location: requirements.php?from=messages');
    exit();
}

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
$filter = $_GET['filter'] ?? 'all'; // Initialize $filter here
$search = $_GET['search'] ?? ''; // Initialize $search here
// Explicitly set admin_id to 1, assuming admin user has ID 1
// Adjust this if your admin user has a different fixed ID or is dynamically fetched from another table
$admin_id = 1; 
// Handle form submissions
if ($_POST) {
    if ($action === 'compose' || $action === 'conversation') { // 'conversation' action now handles replies
        $recipient_id = $_POST['recipient_id'] ?? $admin_id; // Default to admin_id if not explicitly set
        $subject = trim($_POST['subject'] ?? '');
        $message_text = trim($_POST['message'] ?? '');
        $message_type = $_POST['message_type'] ?? 'general';
        $job_id = !empty($_POST['job_id']) ? $_POST['job_id'] : null; // For resident, job_id might be from original message
        if (empty($message_text)) {
            $error = 'Please enter a message';
        } else {
            // Generate subject if not provided for new conversations
            if (empty($subject) && $action === 'compose') {
                $subject = ($message_type === 'job_offer') ? 'Job Application/Inquiry' : 'Message from Resident';
            } elseif ($action === 'conversation' && empty($subject)) {
                // For replies, if subject is empty, try to get original subject or default
                $original_subject_query = "SELECT subject FROM messages WHERE (sender_id = :resident_id AND receiver_id = :admin_id AND sender_type = 'resident' AND receiver_type = 'admin') OR (sender_id = :admin_id AND receiver_id = :resident_id AND sender_type = 'admin' AND receiver_type = 'resident') ORDER BY created_at DESC LIMIT 1";
                $original_subject_stmt = $db->prepare($original_subject_query);
                $original_subject_stmt->bindParam(':resident_id', $_SESSION['user_id']);
                $original_subject_stmt->bindParam(':admin_id', $recipient_id); // Admin ID
                $original_subject_stmt->execute();
                $original_subject = $original_subject_stmt->fetchColumn();
                $subject = 'Re: ' . ($original_subject ?: 'Message');
            }
            // Insert message to admin (admin_id is assumed to be 1)
            $insert_query = "INSERT INTO messages (sender_type, sender_id, receiver_type, receiver_id, subject, message, message_type, job_id) 
                           VALUES ('resident', :sender_id, 'admin', :recipient_id, :subject, :message, :message_type, :job_id)";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':sender_id', $resident_id);
            $insert_stmt->bindParam(':recipient_id', $recipient_id); // This is the admin ID
            $insert_stmt->bindParam(':subject', $subject);
            $insert_stmt->bindParam(':message', $message_text);
            $insert_stmt->bindParam(':message_type', $message_type);
            $insert_stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
            if ($insert_stmt->execute()) {
                $message = 'Message sent successfully!';
                // Redirect to the conversation after sending
                header("Location: messages.php?action=conversation&admin_id=" . $recipient_id . "&job_id=" . ($job_id ?? ''));
                exit();
            } else {
                $error = 'Failed to send message. Please try again.';
            }
        }
    }
}
// Get jobs for dropdown (for resident's reply if related to a job)
$jobs_query = "SELECT id, title, company FROM jobs WHERE status = 'Active' ORDER BY title";
$jobs_stmt = $db->prepare($jobs_query);
$jobs_stmt->execute();
$jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);
// Get unread count for resident's received messages
$unread_query = "SELECT COUNT(*) as unread_count FROM messages WHERE receiver_type = 'resident' AND receiver_id = :resident_id AND is_read = FALSE";
$unread_stmt = $db->prepare($unread_query);
$unread_stmt->bindParam(':resident_id', $_SESSION['user_id']);
$unread_stmt->execute();
$unread_count = $unread_stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
// --- Conversation View Logic ---
$conversation_messages = [];
$current_admin_id = null;
$current_job_id = null;
$current_job_title = '';
if ($action === 'conversation' && isset($_GET['admin_id'])) {
    $current_admin_id = $_GET['admin_id'];
    $current_job_id = $_GET['job_id'] ?? null; // Optional job ID for specific threads
    // Mark all messages in this conversation as read for the resident
    $mark_read_query = "UPDATE messages SET is_read = TRUE WHERE receiver_type = 'resident' AND receiver_id = :resident_id AND sender_id = :admin_id";
    if ($current_job_id) {
        $mark_read_query .= " AND job_id = :job_id";
    }
    $mark_read_stmt = $db->prepare($mark_read_query);
    $mark_read_stmt->bindParam(':resident_id', $_SESSION['user_id']);
    $mark_read_stmt->bindParam(':admin_id', $current_admin_id);
    if ($current_job_id) {
        $mark_read_stmt->bindParam(':job_id', $current_job_id, PDO::PARAM_INT);
    }
    $mark_read_stmt->execute();
    // Fetch all messages in this conversation
    $conversation_query = "SELECT m.*, 
                           CASE 
                             WHEN m.sender_type = 'resident' THEN 'You'
                             ELSE 'Admin' 
                           END as sender_name,
                           CASE 
                             WHEN m.receiver_type = 'resident' THEN 'You'
                             ELSE 'Admin' 
                           END as receiver_name,
                           j.title as job_title,
                           j.company as job_company
                           FROM messages m 
                           LEFT JOIN jobs j ON m.job_id = j.id
                           WHERE (
                               (m.sender_type = 'resident' AND m.sender_id = :resident_id AND m.receiver_type = 'admin' AND m.receiver_id = :admin_id) OR
                               (m.sender_type = 'admin' AND m.sender_id = :admin_id AND m.receiver_type = 'resident' AND m.receiver_id = :resident_id)
                           )";
    
    // Add job_id to the WHERE clause for conversation filtering if it's provided
    if ($current_job_id) {
        $conversation_query .= " AND m.job_id = :job_id";
    } else {
        // If no job_id is provided, ensure we only get general messages (job_id IS NULL)
        $conversation_query .= " AND m.job_id IS NULL";
    }
    $conversation_query .= " ORDER BY m.created_at ASC";
    $conversation_stmt = $db->prepare($conversation_query);
    $conversation_stmt->bindParam(':resident_id', $resident_id);
    $conversation_stmt->bindParam(':admin_id', $current_admin_id);
    if ($current_job_id) {
        $conversation_stmt->bindParam(':job_id', $current_job_id, PDO::PARAM_INT);
    }
    $conversation_stmt->execute();
    $conversation_messages = $conversation_stmt->fetchAll(PDO::FETCH_ASSOC);
    // Get job title for conversation header if applicable
    if ($current_job_id) {
        $job_title_query = "SELECT title FROM jobs WHERE id = :job_id";
        $job_title_stmt = $db->prepare($job_title_query);
        $job_title_stmt->bindParam(':job_id', $current_job_id);
        $job_title_stmt->execute();
        $current_job_title = $job_title_stmt->fetchColumn();
    }
    if (empty($conversation_messages)) {
        $error = 'No conversation found with Admin.';
        $action = 'list'; // Fallback to list if no messages
    }
}
// --- List View Logic ---
$conversations = [];
if ($action === 'list') {
    $list_query = "SELECT m.*, 
                   CASE 
                     WHEN m.sender_type = 'resident' THEN 'You'
                     ELSE 'Admin' 
                   END as sender_name,
                   CASE 
                     WHEN m.receiver_type = 'resident' THEN 'You'
                     ELSE 'Admin' 
                   END as receiver_name,
                   j.title as job_title,
                   j.company as job_company
                   FROM messages m 
                   LEFT JOIN jobs j ON m.job_id = j.id
                   WHERE (m.receiver_type = 'resident' AND m.receiver_id = :resident_id) OR (m.sender_type = 'resident' AND m.sender_id = :resident_id)
                   ORDER BY m.created_at DESC";
    $list_stmt = $db->prepare($list_query);
    $list_stmt->bindParam(':resident_id', $_SESSION['user_id']);
    $list_stmt->execute();
    $all_messages = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
    $seen_conversations = [];
    foreach ($all_messages as $msg) {
        // For residents, conversations are always with the admin. Group by job_id if applicable.
        $thread_key = 'admin_' . ($msg['job_id'] ?? 'general'); 
        
        if (!isset($seen_conversations[$thread_key])) {
            $conversations[] = $msg;
            $seen_conversations[$thread_key] = true;
        }
    }
    // Filter conversations based on search
    if (!empty($search)) {
        $filtered_conversations = [];
        foreach ($conversations as $conv) {
            if (stripos($conv['message'] ?? '', $search) !== false ||
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
            return !$conv['is_read'] && $conv['receiver_type'] === 'resident' && $conv['receiver_id'] == $_SESSION['user_id'];
        });
    } elseif ($filter === 'sent') {
        $conversations = array_filter($conversations, function($conv) {
            return $conv['sender_type'] === 'resident' && $conv['sender_id'] == $_SESSION['user_id'];
        });
    } elseif ($filter === 'received') {
        $conversations = array_filter($conversations, function($conv) {
            return $conv['receiver_type'] === 'resident' && $conv['receiver_id'] == $_SESSION['user_id'];
        });
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - JobMatch Resident</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/resident/rmessages.css">
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
        <div class="sidebar resident-sidebar mobile-hidden" id="sidebar">
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
                    <li><a href="notifications.php"><i class="fas fa-bell"></i> Job Notifications
                        <?php if($stats['pending_responses'] > 0): ?>
                            <span class="notification-badge"><?php echo $stats['pending_responses']; ?></span>
                        <?php endif; ?>
                    </a></li>
                    <li>
                        <a href="messages.php" class="active">
                            <i class="fas fa-envelope"></i> Messages
                            <?php if ($stats['unread_messages'] > 0): ?>
                                <span class="notification-badge"><?php echo $stats['unread_messages']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1>Messages</h1>
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
            <!-- Conversations List -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Conversations <?php if ($unread_count > 0): ?><span class="unread-indicator">(<?php echo $unread_count; ?> unread)</span><?php endif; ?></h3>
                    <?php if ($admin_id): // Only allow composing if admin ID is known ?>
                        <a href="?action=compose" class="btn-primary">Create New Message</a>
                    <?php endif; ?>
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
                            <p>Start a conversation by creating a new message to the Admin.</p>
                            <?php if ($admin_id): ?>
                                <div style="margin-bottom: 1.5rem;"></div>
                                <a href="?action=compose" class="btn-primary">Create Message</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="messages-list">
                            <?php foreach ($conversations as $conv): ?>
                            <div class="message-item <?php echo (!$conv['is_read'] && $conv['receiver_type'] === 'resident' && $conv['receiver_id'] == $_SESSION['user_id']) ? 'unread' : ''; ?>">
                                <div class="message-header">
                                    <div class="message-participants">
                                        <strong>
                                            <?php if ($conv['sender_type'] === 'resident'): ?>
                                                To: Admin
                                            <?php else: ?>
                                                From: Admin
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
                                        <?php if (!$conv['is_read'] && $conv['receiver_type'] === 'resident' && $conv['receiver_id'] == $_SESSION['user_id']): ?>
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
                                    <a href="?action=conversation&admin_id=<?php echo htmlspecialchars($admin_id); ?><?php echo $conv['job_id'] ? '&job_id=' . htmlspecialchars($conv['job_id']) : ''; ?>" class="btn-secondary btn-small">View Conversation</a>
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
                <h3>Create New Message to Admin</h3>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="compose">
                    <input type="hidden" name="recipient_id" value="<?php echo htmlspecialchars($admin_id); ?>">
                    
                    <div class="form-group">
                        <label for="message_type">Message Type:</label>
                        <select name="message_type" id="message_type">
                            <option value="general" <?php echo (isset($_POST['message_type']) && $_POST['message_type'] === 'general') ? 'selected' : ''; ?>>General Message</option>
                            <option value="job_offer" <?php echo (isset($_POST['message_type']) && $_POST['message_type'] === 'job_offer') ? 'selected' : ''; ?>>Job Application/Inquiry</option>
                            <option value="notification" <?php echo (isset($_POST['message_type']) && $_POST['message_type'] === 'notification') ? 'selected' : ''; ?>>Notification Response</option>
                            <option value="job_response" <?php echo (isset($_POST['message_type']) && $_POST['message_type'] === 'job_response') ? 'selected' : ''; ?>>Job Offer Response</option>
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
                    <h3>Conversation with Admin
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
                            <div class="chat-bubble <?php echo ($msg['sender_type'] === 'resident') ? 'sent' : 'received'; ?>">
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
                    <h4>Reply to Admin</h4>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="conversation">
                        <input type="hidden" name="recipient_id" value="<?php echo htmlspecialchars($current_admin_id); ?>">
                        <?php if ($current_job_id): ?>
                            <input type="hidden" name="job_id" value="<?php echo htmlspecialchars($current_job_id); ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="message_type" style="color: black">Message Type:</label>
                            <select name="message_type" id="message_type_reply">
                                <option value="general">General Message</option>
                                <option value="job_offer">Job Application/Inquiry</option>
                                <option value="notification">Notification Response</option>
                                <option value="job_response">Job Offer Response</option>
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
                            <label for="subject_reply" style="color: black">Subject:</label>
                            <input type="text" name="subject" id="subject_reply" value="Re: <?php echo htmlspecialchars($conversation_messages[0]['subject'] ?? 'Message'); ?>" placeholder="Reply subject (optional)">
                        </div>
                        <div class="form-group">
                            <label for="message_reply" style="color: black">Your Reply *:</label>
                            <textarea name="message" id="message_reply" rows="4" required placeholder="Type your reply here..."></textarea>
                        </div>
                        <div style="margin-top: 1rem;">
                            <button type="submit" class="btn-primary">Send Reply</button>
                            <a href="messages.php?action=conversation&admin_id=<?php echo htmlspecialchars($current_admin_id); ?><?php echo $current_job_id ? '&job_id=' . htmlspecialchars($current_job_id) : ''; ?>" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
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

</body>
</html>
