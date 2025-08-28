-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 28, 2025 at 07:21 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `jobmatch_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `profile_picture` varchar(225) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `profile_picture`, `username`, `password`, `email`, `created_at`, `updated_at`) VALUES
(1, 'admin_1_1755593957.jpg', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'adminpeso@gmail.com', '2025-06-08 06:51:48', '2025-08-19 08:59:17');

-- --------------------------------------------------------

--
-- Table structure for table `barangays`
--

CREATE TABLE `barangays` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barangays`
--

INSERT INTO `barangays` (`id`, `name`, `created_at`) VALUES
(1, 'Salcedo', '2025-06-08 06:51:48'),
(2, 'Conrazon', '2025-06-08 06:51:48'),
(3, 'Manihala', '2025-06-08 06:51:48'),
(4, 'Poblacion', '2025-06-08 06:51:48'),
(5, 'Proper Tiguisan', '2025-06-08 06:51:48'),
(6, 'Sumagui', '2025-06-08 12:38:59'),
(7, 'Alcadesma', '2025-06-08 12:39:33'),
(8, 'Malo', '2025-06-08 12:43:41'),
(9, 'Pag-asa', '2025-06-08 12:44:15'),
(10, 'Proper Bansud', '2025-06-08 12:44:46'),
(11, 'Rosacara', '2025-06-08 12:45:07'),
(12, 'Villa Pag-asa', '2025-06-08 12:45:52');

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `company` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `job_type` enum('Full-time','Part-time','Contract','Temporary') NOT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `status` enum('Active','Inactive','Closed') DEFAULT 'Active',
  `max_positions` int(11) DEFAULT 1,
  `deadline` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `jobs`
--

INSERT INTO `jobs` (`id`, `title`, `company`, `description`, `requirements`, `location`, `job_type`, `barangay_id`, `status`, `max_positions`, `deadline`, `created_by`, `created_at`, `updated_at`) VALUES
(36, 'Dole', 'LGU', 'masipag, flexible', 'valid Id', 'Bansud', 'Contract', NULL, 'Active', 1, '2025-07-29 16:43:00', NULL, '2025-07-29 07:52:31', '2025-07-29 14:12:27'),
(37, 'Helper', 'Han\'s Company', 'goods', 'valid id', 'Pinamalayan', 'Contract', NULL, 'Active', 1, '2025-08-22 22:11:00', NULL, '2025-07-29 09:23:00', '2025-08-22 14:12:04');

-- --------------------------------------------------------

--
-- Table structure for table `job_applications`
--

CREATE TABLE `job_applications` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_notifications`
--

CREATE TABLE `job_notifications` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `status` enum('sent','accepted','declined','pending') DEFAULT 'sent',
  `message` text DEFAULT NULL,
  `response_deadline` datetime DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `response_message` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_notifications`
--

INSERT INTO `job_notifications` (`id`, `job_id`, `resident_id`, `status`, `message`, `response_deadline`, `is_read`, `created_at`, `updated_at`, `response_message`) VALUES
(65, 37, 15, 'sent', '', NULL, 0, '2025-08-23 10:29:27', '2025-08-23 10:29:27', '');

-- --------------------------------------------------------

