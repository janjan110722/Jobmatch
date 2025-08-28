<?php
require_once '../config/database.php';
require_once '../config/session.php';

// Handle reset attempts request from JavaScript
if (isset($_POST['reset_attempts']) && $_POST['reset_attempts'] == '0') {
    $username = $_POST['username'] ?? '';
    if (!empty($username)) {
        $user_identifier = 'user_' . md5(strtolower(trim($username)));
        if (isset($_SESSION['lockout_data'][$user_identifier])) {
            $_SESSION['lockout_data'][$user_identifier]['attempts'] = 0;
            $_SESSION['lockout_data'][$user_identifier]['locked_until'] = 0;
        }
    }
    header('Content-Type: text/plain');
    exit('OK');
}

// Login attempt limiter
$max_attempts = 3;
$base_lockout_time = 5; // Base lockout time in seconds
$error = '';
$is_locked = false;
$remaining_time = 0;
$approval_pending = false;

// Initialize lockout tracking array in session if not set
if (!isset($_SESSION['lockout_data'])) {
    $_SESSION['lockout_data'] = [];
}

// Clean up old lockout data (remove entries older than 1 hour)
$current_time = time();
foreach ($_SESSION['lockout_data'] as $key => $data) {
    if (isset($data['last_attempt']) && ($current_time - $data['last_attempt']) > 3600) {
        unset($_SESSION['lockout_data'][$key]);
    }
}

// We'll determine user_identifier after we get the username from POST data
$user_identifier = null;
$current_user_data = null;

// Check if account is locked (only if we have a username)
if (isset($_POST['username']) || isset($_GET['check_user'])) {
    $username = $_POST['username'] ?? $_GET['check_user'] ?? '';
    if (!empty($username)) {
        // Create user identifier based on the actual username being attempted
        $user_identifier = 'user_' . md5(strtolower(trim($username)));
        
        // Get current user's lockout data
        if (!isset($_SESSION['lockout_data'][$user_identifier])) {
            $_SESSION['lockout_data'][$user_identifier] = [
                'attempts' => 0,
                'cycle' => 0,
                'locked_until' => 0,
                'last_attempt' => 0
            ];
        }
        
        $current_user_data = &$_SESSION['lockout_data'][$user_identifier];
        
        if ($current_user_data['locked_until'] > time()) {
            $is_locked = true;
            $remaining_time = $current_user_data['locked_until'] - time();
        } else {
            // Reset attempts if lockout period has expired
            if ($current_user_data['locked_until'] > 0 && $current_user_data['locked_until'] <= time()) {
                $current_user_data['attempts'] = 0;
                $current_user_data['locked_until'] = 0;
                $error = '';
            }
        }
    }
}

// Check if this is a fresh page load after reset (no POST data)
if (!$_POST && !$is_locked && $current_user_data && $current_user_data['attempts'] == 0) {
    $error = ''; // Clear any lingering error messages
}

if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../resident/dashboard.php');
    }
    exit();
}

