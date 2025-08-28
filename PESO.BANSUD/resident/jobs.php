<?php
require_once '../config/database.php';
require_once '../config/session.php';

requireResident();

// Check if requirements are completed
$requirements_completed = $_SESSION['requirements_completed'] ?? 0;
if ($requirements_completed == 0) {
    header('Location: requirements.php?from=jobs');
    exit();
}

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

// Get resident profile including profile_picture
$resident_query = "SELECT * FROM residents WHERE id = :id";
$resident_stmt = $db->prepare($resident_query);
$resident_stmt->bindParam(':id', $resident_id);
$resident_stmt->execute();
$resident_data = $resident_stmt->fetch(PDO::FETCH_ASSOC);

// Get jobs list with filters
$search = $_GET['search'] ?? '';
$barangay_filter = $_GET['barangay'] ?? '';
$type_filter = $_GET['type'] ?? '';
$show_all = $_GET['show_all'] ?? '';

$where_conditions = ["j.status = 'Active'"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(j.title LIKE :search OR j.company LIKE :search OR j.description LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($barangay_filter)) {
    $where_conditions[] = "(j.barangay_id = :barangay_filter OR j.barangay_id IS NULL)";
    $params[':barangay_filter'] = $barangay_filter;
} elseif (empty($show_all) && $resident_data['barangay_id']) {
    // Show jobs for resident's barangay and general jobs by default
    $where_conditions[] = "(j.barangay_id = :resident_barangay OR j.barangay_id IS NULL)";
    $params[':resident_barangay'] = $resident_data['barangay_id'];
}

if (!empty($type_filter)) {
    $where_conditions[] = "j.job_type = :type_filter";
    $params[':type_filter'] = $type_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

$query = "SELECT j.*, b.name as barangay_name,
          (SELECT COUNT(*) FROM job_notifications jn WHERE jn.job_id = j.id AND jn.resident_id = :resident_id) as is_notified
          FROM jobs j 
          LEFT JOIN barangays b ON j.barangay_id = b.id 
          $where_clause 
          ORDER BY j.created_at DESC";

$stmt = $db->prepare($query);
$stmt->bindParam(':resident_id', $resident_id);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get barangays for filter dropdown
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
    <title>Available Jobs - JobMatch</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/resident/rjobs.css">
    <link rel="stylesheet" href="../assets/css/profilePic.css">
    <link rel="stylesheet" href="../assets/css/resident/requirements-sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .expired-text {
            color: red;
        }

        .deadline-text {
            color: green;
        }

        .no-deadline-text {
            font-style: italic;
            color: gray;
        }

        .expired-indicator {
            font-weight: bold;
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
            <nav class="sidebar-nav">
                <ul>
                    <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                     <li><a href="requirements.php"><i class="fas fa-file-upload"></i> Complete Requirements <span class="badge completed"><i class="fas fa-check"></i></span></a></li>
                    <li><a href="jobs.php" class="active"><i class="fas fa-briefcase"></i> Jobs</a></li>
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
                <h1>Jobs</h1>
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

        <!-- Jobs List -->
        <div class="dashboard-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3>Job Opportunities</h3>
                <div class="job-count">
                    <span><?php echo count($jobs); ?> job(s) found</span>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" style="margin-bottom: 2rem;">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto auto; gap: 1rem; align-items: end;">
                    <div class="form-group">
                        <label for="search">Search Jobs:</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Job title, company, or description">
                    </div>
                    <div class="form-group">
                        <label for="barangay">Barangay:</label>
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
                        <label for="type">Job Type:</label>
                        <select name="type" id="type">
                            <option value="">All Types</option>
                            <option value="Full-time" <?php echo ($type_filter === 'Full-time') ? 'selected' : ''; ?>>Full-time</option>
                            <option value="Part-time" <?php echo ($type_filter === 'Part-time') ? 'selected' : ''; ?>>Part-time</option>
                            <option value="Contract" <?php echo ($type_filter === 'Contract') ? 'selected' : ''; ?>>Contract</option>
                            <option value="Temporary" <?php echo ($type_filter === 'Temporary') ? 'selected' : ''; ?>>Temporary</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-secondary">Search</button>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="show_all" value="1" <?php echo $show_all ? 'checked' : ''; ?>>
                            Show all jobs
                        </label>
                    </div>
                </div>
            </form>

            <!-- Jobs Grid -->
            <?php if (empty($jobs)): ?>
                <div class="no-jobs">
                    <h4>No jobs found</h4>
                    <p>Try adjusting your search criteria or check back later for new opportunities.</p>
                    <a href="?show_all=1" class="btn-primary">Show All Available Jobs</a>
                </div>
            <?php else: ?>
                <div class="jobs-grid">
                    <?php foreach ($jobs as $job): ?>
                    <div class="job-card <?php echo $job['is_notified'] > 0 ? 'notified' : ''; ?>">
                        <div class="job-header">
                            <h4><?php echo htmlspecialchars($job['title']); ?></h4>
                            <div class="job-badges">
                                <span class="job-type-badge"><?php echo htmlspecialchars($job['job_type']); ?></span>
                                <?php if ($job['is_notified'] > 0): ?>
                                    <span class="notified-badge">Notified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="job-company">
                            <strong><?php echo htmlspecialchars($job['company']); ?></strong>
                        </div>
                        
                        <div class="job-location">
                            <span class="location-icon">📍</span>
                            <?php echo htmlspecialchars($job['location'] ?: ($job['barangay_name'] ?: 'Any location')); ?>
                        </div>
                        
                        <div class="job-description">
                            <?php 
                            $description = $job['description'];
                            echo nl2br(htmlspecialchars(strlen($description) > 150 ? substr($description, 0, 150) . '...' : $description));
                            ?>
                        </div>
                        
                        <?php if ($job['requirements']): ?>
                        <div class="job-requirements">
                            <strong>Requirements:</strong>
                            <?php 
                            $requirements = $job['requirements'];
                            echo nl2br(htmlspecialchars(strlen($requirements) > 100 ? substr($requirements, 0, 100) . '...' : $requirements));
                            ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="job-footer">
                            <div class="job-date">
                                Posted: <?php echo date('M j, Y', strtotime($job['created_at'])); ?>
                            </div>
                            <div class="job-actions">
                                <button onclick="viewJob(<?php echo $job['id']; ?>)" class="btn-primary btn-small">View Details</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Job Details Modal -->
    <div id="jobModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"></h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Job details will be loaded here -->
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
        function updateCountdown(deadline, element) {
            const now = new Date().getTime();
            const deadlineTime = new Date(deadline).getTime();
            const distance = deadlineTime - now;
            
            if (distance < 0) {
                element.innerHTML = 'Expired';
                return;
            }
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            
            if (days > 0) {
                element.innerHTML = `${days} day${days > 1 ? 's' : ''}, ${hours} hour${hours > 1 ? 's' : ''}`;
            } else if (hours > 0) {
                element.innerHTML = `${hours} hour${hours > 1 ? 's' : ''}, ${minutes} minute${minutes > 1 ? 's' : ''}`;
            } else {
                element.innerHTML = `${minutes} minute${minutes > 1 ? 's' : ''}`;
            }
        }

        function viewJob(jobId) {
            // Find job data
            const jobs = <?php echo json_encode($jobs); ?>;
            const job = jobs.find(j => j.id == jobId);
            
            if (job) {
                document.getElementById('modalTitle').textContent = job.title;
                
                let modalContent = `
                    <div class="job-detail">
                        <div class="detail-section">
                            <h4>Company</h4>
                            <p>${job.company}</p>
                        </div>
                        
                        <div class="detail-section">
                            <h4>Job Type</h4>
                            <p>${job.job_type}</p>
                        </div>
                        
                        <div class="detail-section">
                            <h4>Location</h4>
                            <p>${job.location || job.barangay_name || 'Any location'}</p>
                        </div>
                        
                        ${job.description ? `
                        <div class="detail-section">
                            <h4>Job Description</h4>
                            <p>${job.description.replace(/\n/g, '<br>')}</p>
                        </div>
                        ` : ''}
                        
                        ${job.requirements ? `
                        <div class="detail-section">
                            <h4>Requirements</h4>
                            <p>${job.requirements.replace(/\n/g, '<br>')}</p>
                        </div>
                        ` : ''}
                        
                        <div class="detail-section">
                            <h4>Posted Date</h4>
                            <p>${new Date(job.created_at).toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric' 
                            })}</p>
                        </div>

                        ${job.deadline ? `
                        <div class="detail-section">
                            <h4>Application Deadline</h4>
                            <p class="${new Date(job.deadline) < new Date() ? 'expired-text' : 'deadline-text'}">
                                ${new Date(job.deadline).toLocaleDateString('en-US', { 
                                    year: 'numeric', 
                                    month: 'long', 
                                    day: 'numeric',
                                    hour: 'numeric',
                                    minute: '2-digit',
                                    hour12: true
                                })}
                                ${new Date(job.deadline) < new Date() ? ' <span class="expired-indicator">(EXPIRED)</span>' : ''}
                            </p>
                            ${new Date(job.deadline) > new Date() ? `
                            <p class="time-remaining">
                                <small>Time remaining: <span id="countdown-${job.id}"></span></small>
                            </p>
                            ` : ''}
                        </div>
                        ` : `
                        <div class="detail-section">
                            <h4>Application Deadline</h4>
                            <p class="no-deadline-text">No deadline set - Apply anytime</p>
                        </div>
                        `}
                        
                        ${job.is_notified > 0 ? `
                        <div class="alert-info">
                            <strong>Note:</strong> You have already been notified about this job opportunity.
                        </div>
                        ` : ''}
                    </div>
                `;
                
                document.getElementById('modalBody').innerHTML = modalContent;

                // Add countdown timer if deadline exists and not expired
                if (job.deadline && new Date(job.deadline) > new Date()) {
                    const countdownElement = document.getElementById(`countdown-${job.id}`);
                    if (countdownElement) {
                        updateCountdown(job.deadline, countdownElement);
                        // Update every minute
                        const interval = setInterval(() => {
                            if (new Date(job.deadline) <= new Date()) {
                                clearInterval(interval);
                                countdownElement.innerHTML = 'Expired';
                                return;
                            }
                            updateCountdown(job.deadline, countdownElement);
                        }, 60000);
                    }
                }

                document.getElementById('jobModal').style.display = 'block';
            }
        }
        
        function closeModal() {
            document.getElementById('jobModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('jobModal');
            if (event.target == modal) {
                modal.style.display = 'none';
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
