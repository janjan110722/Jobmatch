<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

$admin_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle approval/rejection actions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    $requirement_id = $_POST['requirement_id'] ?? '';
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    if (in_array($action, ['approve', 'reject']) && !empty($requirement_id)) {
        try {
            if ($action == 'approve') {
                $update_query = "UPDATE resident_requirements SET status = 'approved', reviewed_at = NOW(), reviewed_by = :admin_id, rejection_reason = NULL WHERE id = :requirement_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':admin_id', $admin_id);
                $update_stmt->bindParam(':requirement_id', $requirement_id);
                
                if ($update_stmt->execute()) {
                    $message = 'Requirement approved successfully!';
                    
                    // Check if all requirements for this resident are approved
                    $resident_query = "SELECT resident_id FROM resident_requirements WHERE id = :requirement_id";
                    $resident_stmt = $db->prepare($resident_query);
                    $resident_stmt->bindParam(':requirement_id', $requirement_id);
                    $resident_stmt->execute();
                    $resident_data = $resident_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($resident_data) {
                        $check_all_query = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved 
                                          FROM resident_requirements WHERE resident_id = :resident_id";
                        $check_stmt = $db->prepare($check_all_query);
                        $check_stmt->bindParam(':resident_id', $resident_data['resident_id']);
                        $check_stmt->execute();
                        $counts = $check_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($counts['total'] == $counts['approved']) {
                            // All requirements approved, activate account
                            $activate_query = "UPDATE residents SET requirements_completed = 1 WHERE id = :resident_id";
                            $activate_stmt = $db->prepare($activate_query);
                            $activate_stmt->bindParam(':resident_id', $resident_data['resident_id']);
                            $activate_stmt->execute();
                            $message .= ' Resident account fully activated!';
                        }
                    }
                }
            } else { // reject
                if (empty($rejection_reason)) {
                    $error = 'Please provide a reason for rejection.';
                } else {
                    $update_query = "UPDATE resident_requirements SET status = 'rejected', reviewed_at = NOW(), reviewed_by = :admin_id, rejection_reason = :rejection_reason WHERE id = :requirement_id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':admin_id', $admin_id);
                    $update_stmt->bindParam(':requirement_id', $requirement_id);
                    $update_stmt->bindParam(':rejection_reason', $rejection_reason);
                    
                    if ($update_stmt->execute()) {
                        // Deactivate account since requirement was rejected
                        $resident_query = "SELECT resident_id FROM resident_requirements WHERE id = :requirement_id";
                        $resident_stmt = $db->prepare($resident_query);
                        $resident_stmt->bindParam(':requirement_id', $requirement_id);
                        $resident_stmt->execute();
                        $resident_data = $resident_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($resident_data) {
                            $deactivate_query = "UPDATE residents SET requirements_completed = 0 WHERE id = :resident_id";
                            $deactivate_stmt = $db->prepare($deactivate_query);
                            $deactivate_stmt->bindParam(':resident_id', $resident_data['resident_id']);
                            $deactivate_stmt->execute();
                        }
                        
                        $message = 'Requirement rejected successfully!';
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Failed to update requirement: ' . $e->getMessage();
        }
    }
}

// Get pending requirements with resident information
try {
    $requirements_query = "SELECT rr.*, r.first_name, r.middle_name, r.last_name, r.email, b.name as barangay_name,
                                  a.username as reviewed_by_name
                           FROM resident_requirements rr
                           JOIN residents r ON rr.resident_id = r.id
                           LEFT JOIN barangays b ON r.barangay_id = b.id
                           LEFT JOIN admins a ON rr.reviewed_by = a.id
                           ORDER BY rr.status ASC, rr.uploaded_at DESC";
    $requirements_stmt = $db->prepare($requirements_query);
    $requirements_stmt->execute();
    $requirements = $requirements_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage() . '. Please ensure the requirements tables are created by running the database migration.';
    $requirements = [];
}

// Group requirements by resident
$requirements_by_resident = [];
foreach ($requirements as $req) {
    $resident_id = $req['resident_id'];
    if (!isset($requirements_by_resident[$resident_id])) {
        $requirements_by_resident[$resident_id] = [
            'resident_info' => $req,
            'requirements' => []
        ];
    }
    $requirements_by_resident[$resident_id]['requirements'][] = $req;
}

// Get admin profile
$admin_query = "SELECT * FROM admins WHERE id = :id";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->bindParam(':id', $admin_id);
$admin_stmt->execute();
$admin_profile = $admin_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">       
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Requirements - Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin/adashboardbadge.css">
    <link rel="stylesheet" href="/assets/css/profilePic.css">
    <link rel="stylesheet" href="../assets/css/admin/requirements-review.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
       
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
                    <li><a href="residents.php"><i class="fas fa-users"></i> Manage Residents</a></li>
                    <li><a href="pending-accounts.php"><i class="fas fa-user-clock"></i> Pending Accounts</a></li>
                    <li><a href="requirements-review.php" class="active"><i class="fas fa-file-circle-check"></i> Review Requirements</a></li>
                    <li><a href="jobs.php"><i class="fas fa-briefcase"></i> Manage Jobs</a></li>
                    <li><a href="messages.php"><i class="fas fa-envelope"></i> Messages</a></li>
                    <li><a href="notifications.php"><i class="fas fa-bell"></i>Job Notifications</a></li>
                    <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </nav>
        </div>

        <!-- Main Content Area -->
        <div class="main-content has-sidebar-toggle" id="mainContent">
            <div class="header">
                <h1><i class="fas fa-file-circle-check"></i> Review Requirements</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($admin_profile['username']); ?></span>
                    <div class="profile-dropdown">
                        <img src="<?php echo !empty($admin_profile['profile_picture']) ? '../images/' . htmlspecialchars($admin_profile['profile_picture']) : '../images/PesoLogo.jpg'; ?>" class="profile-pic" id="profilePic" alt="Profile Picture">
                        <div class="dropdown-content" id="profileDropdown">
                            <a href="settings.php" class="dropdown-btn"><i class="fas fa-user-edit"></i> Update Profile</a>
                            <a href="../auth/logout.php" class="dropdown-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="requirements-container">
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($requirements_by_resident)): ?>
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                            <br><br>
                            <strong>Troubleshooting Steps:</strong>
                            <ol>
                                <li>Run the database migration script: <code>database_requirements_migration.sql</code></li>
                                <li>Ensure the <code>resident_requirements</code> table exists</li>
                                <li>Check that residents have uploaded requirements</li>
                            </ol>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> No requirements pending review. All requirements have been processed.
                            <br><br>
                            <strong>Current Status:</strong>
                            <ul>
                                <li>Database connection: ✅ Connected</li>
                                <li>Requirements table: ✅ Available</li>
                                <li>Pending requirements: 0</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php foreach ($requirements_by_resident as $resident_id => $data): ?>
                        <div class="resident-card">
                            <div class="resident-header">
                                <div class="resident-info">
                                    <div>
                                        <div class="resident-name">
                                            <?php echo htmlspecialchars($data['resident_info']['first_name'] . ' ' . 
                                                   ($data['resident_info']['middle_name'] ? $data['resident_info']['middle_name'] . ' ' : '') . 
                                                   $data['resident_info']['last_name']); ?>
                                        </div>
                                        <div class="resident-details">
                                            Email: <?php echo htmlspecialchars($data['resident_info']['email']); ?> | 
                                            Barangay: <?php echo htmlspecialchars($data['resident_info']['barangay_name'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php foreach ($data['requirements'] as $req): ?>
                                <div class="requirement-item">
                                    <div class="requirement-icon">
                                        <?php 
                                        $icons = [
                                            'valid_id' => 'fas fa-id-card',
                                            'proof_of_residency' => 'fas fa-home',
                                            'barangay_certificate' => 'fas fa-certificate',
                                            'professional_license' => 'fas fa-award'
                                        ];
                                        echo '<i class="' . ($icons[$req['requirement_type']] ?? 'fas fa-file') . '"></i>';
                                        ?>
                                    </div>
                                    
                                    <div class="requirement-details">
                                        <div class="requirement-title">
                                            <?php echo ucwords(str_replace('_', ' ', $req['requirement_type'])); ?>
                                        </div>
                                        <div class="requirement-file">
                                            File: <?php echo htmlspecialchars($req['original_name']); ?>
                                            <br>Uploaded: <?php echo date('M j, Y g:i A', strtotime($req['uploaded_at'])); ?>
                                            <?php if ($req['reviewed_at']): ?>
                                                <br>Reviewed: <?php echo date('M j, Y g:i A', strtotime($req['reviewed_at'])); ?>
                                                by <?php echo htmlspecialchars($req['reviewed_by_name'] ?? 'Unknown'); ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($req['status'] == 'rejected' && !empty($req['rejection_reason'])): ?>
                                            <div class="rejection-reason">
                                                <i class="fas fa-exclamation-circle"></i>
                                                <strong>Rejection Reason:</strong> <?php echo htmlspecialchars($req['rejection_reason']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="requirement-actions">
                                        <span class="status-badge status-<?php echo $req['status']; ?>">
                                            <?php echo ucfirst($req['status']); ?>
                                        </span>
                                        
                                        <?php if ($req['status'] == 'pending'): ?>
                                            <a href="../uploads/requirements/<?php echo htmlspecialchars($req['file_name']); ?>" 
                                               target="_blank" class="btn-view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="requirement_id" value="<?php echo $req['id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn-approve">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                            
                                            <button class="btn-reject" onclick="showRejectModal(<?php echo $req['id']; ?>)">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        <?php else: ?>
                                            <a href="../uploads/requirements/<?php echo htmlspecialchars($req['file_name']); ?>" 
                                               target="_blank" class="btn-view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Reject Requirement</h2>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="requirement_id" id="rejectRequirementId">
                <input type="hidden" name="action" value="reject">
                
                <label for="rejection_reason">Reason for rejection:</label>
                <textarea name="rejection_reason" id="rejection_reason" required 
                          style="width: 100%; height: 100px; margin: 10px 0; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"
                          placeholder="Please provide a clear reason for rejecting this requirement..."></textarea>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeRejectModal()" style="margin-right: 10px; padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 4px;">Cancel</button>
                    <button type="submit" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 4px;">Reject Requirement</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functionality
        function showRejectModal(requirementId) {
            document.getElementById('rejectRequirementId').value = requirementId;
            document.getElementById('rejectModal').style.display = 'block';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.getElementById('rejection_reason').value = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('rejectModal');
            if (event.target == modal) {
                closeRejectModal();
            }
        }

        // Close modal with X button
        document.querySelector('.close').onclick = closeRejectModal;

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
