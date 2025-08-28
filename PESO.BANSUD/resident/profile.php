<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireResident();

$database = new Database();
$db = $database->getConnection();

$resident_id = $_SESSION['user_id'];

// Calculate notification statistics
$stats = [];

// Count unread messages
$unread_query = "SELECT COUNT(*) as unread_count FROM messages WHERE receiver_type = 'resident' AND receiver_id = :resident_id AND is_read = FALSE";
$unread_stmt = $db->prepare($unread_query);
$unread_stmt->bindParam(':resident_id', $resident_id);
$unread_stmt->execute();
$stats['unread_messages'] = $unread_stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

// Count pending job responses
$pending_query = "SELECT COUNT(*) as pending_count FROM job_notifications WHERE resident_id = :resident_id AND status = 'sent'";
$pending_stmt = $db->prepare($pending_query);
$pending_stmt->bindParam(':resident_id', $resident_id);
$pending_stmt->execute();
$stats['pending_responses'] = $pending_stmt->fetch(PDO::FETCH_ASSOC)['pending_count'];

$message = '';
$error = '';

// Handle profile update and profile picture upload together
if ($_POST) {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $age = $_POST['age'] ?? '';
    $preferred_job = trim($_POST['preferred_job'] ?? '');
    $employed = trim($_POST['employed'] ?? '');
    $educational_attainment = trim($_POST['educational_attainment'] ?? '');
    $job_title = trim($_POST['job_title'] ?? '');
    $barangay_id = $_POST['barangay_id'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = $_POST['gender'] ?? '';

    // Handle profile picture upload if file is present
    $profile_picture_filename = $profile['profile_picture'] ?? '';
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
        $fileName = $_FILES['profile_picture']['name'];
        $fileSize = $_FILES['profile_picture']['size'];
        $fileType = $_FILES['profile_picture']['type'];
        $fileNameCmps = explode('.', $fileName);
        $fileExtension = strtolower(end($fileNameCmps));
        $allowedfileExtensions = array('jpg', 'jpeg', 'png', 'gif');
        if (in_array($fileExtension, $allowedfileExtensions)) {
            $newFileName = 'resident_' . $resident_id . '_' . time() . '.' . $fileExtension;
            $uploadFileDir = '../images/';
            $dest_path = $uploadFileDir . $newFileName;
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                $profile_picture_filename = $newFileName;
            } else {
                $error = 'Error uploading file.';
            }
        } else {
            $error = 'Invalid file type. Only jpg, jpeg, png, gif allowed.';
        }
    }

    if (
        empty($first_name) || empty($last_name) || empty($age) || empty($employed) ||
        empty($email) || empty($barangay_id) || empty($gender)
    ) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        // Check if email exists for other residents
        $check_query = "SELECT id FROM residents WHERE email = :email AND id != :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':email', $email);
        $check_stmt->bindParam(':id', $resident_id);
        $check_stmt->execute();

        if ($check_stmt->rowCount() > 0) {
            $error = 'Email address already used by another resident';
        } else {
            // Update resident profile including profile picture
            $update_query = "UPDATE residents SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, age = :age, 
                           preferred_job = :preferred_job, employed = :employed, 
                           educational_attainment = :educational_attainment, job_title = :job_title,
                           barangay_id = :barangay_id, email = :email, phone = :phone, gender = :gender, 
                           profile_picture = :profile_picture 
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
            $update_stmt->bindParam(':profile_picture', $profile_picture_filename);
            $update_stmt->bindParam(':id', $resident_id);

            if ($update_stmt->execute()) {
                $message = 'Profile updated successfully!';
                $_SESSION['username'] = $first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name; // Update session username
                $profile['profile_picture'] = $profile_picture_filename;
            } else {
                $error = 'Failed to update profile. Please try again.';
            }
        }
    }
}

// Handle form submission
if ($_POST && !isset($_POST['upload_pic'])) {
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

    if (
        empty($first_name) || empty($last_name) || empty($age) || empty($employed) ||
        empty($email) || empty($barangay_id) || empty($gender)
    ) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        // Check if email exists for other residents
        $check_query = "SELECT id FROM residents WHERE email = :email AND id != :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':email', $email);
        $check_stmt->bindParam(':id', $resident_id);
        $check_stmt->execute();

        if ($check_stmt->rowCount() > 0) {
            $error = 'Email address already used by another resident';
        } else {
            // Update resident profile
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
                $message = 'Profile updated successfully!';
                $_SESSION['username'] = $first_name . ' ' . ($middle_name ? $middle_name . ' ' : '') . $last_name; // Update session username
            } else {
                $error = 'Failed to update profile. Please try again.';
            }
        }
    }
}

// Get current profile data
$profile_query = "SELECT r.*, b.name as barangay_name FROM residents r 
                  LEFT JOIN barangays b ON r.barangay_id = b.id 
                  WHERE r.id = :id";
$profile_stmt = $db->prepare($profile_query);
$profile_stmt->bindParam(':id', $resident_id);
$profile_stmt->execute();
$profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