--
-- Stand-in structure for view `job_stats`
-- (See below for the actual view)
--
CREATE TABLE `job_stats` (
`id` int(11)
,`title` varchar(200)
,`company` varchar(200)
,`status` enum('Active','Inactive','Closed')
,`created_at` timestamp
,`total_notifications` bigint(21)
,`accepted_count` decimal(22,0)
,`declined_count` decimal(22,0)
,`pending_count` decimal(22,0)
);

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_type` enum('admin','resident') NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_type` enum('admin','resident') NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `message_type` enum('general','job_offer','notification') DEFAULT 'general',
  `job_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `sender_type`, `sender_id`, `receiver_type`, `receiver_id`, `subject`, `message`, `is_read`, `message_type`, `job_id`, `created_at`) VALUES
(15, 'admin', 1, 'resident', 15, 'Re: Message', 'helloooo', 1, 'job_offer', 36, '2025-08-23 14:08:53');

-- --------------------------------------------------------

--
-- Table structure for table `requirements_list`
--

CREATE TABLE `requirements_list` (
  `id` int(11) NOT NULL,
  `requirement_name` varchar(100) NOT NULL,
  `requirement_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `is_mandatory` tinyint(1) DEFAULT 1,
  `applies_to_employment_status` varchar(100) DEFAULT NULL,
  `applies_to_profession` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requirements_list`
--

INSERT INTO `requirements_list` (`id`, `requirement_name`, `requirement_type`, `description`, `is_mandatory`, `applies_to_employment_status`, `applies_to_profession`, `created_at`) VALUES
(1, 'Resume', 'resume', 'Upload your most recent resume or cv(maximum file size: 5mb| Supported formats: JPG,PNG,PDF)', 1, NULL, NULL, '2025-08-17 08:10:03');

-- --------------------------------------------------------

--
-- Table structure for table `residents`
--

CREATE TABLE `residents` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `birthdate` date DEFAULT NULL,
  `profile_picture` varchar(225) DEFAULT NULL,
  `age` int(11) NOT NULL,
  `preferred_job` varchar(255) DEFAULT NULL,
  `employed` varchar(255) DEFAULT NULL,
  `educational_attainment` varchar(100) DEFAULT NULL,
  `skills` varchar(225) NOT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `salary_income` varchar(225) NOT NULL,
  `sitio` varchar(225) NOT NULL,
  `barangay_id` int(11) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `approved` tinyint(1) DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL,
  `valid_id` varchar(225) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `email_notifications` tinyint(1) DEFAULT 1,
  `job_alerts` tinyint(1) DEFAULT 1,
  `preferred_job_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferred_job_types`)),
  `gender` varchar(10) DEFAULT 'Unknown',
  `requirements_completed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `residents`
--

