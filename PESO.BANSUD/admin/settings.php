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

$admin_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle profile picture upload
if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
        $fileName = $_FILES['profile_picture']['name'];
        $fileNameCmps = explode('.', $fileName);
        $fileExtension = strtolower(end($fileNameCmps));
        $allowedfileExtensions = array('jpg', 'jpeg', 'png', 'gif');
        if (in_array($fileExtension, $allowedfileExtensions)) {
            $newFileName = 'admin_' . $admin_id . '_' . time() . '.' . $fileExtension;
            $uploadFileDir = '../images/';
            $dest_path = $uploadFileDir . $newFileName;
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                // Update profile picture in DB
                $update_pic_query = "UPDATE admins SET profile_picture = :profile_picture, updated_at = NOW() WHERE id = :id";
                $update_pic_stmt = $db->prepare($update_pic_query);
                $update_pic_stmt->bindParam(':profile_picture', $newFileName);
                $update_pic_stmt->bindParam(':id', $admin_id);
                $update_pic_stmt->execute();
                $admin_data['profile_picture'] = $newFileName;
                $message = 'Profile picture updated!';
            } else {
                $error = 'Error uploading file.';
            }
        } else {
            $error = 'Invalid file type. Only jpg, jpeg, png, gif allowed.';
        }
    }
}

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'Please fill in all password fields';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match';
        } elseif (strlen($new_password) < 6) {
            $error = 'New password must be at least 6 characters long';
        } else {
            // Verify current password
            $verify_query = "SELECT password FROM admins WHERE id = :id";
            $verify_stmt = $db->prepare($verify_query);
            $verify_stmt->bindParam(':id', $admin_id);
            $verify_stmt->execute();
            $admin_data = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($current_password, $admin_data['password'])) {
                $error = 'Current password is incorrect';
            } else {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE admins SET password = :password, updated_at = NOW() WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':password', $hashed_password);
                $update_stmt->bindParam(':id', $admin_id);
                
                if ($update_stmt->execute()) {
                    $message = 'Password changed successfully!';
                } else {
                    $error = 'Failed to change password. Please try again.';
                }
            }
        }
    } elseif ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($username) || empty($email)) {
            $error = 'Please fill in all required fields';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address';
        } else {
            // Check if username/email exists for other admins
            $check_query = "SELECT id FROM admins WHERE (username = :username OR email = :email) AND id != :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':username', $username);
            $check_stmt->bindParam(':email', $email);
            $check_stmt->bindParam(':id', $admin_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $error = 'Username or email already exists';
            } else {
                // Update profile
                $update_query = "UPDATE admins SET username = :username, email = :email, updated_at = NOW() WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':username', $username);
                $update_stmt->bindParam(':email', $email);
                $update_stmt->bindParam(':id', $admin_id);
                
                if ($update_stmt->execute()) {
                    $_SESSION['username'] = $username; // Update session
                    $message = 'Profile updated successfully!';
                } else {
                    $error = 'Failed to update profile. Please try again.';
                }
            }
        }
    }
}

// Get current admin data
$admin_query = "SELECT * FROM admins WHERE id = :id";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->bindParam(':id', $admin_id);
$admin_stmt->execute();
$admin_data = $admin_stmt->fetch(PDO::FETCH_ASSOC);