// Get barangays for dropdown
$barangay_query = "SELECT id, name FROM barangays ORDER BY name";
$barangay_stmt = $db->prepare($barangay_query);
$barangay_stmt->execute();
$barangays = $barangay_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/resident/rprofile.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="/assets/css/resident/requirements-sidebar.css">
    <link rel="stylesheet" href="../assets/css/login.css">
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
                    <li><a href="profile.php" class="active"><i class="fas fa-user"></i> My Profile</a></li>
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
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-user"></i> My Profile</h1>
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

        <?php if ($message): ?>
            <div class="success-message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Profile Form -->
        <div class="dashboard-card">
            <h3>Update Profile Information</h3>
            <p style="color: white; margin-bottom: 2rem;">Keep your profile updated to receive relevant job opportunities.</p>

            <form method="POST" action="" enctype="multipart/form-data">
                <div style="display: flex; flex-direction: column; align-items: center; margin-bottom: 2rem;">
                    <?php if (!empty($profile['profile_picture'])): ?>
                        <img src="../images/<?php echo htmlspecialchars($profile['profile_picture']); ?>" alt="Profile Picture" style="width:90px;height:90px;border-radius:50%;object-fit:cover;box-shadow:0 2px 8px rgba(0,0,0,0.12);margin-bottom:1rem;">
                    <?php endif; ?>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="profile_picture_form">Profile Picture:</label>
                        <input type="file" name="profile_picture" id="profile_picture_form" accept="image/*">
                    </div>
                    <div class="form-group">
                        <label for="first_name">First Name *:</label>
                        <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($profile['first_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="middle_name">Middle Name (Optional):</label>
                        <input type="text" name="middle_name" id="middle_name" value="<?php echo htmlspecialchars($profile['middle_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *:</label>
                        <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($profile['last_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="age">Age *:</label>
                        <input type="number" name="age" id="age" min="18" max="100" value="<?php echo htmlspecialchars($profile['age']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="gender">Gender *:</label>
                        <select name="gender" id="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male" <?php echo ($profile['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($profile['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other" <?php echo ($profile['gender'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                            <option value="Prefer not to say" <?php echo ($profile['gender'] == 'Prefer not to say') ? 'selected' : ''; ?>>Prefer not to say</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="preferred_job">Preferred Job:</label>
                        <input type="text" name="preferred_job" id="preferred_job" value="<?php echo htmlspecialchars($profile['preferred_job']); ?>" placeholder="e.g., Sales Associate, Student, etc.">
                    </div>

                    <div class="form-group">
                        <label for="employed">Employed *:</label>
                        <input type="text" name="employed" id="employed" value="<?php echo htmlspecialchars($profile['employed']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="educational_attainment">Educational Attainment:</label>
                        <input type="text" name="educational_attainment" id="educational_attainment" value="<?php echo htmlspecialchars($profile['educational_attainment']); ?>" placeholder="e.g., High School Graduate, College Graduate, etc.">
                    </div>

                    <div class="form-group">
                        <label for="job_title">Profession/Job Title:</label>
                        <input type="text" name="job_title" id="job_title" value="<?php echo htmlspecialchars($profile['job_title']); ?>" placeholder="e.g., Information Technology, Marketing, etc.">
                    </div>

                    <div class="form-group">
                        <label for="barangay_id">Barangay *:</label>
                        <select name="barangay_id" id="barangay_id" required>
                            <option value="">Select Barangay</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo $barangay['id']; ?>" <?php echo ($profile['barangay_id'] == $barangay['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($barangay['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address *:</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" name="email" id="email" placeholder="Enter email address" value="<?php echo htmlspecialchars($profile['email']); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number:</label>
                        <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($profile['phone']); ?>" placeholder="e.g., 09123456789">
                    </div>
                </div>

                <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eee;">
                    <button type="submit" class="btn-primary">Update Profile</button>
                    <a href="dashboard.php" class="btn-secondary">Cancel</a>
                </div>
            </form>
        </div>

        <!-- Profile Summary -->
        <div style="margin-bottom: 1.5rem;"></div>
        <div class="dashboard-card">
            <h3>Profile Summary</h3>
            <div class="profile-display">
                <div class="profile-section">
                    <h4>Personal Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <strong>Full Name:</strong>
                            <span><?php echo htmlspecialchars($profile['first_name'] . ' ' . ($profile['middle_name'] ? $profile['middle_name'] . ' ' : '') . $profile['last_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>Age:</strong>
                            <span><?php echo htmlspecialchars($profile['age']); ?> years old</span>
                        </div>
                        <div class="info-item">
                            <strong>Gender:</strong>
                            <span><?php echo htmlspecialchars($profile['gender']); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>Email:</strong>
                            <span><?php echo htmlspecialchars($profile['email']); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>Phone:</strong>
                            <span><?php echo htmlspecialchars($profile['phone'] ?: 'Not provided'); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>Barangay:</strong>
                            <span><?php echo htmlspecialchars($profile['barangay_name']); ?></span>
                        </div>
                    </div>
                </div>


                <div class="profile-section">
                    <h4>Employment Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <strong>Employed:</strong>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $profile['employed'])); ?>">
                                <?php echo htmlspecialchars($profile['employed']); ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <strong>Preferred Job:</strong>
                            <span><?php echo htmlspecialchars($profile['preferred_job'] ?: 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>Educational Attainment:</strong>
                            <span><?php echo htmlspecialchars($profile['educational_attainment'] ?: 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>Profession/Job Title:</strong>
                            <span><?php echo htmlspecialchars($profile['job_title'] ?: 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>Member Since:</strong>
                            <span><?php echo date('F j, Y', strtotime($profile['created_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <strong>Last Updated:</strong>
                            <span><?php echo date('F j, Y g:i A', strtotime($profile['updated_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </div>
    </div>

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
        // Show file input when label is clicked
        const uploadLabel = document.querySelector('.dropdown-upload-label');
        const uploadInput = document.getElementById('profile_picture');
        if (uploadLabel && uploadInput) {
            uploadLabel.addEventListener('click', function() {
                uploadInput.click();
            });
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

</body>

</html>