INSERT INTO `residents` (`id`, `first_name`, `middle_name`, `last_name`, `birthdate`, `profile_picture`, `age`, `preferred_job`, `employed`, `educational_attainment`, `skills`, `job_title`, `salary_income`, `sitio`, `barangay_id`, `email`, `password`, `approved`, `phone`, `valid_id`, `created_at`, `updated_at`, `email_notifications`, `job_alerts`, `preferred_job_types`, `gender`, `requirements_completed`) VALUES
(15, 'Jan Jan', 'Mañibo', 'Garcia', NULL, NULL, 24, 'Manager', 'Yes', 'Still studying', '', 'web developer', '', '', 5, 'garciajanjan832@gmail.com', '$2y$10$Lr9flhG/Tf00dS8hi.fhp.Sjk2GFsYBPBMyyKlki8Q1uzfOo1.LNW', 1, '09263788830', '', '2025-08-17 10:45:48', '2025-08-23 10:39:03', 1, 1, '[\"Part-time\"]', 'Male', 1),
(16, 'Nikki', 'Balonzo', 'Dimalibot', NULL, NULL, 22, 'Public Ad', 'Yes', 'Still studying', '', 'Restaurant Owner', '', '', 5, 'nikki@gmail.com', '$2y$10$jZVxV8g42qafa5l3ZR100uGTHUFXmCmF/.k6eLmNrprMyOkNaMcSy', 1, '09263788830', '', '2025-08-17 11:20:05', '2025-08-23 10:38:10', 1, 1, '[]', 'Female', 1),
(18, 'Robert', '', 'Cruz', NULL, '', 18, 'Technician', 'Yes', 'Still studying', '', 'software Engineer', '', '', 4, 'trevor@gmail.com', '$2y$10$i33Fv1MPt0BcsVKRJjarNeYPrjhJaowj5Nh9BOOWHAyY8meoyYbFm', 1, '09263788830', '', '2025-08-22 08:22:28', '2025-08-23 13:30:02', 1, 1, NULL, 'Male', 1),
(19, 'Charles', '', 'Pelayo', NULL, NULL, 22, 'Web developer', 'yes', 'Still studying', '', 'Restaurant Owner', '', '', 4, 'pelayo@gmail.com', '$2y$10$1rvCSyjZUxvF8GT57tJjHunRo1sDVZytfnCgUKvWdlptvqohTbDjS', 1, '09263788830', '', '2025-08-22 09:21:03', '2025-08-23 13:39:52', 1, 1, NULL, 'Male', 1),
(20, 'Niño', '', 'Evangelista', NULL, NULL, 21, 'Web developer', 'Yes', '4th year', '', 'Crew', '', '', 4, 'nino@gmail.com', '$2y$10$cYs3Q2NkVQV3FiSYp5SC5.IWAKywco/S18Lgq.3y4XEn8jYUq4kCS', 1, '09263788830', '', '2025-08-22 13:40:08', '2025-08-23 13:39:39', 1, 1, NULL, 'Male', 1),
(21, 'Joshua', '', 'Javier', '2003-03-21', NULL, 22, 'Awan', 'Yes', 'Still studying', '', NULL, '', '', 5, 'josh@gmail.com', '$2y$10$iZmXylegBHvybjuXWw68k.AEW4zx8pPuTa/4V9gM7qFYiYq/z0kye', 0, '09263788830', '', '2025-08-23 09:23:04', '2025-08-23 09:23:04', 1, 1, NULL, 'Prefer not', 0),
(22, 'Mhike', 'Tangalin', 'Hidaldo', '2002-03-23', NULL, 23, 'Web developer', 'Yes', 'Still studying', '', NULL, '', '', 5, 'mhike@gmail.com', '$2y$10$jteOaTBDth7jDj3nL8EqtuXCOLJukJQEOJblWT6cMGwE8RuYdgl6a', 0, '09263788830', '', '2025-08-23 13:44:04', '2025-08-23 13:44:04', 1, 1, NULL, 'Male', 0),
(23, 'Carl Cyrus', '', 'Robiso', '2007-08-25', NULL, 18, 'Web developer', 'No', 'Still studying', '', '', '', '', 12, 'carl@gmail.com', '$2y$10$HTMEO/GtaVQ9FQ4YIdfovOKpZGDMqu0T8kV1Of7844oAbe9ZLxz22', 0, '09263788830', 'valid_id_1756102151_2651.png', '2025-08-25 06:09:11', '2025-08-25 06:09:11', 1, 1, NULL, 'Male', 0);

-- --------------------------------------------------------

--
-- Table structure for table `resident_requirements`
--

