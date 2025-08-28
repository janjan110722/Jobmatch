<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/sidebar_helper.php';

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

// Get resident profile
$profile_query = "SELECT r.*, b.name as barangay_name FROM residents r 
                  LEFT JOIN barangays b ON r.barangay_id = b.id 
                  WHERE r.id = :id";
$profile_stmt = $db->prepare($profile_query);
$profile_stmt->bindParam(':id', $resident_id);
$profile_stmt->execute();
$profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

// Handle file uploads
if ($_POST && isset($_POST['upload_requirements'])) {
    $upload_dir = '../uploads/requirements/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
    $max_size = 5 * 1024 * 1024; // 5MB

    $uploaded_files = [];
    $has_errors = false;

    // Process each requirement type
    $requirement_types = ['resume'];

    // Check if at least one file is uploaded
    $files_uploaded = false;
    foreach ($requirement_types as $type) {
        if (isset($_FILES[$type]) && $_FILES[$type]['error'] == UPLOAD_ERR_OK) {
            $files_uploaded = true;
            break;
        }
    }

    if (!$files_uploaded) {
        $error = 'Please select at least one file to upload.';
        $has_errors = true;
    }

    if (!$has_errors) {
        foreach ($requirement_types as $type) {
            if (isset($_FILES[$type]) && $_FILES[$type]['error'] == UPLOAD_ERR_OK) {
                $file = $_FILES[$type];
                
                // Validate file type
                if (!in_array($file['type'], $allowed_types)) {
                    $error = "Invalid file type for " . str_replace('_', ' ', $type) . ". Only JPG, PNG, and PDF files are allowed.";
                    $has_errors = true;
                    break;
                }
                
                // Validate file size
                if ($file['size'] > $max_size) {
                    $error = "File too large for " . str_replace('_', ' ', $type) . ". Maximum size is 5MB.";
                    $has_errors = true;
                    break;
                }
                
                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = $resident_id . '_' . $type . '_' . time() . '.' . $extension;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    $uploaded_files[$type] = [
                        'filename' => $filename,
                        'original_name' => $file['name'],
                        'file_path' => $filepath,
                        'file_size' => $file['size'],
                        'mime_type' => $file['type']
                    ];
                } else {
                    $error = "Failed to upload " . str_replace('_', ' ', $type) . ".";
                    $has_errors = true;
                    break;
                }
            }
        }
    }

    if (!$has_errors && !empty($uploaded_files)) {
        // Check if resident_requirements table exists
        try {
            $check_table = "SHOW TABLES LIKE 'resident_requirements'";
            $check_stmt = $db->prepare($check_table);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() == 0) {
                $error = 'Database table not found. Please run the database migration first.';
            } else {
                // Save to database
                $db->beginTransaction();
                
                // Delete old requirements for this resident
                $delete_query = "DELETE FROM resident_requirements WHERE resident_id = :resident_id";
                $delete_stmt = $db->prepare($delete_query);
                $delete_stmt->bindParam(':resident_id', $resident_id);
                $delete_stmt->execute();
                
                // Insert new requirements
                foreach ($uploaded_files as $type => $file_info) {
                    $insert_query = "INSERT INTO resident_requirements (resident_id, requirement_type, file_name, original_name, file_path, file_size, mime_type) 
                                    VALUES (:resident_id, :requirement_type, :file_name, :original_name, :file_path, :file_size, :mime_type)";
                    $insert_stmt = $db->prepare($insert_query);
                    $insert_stmt->bindParam(':resident_id', $resident_id);
                    $insert_stmt->bindParam(':requirement_type', $type);
                    $insert_stmt->bindParam(':file_name', $file_info['filename']);
                    $insert_stmt->bindParam(':original_name', $file_info['original_name']);
                    $insert_stmt->bindParam(':file_path', $file_info['file_path']);
                    $insert_stmt->bindParam(':file_size', $file_info['file_size']);
                    $insert_stmt->bindParam(':mime_type', $file_info['mime_type']);
                    $insert_stmt->execute();
                }
                
                // Update requirements completion status
                // Don't mark as completed until admin approves
                $update_query = "UPDATE residents SET requirements_completed = 0 WHERE id = :resident_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':resident_id', $resident_id);
                $update_stmt->execute();
                
                // Keep session as incomplete until admin approval
                $_SESSION['requirements_completed'] = 0;
                
                $db->commit();
                $message = 'Requirements uploaded successfully! Please wait for admin approval before accessing all features.';
            }
        } catch (Exception $e) {
            $db->rollback();
            $error = 'Failed to save requirements: ' . $e->getMessage();
        }
    }
}

// Get current requirements
$requirements_query = "SELECT * FROM resident_requirements WHERE resident_id = :resident_id";
$requirements_stmt = $db->prepare($requirements_query);
$requirements_stmt->bindParam(':resident_id', $resident_id);
$requirements_stmt->execute();
$current_requirements = $requirements_stmt->fetchAll(PDO::FETCH_ASSOC);

# Check requirements status for display
$all_requirements_approved = true;
$has_uploaded_requirements = false;
$pending_requirements = 0;
$rejected_requirements = 0;

