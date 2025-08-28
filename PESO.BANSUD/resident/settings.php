<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireResident();

// Check if requirements are completed
$requirements_completed = $_SESSION['requirements_completed'] ?? 0;
if ($requirements_completed == 0) {
    // Redirect to requirements page with message
    header("Location: requirements.php?error=complete_requirements_first");
    exit();
}

$database = new Database();
$db = $database->getConnection();

$resident_id = $_SESSION['user_id'];
$message = '';
$error = '';

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
            $verify_query = "SELECT password FROM residents WHERE id = :id";
            $verify_stmt = $db->prepare($verify_query);
            $verify_stmt->bindParam(':id', $resident_id);
            $verify_stmt->execute();
            $resident_data = $verify_stmt->fetch(PDO::FETCH_ASSOC);

            if (!password_verify($current_password, $resident_data['password'])) {
                $error = 'Current password is incorrect';
            } else {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE residents SET password = :password, updated_at = NOW() WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':password', $hashed_password);
                $update_stmt->bindParam(':id', $resident_id);

                if ($update_stmt->execute()) {
                    $message = 'Password changed successfully!';
                } else {
                    $error = 'Failed to change password. Please try again.';
                }
            }
        }
    } elseif ($action === 'update_preferences') {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $job_alerts = isset($_POST['job_alerts']) ? 1 : 0;
        $preferred_job_types = $_POST['preferred_job_types'] ?? [];

        // Convert array to JSON for storage
        $preferred_job_types_json = json_encode($preferred_job_types);

        // Update preferences (we'll add these columns to the residents table)
        $update_query = "UPDATE residents SET 
                        email_notifications = :email_notifications,
                        job_alerts = :job_alerts,
                        preferred_job_types = :preferred_job_types,
                        updated_at = NOW() 
                        WHERE id = :id";

        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':email_notifications', $email_notifications);
        $update_stmt->bindParam(':job_alerts', $job_alerts);
        $update_stmt->bindParam(':preferred_job_types', $preferred_job_types_json);
        $update_stmt->bindParam(':id', $resident_id);

        if ($update_stmt->execute()) {
            $message = 'Preferences updated successfully!';
        } else {
            $error = 'Failed to update preferences. Please try again.';
        }
    }
}

// Get current resident data
$resident_query = "SELECT * FROM residents WHERE id = :id";
$resident_stmt = $db->prepare($resident_query);
$resident_stmt->bindParam(':id', $resident_id);
$resident_stmt->execute();
$resident_data = $resident_stmt->fetch(PDO::FETCH_ASSOC);

// Get resident statistics
$stats_query = "SELECT 
                (SELECT COUNT(*) FROM job_notifications WHERE resident_id = :resident_id) as total_notifications,
                (SELECT COUNT(*) FROM job_notifications WHERE resident_id = :resident_id AND status = 'accepted') as accepted_jobs,
                (SELECT COUNT(*) FROM job_notifications WHERE resident_id = :resident_id AND status = 'declined') as declined_jobs,
                (SELECT COUNT(*) FROM job_notifications WHERE resident_id = :resident_id AND status = 'sent') as pending_responses,
                (SELECT COUNT(*) FROM messages WHERE receiver_type = 'resident' AND receiver_id = :resident_id AND is_read = 0) as unread_messages,
                (SELECT COUNT(*) FROM messages WHERE (sender_type = 'resident' AND sender_id = :resident_id) OR (receiver_type = 'resident' AND receiver_id = :resident_id)) as total_messages";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':resident_id', $resident_id);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Decode preferred job types
