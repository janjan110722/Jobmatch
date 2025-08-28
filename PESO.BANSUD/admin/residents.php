<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    if ($action === 'add' || $action === 'edit') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
        $age = $_POST['age'] ?? '';
        $preferred_job = trim($_POST['preferred_job'] ?? '');
        $employed = trim($_POST['employed'] ?? ''); // Changed to text input
        $educational_attainment = trim($_POST['educational_attainment'] ?? '');
    $job_title = trim($_POST['job_title'] ?? '');
        $barangay_id = $_POST['barangay_id'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $gender = $_POST['gender'] ?? '';

        if (empty($first_name) || empty($last_name) || empty($age) || empty($employed) || 
            empty($email) || empty($barangay_id) || empty($gender)) {
            $error = 'Please fill in all required fields';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            if ($action === 'add') {
                $password = $_POST['password'] ?? '';
                if (empty($password) || strlen($password) < 6) {
                    $error = 'Password must be at least 6 characters long';
                } else {
                    // Check if email already exists
                    $check_query = "SELECT id FROM residents WHERE email = :email";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bindParam(':email', $email);
                    $check_stmt->execute();

                    if ($check_stmt->rowCount() > 0) {
                        $error = 'Email address already registered';
                    } else {
                        // Insert new resident
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        $insert_query = "INSERT INTO residents (first_name, middle_name, last_name, age, preferred_job, employed, 
                                       educational_attainment, job_title, barangay_id, email, password, phone, gender)
                                       VALUES (:first_name, :middle_name, :last_name, :age, :preferred_job, :employed, :educational_attainment, 
                                       :job_title, :barangay_id, :email, :password, :phone, :gender)";
                        $insert_stmt = $db->prepare($insert_query);
                        $insert_stmt->bindParam(':first_name', $first_name);
                        $insert_stmt->bindParam(':middle_name', $middle_name);
                        $insert_stmt->bindParam(':last_name', $last_name);
                        $insert_stmt->bindParam(':age', $age);
                        $insert_stmt->bindParam(':preferred_job', $preferred_job);
                        $insert_stmt->bindParam(':employed', $employed);
                        $insert_stmt->bindParam(':educational_attainment', $educational_attainment);
                        $insert_stmt->bindParam(':job_title', $job_title);
                        $insert_stmt->bindParam(':barangay_id', $barangay_id);
                        $insert_stmt->bindParam(':email', $email);
                        $insert_stmt->bindParam(':password', $hashed_password);
                        $insert_stmt->bindParam(':phone', $phone);
                        $insert_stmt->bindParam(':gender', $gender);

                        if ($insert_stmt->execute()) {
                            $message = 'Resident added successfully!';
                            $action = 'list';
                        } else {
                            $error = 'Failed to add resident. Please try again.';
                        }
                    }
                }
            } elseif ($action === 'edit') {
                $resident_id = $_POST['resident_id'] ?? '';
                
                // Check if email exists for other residents
                $check_query = "SELECT id FROM residents WHERE email = :email AND id != :id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':email', $email);
                $check_stmt->bindParam(':id', $resident_id);
                $check_stmt->execute();

                if ($check_stmt->rowCount() > 0) {
                    $error = 'Email address already used by another resident';
                } else {
                    // Update resident
                    $update_query = "UPDATE residents SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, age = :age, 
                                   preferred_job = :preferred_job, employed = :employed, 
                                   educational_attainment = :educational_attainment, job_title = :job_title,
                                   barangay_id = :barangay_id, email = :email, phone = :phone, gender = :gender 
                                   WHERE id = :id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':first_name', $first_name);
                    $update_stmt->bindParam(':middle_name', $middle_name);
                    $update_stmt->bindParam(':last_name', $last_name);
                    $update_stmt->bindParam(':age', $age);
                    $update_stmt->bindParam(':preferred_job', $preferred_job);
                    $update_stmt->bindParam(':employed', $employed);
                    $update_stmt->bindParam(':educational_attainment', $educational_attainment);
                    $update_stmt->bindParam(':job_title', $job_title);
                    $update_stmt->bindParam(':barangay_id', $barangay_id);
                    $update_stmt->bindParam(':email', $email);
                    $update_stmt->bindParam(':phone', $phone);
                    $update_stmt->bindParam(':gender', $gender);
                    $update_stmt->bindParam(':id', $resident_id);

                    if ($update_stmt->execute()) {
                        $message = 'Resident updated successfully!';
                        $action = 'list';
                    } else {
                        $error = 'Failed to update resident. Please try again.';
                    }
                }
            }
        }
    }
}