CREATE TABLE `resident_requirements` (
  `id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `requirement_type` enum('resume') NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `resident_requirements`
--

INSERT INTO `resident_requirements` (`id`, `resident_id`, `requirement_type`, `file_name`, `original_name`, `file_path`, `file_size`, `mime_type`, `status`, `uploaded_at`, `reviewed_at`, `reviewed_by`, `rejection_reason`) VALUES
(23, 15, 'resume', '15_resume_1755869455.pdf', 'JAN JAN M. GARCIA ASSIGNMENT (NETWORK ADMINISTRATION)-WPS Office.pdf', '../uploads/requirements/15_resume_1755869455.pdf', 693012, 'application/pdf', 'approved', '2025-08-22 13:30:55', '2025-08-23 07:40:11', 1, NULL),
(25, 16, 'resume', '16_resume_1755944716.png', '06f02b5204c08fcff35307a734f2d467.png', '../uploads/requirements/16_resume_1755944716.png', 1255719, 'image/png', 'approved', '2025-08-23 10:25:16', '2025-08-23 10:26:50', 1, NULL),
(26, 18, 'resume', '18_resume_1755954896.png', 't.png', '../uploads/requirements/18_resume_1755954896.png', 701550, 'image/png', 'approved', '2025-08-23 13:14:56', '2025-08-23 13:16:34', 1, NULL),
(27, 20, 'resume', '20_resume_1755955901.png', '06f02b5204c08fcff35307a734f2d467.png', '../uploads/requirements/20_resume_1755955901.png', 1255719, 'image/png', 'approved', '2025-08-23 13:31:41', '2025-08-23 13:33:20', 1, NULL),
(28, 19, 'resume', '19_resume_1755955944.png', 'FinalResume OUTPUT.png', '../uploads/requirements/19_resume_1755955944.png', 113373, 'image/png', 'approved', '2025-08-23 13:32:24', '2025-08-23 13:33:22', 1, NULL);

-- --------------------------------------------------------

--
-- Structure for view `job_stats`
--
DROP TABLE IF EXISTS `job_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `job_stats`  AS SELECT `j`.`id` AS `id`, `j`.`title` AS `title`, `j`.`company` AS `company`, `j`.`status` AS `status`, `j`.`created_at` AS `created_at`, count(`jn`.`id`) AS `total_notifications`, sum(case when `jn`.`status` = 'accepted' then 1 else 0 end) AS `accepted_count`, sum(case when `jn`.`status` = 'declined' then 1 else 0 end) AS `declined_count`, sum(case when `jn`.`status` = 'sent' then 1 else 0 end) AS `pending_count` FROM (`jobs` `j` left join `job_notifications` `jn` on(`j`.`id` = `jn`.`job_id`)) GROUP BY `j`.`id` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `barangays`
--
ALTER TABLE `barangays`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barangay_id` (`barangay_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_jobs_created_at` (`created_at`),
  ADD KEY `idx_jobs_status` (`status`);

--
-- Indexes for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_application` (`job_id`,`resident_id`),
  ADD KEY `resident_id` (`resident_id`);

--
-- Indexes for table `job_notifications`
--
ALTER TABLE `job_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `resident_id` (`resident_id`),
  ADD KEY `idx_job_notifications_status` (`status`),
  ADD KEY `idx_job_notifications_created_at` (`created_at`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_id` (`job_id`),
  ADD KEY `idx_messages_created_at` (`created_at`);

--
-- Indexes for table `requirements_list`
--
ALTER TABLE `requirements_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `residents`
--
ALTER TABLE `residents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `barangay_id` (`barangay_id`),
  ADD KEY `idx_residents_employment_status` (`employed`),
  ADD KEY `idx_residents_created_at` (`created_at`),
  ADD KEY `idx_residents_approved` (`approved`),
  ADD KEY `idx_residents_requirements` (`requirements_completed`);

--
-- Indexes for table `resident_requirements`
--
ALTER TABLE `resident_requirements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_resident_requirements` (`resident_id`),
  ADD KEY `idx_requirement_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `barangays`
--
ALTER TABLE `barangays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_notifications`
--
ALTER TABLE `job_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `requirements_list`
--
ALTER TABLE `requirements_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `residents`
--
ALTER TABLE `residents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `resident_requirements`
--
ALTER TABLE `resident_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `jobs`
--
ALTER TABLE `jobs`
  ADD CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`),
  ADD CONSTRAINT `jobs_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`);

--
-- Constraints for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `job_applications_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `job_applications_ibfk_2` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `job_notifications`
--
ALTER TABLE `job_notifications`
  ADD CONSTRAINT `job_notifications_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`),
  ADD CONSTRAINT `job_notifications_ibfk_2` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`);

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`);

--
-- Constraints for table `residents`
--
ALTER TABLE `residents`
  ADD CONSTRAINT `residents_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`);

--
-- Constraints for table `resident_requirements`
--
ALTER TABLE `resident_requirements`
  ADD CONSTRAINT `resident_requirements_ibfk_1` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