$preferred_job_types = [];
if (!empty($resident_data['preferred_job_types'])) {
    $preferred_job_types = json_decode($resident_data['preferred_job_types'], true) ?: [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/resident/rsettings.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="/assets/css/resident/requirements-sidebar.css">
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
                    <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages
                        <?php if($stats['unread_messages'] > 0): ?>
                            <span class="notification-badge"><?php echo $stats['unread_messages']; ?></span>
                        <?php endif; ?>
                    </a></li>
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

        <!-- Account Information -->
        <div class="dashboard-card">
            <h3>Account Information</h3>
            <p style="color: white; margin-bottom: 1rem;">Your account details and statistics.</p>

            <div class="account-info">
                <div class="info-section">
                    <h4>Personal Details</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <hr>
                            <strong>Full Name:</strong>
                            <span><?php echo htmlspecialchars($resident_data['first_name'] . ' ' . ($resident_data['middle_name'] ? $resident_data['middle_name'] . ' ' : '') . $resident_data['last_name']); ?></span>
                            <hr>
                        </div>
                        <div class="info-item">
                            <strong>Email:</strong>
                            <span><?php echo htmlspecialchars($resident_data['email']); ?></span>
                            <hr>
                        </div>
                        <div class="info-item">
                            <strong>Employed:</strong>
                            <span><?php echo htmlspecialchars($resident_data['employed']); ?></span>
                            <hr>
                        </div>
                        <div class="info-item">
                            <strong>Member Since:</strong>
                            <span><?php echo date('F j, Y', strtotime($resident_data['created_at'])); ?></span>
                            <hr>
                        </div>
                    </div>
                </div>

                <div class="info-section">
                    <h4>Activity Statistics</h4>
                    <div class="stat-grid">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo number_format($stats['total_notifications']); ?></div>
                            <div class="stat-label">Total Job Notifications</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo number_format($stats['accepted_jobs']); ?></div>
                            <div class="stat-label">Accepted Jobs</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo number_format($stats['declined_jobs']); ?></div>
                            <div class="stat-label">Declined Jobs</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo number_format($stats['total_messages']); ?></div>
                            <div class="stat-label">Messages</div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top: 1rem;">
                <a href="profile.php" class="btn-secondary">Update Profile</a>
            </div>
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


        <!-- Notification Preferences -->
        <div style="margin-bottom: 1.5rem;"></div>
        <div class="dashboard-card">
            <h3>Notification Preferences</h3>
            <p style="color: white; margin-bottom: 1.5rem;">Customize how you receive job notifications and updates.</p>

            <form method="POST" action="">
                <input type="hidden" name="action" value="update_preferences">

                <div class="preferences-section">
                    <h4>General Preferences</h4>
                    <div class="preference-options">
                        <label class="preference-option">
                            <input type="checkbox" name="email_notifications" value="1" <?php echo ($resident_data['email_notifications'] ?? 0) ? 'checked' : ''; ?>>
                            <div class="option-content">
                                <strong style="color: black;">Email Notifications</strong>
                                <span style="color: #e2d5d5ff;">Receive email notifications for job opportunities and updates</span>
                            </div>
                        </label>

                        <label class="preference-option">
                            <input type="checkbox" name="job_alerts" value="1" <?php echo ($resident_data['job_alerts'] ?? 0) ? 'checked' : ''; ?>>
                            <div class="option-content">
                                <strong style="color: black;">Job Alerts</strong>
                                <span style="color: #e2d5d5ff;">Get notified when new jobs matching your profile are posted</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="preferences-section">
                    <h4>Preferred Job Types</h4>
                    <p style="color: #e2d5d5ff; font-size: 0.9rem; margin-bottom: 1rem;">Select the types of jobs you're interested in to receive relevant notifications.</p>
                    <div class="job-type-options">
                        <?php
                        $job_types = ['Full-time', 'Part-time', 'Contract', 'Temporary', 'Freelance', 'Internship'];
                        foreach ($job_types as $type):
                        ?>
                            <label class="job-type-option">
                                <input type="checkbox" name="preferred_job_types[]" value="<?php echo $type; ?>" <?php echo in_array($type, $preferred_job_types) ? 'checked' : ''; ?>>
                                <span><?php echo $type; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top: 2rem;">
                    <button type="submit" class="btn-primary">Save Preferences</button>
                </div>
            </form>
        </div>

        <!-- Account Actions -->
        <div style="margin-bottom: 1.5rem;"></div>
        <div class="dashboard-card">
            <h3>Account Action</h3>
            <div class="action-buttons">
                <a href="../auth/logout.php" class="btn-danger" onclick="return confirm('Are you sure you want to logout?')">Logout</a>
            </div>

            <div class="account-warning">
                <h4>⚠️ Important Information</h4>
                <ul>
                    <li>Keep your profile updated to receive relevant job opportunities</li>
                    <li>Respond promptly to job notifications from PESO admin</li>
                    <li>Contact admin if you have any issues with your account</li>
                </ul>
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
        function downloadData() {
            if (confirm('Do you want to download your account data?')) {
                alert('Data download feature will be implemented in future updates.');
            }
        }

        function clearHistory() {
            if (confirm('Are you sure you want to clear your notification history? This action cannot be undone.')) {
                alert('History clearing feature will be implemented in future updates.');
            }
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
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
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