// Get barangays for dropdown
$barangay_query = "SELECT id, name FROM barangays ORDER BY name";
$barangay_stmt = $db->prepare($barangay_query);
$barangay_stmt->execute();
$barangays = $barangay_stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Get resident data for editing or viewing
$resident_data = null;
if (($action === 'edit' || $action === 'view') && isset($_GET['id'])) {
    $resident_id = $_GET['id'];
    $query = "SELECT r.*, b.name as barangay_name FROM residents r 
              LEFT JOIN barangays b ON r.barangay_id = b.id 
              WHERE r.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $resident_id);
    $stmt->execute();
    $resident_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$resident_data) {
        $error = 'Resident not found';
        $action = 'list';
    }
}

// Get residents list with filters
$search = $_GET['search'] ?? '';
$barangay_filter = $_GET['barangay'] ?? '';
$job_title_filter = $_GET['job_title'] ?? '';
$gender_filter = $_GET['gender'] ?? '';

$where_conditions = [];
$params = [];

// Only show residents who have completed requirements (approved by admin)
if (!empty($search)) {
    $where_conditions[] = "(CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) LIKE :search OR r.email LIKE :search OR r.preferred_job LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($barangay_filter)) {
    $where_conditions[] = "r.barangay_id = :barangay_filter";
    $params[':barangay_filter'] = $barangay_filter;
}

if (!empty($job_title_filter)) {
    $where_conditions[] = "(r.job_title LIKE :job_title_filter OR r.preferred_job LIKE :job_title_filter2)";
    $params[':job_title_filter'] = "%$job_title_filter%";
    $params[':job_title_filter2'] = "%$job_title_filter%";
}

if (!empty($gender_filter)) {
    $where_conditions[] = "r.gender = :gender_filter";
    $params[':gender_filter'] = $gender_filter;
}

