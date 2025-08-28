<?php
require_once '../config/database.php';
require_once '../config/session.php';

// Start output buffering to prevent "headers already sent" errors
ob_start(); 

requireAdmin();

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? 'list';
$message = $_GET['message'] ?? ''; // Initialize message from GET for PRG pattern
$error = '';

// Function to handle redirects or direct message display if headers sent
function handleRedirectOrMessage($msg_type, $msg_content) {
    if (!headers_sent()) {
        header("Location: jobs.php?$msg_type=" . urlencode($msg_content));
        exit();
    } else {
        // Fallback if headers already sent (e.g., due to accidental output)
        // This will display the message on the current page load
        global $message, $error, $action; // Access global variables
        if ($msg_type === 'message') {
            $message = $msg_content;
        } else {
            $error = $msg_content;
        }
        $action = 'list'; // Ensure list view is shown
    }
}

// Get barangays for dropdown
$barangays_query = "SELECT id, name FROM barangays ORDER BY name";
$barangays_stmt = $db->prepare($barangays_query);
$barangays_stmt->execute();
$barangays = $barangays_stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Handle form submissions
if ($_POST) {
    if ($action === 'add' || $action === 'edit') {
        $title = trim($_POST['title'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $job_type = $_POST['job_type'] ?? '';
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $requirements = trim($_POST['requirements'] ?? '');
        $status = $_POST['status'] ?? 'Active';
        $barangay_id = !empty($_POST['barangay_id']) ? $_POST['barangay_id'] : null;
        $max_positions = !empty($_POST['max_positions']) ? (int)$_POST['max_positions'] : 1;
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        
        if (empty($title) || empty($company) || empty($job_type)) {
            $error = 'Please fill in all required fields';
            // Do not redirect on validation error, let the form re-display with error
        } elseif ($max_positions < 1) {
            $error = 'Number of available positions must be at least 1';
        } else {
            if ($action === 'add') {
                // Insert new job
                $insert_query = "INSERT INTO jobs (title, company, job_type, location, description, requirements, status, barangay_id, max_positions, deadline) 
                               VALUES (:title, :company, :job_type, :location, :description, :requirements, :status, :barangay_id, :max_positions, :deadline)";
                
                $stmt = $db->prepare($insert_query);
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':company', $company);
                $stmt->bindParam(':job_type', $job_type);
                $stmt->bindParam(':location', $location);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':requirements', $requirements);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':barangay_id', $barangay_id, PDO::PARAM_INT);
                $stmt->bindParam(':max_positions', $max_positions, PDO::PARAM_INT);
                $stmt->bindParam(':deadline', $deadline);
                
                if ($stmt->execute()) {
                    handleRedirectOrMessage('message', 'Job added successfully!');
                } else {
                    $error = 'Failed to add job. Please try again.';
                }
            } elseif ($action === 'edit' && isset($_POST['id'])) {
                $id = $_POST['id'];
                
                // Update existing job
                $update_query = "UPDATE jobs SET title = :title, company = :company, job_type = :job_type, 
                               location = :location, description = :description, requirements = :requirements, 
                               status = :status, barangay_id = :barangay_id, max_positions = :max_positions, deadline = :deadline, updated_at = NOW() 
                               WHERE id = :id";
                
                $stmt = $db->prepare($update_query);
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':company', $company);
                $stmt->bindParam(':job_type', $job_type);
                $stmt->bindParam(':location', $location);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':requirements', $requirements);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':barangay_id', $barangay_id, PDO::PARAM_INT);
                $stmt->bindParam(':max_positions', $max_positions, PDO::PARAM_INT);
                $stmt->bindParam(':deadline', $deadline);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    handleRedirectOrMessage('message', 'Job updated successfully!');
                } else {
                    $error = 'Failed to update job. Please try again.';
                }
            }
        }
    } elseif ($action === 'delete' && isset($_POST['id'])) {
        $id = $_POST['id'];
        
        // Check if job has notifications
        $check_query = "SELECT COUNT(*) as count FROM job_notifications WHERE job_id = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $id);
        $check_stmt->execute();
        $has_notifications = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        
        if ($has_notifications) {
            // Just mark as inactive instead of deleting
            $update_query = "UPDATE jobs SET status = 'Inactive', updated_at = NOW() WHERE id = :id";
            $stmt = $db->prepare($update_query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                handleRedirectOrMessage('message', 'Job marked as inactive because it has associated notifications.');
            } else {
                $error = 'Failed to update job status. Please try again.';
            }
        } else {
            // Delete job if no notifications
            $delete_query = "DELETE FROM jobs WHERE id = :id";
            $stmt = $db->prepare($delete_query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                handleRedirectOrMessage('message', 'Job deleted successfully!');
            } else {
                $error = 'Failed to delete job. Please try again.';
            }
        }
    }
}