// Get system statistics
$stats_query = "SELECT 
                (SELECT COUNT(*) FROM residents) as total_residents,
                (SELECT COUNT(*) FROM jobs WHERE status = 'Active') as active_jobs,
                (SELECT COUNT(*) FROM job_notifications) as total_notifications,
                (SELECT COUNT(*) FROM messages) as total_messages";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - JobMatch Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/admin/asettings.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="/assets/css/admin/card_icon.css">
    <link rel="stylesheet" href="../assets/css/login.css">
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
                    <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-cog"></i> Settings</h1>
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

        <!-- Profile Settings -->
        <div class="dashboard-card">
            <h3>Profile Settings</h3>
            <p style="color: white; margin-bottom: 1.5rem;">Update your admin profile information.</p>
                <div style="display: flex; flex-direction: column; align-items: center; margin-bottom: 2rem;">
                    <?php if (!empty($admin_data['profile_picture'])): ?>
                        <img src="../images/<?php echo htmlspecialchars($admin_data['profile_picture']); ?>" alt="Profile Picture" style="width:90px;height:90px;border-radius:50%;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.12);margin-bottom:1rem;">
                    <?php else: ?>
                        <img src="../images/PesoLogo.jpg" alt="Profile Picture" style="width:90px;height:90px;border-radius:50%;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.12);margin-bottom:1rem;">
                    <?php endif; ?>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="profile_picture">Profile Picture:</label>
                            <input type="file" name="profile_picture" id="profile_picture" accept="image/*">
                        </div>
                        <div class="form-group">
                            <label for="username">Username *:</label>
                            <div class="input-with-icon">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" name="username" id="username" placeholder="Enter username" value="<?php echo htmlspecialchars($admin_data['username']); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address *:</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" name="email" id="email" placeholder="Enter email address" value="<?php echo htmlspecialchars($admin_data['email']); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Account Created:</label>
                        <input type="text" value="<?php echo date('F j, Y g:i A', strtotime($admin_data['created_at'])); ?>" readonly style="background: transparent;">
                    </div>
                    <div class="form-group">
                        <label>Last Updated:</label>
                        <input type="text" value="<?php echo date('F j, Y g:i A', strtotime($admin_data['updated_at'])); ?>" readonly style="background: transparent;">
                    </div>
                    <div style="margin-top: 1rem;">
                        <button type="submit" class="btn-primary">Update Profile</button>
                    </div>
                </form>
        </div>

        <!-- Password Settings -->
        <div style="margin-bottom: 1.5rem;"></div>

        <div class="dashboard-card">
            <h3>Change Password</h3>
            <p style="color: white; margin-bottom: 1.5rem;">Update your account password for security.</p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                
                <div class="form-group">
                    <label for="current_password">Current Password *:</label>
                    <div class="input-with-icon password-container">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="current_password" id="current_password" placeholder="Enter current password" required>
                        <i class="toggle-password fas fa-eye-slash" onclick="togglePassword('current_password', this)"></i>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="new_password">New Password *:</label>
                        <div class="input-with-icon password-container">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required minlength="6">
                            <i class="toggle-password fas fa-eye-slash" onclick="togglePassword('new_password', this)"></i>
                        </div>
                        <small style="color: white;">Minimum 6 characters</small>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password *:</label>
                        <div class="input-with-icon password-container">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required minlength="6">
                            <i class="toggle-password fas fa-eye-slash" onclick="togglePassword('confirm_password', this)"></i>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 1rem;">
                    <button type="submit" class="btn-primary">Change Password</button>
                </div>
            </form>
        </div>

        <!-- System Information -->
         <div style="margin-bottom: 1.5rem;"></div>
        <div class="dashboard-card">
            <h3>System Information</h3>
            <p style="color: white; margin-bottom: 1.5rem;">Overview of the JobMatch system.</p>
            
            <div class="system-stats">
                <div class="stat-grid">
                    <a href="residents.php" class="stat-item clickable-card">
                        <div class="stat-number"><?php echo number_format($stats['total_residents']); ?></div>
                        <div class="stat-label">Total Residents</div>
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                    </a>
                    <a href="jobs.php" class="stat-item clickable-card">
                        <div class="stat-number"><?php echo number_format($stats['active_jobs']); ?></div>
                        <div class="stat-label">Active Jobs</div>
                        <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
                    </a>
                    <a href="notifications.php" class="stat-item clickable-card">
                        <div class="stat-number"><?php echo number_format($stats['total_notifications']); ?></div>
                        <div class="stat-label">Job Notifications</div>
                        <div class="stat-icon"><i class="fas fa-bell"></i></div>
                    </a>
                    <a href="messages.php" class="stat-item clickable-card">
                        <div class="stat-number"><?php echo number_format($stats['total_messages']); ?></div>
                        <div class="stat-label">Total Messages</div>
                        <div class="stat-icon"><i class="fas fa-envelope"></i></div>
                    </a>
                </div>
            </div>
            
            <div class="system-info"> 
                <h4>System Details</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <strong>System Name:</strong>
                        <span>JobMatch - PESO Labor Force Management System</span>
                        <hr>
                    </div>
                    <div class="info-item">
                        <strong>Version:</strong>
                        <span>1.0.0</span>
                        <hr>
                    </div>
                    <div class="info-item">
                        <strong>Database:</strong>
                        <span>MySQL</span>
                        <hr>
                    </div>
                    <div class="info-item">
                        <strong>Server Time:</strong>
                        <span><?php echo date('F j, Y g:i:s A'); ?></span>
                        
                    </div>
                </div>
                
            </div>
        </div>
        

        <!-- Account Actions -->
        <div style="margin-bottom: 1.5rem;"></div>

        <div class="dashboard-card">
            <h3>Account Action</h3>
           <div class="action-buttons">
                <a href="../auth/logout.php" class="btn-danger" onclick="return confirm('Are you sure you want to logout?')">Logout</a>
            </div>
        </div>
    </div>

    <div style="margin-bottom: 1.5rem;"></div>

    <script>
        function clearCache() {
            if (confirm('Are you sure you want to clear the system cache?')) {
                alert('Cache cleared successfully!');
            }
        }
        
        function exportData() {
            if (confirm('Do you want to export system data?')) {
                alert('Data export feature will be implemented in future updates.');
            }
        }
        
        function viewLogs() {
            alert('System logs feature will be implemented in future updates.');
        }
        
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        function togglePassword(inputId, icon) {
            const passwordInput = document.getElementById(inputId);
            if (passwordInput.type === "password") {
                passwordInput.type = "text";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            } else {
                passwordInput.type = "password";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
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
</html>
