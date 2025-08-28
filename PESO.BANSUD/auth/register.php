<?php
require_once '../config/database.php';
require_once '../config/session.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../resident/dashboard.php');
    }
    exit();
}

$error = '';
$success = '';

// Get barangays for dropdown
$database = new Database();
$db = $database->getConnection();

$barangay_query = "SELECT id, name FROM barangays ORDER BY name";
$barangay_stmt = $db->prepare($barangay_query);
$barangay_stmt->execute();
$barangays = $barangay_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_POST) {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $birthdate = $_POST['birthdate'] ?? '';
    $age = $_POST['age'] ?? '';
    $occupation = trim($_POST['occupation'] ?? ''); // keep variable for DB, but change label below
    $preferred_job = trim($_POST['preferred_job'] ?? '');
    $employed = $_POST['employed'] ?? '';
    $salary_income = $_POST['salary_income'] ?? '';
    $educational_attainment = trim($_POST['educational_attainment'] ?? '');
    $job_title = trim($_POST['job_title'] ?? '');
    $barangay_id = $_POST['barangay_id'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $sitio = trim($_POST['sitio'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $gender = $_POST['gender'] ?? ''; // Added gender

    // Handle Valid ID file upload
    $valid_id_filename = '';
    if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['valid_id']['tmp_name'];
        $fileName = $_FILES['valid_id']['name'];
        $fileSize = $_FILES['valid_id']['size'];
        $fileNameCmps = explode('.', $fileName);
        $fileExtension = strtolower(end($fileNameCmps));
        $allowedfileExtensions = array('jpg', 'jpeg', 'png', 'pdf');
        
        if (in_array($fileExtension, $allowedfileExtensions) && $fileSize < 5000000) { // 5MB limit
            $newFileName = 'valid_id_' . time() . '_' . rand(1000, 9999) . '.' . $fileExtension;
            $uploadFileDir = '../uploads/valid_ids/';
            
            // Create directory if it doesn't exist
            if (!file_exists($uploadFileDir)) {
                mkdir($uploadFileDir, 0777, true);
            }
            
            $dest_path = $uploadFileDir . $newFileName;
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                $valid_id_filename = $newFileName;
            }
        }
    }

    // Calculate age from birthdate if provided
    if (!empty($birthdate)) {
        $birth_date = new DateTime($birthdate);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
    }

    // Validation
    if (empty($first_name) || empty($last_name) || empty($birthdate) || empty($employed) || 
        empty($email) || empty($password) || empty($barangay_id) || empty($gender)) { // Removed valid_id text validation
        $error = 'Please fill in all required fields';
    } elseif (!isset($_FILES['valid_id']) || $_FILES['valid_id']['error'] != UPLOAD_ERR_OK) {
        $error = 'Please upload a valid ID photo';
    } elseif (empty($valid_id_filename)) {
        $error = 'Valid ID upload failed. Please ensure the file is JPG, PNG, or PDF and under 5MB';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
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
            
            $insert_query = "INSERT INTO residents (first_name, middle_name, last_name, birthdate, age, preferred_job, employed, salary_income,
                               educational_attainment, job_title, barangay_id, email, password, phone, sitio, skills, valid_id, gender, approved) 
                               VALUES (:first_name, :middle_name, :last_name, :birthdate, :age, :preferred_job, :employed, :salary_income, :educational_attainment, 
                               :job_title, :barangay_id, :email, :password, :phone, :sitio, :skills, :valid_id, :gender, 0)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':first_name', $first_name);
            $insert_stmt->bindParam(':middle_name', $middle_name);
            $insert_stmt->bindParam(':last_name', $last_name);
            $insert_stmt->bindParam(':birthdate', $birthdate);
            $insert_stmt->bindParam(':age', $age);
            $insert_stmt->bindParam(':preferred_job', $preferred_job);
            $insert_stmt->bindParam(':employed', $employed);
            $insert_stmt->bindParam(':salary_income', $salary_income);
            $insert_stmt->bindParam(':educational_attainment', $educational_attainment);
            $insert_stmt->bindParam(':job_title', $job_title);
            $insert_stmt->bindParam(':barangay_id', $barangay_id);
            $insert_stmt->bindParam(':email', $email);
            $insert_stmt->bindParam(':password', $hashed_password);
            $insert_stmt->bindParam(':phone', $phone);
            $insert_stmt->bindParam(':sitio', $sitio);
            $insert_stmt->bindParam(':skills', $skills);
            $insert_stmt->bindParam(':valid_id', $valid_id_filename);
            $insert_stmt->bindParam(':gender', $gender); // Bind gender parameter

            if ($insert_stmt->execute()) {
                $success = 'Registration successful! Please wait for admin approval before logging into your account.';
            } else {
                $error = 'Registration failed. Please try again.';
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
    <title>JobMatch - Register</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/register.css">
    <link rel="stylesheet" href="../assets/css/login.css">
    
    <style>
        /* Hide number input spinners */
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        
        input[type="number"] {
            -moz-appearance: textfield;
            appearance: textfield;
        }

        /* Multi-step Form Styles */
        .landscape-form {
            max-width: 900px !important;
            width: 95% !important;
        }

        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 0 20px;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }

        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 20px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #ddd;
            z-index: 1;
        }

        .step.active:not(:last-child)::after {
            background: #007bff;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #ddd;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }

        .step.active .step-number {
            background: #007bff;
            color: white;
        }

        .step.completed .step-number {
            background: #28a745;
            color: white;
        }

        .step-title {
            font-size: 12px;
            text-align: center;
            color: #666;
            font-weight: 500;
        }

        .step.active .step-title {
            color: #007bff;
            font-weight: 600;
        }

        /* Form Steps */
        .form-step {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }

        .form-step.active {
            display: block;
        }

        .form-step h3 {
            text-align: center;
            margin-bottom: 25px;
            color: #333;
            font-size: 24px;
            font-weight: 600;
        }

        .step-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        /* Step 1 and Step 2 have more fields, so use a 3-column layout on larger screens */
        #step1 .step-grid,
        #step2 .step-grid {
            grid-template-columns: 1fr 1fr 1fr;
        }

        @media (max-width: 1024px) {
            #step1 .step-grid,
            #step2 .step-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* Navigation Buttons */
        .form-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn-primary {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .landscape-form {
                max-width: 500px !important;
            }
            
            .step-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .step-indicator {
                padding: 0 10px;
            }

            .step-title {
                font-size: 10px;
            }

            .step-number {
                width: 35px;
                height: 35px;
            }

            .form-navigation {
                flex-direction: column;
                gap: 15px;
            }

            .btn-primary, .btn-secondary {
                width: 100%;
                justify-content: center;
            }
        }

        /* Ensure all input fields have consistent height */
        .landscape-form input[type="text"],
        .landscape-form input[type="email"],
        .landscape-form input[type="tel"],
        .landscape-form input[type="date"],
        .landscape-form input[type="number"],
        .landscape-form input[type="password"],
        .landscape-form select {
            height: 45px;
            box-sizing: border-box;
        }

        /* Readonly field styling */
        .landscape-form input[readonly] {
            background-color: #f8f9fa !important;
            color: #6c757d;
            cursor: not-allowed;
            border-color: #e9ecef;
        }

        .landscape-form textarea {
            min-height: 80px;
            resize: vertical;
        }

        /* Form group spacing for steps */
        .form-step .form-group {
            margin-bottom: 15px;
        }
    </style>
    
</head>
<body>
    <div class="login-container">
        <div class="login-form landscape-form">
            <div class="logo">
                <h1>JobMatch</h1>
                <p>Resident Registration</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" id="registrationForm">
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" data-step="1">
                        <div class="step-number">1</div>
                        <div class="step-title">Personal Info</div>
                    </div>
                    <div class="step" data-step="2">
                        <div class="step-number">2</div>
                        <div class="step-title">Professional Info</div>
                    </div>
                    <div class="step" data-step="3">
                        <div class="step-number">3</div>
                        <div class="step-title">Contact & Security</div>
                    </div>
                </div>

                <!-- Step 1: Personal Information -->
                <div class="form-step active" id="step1">
                    <h3>Personal Information</h3>
                    <div class="form-grid step-grid">
                        <div class="form-group">
                            <label for="first_name">First Name *:</label>
                            <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name (Optional):</label>
                            <input type="text" name="middle_name" id="middle_name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *:</label>
                            <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="birthdate">Birthdate *:</label>
                            <input type="date" name="birthdate" id="birthdate" 
                                   value="<?php echo htmlspecialchars($_POST['birthdate'] ?? ''); ?>" 
                                   max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" 
                                   required onchange="calculateAge()">
                        </div>
                        <div class="form-group">
                            <label for="age">Age:</label>
                            <input type="number" name="age" id="age" min="18" max="100" value="<?php echo htmlspecialchars($_POST['age'] ?? ''); ?>" step="1">
                            <small style="color: #333; font-size: 12px;">Age will be calculated from birthdate, but you can adjust it manually</small>
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender *:</label>
                            <select name="gender" id="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                <option value="Prefer not to say" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'Prefer not to say') ? 'selected' : ''; ?>>Prefer not to say</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="sitio">Sitio:</label>
                            <input type="text" name="sitio" id="sitio" value="<?php echo htmlspecialchars($_POST['sitio'] ?? ''); ?>" placeholder="Enter your sitio">
                        </div>
                        <div class="form-group">
                            <label for="barangay_id">Barangay *:</label>
                            <select name="barangay_id" id="barangay_id" required>
                                <option value="">Select Barangay</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['id']; ?>" <?php echo (isset($_POST['barangay_id']) && $_POST['barangay_id'] == $barangay['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Professional Information -->
                <div class="form-step" id="step2">
                    <h3>Professional Information</h3>
                    <div class="form-grid step-grid">
                        <div class="form-group">
                            <label for="educational_attainment">Educational Attainment:</label>
                            <input type="text" name="educational_attainment" id="educational_attainment" value="<?php echo htmlspecialchars($_POST['educational_attainment'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="job_title">Job Title:</label>
                            <input type="text" name="job_title" id="job_title" value="<?php echo htmlspecialchars($_POST['job_title'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="employed">Are you currently employed? :</label>
                            <input type="text" name="employed" id="employed" value="<?php echo htmlspecialchars($_POST['employed'] ?? 'No'); ?>" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                            <small style="color: black; font-size: 12px;">This field is automatically filled based on your job title</small>
                        </div>
                        <div class="form-group">
                            <label for="salary_income">Monthly Salary/Income:</label>
                            <select name="salary_income" id="salary_income">
                                <option value="">Select Salary Range</option>
                                <option value="Below 15,000" <?php echo (isset($_POST['salary_income']) && $_POST['salary_income'] == 'Below 15,000') ? 'selected' : ''; ?>>Below ₱15,000</option>
                                <option value="15,000 - 25,000" <?php echo (isset($_POST['salary_income']) && $_POST['salary_income'] == '15,000 - 25,000') ? 'selected' : ''; ?>>₱15,000 - ₱25,000</option>
                                <option value="25,001 - 35,000" <?php echo (isset($_POST['salary_income']) && $_POST['salary_income'] == '25,001 - 35,000') ? 'selected' : ''; ?>>₱25,001 - ₱35,000</option>
                                <option value="35,001 - 50,000" <?php echo (isset($_POST['salary_income']) && $_POST['salary_income'] == '35,001 - 50,000') ? 'selected' : ''; ?>>₱35,001 - ₱50,000</option>
                                <option value="50,001 - 75,000" <?php echo (isset($_POST['salary_income']) && $_POST['salary_income'] == '50,001 - 75,000') ? 'selected' : ''; ?>>₱50,001 - ₱75,000</option>
                                <option value="75,001 - 100,000" <?php echo (isset($_POST['salary_income']) && $_POST['salary_income'] == '75,001 - 100,000') ? 'selected' : ''; ?>>₱75,001 - ₱100,000</option>
                                <option value="Above 100,000" <?php echo (isset($_POST['salary_income']) && $_POST['salary_income'] == 'Above 100,000') ? 'selected' : ''; ?>>Above ₱100,000</option>
                                <option value="No Income" <?php echo (isset($_POST['salary_income']) && $_POST['salary_income'] == 'No Income') ? 'selected' : ''; ?>>No Income</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="preferred_job">Preferred Job *:</label>
                            <input type="text" name="preferred_job" id="preferred_job" value="<?php echo htmlspecialchars($_POST['preferred_job'] ?? ''); ?>" placeholder="e.g., Salesman, Driver, Teacher..." required>
                        </div>
                        <div class="form-group">
                            <label for="skills">Skills (Optional):</label>
                            <textarea name="skills" id="skills" rows="3" placeholder="List your skills, e.g., Computer skills, Driving, Cooking..."><?php echo htmlspecialchars($_POST['skills'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Contact & Security -->
                <div class="form-step" id="step3">
                    <h3>Contact & Security Information</h3>
                    <div class="form-grid step-grid">
                        <div class="form-group">
                            <label for="email">Email Address *:</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" name="email" id="email" placeholder="Enter your email address" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number *:</label>
                            <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="valid_id">Valid ID Photo *:</label>
                            <input type="file" name="valid_id" id="valid_id" accept="image/*,.pdf" required>
                            <small style="color: #333; font-size: 12px;">Upload a clear photo of your government-issued ID (JPG, PNG, PDF)</small>
                        </div>
                        <div class="form-group">
                            <label for="password">Password *:</label>
                            <div class="input-with-icon password-container">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" name="password" id="password" placeholder="Enter your password" required>
                                <i class="toggle-password fas fa-eye-slash" onclick="togglePassword('password')"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password *:</label>
                            <div class="input-with-icon password-container">
                                <i class="fas fa-lock input-icon"></i>
                                <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm your password" required>
                                <i class="toggle-password fas fa-eye-slash" onclick="togglePassword('confirm_password')"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Buttons -->
                <div class="form-navigation">
                    <button type="button" class="btn-secondary" id="prevBtn" onclick="changeStep(-1)" style="display: none;">
                        <i class="fas fa-arrow-left"></i> Previous
                    </button>
                    <button type="button" class="btn-primary" id="nextBtn" onclick="changeStep(1)">
                        Next <i class="fas fa-arrow-right"></i>
                    </button>
                    <button type="submit" class="btn-primary" id="submitBtn" style="display: none;">
                        <i class="fas fa-user-plus"></i> Register
                    </button>
                </div>
            </form>

            <div class="register-link">
                <p>Already have an account? <a href="login.php">Login here</a></p>
            </div>
        </div>
    </div>
    <script>
        let currentStep = 1;
        const totalSteps = 3;

        function togglePassword(fieldId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = passwordField.nextElementSibling;
            
            if (passwordField.type === "password") {
                passwordField.type = "text";
                toggleIcon.classList.remove("fa-eye-slash");
                toggleIcon.classList.add("fa-eye");
            } else {
                passwordField.type = "password";
                toggleIcon.classList.remove("fa-eye");
                toggleIcon.classList.add("fa-eye-slash");
            }
        }

        function calculateAge() {
            const birthdateInput = document.getElementById('birthdate');
            const ageInput = document.getElementById('age');
            
            if (birthdateInput.value) {
                const birthdate = new Date(birthdateInput.value);
                const today = new Date();
                
                let age = today.getFullYear() - birthdate.getFullYear();
                const monthDiff = today.getMonth() - birthdate.getMonth();
                
                // Adjust age if birthday hasn't occurred this year
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthdate.getDate())) {
                    age--;
                }
                
                // Check if age is at least 18
                if (age < 18) {
                    alert('You must be at least 18 years old to register.');
                    birthdateInput.value = '';
                    ageInput.value = '';
                    return;
                }
                
                ageInput.value = age;
            } else {
                ageInput.value = '';
            }
        }

        // Validate manually entered age
        function validateManualAge() {
            const ageInput = document.getElementById('age');
            const age = parseInt(ageInput.value);
            
            if (age && (age < 18 || age > 100)) {
                alert('Age must be between 18 and 100 years old.');
                if (age < 18) ageInput.value = 18;
                if (age > 100) ageInput.value = 100;
            }
        }

        // Function to automatically set employed status based on job title
        function updateEmploymentStatus() {
            const jobTitleInput = document.getElementById('job_title');
            const employedSelect = document.getElementById('employed');
            
            if (jobTitleInput.value.trim() !== '') {
                // If job title is filled, set employed to "Yes"
                employedSelect.value = 'Yes';
            } else {
                // If job title is empty, set employed to "No"
                employedSelect.value = 'No';
            }
        }

        // Multi-step form functions
        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('.form-step').forEach(stepEl => {
                stepEl.classList.remove('active');
            });
            
            // Show current step
            document.getElementById(`step${step}`).classList.add('active');
            
            // Update step indicators
            document.querySelectorAll('.step').forEach((stepEl, index) => {
                stepEl.classList.remove('active', 'completed');
                if (index + 1 === step) {
                    stepEl.classList.add('active');
                } else if (index + 1 < step) {
                    stepEl.classList.add('completed');
                }
            });
            
            // Update navigation buttons
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const submitBtn = document.getElementById('submitBtn');
            
            if (step === 1) {
                prevBtn.style.display = 'none';
            } else {
                prevBtn.style.display = 'flex';
            }
            
            if (step === totalSteps) {
                nextBtn.style.display = 'none';
                submitBtn.style.display = 'flex';
            } else {
                nextBtn.style.display = 'flex';
                submitBtn.style.display = 'none';
            }
        }

        function validateStep(step) {
            let isValid = true;
            const currentStepEl = document.getElementById(`step${step}`);
            const requiredFields = currentStepEl.querySelectorAll('input[required], select[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = '#dc3545';
                    isValid = false;
                } else {
                    field.style.borderColor = '';
                }
            });
            
            // Additional validation for specific steps
            if (step === 1) {
                // Validate age
                const age = parseInt(document.getElementById('age').value);
                if (age && (age < 18 || age > 100)) {
                    alert('Age must be between 18 and 100 years old.');
                    isValid = false;
                }
            } else if (step === 3) {
                // Validate password match
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                if (password !== confirmPassword) {
                    alert('Passwords do not match.');
                    document.getElementById('confirm_password').style.borderColor = '#dc3545';
                    isValid = false;
                }
                
                if (password.length < 6) {
                    alert('Password must be at least 6 characters long.');
                    document.getElementById('password').style.borderColor = '#dc3545';
                    isValid = false;
                }
                
                // Validate email
                const email = document.getElementById('email').value;
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (email && !emailPattern.test(email)) {
                    alert('Please enter a valid email address.');
                    document.getElementById('email').style.borderColor = '#dc3545';
                    isValid = false;
                }
            }
            
            if (!isValid) {
                alert('Please fill in all required fields correctly before proceeding.');
            }
            
            return isValid;
        }

        function changeStep(direction) {
            if (direction === 1) {
                // Going forward - validate current step
                if (!validateStep(currentStep)) {
                    return;
                }
                
                if (currentStep < totalSteps) {
                    currentStep++;
                    showStep(currentStep);
                }
            } else {
                // Going backward
                if (currentStep > 1) {
                    currentStep--;
                    showStep(currentStep);
                }
            }
        }

        // Add event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize the form
            showStep(1);
            
            // Add event listeners
            document.getElementById('age').addEventListener('change', validateManualAge);
            document.getElementById('job_title').addEventListener('input', updateEmploymentStatus);
            document.getElementById('job_title').addEventListener('change', updateEmploymentStatus);
            
            // Calculate age and set employment status on page load
            calculateAge();
            updateEmploymentStatus();
            
            // Clear border color on input
            document.querySelectorAll('input, select').forEach(field => {
                field.addEventListener('input', function() {
                    this.style.borderColor = '';
                });
            });
        });

        // Show popup when registration is successful
        <?php if ($success): ?>
        window.onload = function() {
            alert("Registration successful! Please wait for admin approval before logging into your account.");
        };
        <?php endif; ?>
    </script>
</body>
</html>
