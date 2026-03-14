-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Mar 14, 2026 at 09:46 PM
-- Server version: 8.4.6-6
-- PHP Version: 8.1.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `portaldb`
--

-- --------------------------------------------------------

--
-- Table structure for table `aesthetics`
--

CREATE TABLE `aesthetics` (
  `id` int NOT NULL,
  `mrn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_number` int NOT NULL,
  `visit_number` int DEFAULT NULL,
  `session_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `presenting_complaint` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `history_presenting_complaint` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `relevant_medical_history` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `previous_aesthetic_treatments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `skin_type_assessment` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `patient_goals` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `treatment_area` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `provisional_assessment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `treatment_plan` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `treatment_done_today` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `products_used` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `post_treatment_instructions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `follow_up_recommended` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consent_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consent_signature_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `consent_signed_by` int DEFAULT NULL,
  `consent_signed_at` datetime DEFAULT NULL,
  `treatment_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `locked_by` int DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int UNSIGNED NOT NULL,
  `patient_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `appointment_time` datetime NOT NULL,
  `department` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','completed','dismissed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `dismiss_reason` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

CREATE TABLE `attendance_logs` (
  `attendance_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `barledger`
--

CREATE TABLE `barledger` (
  `id` int NOT NULL,
  `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invoice_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `customer_type` enum('Employee','Patient') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `employee_user_id` int DEFAULT NULL,
  `payment_method` enum('Cash','Credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `created_by_user` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `certificate_id` int NOT NULL,
  `mrn` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `patient_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `gender` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `age` int DEFAULT NULL,
  `age_unit` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `certificate_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clinicalpsychology`
--

CREATE TABLE `clinicalpsychology` (
  `id` int NOT NULL,
  `mrn` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_number` int NOT NULL,
  `visit_number` int DEFAULT NULL,
  `session_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `presenting_problem` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `history_of_presenting_problem` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `relevant_psychiatric_history` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `relevant_medical_history` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `personal_social_history` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `risk_assessment_suicide_ideation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `risk_assessment_self_harm` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `risk_assessment_harm_to_others` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `protective_factors` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mse_appearance_behaviour` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mse_speech` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mse_mood` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mse_affect` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mse_thought_process_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mse_perception` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mse_cognition_insight_judgment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `assessment_tools_used` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `diagnostic_impression` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `session_focus_agenda` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `therapeutic_interventions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `client_response_to_interventions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `progress_towards_goals` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `homework_assigned` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `plan_for_next_session` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `other_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consent_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consent_signature_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `consent_signed_by` int DEFAULT NULL,
  `consent_signed_at` datetime DEFAULT NULL,
  `treatment_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `is_locked` tinyint(1) DEFAULT '0',
  `locked_by` int DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `complaints`
--

CREATE TABLE `complaints` (
  `complaint_id` int NOT NULL,
  `complaint_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `complaint_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `complaint_against` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `priority_code` tinyint UNSIGNED NOT NULL,
  `priority_label` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `issued_by` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dental`
--

CREATE TABLE `dental` (
  `mrn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_number` int NOT NULL,
  `visit_number` int NOT NULL,
  `session_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `chief_complaint` varchar(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `chief_complaint_history` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `other_complaints` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `dentist_visit_frequency` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `attendance_type` enum('symptomatic','asymptomatic') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `brush_frequency` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `previous_dental_tx` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `jaw_problems` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `parafunctional_habits` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mh_fit_well` tinyint(1) DEFAULT NULL,
  `mh_medications` tinyint(1) DEFAULT NULL,
  `mh_medications_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mh_allergies` tinyint(1) DEFAULT NULL,
  `mh_allergies_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mh_family_history` tinyint(1) DEFAULT NULL,
  `mh_family_history_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mh_cardio_resp_eye` tinyint(1) DEFAULT NULL,
  `mh_cardio_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mh_pregnancy` tinyint(1) DEFAULT NULL,
  `mh_pregnancy_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mh_menstrual_normal` tinyint(1) DEFAULT NULL,
  `mh_menstrual_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sh_smoker` tinyint(1) DEFAULT NULL,
  `sh_smoker_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sh_alcohol` tinyint(1) DEFAULT NULL,
  `sh_alcohol_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sh_diet` tinyint(1) DEFAULT NULL,
  `sh_diet_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sh_stress` tinyint(1) DEFAULT NULL,
  `sh_stress_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sh_occupation` tinyint(1) DEFAULT NULL,
  `sh_occupation_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `notes_expectations` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `notes_constraints` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `provisional_dx` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `investigations` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `incidental_findings` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `diagnosis` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `treatment_planned` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consent_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consent_signature_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `consent_signed_by` int DEFAULT NULL,
  `consent_signed_at` datetime DEFAULT NULL,
  `treatment_done_today` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `discharge_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `locked_by` int DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `drug_inventory`
--

CREATE TABLE `drug_inventory` (
  `id` int UNSIGNED NOT NULL,
  `brand_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `generic_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dosage` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int UNSIGNED NOT NULL DEFAULT '0',
  `rack` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Rack or shelf location, can be comma-separated',
  `purchase_price` decimal(10,2) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `drug_stock`
--

CREATE TABLE `drug_stock` (
  `id` int NOT NULL,
  `brand_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `generic_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `quantity` int NOT NULL,
  `purchase_price` decimal(10,2) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `added_on` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_reports`
--

CREATE TABLE `event_reports` (
  `event_id` int NOT NULL,
  `event_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `event_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `intensity_code` tinyint UNSIGNED NOT NULL,
  `intensity_label` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `issued_by` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `generalledger`
--

CREATE TABLE `generalledger` (
  `serial_number` int NOT NULL,
  `datetime` datetime NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `user` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `group_id` int NOT NULL,
  `group_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `labledger`
--

CREATE TABLE `labledger` (
  `serial_number` int UNSIGNED NOT NULL,
  `datetime` datetime NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `user` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_orders`
--

CREATE TABLE `lab_orders` (
  `order_id` int NOT NULL,
  `patient_mrn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `order_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ordered_by_user` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Username who created the order',
  `status` enum('Pending','Processing','Completed','Cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pending',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `report_link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `dob` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `invoice_link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_order_items`
--

CREATE TABLE `lab_order_items` (
  `item_id` int NOT NULL,
  `order_id` int NOT NULL,
  `test_id` int NOT NULL,
  `result_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'The actual test result',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'Any notes related to this specific test result',
  `reported_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when the result was entered/reported',
  `reported_by_user` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Username who reported the result',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_tests`
--

CREATE TABLE `lab_tests` (
  `test_id` int NOT NULL,
  `test_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `test_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `test_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `reference_range` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional: Normal value range',
  `result_unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional: Unit for the result (e.g., mg/dL)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'True=Visible/Usable, False=Hidden/Archived',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lab_test_parameters`
--

CREATE TABLE `lab_test_parameters` (
  `parameter_id` int NOT NULL,
  `test_id` int NOT NULL,
  `parameter_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_range` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_unit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ledger`
--

CREATE TABLE `ledger` (
  `id` int NOT NULL,
  `entry_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('revenue','expense') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int NOT NULL,
  `sender_id` int NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_recipients`
--

CREATE TABLE `message_recipients` (
  `message_id` int NOT NULL,
  `recipient_id` int NOT NULL,
  `seen` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `monthly_wages_summary`
--

CREATE TABLE `monthly_wages_summary` (
  `summary_id` int NOT NULL,
  `user_id` int NOT NULL,
  `year` int NOT NULL,
  `month` int NOT NULL,
  `total_hours` decimal(6,2) NOT NULL,
  `hourly_rate` decimal(10,2) NOT NULL,
  `calculated_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','paid','withheld') DEFAULT 'pending',
  `reason` text,
  `generated_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `office_notices`
--

CREATE TABLE `office_notices` (
  `notice_id` int UNSIGNED NOT NULL,
  `notice_title` varchar(255) NOT NULL,
  `notice_body` text NOT NULL,
  `issued_by` varchar(100) NOT NULL,
  `target_user_id` int DEFAULT NULL,
  `target_username` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patientregister`
--

CREATE TABLE `patientregister` (
  `id` int NOT NULL,
  `fullname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `age` int NOT NULL,
  `gender` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phonenumber` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `timeofpresentation` datetime NOT NULL,
  `MRN` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `department` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `payment` int NOT NULL DEFAULT '0',
  `createdby` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `visit_number` int NOT NULL DEFAULT '1',
  `total_amount` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int NOT NULL,
  `mrn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `gender` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `age` int DEFAULT '0',
  `age_unit` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'years',
  `last_department` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Stores the last department a ticket was issued for.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_emr`
--

CREATE TABLE `patient_emr` (
  `mrn` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `visit_number` int NOT NULL DEFAULT '1',
  `presenting_complaints` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `systemic_review_general` json DEFAULT NULL,
  `systemic_review_cv` json DEFAULT NULL,
  `systemic_review_resp` json DEFAULT NULL,
  `systemic_review_gi` json DEFAULT NULL,
  `systemic_review_gu` json DEFAULT NULL,
  `systemic_review_neuro` json DEFAULT NULL,
  `systemic_review_psych` json DEFAULT NULL,
  `examination_findings_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `focused_examination_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tests_ordered` json DEFAULT NULL,
  `past_medical_history` json DEFAULT NULL,
  `current_medications` json DEFAULT NULL,
  `allergies_option` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allergy_details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `working_diagnosis` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `differentials` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `drug_chart` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `consents` json DEFAULT NULL,
  `doctors_progress_notes` json DEFAULT NULL,
  `nurses_progress_notes` json DEFAULT NULL,
  `consultations_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tests_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `vitals_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `anaesthesia_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `discharge_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discharge_details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pharmacyledger`
--

CREATE TABLE `pharmacyledger` (
  `serial_number` int UNSIGNED NOT NULL,
  `datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `user` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pharmacy_expenses`
--

CREATE TABLE `pharmacy_expenses` (
  `id` int UNSIGNED NOT NULL,
  `expense_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `user` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pharmacy_sales_items`
--

CREATE TABLE `pharmacy_sales_items` (
  `sale_item_id` int UNSIGNED NOT NULL,
  `serial` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `drug_id` int UNSIGNED NOT NULL,
  `quantity_sold` int UNSIGNED NOT NULL,
  `quantity_returned` int UNSIGNED NOT NULL DEFAULT '0',
  `price_per_item` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `physiotherapy`
--

CREATE TABLE `physiotherapy` (
  `id` int UNSIGNED NOT NULL,
  `mrn` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `session_number` int UNSIGNED NOT NULL,
  `visit_number` int UNSIGNED DEFAULT NULL,
  `session_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `presenting_complaint` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `history_of_presenting_complaint` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `relevant_medical_history` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `functional_limitations` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `patient_goals` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `social_history` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `observation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `palpation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `range_of_motion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `muscle_strength` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `special_tests` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `neurological_assessment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `functional_tests` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `respiratory_assessment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `clinical_impression` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `treatment_plan` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `treatment_administered_today` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `home_exercise_program` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `precautions_contraindications` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `follow_up_recommended` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consent_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `consent_signature_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `consent_signed_by` int UNSIGNED DEFAULT NULL,
  `consent_signed_at` datetime DEFAULT NULL,
  `treatment_summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `locked_by` int UNSIGNED DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `prescription_id` int NOT NULL,
  `mrn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `patient_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `age` int DEFAULT NULL,
  `gender` enum('Male','Female','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_by` int NOT NULL,
  `age_unit` enum('Years','Months') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Years',
  `signed_by` int DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescription_medicines`
--

CREATE TABLE `prescription_medicines` (
  `med_id` int NOT NULL,
  `prescription_id` int NOT NULL,
  `medicine_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `route` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `dosage` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `frequency` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `duration` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int NOT NULL,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_reset_required` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = User must set password on first login, 0 = Password set',
  `full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `postal_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hours_per_week` int NOT NULL,
  `monthly_pay` decimal(10,2) NOT NULL,
  `date_of_joining` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `national_identity_number` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_id` int NOT NULL,
  `suspended` int NOT NULL DEFAULT '0',
  `terminated` tinyint(1) NOT NULL DEFAULT '0',
  `suspended_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_files`
--

CREATE TABLE `user_files` (
  `file_id` int NOT NULL,
  `user_id` int NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_groups`
--

CREATE TABLE `user_groups` (
  `user_id` int NOT NULL,
  `group_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_notices`
--

CREATE TABLE `user_notices` (
  `notice_id` int NOT NULL,
  `user_id` int NOT NULL,
  `notice_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `notice_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visits`
--

CREATE TABLE `visits` (
  `visit_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `visit_number` int DEFAULT '1',
  `department` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `time_of_presentation` datetime NOT NULL,
  `age_value` int DEFAULT '0',
  `age_unit` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'years',
  `total_amount` decimal(10,2) DEFAULT '0.00',
  `services_rendered` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mrn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visit_invoices`
--

CREATE TABLE `visit_invoices` (
  `invoice_id` int NOT NULL,
  `mrn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `visit_number` int NOT NULL,
  `invoice_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'The base filename, e.g., Ticket_MRN_Visit_1.pdf',
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'The relative path, e.g., patiententries/Ticket_MRN_Visit_1.pdf',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int NOT NULL COMMENT 'The user_id of the person who created the ticket'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wages_disbursements`
--

CREATE TABLE `wages_disbursements` (
  `wage_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `year` smallint UNSIGNED NOT NULL,
  `month` tinyint UNSIGNED NOT NULL,
  `hours_worked` decimal(10,2) NOT NULL DEFAULT '0.00',
  `calculated_pay` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('paid','withheld') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `disbursed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `disbursed_amount` decimal(12,2) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `aesthetics`
--
ALTER TABLE `aesthetics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mrn_session` (`mrn`,`session_number`),
  ADD KEY `mrn_idx` (`mrn`),
  ADD KEY `created_by_idx` (`created_by`),
  ADD KEY `locked_by_idx` (`locked_by`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `idx_status_time` (`status`,`appointment_time`),
  ADD KEY `fk_appointments_users` (`created_by`);

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`attendance_id`),
  ADD KEY `idx_user_day` (`user_id`,`check_in`);

--
-- Indexes for table `barledger`
--
ALTER TABLE `barledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_id` (`invoice_id`),
  ADD KEY `idx_employee_user_id` (`employee_user_id`),
  ADD KEY `idx_datetime` (`datetime`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`certificate_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `mrn` (`mrn`),
  ADD KEY `patient_name` (`patient_name`),
  ADD KEY `certificate_type` (`certificate_type`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `clinicalpsychology`
--
ALTER TABLE `clinicalpsychology`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mrn_session` (`mrn`,`session_number`);

--
-- Indexes for table `complaints`
--
ALTER TABLE `complaints`
  ADD PRIMARY KEY (`complaint_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `dental`
--
ALTER TABLE `dental`
  ADD PRIMARY KEY (`mrn`,`session_number`),
  ADD KEY `idx_mrn` (`mrn`);

--
-- Indexes for table `drug_inventory`
--
ALTER TABLE `drug_inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_brand_generic` (`brand_name`,`generic_name`);

--
-- Indexes for table `drug_stock`
--
ALTER TABLE `drug_stock`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `event_reports`
--
ALTER TABLE `event_reports`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `generalledger`
--
ALTER TABLE `generalledger`
  ADD PRIMARY KEY (`serial_number`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`group_id`),
  ADD UNIQUE KEY `group_name` (`group_name`);

--
-- Indexes for table `labledger`
--
ALTER TABLE `labledger`
  ADD PRIMARY KEY (`serial_number`),
  ADD KEY `idx_datetime` (`datetime`),
  ADD KEY `idx_user` (`user`);

--
-- Indexes for table `lab_orders`
--
ALTER TABLE `lab_orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `idx_patient_mrn` (`patient_mrn`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `lab_order_items`
--
ALTER TABLE `lab_order_items`
  ADD PRIMARY KEY (`item_id`),
  ADD UNIQUE KEY `unique_order_test` (`order_id`,`test_id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_test_id` (`test_id`);

--
-- Indexes for table `lab_tests`
--
ALTER TABLE `lab_tests`
  ADD PRIMARY KEY (`test_id`),
  ADD UNIQUE KEY `test_name` (`test_name`);

--
-- Indexes for table `lab_test_parameters`
--
ALTER TABLE `lab_test_parameters`
  ADD PRIMARY KEY (`parameter_id`),
  ADD KEY `idx_test_id` (`test_id`);

--
-- Indexes for table `ledger`
--
ALTER TABLE `ledger`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `message_recipients`
--
ALTER TABLE `message_recipients`
  ADD KEY `message_id` (`message_id`),
  ADD KEY `recipient_id` (`recipient_id`);

--
-- Indexes for table `monthly_wages_summary`
--
ALTER TABLE `monthly_wages_summary`
  ADD PRIMARY KEY (`summary_id`),
  ADD UNIQUE KEY `user_id` (`user_id`,`year`,`month`);

--
-- Indexes for table `office_notices`
--
ALTER TABLE `office_notices`
  ADD PRIMARY KEY (`notice_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `patientregister`
--
ALTER TABLE `patientregister`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`),
  ADD UNIQUE KEY `mrn` (`mrn`);

--
-- Indexes for table `patient_emr`
--
ALTER TABLE `patient_emr`
  ADD PRIMARY KEY (`mrn`,`visit_number`);

--
-- Indexes for table `pharmacyledger`
--
ALTER TABLE `pharmacyledger`
  ADD PRIMARY KEY (`serial_number`),
  ADD UNIQUE KEY `uniq_description` (`description`),
  ADD KEY `idx_datetime` (`datetime`),
  ADD KEY `idx_desc` (`description`);

--
-- Indexes for table `pharmacy_expenses`
--
ALTER TABLE `pharmacy_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expense_date` (`expense_date`);

--
-- Indexes for table `pharmacy_sales_items`
--
ALTER TABLE `pharmacy_sales_items`
  ADD PRIMARY KEY (`sale_item_id`),
  ADD KEY `idx_serial` (`serial`),
  ADD KEY `fk_drug_id` (`drug_id`);

--
-- Indexes for table `physiotherapy`
--
ALTER TABLE `physiotherapy`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_physio_mrn_session` (`mrn`,`session_number`),
  ADD KEY `idx_physio_mrn` (`mrn`),
  ADD KEY `idx_visit_num` (`visit_number`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`prescription_id`);

--
-- Indexes for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  ADD PRIMARY KEY (`med_id`),
  ADD KEY `prescription_id` (`prescription_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_files`
--
ALTER TABLE `user_files`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_groups`
--
ALTER TABLE `user_groups`
  ADD PRIMARY KEY (`user_id`,`group_id`),
  ADD KEY `group_id` (`group_id`);

--
-- Indexes for table `user_notices`
--
ALTER TABLE `user_notices`
  ADD PRIMARY KEY (`notice_id`),
  ADD KEY `fk_user_notices_user` (`user_id`);

--
-- Indexes for table `visits`
--
ALTER TABLE `visits`
  ADD PRIMARY KEY (`visit_id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `visit_invoices`
--
ALTER TABLE `visit_invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD KEY `idx_mrn_visit` (`mrn`,`visit_number`);

--
-- Indexes for table `wages_disbursements`
--
ALTER TABLE `wages_disbursements`
  ADD PRIMARY KEY (`wage_id`),
  ADD UNIQUE KEY `uniq_user_month` (`user_id`,`year`,`month`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `aesthetics`
--
ALTER TABLE `aesthetics`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `attendance_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `barledger`
--
ALTER TABLE `barledger`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `certificate_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clinicalpsychology`
--
ALTER TABLE `clinicalpsychology`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `complaints`
--
ALTER TABLE `complaints`
  MODIFY `complaint_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `drug_inventory`
--
ALTER TABLE `drug_inventory`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `drug_stock`
--
ALTER TABLE `drug_stock`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_reports`
--
ALTER TABLE `event_reports`
  MODIFY `event_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `generalledger`
--
ALTER TABLE `generalledger`
  MODIFY `serial_number` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `group_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `labledger`
--
ALTER TABLE `labledger`
  MODIFY `serial_number` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_orders`
--
ALTER TABLE `lab_orders`
  MODIFY `order_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_order_items`
--
ALTER TABLE `lab_order_items`
  MODIFY `item_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_tests`
--
ALTER TABLE `lab_tests`
  MODIFY `test_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lab_test_parameters`
--
ALTER TABLE `lab_test_parameters`
  MODIFY `parameter_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ledger`
--
ALTER TABLE `ledger`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `monthly_wages_summary`
--
ALTER TABLE `monthly_wages_summary`
  MODIFY `summary_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `office_notices`
--
ALTER TABLE `office_notices`
  MODIFY `notice_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patientregister`
--
ALTER TABLE `patientregister`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pharmacyledger`
--
ALTER TABLE `pharmacyledger`
  MODIFY `serial_number` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pharmacy_expenses`
--
ALTER TABLE `pharmacy_expenses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pharmacy_sales_items`
--
ALTER TABLE `pharmacy_sales_items`
  MODIFY `sale_item_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `physiotherapy`
--
ALTER TABLE `physiotherapy`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prescriptions`
--
ALTER TABLE `prescriptions`
  MODIFY `prescription_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  MODIFY `med_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_files`
--
ALTER TABLE `user_files`
  MODIFY `file_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_notices`
--
ALTER TABLE `user_notices`
  MODIFY `notice_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visits`
--
ALTER TABLE `visits`
  MODIFY `visit_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visit_invoices`
--
ALTER TABLE `visit_invoices`
  MODIFY `invoice_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wages_disbursements`
--
ALTER TABLE `wages_disbursements`
  MODIFY `wage_id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appointments_users` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `certificates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `dental`
--
ALTER TABLE `dental`
  ADD CONSTRAINT `fk_dental_patient` FOREIGN KEY (`mrn`) REFERENCES `patients` (`mrn`) ON DELETE CASCADE;

--
-- Constraints for table `lab_orders`
--
ALTER TABLE `lab_orders`
  ADD CONSTRAINT `lab_orders_ibfk_1` FOREIGN KEY (`patient_mrn`) REFERENCES `patients` (`mrn`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `lab_order_items`
--
ALTER TABLE `lab_order_items`
  ADD CONSTRAINT `lab_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `lab_orders` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lab_order_items_ibfk_2` FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`test_id`) ON DELETE RESTRICT;

--
-- Constraints for table `lab_test_parameters`
--
ALTER TABLE `lab_test_parameters`
  ADD CONSTRAINT `lab_test_parameters_ibfk_1` FOREIGN KEY (`test_id`) REFERENCES `lab_tests` (`test_id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `message_recipients`
--
ALTER TABLE `message_recipients`
  ADD CONSTRAINT `message_recipients_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`message_id`),
  ADD CONSTRAINT `message_recipients_ibfk_2` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `monthly_wages_summary`
--
ALTER TABLE `monthly_wages_summary`
  ADD CONSTRAINT `monthly_wages_summary_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `pharmacy_sales_items`
--
ALTER TABLE `pharmacy_sales_items`
  ADD CONSTRAINT `fk_drug_id` FOREIGN KEY (`drug_id`) REFERENCES `drug_inventory` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `prescription_medicines`
--
ALTER TABLE `prescription_medicines`
  ADD CONSTRAINT `prescription_medicines_ibfk_1` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`prescription_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_files`
--
ALTER TABLE `user_files`
  ADD CONSTRAINT `user_files_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `user_groups`
--
ALTER TABLE `user_groups`
  ADD CONSTRAINT `user_groups_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `groups` (`group_id`);

--
-- Constraints for table `user_notices`
--
ALTER TABLE `user_notices`
  ADD CONSTRAINT `fk_user_notices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `visits`
--
ALTER TABLE `visits`
  ADD CONSTRAINT `visits_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`);

--
-- Constraints for table `wages_disbursements`
--
ALTER TABLE `wages_disbursements`
  ADD CONSTRAINT `fk_wages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