// Only show residents who have completed all requirements
$where_conditions[] = "r.requirements_completed = 1";

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$query = "SELECT r.*, b.name as barangay_name FROM residents r 
          LEFT JOIN barangays b ON r.barangay_id = b.id 
          $where_clause 
          ORDER BY r.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Residents - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin/residents.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="../assets/css/login.css">
    <link rel="stylesheet" href="../assets/css/table-sort.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        /* Fix font size consistency in table */
        table td {
            font-size: 0.9rem !important;
            font-weight: normal !important;
        }
        
        /* Ensure Gender column has consistent styling */
        td[data-label="Gender"] {
            font-size: 0.9rem !important;
            font-weight: normal !important;
            text-transform: capitalize;
        }
        
        /* Ensure Employed column has consistent styling */
        td[data-label="Employed"] {
            font-size: 0.9rem !important;
            font-weight: normal !important;
            text-transform: capitalize;
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
                    <li><a href="residents.php" class="active"><i class="fas fa-users"></i> Manage Residents</a></li>
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
                <h1><i class="fas fa-users"></i> Manage Residents</h1>
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
            <!-- Residents List -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Residents List</h3>
                    <a href="?action=add" class="btn-primary">Add New Resident</a>
                </div>

                <!-- Filters -->
                <form method="GET" style="margin-bottom: 1rem;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                        <div class="form-group">
                            <label for="search">Name</label>
                            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name">
                        </div>
                        <div class="form-group">
                            <label for="barangay">Barangay</label>
                            <select name="barangay" id="barangay">
                                <option value="">All Barangay</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['id']; ?>" <?php echo ($barangay_filter == $barangay['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="job_title">Job Title</label>
                            <input type="text" name="job_title" id="job_title" value="<?php echo htmlspecialchars($job_title_filter); ?>" placeholder="e.g., Teacher, Engineer, Driver...">
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select name="gender" id="gender">
                                <option value="">All Genders</option>
                                <option value="Male" <?php echo ($gender_filter == 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($gender_filter == 'Female') ? 'selected' : ''; ?>>Female</option>
                                <option value="Prefer not to say" <?php echo ($gender_filter == 'Prefer not to say') ? 'selected' : ''; ?>>Prefer not to say</option>
                            </select>
                        </div>
                        <button type="submit" class="btn-secondary">Search</button>
                    </div>
                </form>

                <div class="table-container">
                    <div class="table-responsive">
                        <table id="residentsTable" class="auto-sort" data-exclude-columns='[7]'>
                            <thead>
                                <tr>
                                    <th>Full Name</th>
                                    <th>Gender</th>
                                    <th>Employed</th>
                                    <th>Age</th>
                                    <th>Job Title</th>
                                    <th>Preferred Job</th>
                                    <th>Barangay</th>
                                    <th>Contact Number</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($residents)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center;">No residents found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($residents as $resident): ?>
                                    <tr>
                                        <td data-label="Name"><?php echo htmlspecialchars($resident['first_name'] . ' ' . ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . $resident['last_name']); ?></td>
                                        <td data-label="Gender"><?php echo htmlspecialchars($resident['gender'] ?? 'N/A'); ?></td>
                                        <td data-label="Employed"><?php echo htmlspecialchars($resident['employed'] ?? 'N/A'); ?></td>
                                        <td data-label="Age" data-sort="<?php echo $resident['age']; ?>"><?php echo htmlspecialchars($resident['age']); ?></td>
                                        <td data-label="Job Title">
                                            <?php echo htmlspecialchars(isset($resident['job_title']) ? $resident['job_title'] : 'N/A'); ?>
                                        </td>
                                         <td data-label="Preferred Job"><?php echo htmlspecialchars($resident['preferred_job'] ?? 'N/A'); ?></td>
                                        <td data-label="Barangay"><?php echo htmlspecialchars($resident['barangay_name']); ?></td>
                                        <td data-label="Contact Number"><?php echo htmlspecialchars($resident['phone'] ?: 'Not provided'); ?></td>
                                        <td data-label="Actions">
                                            <button onclick="viewResident(<?php echo $resident['id']; ?>)" class="btn-secondary" style="font-size: 0.8rem; padding: 0.3rem 0.6rem;">View</button>
                                            <a href="?action=edit&id=<?php echo $resident['id']; ?>" class="btn-secondary" style="font-size: 0.8rem; padding: 0.3rem 0.6rem;">Edit</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'view'): ?>
            <!-- Resident Details View -->
            <div class="dashboard-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3>Resident Details</h3>
                    <div>
                        <a href="?action=edit&id=<?php echo $resident_data['id']; ?>" class="btn-primary">Edit Resident</a>
                        <a href="residents.php" class="btn-secondary">Back to List</a>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <div>
                        <h4>Personal Information</h4>
                        <div class="detail-item">
                            <div class="detail-label">Full Name:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['first_name'] . ' ' . ($resident_data['middle_name'] ? $resident_data['middle_name'] . ' ' : '') . $resident_data['last_name']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Age:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['age']); ?> years old</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Gender:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['gender'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Email:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['email']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Phone:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['phone'] ?: 'Not provided'); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Barangay:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['barangay_name']); ?></div>
                        </div>
                    </div>

                    <div>
                        <h4>Employment Information</h4>
                        <div class="detail-item">
                            <div class="detail-label">Current Occupation:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['preferred_job'] ?: 'Not specified'); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Employed:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['employed']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Educational Attainment:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['educational_attainment'] ?: 'Not specified'); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Job Title:</div>
                            <div class="detail-value"><?php echo htmlspecialchars($resident_data['job_title'] ?: 'Not specified'); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Registration Date:</div>
                            <div class="detail-value"><?php echo date('F j, Y g:i A', strtotime($resident_data['created_at'])); ?></div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'add' || $action === 'edit'): ?>
            
            <!-- Add/Edit Resident Form -->
            <div class="dashboard-card">
                <h3><?php echo $action === 'add' ? 'Add New Resident' : 'Edit Resident'; ?></h3>
                
                <form method="POST" action="">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="resident_id" value="<?php echo $resident_data['id']; ?>">
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="first_name">First Name *:</label>
                            <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($resident_data['first_name'] ?? $_POST['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name (Optional):</label>
                            <input type="text" name="middle_name" id="middle_name" value="<?php echo htmlspecialchars($resident_data['middle_name'] ?? $_POST['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *:</label>
                            <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($resident_data['last_name'] ?? $_POST['last_name'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="age">Age *:</label>
                            <input type="number" name="age" id="age" min="18" max="100" value="<?php echo htmlspecialchars($resident_data['age'] ?? $_POST['age'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="gender">Gender *:</label>
                            <select name="gender" id="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo ((($resident_data['gender'] ?? '') == 'Male') || ((isset($_POST['gender']) && $_POST['gender'] == 'Male'))) ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ((($resident_data['gender'] ?? '') == 'Female') || ((isset($_POST['gender']) && $_POST['gender'] == 'Female'))) ? 'selected' : ''; ?>>Female</option>
                                <option value="Prefer not to say" <?php echo ((($resident_data['gender'] ?? '') == 'Prefer not to say') || ((isset($_POST['gender']) && $_POST['gender'] == 'Prefer not to say'))) ? 'selected' : ''; ?>>Prefer not to say</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="preferred_job">Preferred Job:</label>
                            <input type="text" name="preferred_job" id="preferred_job" value="<?php echo htmlspecialchars($resident_data['preferred_job'] ?? $_POST['preferred_job'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="employed">Employed:</label>
                            <input type="text" name="employed" id="employed" value="<?php echo htmlspecialchars($resident_data['employed'] ?? $_POST['employed'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="educational_attainment">Educational Attainment:</label>
                            <input type="text" name="educational_attainment" id="educational_attainment" value="<?php echo htmlspecialchars($resident_data['educational_attainment'] ?? $_POST['educational_attainment'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="job_title">Job Title:</label>
                            <input type="text" name="job_title" id="job_title" value="<?php echo htmlspecialchars($resident_data['job_title'] ?? $_POST['job_title'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="barangay_id">Barangay *:</label>
                            <select name="barangay_id" id="barangay_id" required>
                                <option value="">Select Barangay</option>
                                <?php 
                                $selected_barangay = $resident_data['barangay_id'] ?? $_POST['barangay_id'] ?? '';
                                foreach ($barangays as $barangay): 
                                ?>
                                    <option value="<?php echo $barangay['id']; ?>" <?php echo ($selected_barangay == $barangay['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address *:</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" name="email" id="email" placeholder="Enter email address" value="<?php echo htmlspecialchars($resident_data['email'] ?? $_POST['email'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number:</label>
                            <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($resident_data['phone'] ?? $_POST['phone'] ?? ''); ?>">
                        </div>

                        <?php if ($action === 'add'): ?>
                        <div class="form-group">
                            <label for="password">Password *:</label>
                            <div class="input-with-icon password-input-container" style="position: relative;">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" name="password" id="password" placeholder="Enter password" required style="padding-right: 45px;">
                                <span class="password-toggle" onclick="togglePassword()" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: black; font-size: 16px;">
                                    <i class="fas fa-eye-slash" id="toggleIcon"></i>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 1rem;">
                        <button type="submit" class="btn-primary"><?php echo $action === 'add' ? 'Add Resident' : 'Update Resident'; ?></button>
                        <a href="residents.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>

        <?php endif; ?>
    </div>
    <div style="margin-bottom: 1.5rem;"></div>

    <!-- Resident Details Modal -->
    <div id="residentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Resident Details</h3>
                <span class="close" onclick="closeResidentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="detail-section">
                    <h4>Resident ID: <span id="modalResidentId"></span></h4>
                </div>

                <div class="detail-section">
                    <h4>Personal Information</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Full Name</div>
                            <div class="detail-value" id="modalFullName"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Age</div>
                            <div class="detail-value" id="modalAge"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Gender</div>
                            <div class="detail-value" id="modalGender"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Email</div>
                            <div class="detail-value" id="modalEmail"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Phone</div>
                            <div class="detail-value" id="modalPhone"></div>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Location Information</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Barangay</div>
                            <div class="detail-value" id="modalBarangay"></div>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Employment Information</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Preferred Job</div>
                            <div class="detail-value" id="modalOccupation"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Employed</div>
                            <div class="detail-value" id="modalEmploymentStatus"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Educational Attainment</div>
                            <div class="detail-value" id="modalEducation"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Job Title</div>
                            <div class="detail-value" id="modalProfession"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Salary/Income</div>
                            <div class="detail-value" id="modalSalary"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Skills</div>
                            <div class="detail-value" id="modalSkills"></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Sitio</div>
                            <div class="detail-value" id="modalSitio"></div>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Registration Information</h4>
                    <div class="detail-item">
                        <div class="detail-label">Registration Date</div>
                        <div class="detail-value" id="modalRegistrationDate"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Convert PHP residents data to JavaScript
        const residentsData = <?php echo json_encode($residents); ?>;

        function viewResident(residentId) {
            const resident = residentsData.find(r => r.id == residentId);
            
            if (!resident) {
                alert('Resident not found!');
                return;
            }

            // Populate modal with resident data
            document.getElementById('modalResidentId').textContent = resident.id || 'N/A';
            document.getElementById('modalFullName').textContent = (resident.first_name ? resident.first_name + ' ' : '') + (resident.middle_name ? resident.middle_name + ' ' : '') + (resident.last_name ? resident.last_name : 'N/A');
            document.getElementById('modalAge').textContent = (resident.age ? resident.age + ' years old' : 'N/A');
            document.getElementById('modalGender').textContent = resident.gender || 'N/A';
            document.getElementById('modalEmail').textContent = resident.email || 'N/A';
            document.getElementById('modalPhone').textContent = resident.phone || 'Not provided';
            document.getElementById('modalBarangay').textContent = resident.barangay_name || 'N/A';
            document.getElementById('modalOccupation').textContent = resident.preferred_job || 'Not specified';
            document.getElementById('modalEmploymentStatus').textContent = resident.employed || 'N/A';
            document.getElementById('modalEducation').textContent = resident.educational_attainment || 'Not specified';
            document.getElementById('modalProfession').textContent = resident.job_title || 'Not specified';
            document.getElementById('modalSalary').textContent = resident.salary_income || 'Not specified';
            document.getElementById('modalSkills').textContent = resident.skills || 'Not specified';
            document.getElementById('modalSitio').textContent = resident.sitio || 'Not specified';
            
            // Format registration date
            if (resident.created_at) {
                const date = new Date(resident.created_at);
                document.getElementById('modalRegistrationDate').textContent = date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } else {
                document.getElementById('modalRegistrationDate').textContent = 'N/A';
            }

            // Show modal
            document.getElementById('residentModal').style.display = 'block';
        }

        function closeResidentModal() {
            document.getElementById('residentModal').style.display = 'none';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('residentModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Password toggle functionality
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput && toggleIcon) {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    toggleIcon.className = 'fas fa-eye';
                } else {
                    passwordInput.type = 'password';
                    toggleIcon.className = 'fas fa-eye-slash';
                }
            }
        }
    </script>
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

    <script src="../assets/js/table-sort.js"></script>
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