if ($_POST && !$is_locked) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields';
    } else {
        // Create user identifier based on the username being attempted
        $user_identifier = 'user_' . md5(strtolower(trim($username)));
        
        // Get current user's lockout data
        if (!isset($_SESSION['lockout_data'][$user_identifier])) {
            $_SESSION['lockout_data'][$user_identifier] = [
                'attempts' => 0,
                'cycle' => 0,
                'locked_until' => 0,
                'last_attempt' => 0
            ];
        }
        
        $current_user_data = &$_SESSION['lockout_data'][$user_identifier];

        $database = new Database();
        $db = $database->getConnection();

        $user_found = false;
        $password_correct = false;
        $user_type = '';
        $user = null;

        // First, check in admin table
        $admin_query = "SELECT id, username, password FROM admins WHERE username = :username";
        $admin_stmt = $db->prepare($admin_query);
        $admin_stmt->bindParam(':username', $username);
        $admin_stmt->execute();

        if ($admin_stmt->rowCount() > 0) {
            $user = $admin_stmt->fetch(PDO::FETCH_ASSOC);
            $user_found = true;
            $user_type = 'admin';

            if (password_verify($password, $user['password'])) {
                $password_correct = true;
            }
        } else {
            // If not found in admin, check in residents table
            $resident_query = "SELECT id, first_name, middle_name, last_name, email, password, approved, requirements_completed FROM residents WHERE email = :username";
            $resident_stmt = $db->prepare($resident_query);
            $resident_stmt->bindParam(':username', $username);
            $resident_stmt->execute();

            if ($resident_stmt->rowCount() > 0) {
                $user = $resident_stmt->fetch(PDO::FETCH_ASSOC);
                $user_found = true;
                $user_type = 'resident';
                if ($user['approved'] != 1) {
                    $approval_pending = true;
                    $error = 'Your account is pending approval by the admin.';
                } elseif (password_verify($password, $user['password'])) {
                    $password_correct = true;
                    // Store requirements completion status in session
                    $_SESSION['requirements_completed'] = $user['requirements_completed'] ?? 0;
                }
            }
        }

        if ($password_correct) {
            // Successful login - reset all attempt counters for this user
            $current_user_data['attempts'] = 0;
            $current_user_data['cycle'] = 0; // Reset progressive lockout cycle
            $current_user_data['locked_until'] = 0;

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_type'] = $user_type;
            $_SESSION['username'] = $user_type === 'admin' ? $user['username'] : ($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']);

            if ($user_type === 'admin') {
                header('Location: ../admin/dashboard.php');
            } else {
                header('Location: ../resident/dashboard.php');
            }
            exit();
        }

        // Handle failed login attempts
        if (!$password_correct) {
            $current_user_data['attempts'] = $current_user_data['attempts'] + 1;
            $current_user_data['last_attempt'] = time();

            if ($current_user_data['attempts'] >= $max_attempts) {
                // Increment lockout cycle for progressive timing
                $current_user_data['cycle'] = $current_user_data['cycle'] + 1;
                
                // Calculate progressive lockout time: base_time * cycle_number
                $current_lockout_time = $base_lockout_time * $current_user_data['cycle'];
                
                $current_user_data['locked_until'] = time() + $current_lockout_time;
                $is_locked = true;
                $remaining_time = $current_lockout_time;
                $error = "Too many failed login attempts. Account locked for $current_lockout_time seconds. (Lockout #" . $current_user_data['cycle'] . ")";
            } else {
                $remaining_attempts = $max_attempts - $current_user_data['attempts'];

                // Determine specific error message
                if (!$user_found) {
                    $error = "Username/Email not found. $remaining_attempts attempt" . ($remaining_attempts != 1 ? 's' : '') . " remaining.";
                } else {
                    $error = "Incorrect password. $remaining_attempts attempt" . ($remaining_attempts != 1 ? 's' : '') . " remaining.";
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JobMatch - Login</title>
    <link rel="stylesheet" href="../assets/css/login.css"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">

</head>

<body>
    <div class="login-container">
        <div class="login-form">
            <div class="logo">
                <h1>JobMatch</h1>
                <p>Labor Force Management System of PESO in Bansud Oriental Mindoro</p>
            </div>

            <?php if ($error && !$approval_pending): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php 
            // Show fresh attempts message if just unlocked
            if (!$_POST && !$is_locked && $current_user_data && $current_user_data['attempts'] == 0 && !$error): 
            ?>
                <div class="info-message" style="background-color: #e8f5e8; color: #2e7d32; border: 1px solid #4caf50; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center;">
                    <i class="fas fa-check-circle"></i>
                    You have <?php echo $max_attempts; ?> login attempts available.
                </div>
            <?php endif; ?>

            <?php if ($is_locked): ?>
                <div class="locked-message">
                    <div class="lock-icon"><i class="fas fa-lock"></i></div>
                    <h3>Account Temporarily Locked</h3>
                    <p>Too many failed login attempts. Please try again in <span id="countdown"><?php echo $remaining_time; ?></span> seconds.</p>
                    <?php if ($current_user_data && $current_user_data['cycle'] > 1): ?>
                        <p><small>Lockout #<?php echo $current_user_data['cycle']; ?> - Each failed cycle increases lockout time.</small></p>
                    <?php endif; ?>
                </div>
                
                <!-- Hidden form that will be shown after countdown -->
                <form method="POST" action="" style="display: none;">
                    <div class="form-group">
                        <label for="username">Username/Email:</label>
                        <div class="input-with-icon">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" name="username" id="username" placeholder="Enter your username or email" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group password-group">
                        <label for="password">Password:</label>
                        <div class="input-with-icon password-input-container">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" id="password" placeholder="Enter your password" required>
                            <span class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye-slash" id="toggleIcon"></i>
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Login</button>
                </form>
            <?php else: ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">Username/Email:</label>
                        <div class="input-with-icon">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" name="username" id="username" placeholder="Enter your username or email" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-group password-group">
                        <label for="password">Password:</label>
                        <div class="input-with-icon password-input-container">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" id="password" placeholder="Enter your password" required>
                            <span class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye-slash" id="toggleIcon"></i>
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Login</button>
                </form>
            <?php endif; ?>

            <div class="register-link">
                <p>Don't have an account? <a href="register.php">Register as Resident</a> or </p>
                <a href="register.php">Register as Employer</a>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');

            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            }
        }

        // Countdown timer for locked account
        <?php if ($is_locked): ?>
            let timeLeft = <?php echo $remaining_time; ?>;
            const countdownElement = document.getElementById('countdown');
            const lockedMessage = document.querySelector('.locked-message');
            const loginForm = document.querySelector('form');

            const countdownTimer = setInterval(function() {
                timeLeft--;
                countdownElement.textContent = timeLeft;

                if (timeLeft <= 0) {
                    clearInterval(countdownTimer);
                    // Get username from the form
                    const username = document.getElementById('username').value;
                    // Reset login attempts on client side before reload
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'reset_attempts=0&username=' + encodeURIComponent(username)
                    }).then(() => {
                        // Smooth transition from lockout to login form
                        if (lockedMessage) {
                            lockedMessage.classList.add('hiding');
                            setTimeout(() => {
                                lockedMessage.style.display = 'none';
                            }, 300);
                        }
                        
                        if (loginForm) {
                            setTimeout(() => {
                                loginForm.style.display = 'block';
                                loginForm.classList.add('showing');
                            }, 150);
                        }
                        
                        // Show success message
                        const loginFormContainer = document.querySelector('.login-form');
                        if (loginFormContainer) {
                            setTimeout(() => {
                                const successMessage = document.createElement('div');
                                successMessage.className = 'info-message';
                                successMessage.style.cssText = 'background-color: #e8f5e8; color: #2e7d32; border: 1px solid #4caf50; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center; opacity: 0; transition: opacity 0.3s ease;';
                                successMessage.innerHTML = '<i class="fas fa-check-circle"></i> Account unlocked! You have 3 login attempts available.';
                                
                                // Insert before the form
                                loginFormContainer.insertBefore(successMessage, loginForm);
                                
                                // Fade in the message
                                setTimeout(() => {
                                    successMessage.style.opacity = '1';
                                }, 10);
                                
                                // Remove the message after 4 seconds
                                setTimeout(() => {
                                    if (successMessage.parentNode) {
                                        successMessage.style.opacity = '0';
                                        setTimeout(() => {
                                            if (successMessage.parentNode) {
                                                successMessage.parentNode.removeChild(successMessage);
                                            }
                                        }, 300);
                                    }
                                }, 4000);
                            }, 300);
                        }
                    }).catch(() => {
                        // If AJAX fails, just reload the page
                        window.location.reload();
                    });
                }
            }, 1000);
        <?php endif; ?>

        // Show approval popup when account is pending approval
        <?php if ($approval_pending): ?>
            window.onload = function() {
                showApprovalPopup();
            };

            function showApprovalPopup() {
                // Create custom popup instead of alert for better styling
                const popup = document.createElement('div');
                popup.className = 'approval-popup-overlay';
                popup.innerHTML = `
                    <div class="approval-popup">
                        <div class="approval-popup-header">
                            <i class="fas fa-hourglass-half"></i>
                            <h3>Account Pending Approval</h3>
                        </div>
                        <div class="approval-popup-body">
                            <p>Your account registration is currently being reviewed by the admin. Please wait for approval before logging into your account.</p>
                            <p><strong>What's next?</strong></p>
                            <ul>
                                <li>An admin will review your registration details</li>
                                <li>You'll be notified once your account is approved</li>
                                <li>You can then log in with your credentials</li>
                            </ul>
                        </div>
                        <div class="approval-popup-footer">
                            <button onclick="closeApprovalPopup()" class="popup-btn">I Understand</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(popup);

                // Prevent background scrolling
                document.body.style.overflow = 'hidden';
            }

            function closeApprovalPopup() {
                const popup = document.querySelector('.approval-popup-overlay');
                if (popup) {
                    popup.remove();
                    document.body.style.overflow = 'auto';
                }
            }
        <?php endif; ?>
    </script>
</body>

</html>