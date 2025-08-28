<?php
/**
 * Generate sidebar navigation for resident pages
 * This function handles the requirements completion state and shows appropriate navigation
 */
function generateResidentSidebar($current_page = '', $stats = []) {
    $requirements_completed = $_SESSION['requirements_completed'] ?? 0;
    $notification_count = $stats['pending_responses'] ?? 0;
    $message_count = $stats['unread_messages'] ?? 0;
    
    ob_start();
    ?>
    <nav class="sidebar-nav">
        <ul>
            <?php if ($requirements_completed == 0): ?>
                <!-- Limited access when requirements not completed -->
                <li>
                    <a href="dashboard.php" class="<?php echo $current_page == 'dashboard' ? 'active disabled-partial' : 'disabled-partial'; ?>">
                       <i class="fas fa-tachometer-alt"></i> Dashboard 
                        <span class="warning-badge">Limited</span>
                    </a>
                </li>
                <li>
                    <a href="profile.php" class="disabled" onclick="return false;">
                        <i class="fas fa-user"></i> My Profile 
                        <span class="locked-icon"><i class="fas fa-lock"></i></span>
                    </a>
                </li>
                <li>
                    <a href="requirements.php" class="<?php echo $current_page == 'requirements' ? 'active highlight' : 'highlight'; ?>">
                        <i class="fas fa-file-upload"></i> Complete Requirements 
                        <span class="badge required">Required</span>
                    </a>
                </li>
                <li>
                    <a href="jobs.php" class="disabled" onclick="return false;">
                        <i class="fas fa-briefcase"></i> Jobs 
                        <span class="locked-icon"><i class="fas fa-lock"></i></span>
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="disabled" onclick="return false;">
                        <i class="fas fa-bell"></i> Job Notifications 
                        <span class="locked-icon"><i class="fas fa-lock"></i></span>
                    </a>
                </li>
                <li>
                    <a href="messages.php" class="disabled" onclick="return false;">
                        <i class="fas fa-envelope"></i> Messages 
                        <span class="locked-icon"><i class="fas fa-lock"></i></span>
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="disabled" onclick="return false;">
                        <i class="fas fa-cog"></i> Settings 
                        <span class="locked-icon"><i class="fas fa-lock"></i></span>
                    </a>
                </li>
            <?php else: ?>
                <!-- Full access when requirements completed -->
                <li>
                    <a href="dashboard.php" class="<?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="profile.php" class="<?php echo $current_page == 'profile' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> My Profile
                    </a>
                </li>
                <li>
                    <a href="requirements.php" class="<?php echo $current_page == 'requirements' ? 'active' : ''; ?>">
                        <i class="fas fa-file-upload"></i> Complete Requirements 
                        <span class="badge completed"><i class="fas fa-check"></i></span>
                    </a>
                </li>
                <li>
                    <a href="jobs.php" class="<?php echo $current_page == 'jobs' ? 'active' : ''; ?>">
                        <i class="fas fa-briefcase"></i> Jobs
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="<?php echo $current_page == 'notifications' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i> Job Notifications
                        <?php if($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="messages.php" class="<?php echo $current_page == 'messages' ? 'active' : ''; ?>">
                        <i class="fas fa-envelope"></i> Messages
                        <?php if($message_count > 0): ?>
                            <span class="notification-badge"><?php echo $message_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="<?php echo $current_page == 'settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php
    return ob_get_clean();
}

/**
 * Generate requirements completion alert for pages that have limited access
 */
function generateRequirementsAlert() {
    $requirements_completed = $_SESSION['requirements_completed'] ?? 0;
    
    if ($requirements_completed == 0) {
        return '
        <div class="requirements-incomplete-alert">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Complete Your Requirements:</strong> Upload required documents to unlock full access to JobMatch features.
            <a href="requirements.php" class="btn-complete">Complete Now</a>
        </div>';
    }
    
    return '';
}
?>
