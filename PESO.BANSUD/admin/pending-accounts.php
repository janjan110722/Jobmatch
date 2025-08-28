<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireAdmin();

$database = new Database();
$db = $database->getConnection();

// Get admin profile
$admin_id = $_SESSION['user_id'];
$admin_query = "SELECT * FROM admins WHERE id = :id";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->bindParam(':id', $admin_id);
$admin_stmt->execute();
$admin_profile = $admin_stmt->fetch(PDO::FETCH_ASSOC);

$message = '';
$error = '';

// Handle approval/rejection actions
if ($_POST) {
    if (isset($_POST['action']) && isset($_POST['resident_id'])) {
        $resident_id = $_POST['resident_id'];
        $action = $_POST['action'];
        
        if ($action === 'approve') {
            $update_query = "UPDATE residents SET approved = 1 WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':id', $resident_id);
            
            if ($update_stmt->execute()) {
                $message = 'Account approved successfully!';
            } else {
                $error = 'Failed to approve account.';
            }
        } elseif ($action === 'reject') {
            // Delete the account if rejected
            $delete_query = "DELETE FROM residents WHERE id = :id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':id', $resident_id);
            
            if ($delete_stmt->execute()) {
                $message = 'Account rejected and removed from system.';
            } else {
                $error = 'Failed to reject account.';
            }
        }
    }
}

// Get pending accounts
$pending_query = "SELECT r.*, b.name as barangay_name 
                  FROM residents r 
                  LEFT JOIN barangays b ON r.barangay_id = b.id 
                  WHERE r.approved = 0 
                  ORDER BY r.created_at DESC";