// Get job data for editing or pre-filling form on error
$job_data = null;
if ($_POST && !empty($error) && ($action === 'add' || $action === 'edit')) {
    // If there was a POST request with an error, pre-fill form with submitted data
    $job_data = $_POST;
} elseif (($action === 'edit' || $action === 'view') && isset($_GET['id'])) {
    $id = $_GET['id'];
    $query = "SELECT j.*, b.name as barangay_name FROM jobs j 
              LEFT JOIN barangays b ON j.barangay_id = b.id 
              WHERE j.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $job_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$job_data) {
        $error = 'Job not found';
        $action = 'list'; // Fallback to list view if job not found
    }
}

// Function to get filled positions safely
function getFilledPositions($db, $job_id) {
    try {
        // Change from job_applications to job_notifications table
        $filled_query = "SELECT COUNT(*) as filled FROM job_notifications WHERE job_id = :id AND status = 'accepted'";
        $filled_stmt = $db->prepare($filled_query);
        $filled_stmt->bindParam(':id', $job_id);
        $filled_stmt->execute();
        return $filled_stmt->fetch(PDO::FETCH_ASSOC)['filled'];
    } catch (PDOException $e) {
        // If table doesn't exist, return 0
        return 0;
    }
}

// Get jobs list with filters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(title LIKE :search OR company LIKE :search OR location LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($status_filter)) {
    $where_conditions[] = "status = :status_filter";
    $params[':status_filter'] = $status_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "job_type = :type_filter"; // Corrected parameter name
    $params[':type_filter'] = $type_filter; // Corrected parameter name
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get jobs from the database
$query = "SELECT j.*, b.name as barangay_name
          FROM jobs j 
          LEFT JOIN barangays b ON j.barangay_id = b.id 
          $where_clause 
          ORDER BY j.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$raw_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process jobs to add filled_positions, ensuring no reference issues
$jobs = [];
foreach ($raw_jobs as $job_item) {
    $job_item['filled_positions'] = getFilledPositions($db, $job_item['id']);
    $jobs[] = $job_item;
}

// Get job types for filter
$job_types_query = "SELECT DISTINCT job_type FROM jobs ORDER BY job_type";
$job_types_stmt = $db->prepare($job_types_query);
$job_types_stmt->execute();
$job_types = $job_types_stmt->fetchAll(PDO::FETCH_COLUMN);