if (!empty($current_requirements)) {
    $has_uploaded_requirements = true;
    foreach ($current_requirements as $req) {
        if ($req['status'] == 'pending') {
            $all_requirements_approved = false;
            $pending_requirements++;
        } elseif ($req['status'] == 'rejected') {
            $all_requirements_approved = false;
            $rejected_requirements++;
        }
    }
}

// Update session based on admin approval
if ($has_uploaded_requirements && $all_requirements_approved && empty($pending_requirements)) {
    $_SESSION['requirements_completed'] = 1;
    // Update database
    $update_query = "UPDATE residents SET requirements_completed = 1 WHERE id = :resident_id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':resident_id', $resident_id);
    $update_stmt->execute();
} else {
    $_SESSION['requirements_completed'] = 0;
}

$requirements_by_type = [];
foreach ($current_requirements as $req) {
    $requirements_by_type[$req['requirement_type']] = $req;
}
?>

<!DOCTYPE html>
<html lang="en">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Requirements - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/resident/rsettings.css">
    <link rel="stylesheet" href="../assets/css/resident/requirements-sidebar.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .requirements-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .requirement-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #2196f3;
        }
        
        .requirement-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .requirement-header i {
            font-size: 1.5rem;
            color: #2196f3;
            margin-right: 0.5rem;
        }
        
        .requirement-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
        }
        
        .requirement-description {
            color: #666;
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        
        .file-upload-area {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            background: #fafafa;
            transition: all 0.3s ease;
        }
        
        .file-upload-area:hover {
            border-color: #2196f3;
            background: #f0f8ff;
        }
        
        .file-upload-area.dragover {
            border-color: #2196f3;
            background: #e3f2fd;
        }
        
        .upload-icon {
            font-size: 3rem;
            color: #bbb;
            margin-bottom: 1rem;
        }
        
        .upload-text {
            color: #666;
            margin-bottom: 1rem;
        }
        
        .file-input {
            display: none;
        }
        
        .upload-btn {
            background: #2196f3;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .upload-btn:hover {
            background: #1976d2;
        }
        
        .current-file {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 4px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .current-file i {
            color: #4caf50;
            margin-right: 0.5rem;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .status-not-uploaded {
            background: #e2e3e5;
            color: #495057;
        }

        .rejection-reason {
            background: #ffe6e6;
            border: 1px solid #ffcccc;
            border-radius: 4px;
            padding: 0.5rem;
            margin-top: 0.5rem;
            color: #721c24;
            font-size: 0.9rem;
        }

        .rejection-reason i {
            color: #dc3545;
            margin-right: 0.5rem;
        }        .submit-requirements {
            background: linear-gradient(135deg, #4caf50, #45a049);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            margin-top: 2rem;
        }
        
        .submit-requirements:hover {
            background: linear-gradient(135deg, #45a049, #3d8b40);
        }
        
        .submit-requirements:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        /* Sidebar styling for disabled/locked state */
        .sidebar-nav a.disabled {
            color: #999 !important;
            cursor: not-allowed !important;
            opacity: 0.5;
            position: relative;
        }

        .sidebar-nav a.disabled:hover {
            background: none !important;
            color: #999 !important;
        }

        .sidebar-nav a.highlight {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7) !important;
            color: #856404 !important;
            border-left: 4px solid #ffc107;
            animation: pulse 2s infinite;
        }

        .sidebar-nav .badge {
            padding: 0.2rem 0.5rem;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 0.5rem;
            font-weight: bold;
        }

        .sidebar-nav .badge.required {
            background: #dc3545;
            color: white;
            animation: blink 1.5s infinite;
        }

        .sidebar-nav .badge.completed {
            background: #28a745;
            color: white;
        }

        .locked-icon {
            margin-left: auto;
            opacity: 0.7;
        }

        .locked-icon i {
            font-size: 0.8rem;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
        }

        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0.5; }
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
                <h2><i class="fas fa-user-circle"></i> JobMatch</h2>
                <span class="resident-label">Resident Portal</span>
            </div>
            <?php echo generateResidentSidebar('requirements', $stats); ?>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-file-upload"></i> Complete Requirements</h1>
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

            <div class="requirements-container">
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                        <script>
                            // Auto-redirect after successful upload
                            setTimeout(function() {
                                window.location.href = 'dashboard.php';
                            }, 3000);
                        </script>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php 
                // Check for redirected access from other pages
                if (isset($_GET['error']) && $_GET['error'] == 'complete_requirements_first'): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Access Restricted:</strong> You need to complete your requirements first to access that feature.
                        <br><small>Please upload your resume below to activate your account.</small>
                    </div>
                <?php elseif (isset($_GET['from'])): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Requirements Required:</strong> You need to complete your requirements to access <?php 
                        echo ucfirst(str_replace('_', ' ', htmlspecialchars($_GET['from']))); ?>.
                        <br><small>Please upload your resume below to get started.</small>
                    </div>
                <?php endif; ?>

                <?php if ($has_uploaded_requirements && $pending_requirements > 0): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-clock"></i> 
                        <strong>Pending Admin Review:</strong> Your requirements are uploaded and waiting for admin approval. You'll gain full access once approved.
                        <br><small>Requirements pending review: <?php echo $pending_requirements; ?></small>
                    </div>
                <?php elseif ($has_uploaded_requirements && $rejected_requirements > 0): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-times-circle"></i> 
                        <strong>Requirements Rejected:</strong> Some of your requirements were rejected. Please re-upload corrected documents.
                        <br><small>Requirements rejected: <?php echo $rejected_requirements; ?></small>
                    </div>
                <?php elseif ($has_uploaded_requirements && $all_requirements_approved): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> 
                        <strong>Requirements Approved:</strong> All your requirements have been approved! You now have full access to JobMatch features.
                    </div>
                <?php else: ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Action Required:</strong> Please upload all required documents below to activate your account.
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="requirementsForm">
                    <input type="hidden" name="upload_requirements" value="1">
                    
                    <!-- Resume -->
                    <div class="requirement-card">
                        <div class="requirement-header">
                            <i class="fas fa-file-alt"></i>
                            <div class="requirement-title">Resume</div>
                        </div>
                        <div class="requirement-description">
                            Upload your most recent resume or CV
                        </div>
                        
                        <?php if (isset($requirements_by_type['resume'])): ?>
                            <div class="current-file">
                                <i class="fas fa-file"></i>
                                Current file: <?php echo htmlspecialchars($requirements_by_type['resume']['original_name']); ?>
                                <span class="status-badge status-<?php echo $requirements_by_type['resume']['status']; ?>">
                                    <?php echo ucfirst($requirements_by_type['resume']['status']); ?>
                                </span>
                                <?php if ($requirements_by_type['resume']['status'] == 'rejected' && !empty($requirements_by_type['resume']['rejection_reason'])): ?>
                                    <div class="rejection-reason">
                                        <i class="fas fa-exclamation-circle"></i>
                                        <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($requirements_by_type['resume']['rejection_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="file-upload-area" onclick="document.getElementById('resume').click()">
                            <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="upload-text">Click to select or drag and drop your file here</div>
                            <div class="upload-text">Maximum file size: 5MB | Supported formats: JPG, PNG, PDF</div>
                            <button type="button" class="upload-btn">Choose File</button>
                        </div>
                        <input type="file" id="resume" name="resume" class="file-input" accept=".jpg,.jpeg,.png,.pdf">
                    </div>

                    <button type="submit" class="submit-requirements" id="submitBtn">
                        <i class="fas fa-upload"></i> Upload Requirements
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Form validation and submission
        document.getElementById('requirementsForm').addEventListener('submit', function(e) {
            const fileInputs = document.querySelectorAll('.file-input');
            let hasFiles = false;
            
            fileInputs.forEach(input => {
                if (input.files && input.files.length > 0) {
                    hasFiles = true;
                }
            });
            
            if (!hasFiles) {
                e.preventDefault();
                alert('Please select at least one file to upload before submitting.');
                return false;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            submitBtn.disabled = true;
        });

        // Add file selection feedback
        document.querySelectorAll('.file-input').forEach(input => {
            input.addEventListener('change', function() {
                const uploadArea = this.previousElementSibling;
                const fileName = this.files[0] ? this.files[0].name : 'No file selected';
                const uploadText = uploadArea.querySelector('.upload-text');
                if (this.files[0]) {
                    uploadText.textContent = `Selected: ${fileName}`;
                    uploadArea.style.borderColor = '#4caf50';
                    uploadArea.style.backgroundColor = '#f1f8e9';
                    
                    // Update submit button state
                    updateSubmitButton();
                } else {
                    uploadText.textContent = 'Click to select or drag and drop your file here';
                    uploadArea.style.borderColor = '#ddd';
                    uploadArea.style.backgroundColor = '#fafafa';
                }
            });
        });

        // Update submit button based on file selection
        function updateSubmitButton() {
            const fileInputs = document.querySelectorAll('.file-input');
            const submitBtn = document.getElementById('submitBtn');
            let hasFiles = false;
            
            fileInputs.forEach(input => {
                if (input.files && input.files.length > 0) {
                    hasFiles = true;
                }
            });
            
            if (hasFiles) {
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
                submitBtn.disabled = false;
            } else {
                submitBtn.style.opacity = '0.6';
                submitBtn.style.cursor = 'not-allowed';
                submitBtn.disabled = true;
            }
        }

        // Initialize submit button state
        updateSubmitButton();

        // Sidebar toggle functionality
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-visible');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        });

        document.getElementById('sidebarOverlay').addEventListener('click', function() {
            document.getElementById('sidebar').classList.remove('mobile-visible');
            this.classList.remove('active');
        });

        // Profile dropdown
        document.getElementById('profilePic').addEventListener('click', function() {
            document.getElementById('profileDropdown').classList.toggle('show');
        });

        // Close dropdown when clicking outside
        window.addEventListener('click', function(event) {
            if (!event.target.matches('.profile-pic')) {
                const dropdown = document.getElementById('profileDropdown');
                if (dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            }
        });

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
    </script>
</body>
</html>