$pending_stmt = $db->prepare($pending_query);
$pending_stmt->execute();
$pending_accounts = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Accounts - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/admin/residents.css">
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
                        <a href="pending-accounts.php" class="active">
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
                <h1> <i class="fas fa-user-clock"></i> Pending Account Approvals</h1>
                <div class="user-info">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <div class="profile-dropdown">
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

                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>Accounts Awaiting Approval</h3>
                        <p class="card-description">
                            Review and approve or reject new resident registration requests.
                        </p>
                    </div>

                    <?php if (empty($pending_accounts)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-check" style="font-size: 4rem; color: #ccc; margin-bottom: 1rem;"></i>
                            <h3>No Pending Accounts</h3>
                            <p>All registration requests have been processed.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <div class="table-responsive">
                                <table id="pendingAccountsTable" class="auto-sort" data-exclude-columns='[8]'>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Age</th>
                                            <th>Gender</th>
                                            <th>Barangay</th>
                                            <th>Employed</th>
                                            <th>Phone</th>
                                            <th>Registered Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_accounts as $account): ?>
                                        <tr>
                                            <td data-label="Name">
                                                <?php echo htmlspecialchars($account['first_name'] . ' ' . 
                                                    ($account['middle_name'] ? $account['middle_name'] . ' ' : '') . 
                                                    $account['last_name']); ?>
                                            </td>
                                            <td data-label="Email"><?php echo htmlspecialchars($account['email']); ?></td>
                                            <td data-label="Age" data-sort="<?php echo $account['age']; ?>"><?php echo htmlspecialchars($account['age']); ?></td>
                                            <td data-label="Gender"><?php echo htmlspecialchars($account['gender']); ?></td>
                                            <td data-label="Barangay"><?php echo htmlspecialchars($account['barangay_name']); ?></td>
                                            <td data-label="Employed"><?php echo htmlspecialchars($account['employed']); ?></td>
                                            <td data-label="Phone"><?php echo htmlspecialchars($account['phone'] ?: 'N/A'); ?></td>
                                            <td data-label="Registered Date" data-sort="<?php echo strtotime($account['created_at']); ?>"><?php echo date('M j, Y', strtotime($account['created_at'])); ?></td>
                                            <td data-label="Actions">
                                                <div class="action-buttons">
                                                    <button type="button" class="btn-view" onclick="viewAccountDetails(<?php echo $account['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to approve this account?');">
                                                        <input type="hidden" name="resident_id" value="<?php echo $account['id']; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn-approve">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reject and delete this account? This action cannot be undone.');">
                                                        <input type="hidden" name="resident_id" value="<?php echo $account['id']; ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="btn-reject">
                                                            <i class="fas fa-times"></i> Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Details Modal -->
    <div id="accountModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Account Details</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="accountDetails">
                <!-- Account details will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Image Modal for Valid ID -->
    <div id="imageModal" class="modal" style="z-index: 1001;">
        <div class="modal-content" style="max-width: 90%; max-height: 90%;">
            <div class="modal-header">
                <h3>Valid ID - Full Size</h3>
                <span class="close" onclick="closeImageModal()">&times;</span>
            </div>
            <div class="modal-body" style="text-align: center; padding: 20px;">
                <img id="fullSizeImage" src="" alt="Valid ID Full Size" style="max-width: 100%; max-height: 70vh; object-fit: contain;">
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

    // Modal functionality
    function viewAccountDetails(accountId) {
        const accounts = <?php echo json_encode($pending_accounts); ?>;
        const account = accounts.find(acc => acc.id == accountId);
        
        if (account) {
            const detailsHtml = `
                <div class="account-modal-layout">
                    <div class="account-details-left">
                        <div class="account-detail-grid">
                            <div class="detail-item">
                                <strong>Full Name:</strong>
                                <span>${account.first_name} ${account.middle_name || ''} ${account.last_name}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Email:</strong>
                                <span>${account.email}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Age:</strong>
                                <span>${account.age}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Gender:</strong>
                                <span>${account.gender}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Phone:</strong>
                                <span>${account.phone || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Barangay:</strong>
                                <span>${account.barangay_name}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Employed:</strong>
                                <span>${account.employed}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Current Occupation:</strong>
                                <span>${account.occupation || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Profession:</strong>
                                <span>${account.profession || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Educational Attainment:</strong>
                                <span>${account.educational_attainment || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Salary/Income:</strong>
                                <span>${account.salary_income || 'Not specified'}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Skills:</strong>
                                <span>${account.skills || 'Not specified'}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Sitio:</strong>
                                <span>${account.sitio || 'Not specified'}</span>
                            </div>
                            <div class="detail-item">
                                <strong>Registration Date:</strong>
                                <span>${new Date(account.created_at).toLocaleDateString()}</span>
                            </div>
                        </div>
                    </div>
                    <div class="account-details-right">
                        <div class="valid-id-section">
                            <strong>Valid ID:</strong>
                            <div class="valid-id-container">
                                ${account.valid_id ? 
                                    `<img src="../uploads/valid_ids/${account.valid_id}" alt="Valid ID" class="valid-id-image" onclick="openImageModal('../uploads/valid_ids/${account.valid_id}')" style="max-width: 100%; max-height: 300px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; margin-top: 10px;">
                                    <small style="display: block; color: #666; margin-top: 5px; text-align: center;">Click to view full size</small>` 
                                    : '<span style="color: #999;">No ID uploaded</span>'
                                }
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('accountDetails').innerHTML = detailsHtml;
            document.getElementById('accountModal').style.display = 'block';
        }
    }

    function closeModal() {
        document.getElementById('accountModal').style.display = 'none';
    }

    // Image modal functions
    function openImageModal(imageSrc) {
        document.getElementById('fullSizeImage').src = imageSrc;
        document.getElementById('imageModal').style.display = 'block';
    }

    function closeImageModal() {
        document.getElementById('imageModal').style.display = 'none';
    }

    // Close modal when clicking outside of it
    window.onclick = function(event) {
        const modal = document.getElementById('accountModal');
        const imageModal = document.getElementById('imageModal');
        if (event.target == modal) {
            modal.style.display = 'none';
        }
        if (event.target == imageModal) {
            imageModal.style.display = 'none';
        }
    }

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

    <style>
    /* Additional styles for pending accounts page */
    .btn-approve {
        background-color: #28a745;
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.875rem;
        margin: 0 0.25rem;
        transition: background-color 0.3s;
    }

    .btn-approve:hover {
        background-color: #218838;
    }

    .btn-reject {
        background-color: #dc3545;
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.875rem;
        margin: 0 0.25rem;
        transition: background-color 0.3s;
    }

    .btn-reject:hover {
        background-color: #c82333;
    }

    .btn-view {
        background-color: #17a2b8;
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.875rem;
        margin: 0 0.25rem;
        transition: background-color 0.3s;
    }

    .btn-view:hover {
        background-color: #138496;
    }

    .action-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
    }

    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #666;
    }

    /* Modal styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.4);
    }

    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 0;
        border: none;
        border-radius: 8px;
        width: 90%;
        max-width: 600px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        margin: 0;
        color: #495057;
    }

    .close {
        color: #aaa;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover,
    .close:focus {
        color: #000;
        text-decoration: none;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .account-detail-grid {
        display: grid;
        gap: 1rem;
    }

    .detail-item {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .detail-item strong {
        color: #495057;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .detail-item span {
        color: #212529;
        font-size: 1rem;
    }

    @media (max-width: 768px) {
        .action-buttons {
            flex-direction: column;
        }
        
        .action-buttons form {
            width: 100%;
        }
        
        .btn-approve, .btn-reject, .btn-view {
            width: 100%;
            margin: 0.125rem 0;
        }
        
        .modal-content {
            width: 95%;
            margin: 10% auto;
        }
    }

    /* Valid ID styles */
    .account-modal-layout {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 2rem;
        min-height: 400px;
    }

    .account-details-left {
        padding-right: 1rem;
    }

    .account-details-right {
        padding-left: 1rem;
        border-left: 1px solid #dee2e6;
        display: flex;
        flex-direction: column;
    }

    .valid-id-section {
        margin-top: 0;
        padding-top: 0;
        border-top: none;
        text-align: center;
    }

    .valid-id-container {
        margin-top: 0.5rem;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .valid-id-image {
        display: block;
        max-width: 100%;
        max-height: 300px;
        border: 1px solid #ddd;
        border-radius: 4px;
        cursor: pointer;
        margin-top: 10px;
        transition: opacity 0.3s ease;
        object-fit: contain;
    }

    .valid-id-image:hover {
        opacity: 0.8;
    }

    .valid-id-container small {
        display: block;
        color: #666;
        margin-top: 5px;
        font-size: 0.75rem;
        text-align: center;
    }

    @media (max-width: 768px) {
        .account-modal-layout {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .account-details-right {
            border-left: none;
            border-top: 1px solid #dee2e6;
            padding-left: 0;
            padding-top: 1rem;
        }
    }
    </style>

    <script src="../assets/js/table-sort.js"></script>
</body>
</html>