// End output buffering and send output
ob_end_flush(); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Jobs - JobMatch Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin/ajobs.css">
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
                    
                    <li><a href="jobs.php" class="active"><i class="fas fa-briefcase"></i> Manage Jobs</a></li>
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
                <h1><i class="fas fa-briefcase"></i> Manage Jobs</h1>
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
            <!-- Jobs List -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Jobs List</h3>
                    <a href="?action=add" class="btn-primary">Add New Job</a>
                </div>

                <!-- Filters -->
                <form method="GET" style="margin-bottom: 1rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                        <div class="form-group">
                            <label for="search">Search:</label>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Job title, company, or location">
                        </div>
                        <div class="form-group">
                            <label for="status">Status:</label>
                            <select name="status" id="status">
                                <option value="">All Status</option>
                                <option value="Active" <?php echo ($status_filter === 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo ($status_filter === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="type">Job Type:</label>
                            <select name="type" id="type">
                                <option value="">All Types</option>
                                <?php foreach ($job_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($type_filter === $type) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-secondary">Search</button>
                    </div>
                </form>

                <div class="table-container">
                    <div class="table-responsive">
                        <table id="jobsTable" class="auto-sort" data-exclude-columns='[8]'>
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Company</th>
                                    <th>Type</th>
                                    <th>Location</th>
                                    <th>Positions</th>
                                    <th>Status</th>
                                    <th>Posted Date</th>
                                    <th>Deadline</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($jobs)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center;">No jobs found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($jobs as $job): ?>
                                    <tr>
                                        <td data-label="Title"><?php echo htmlspecialchars($job['title']); ?></td>
                                        <td data-label="Company"><?php echo htmlspecialchars($job['company']); ?></td>
                                        <td data-label="Type"><?php echo htmlspecialchars($job['job_type']); ?></td>
                                        <td data-label="Location"><?php echo htmlspecialchars($job['location'] ?: $job['barangay_name'] ?: 'Any location'); ?></td>
                                        <td data-label="Positions" data-sort="<?php echo $job['filled_positions']; ?>">
                                            <div class="positions-info">
                                                <span class="filled"><?php echo $job['filled_positions']; ?></span>
                                                <span class="separator">/</span>
                                                <span class="total"><?php echo $job['max_positions'] ?? 1; ?></span>
                                                <?php if ($job['filled_positions'] >= ($job['max_positions'] ?? 1)): ?>
                                                    <span class="full-badge">FULL</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status-badge status-<?php echo strtolower($job['status']); ?>">
                                                <?php echo htmlspecialchars($job['status']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Posted Date" data-sort="<?php echo strtotime($job['created_at']); ?>"><?php echo date('M j, Y', strtotime($job['created_at'])); ?></td>
                                        <td data-label="Deadline" data-sort="<?php echo $job['deadline'] ? strtotime($job['deadline']) : '0'; ?>">
                                            <?php if ($job['deadline']): ?>
                                                <?php 
                                                $deadline_date = new DateTime($job['deadline']);
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
                                        <td data-label="Actions">
                                            <a href="?action=view&id=<?php echo $job['id']; ?>" class="btn-secondary btn-small">View</a>
                                            <a href="?action=edit&id=<?php echo $job['id']; ?>" class="btn-primary btn-small">Edit</a>
                                            <button onclick="confirmDelete(<?php echo $job['id']; ?>, '<?php echo htmlspecialchars($job['title']); ?>')" class="btn-danger btn-small">Delete</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div style="margin-bottom: 1.5rem;"></div>

        <?php elseif ($action === 'add' || $action === 'edit'): ?>

            <!-- Add/Edit Job Form -->
               
            <div class="dashboard-card">
                <h3><?php echo ($action === 'add') ? 'Add New Job' : 'Edit Job'; ?></h3>
                
                <form method="POST" action="">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($job_data['id']); ?>">
                    <?php endif; ?>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="title">Job Title *:</label>
                            <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($job_data['title'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="company">Company *:</label>
                            <input type="text" name="company" id="company" value="<?php echo htmlspecialchars($job_data['company'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="job_type">Job Type *:</label>
                            <select name="job_type" id="job_type" required>
                                <option value="">Select Job Type</option>
                                <option value="Full-time" <?php echo (isset($job_data['job_type']) && $job_data['job_type'] === 'Full-time') ? 'selected' : ''; ?>>Full-time</option>
                                <option value="Part-time" <?php echo (isset($job_data['job_type']) && $job_data['job_type'] === 'Part-time') ? 'selected' : ''; ?>>Part-time</option>
                                <option value="Contract" <?php echo (isset($job_data['job_type']) && $job_data['job_type'] === 'Contract') ? 'selected' : ''; ?>>Contract</option>
                                <option value="Temporary" <?php echo (isset($job_data['job_type']) && $job_data['job_type'] === 'Temporary') ? 'selected' : ''; ?>>Temporary</option>
                                <option value="Internship" <?php echo (isset($job_data['job_type']) && $job_data['job_type'] === 'Internship') ? 'selected' : ''; ?>>Internship</option>
                                <option value="Freelance" <?php echo (isset($job_data['job_type']) && $job_data['job_type'] === 'Freelance') ? 'selected' : ''; ?>>Freelance</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="max_positions">Available Positions *:</label>
                            <input type="number" name="max_positions" id="max_positions" min="1" max="50" 
                                   value="<?php echo htmlspecialchars($job_data['max_positions'] ?? '1'); ?>" required>
                            <small class="field-help" style="color: white;">Number of residents who can accept this job</small>
                        </div>

                        <div class="form-group">
                            <label for="status">Status:</label>
                            <select name="status" id="status">
                                <option value="Active" <?php echo (isset($job_data['status']) && $job_data['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo (isset($job_data['status']) && $job_data['status'] === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="barangay_id">Preferred Barangay:</label>
                            <select name="barangay_id" id="barangay_id">
                                <option value="">All Barangay</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['id']; ?>" <?php echo (isset($job_data['barangay_id']) && $job_data['barangay_id'] == $barangay['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="deadline">Application Deadline:</label>
                        <input type="datetime-local" name="deadline" id="deadline" 
                               value="<?php echo isset($job_data['deadline']) && $job_data['deadline'] ? date('Y-m-d\TH:i', strtotime($job_data['deadline'])) : ''; ?>">
                        <small class="field-help" style="color: white;">Set a deadline for job applications</small>
                    </div>

                    <div class="form-group">
                        <label for="location">Location (Optional):</label>
                        <input type="text" name="location" id="location" value="<?php echo htmlspecialchars($job_data['location'] ?? ''); ?>" placeholder="Specific address or location details">
                    </div>

                    <div class="form-group">
                        <label for="description">Job Description:</label>
                        <textarea name="description" id="description" rows="5"><?php echo htmlspecialchars($job_data['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="requirements">Requirements:</label>
                        <textarea name="requirements" id="requirements" rows="5"><?php echo htmlspecialchars($job_data['requirements'] ?? ''); ?></textarea>
                    </div>

                    
                        <button type="submit" class="btn-primary"><?php echo ($action === 'add') ? 'Add Job' : 'Update Job'; ?></button>
                        <a href="jobs.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
            <div style="margin-bottom: 1rem;">

        <?php elseif ($action === 'view'): ?>
            <!-- View Job Details -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Job Details</h3>
                    <div>
                        <a href="?action=edit&id=<?php echo htmlspecialchars($job_data['id']); ?>" class="btn-primary">Edit Job</a>
                        <a href="jobs.php" class="btn-secondary">Back to Jobs</a>
                    </div>
                </div>

                <div class="job-details">
                    <div class="job-header">
                        <h2><?php echo htmlspecialchars($job_data['title']); ?></h2>
                        <div class="job-company"><?php echo htmlspecialchars($job_data['company']); ?></div>
                        <div class="job-meta">
                            <span class="job-type"><?php echo htmlspecialchars($job_data['job_type']); ?></span>
                            <span class="status-badge status-<?php echo strtolower($job_data['status']); ?>"><?php echo htmlspecialchars($job_data['status']); ?></span>
                            <?php
                            $filled_positions = getFilledPositions($db, $job_data['id']);
                            $max_positions = $job_data['max_positions'] ?? 1;
                            ?>
                            <span class="positions-badge">
                                <?php echo $filled_positions; ?>/<?php echo $max_positions; ?> Positions
                                <?php if ($filled_positions >= $max_positions): ?>
                                    <span class="full-indicator">FULL</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4>Location</h4>
                        <p><?php echo htmlspecialchars($job_data['location'] ?: $job_data['barangay_name'] ?: 'Any location'); ?></p>
                    </div>

                    <?php if ($job_data['description']): ?>
                    <div class="detail-section">
                        <h4>Job Description</h4>
                        <p><?php echo nl2br(htmlspecialchars($job_data['description'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($job_data['requirements']): ?>
                    <div class="detail-section">
                        <h4>Requirements</h4>
                        <p><?php echo nl2br(htmlspecialchars($job_data['requirements'])); ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="detail-section">
                        <h4>Additional Information</h4>
                        <div class="info-grid">
                            <div class="info-item">
                                <strong>Posted Date:</strong>
                                <span><?php echo date('F j, Y', strtotime($job_data['created_at'])); ?></span>
                            </div>
                            <div class="info-item">
                                <strong>Last Updated:</strong>
                                <span><?php echo date('F j, Y', strtotime($job_data['updated_at'])); ?></span>
                            </div>
                            <div class="info-item">
                                <strong>Application Deadline:</strong>
                                <span>
                                    <?php if ($job_data['deadline']): ?>
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
                                    <?php else: ?>
                                        <span class="no-deadline-text">No deadline set</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <strong>Target Barangay:</strong>
                                <span><?php echo htmlspecialchars($job_data['barangay_name'] ?: 'Any barangay'); ?></span>
                            </div>
                            <div class="info-item">
                                <strong>Available Positions:</strong>
                                <span><?php echo $max_positions; ?></span>
                            </div>
                            <div class="info-item">
                                <strong>Filled Positions:</strong>
                                <span><?php echo $filled_positions; ?></span>
                            </div>
                            <div class="info-item">
                                <strong>Remaining Slots:</strong>
                                <span><?php echo max(0, $max_positions - $filled_positions); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Confirm Delete</h3>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the job: <span id="jobTitle"></span>?</p>
                    <p class="warning-text">This action cannot be undone. Jobs with notifications will be marked as inactive instead of being deleted.</p>
                    <form method="POST" action="?action=delete" id="deleteForm">
                        <input type="hidden" name="id" id="deleteId">
                        <div class="modal-actions">
                            <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                            <button type="submit" class="btn-danger">Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function confirmDelete(id, title) {
            document.getElementById('deleteId').value = id;
            document.getElementById('jobTitle').textContent = title;
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
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
