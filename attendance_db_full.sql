-- ========================================================================
-- LeafPoint Attendance System — Complete Database
-- Import directly into phpMyAdmin on a clean system
-- ========================================================================

DROP DATABASE IF EXISTS `attendance_db`;
CREATE DATABASE `attendance_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `attendance_db`;

-- ========================================================================
-- TABLE 1: users
-- ========================================================================
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Login identifier',
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('employee', 'admin') DEFAULT 'employee',
    `join_date` DATE NOT NULL,
    `remember_token` VARCHAR(255) NULL,
    `reset_token` VARCHAR(64) NULL COMMENT 'Password reset token',
    `reset_token_expiry` DATETIME NULL COMMENT 'Reset token expiry (1 hour)',
    `total_points` INT DEFAULT 0,
    `current_streak` INT DEFAULT 0,
    `plant_highest_stage` INT DEFAULT 1,
    `plant_current_stage` INT DEFAULT 1,
    `plant_status` ENUM('Healthy', 'Withered') DEFAULT 'Healthy',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 2: system_settings
-- ========================================================================
CREATE TABLE `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE,
    `setting_value` VARCHAR(255) NOT NULL,
    `description` VARCHAR(255) NULL
) ENGINE=InnoDB;

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('office_lat', '3.141592', 'Company Office Latitude'),
('office_lng', '101.686530', 'Company Office Longitude'),
('office_ip', '192.168.1.100', 'Company Public Wi-Fi IP'),
('leave_rolling_months', '3', 'Max months ahead employee can apply for leave'),
('enable_ip_validation', '0', 'Enable/disable office IP validation for check-in (0=off, 1=on)'),
('office_radius', '200', 'Office GPS radius in meters for attendance validation'),
('enable_gps_validation', '0', 'Enable/disable GPS validation for check-in (0=off, 1=on)');

-- ========================================================================
-- TABLE 3: attendance
-- ========================================================================
CREATE TABLE `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `check_in_time` TIME NULL,
    `check_out_time` TIME NULL,
    `status` ENUM('on_time', 'grace_period', 'late', 'absent', 'on_leave', 'public_holiday') NOT NULL,
    `points_earned` INT DEFAULT 0,
    `location_lat` DECIMAL(10, 8) NULL,
    `location_lng` DECIMAL(10, 8) NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_daily_attendance` (`user_id`, `date`)
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 4: leave_balances
-- ========================================================================
CREATE TABLE `leave_balances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `year` YEAR NOT NULL,
    `al_total` INT DEFAULT 14,
    `al_used` DECIMAL(5,1) DEFAULT 0.0,
    `mc_total` INT DEFAULT 14,
    `mc_used` DECIMAL(5,1) DEFAULT 0.0,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_year` (`user_id`, `year`)
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 5: leave_requests
-- ========================================================================
CREATE TABLE `leave_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `leave_type` ENUM('AL', 'MC', 'UL') NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `custom_reason` TEXT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `admin_remark` TEXT NULL,
    `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 6: public_holidays
-- ========================================================================
CREATE TABLE `public_holidays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `holiday_date` DATE NOT NULL UNIQUE,
    `holiday_name` VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 7: reward_items
-- ========================================================================
CREATE TABLE `reward_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `image_url` VARCHAR(255) NULL,
    `points_required` INT NOT NULL,
    `stock_quantity` INT NOT NULL DEFAULT 1,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 8: reward_redemptions
-- ========================================================================
CREATE TABLE `reward_redemptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `points_spent` INT NOT NULL,
    `status` ENUM('pending', 'completed', 'cancelled', 'rejected') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`item_id`) REFERENCES `reward_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 9: point_transactions
-- ========================================================================
CREATE TABLE `point_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `amount` INT NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ########################################################################
-- SEED DATA
-- ########################################################################

-- ========================================================================
-- USERS — 1 admin + 19 employees (IDs 1-20)
-- ========================================================================
INSERT INTO `users` (`id`, `name`, `username`, `email`, `password_hash`, `role`, `join_date`, `total_points`, `current_streak`, `plant_highest_stage`, `plant_current_stage`, `plant_status`) VALUES
(1, 'John Tan', 'john.tan', 'john.tan@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'admin', '2024-01-01', 0, 0, 1, 1, 'Healthy'),
(2, 'Alice Lim', 'alice.lim', 'alice.lim@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-01', 9200, 72, 4, 4, 'Healthy'),
(3, 'Brian Wong', 'brian.wong', 'brian.wong@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-05', 8500, 68, 4, 4, 'Healthy'),
(4, 'Catherine Lee', 'catherine.lee', 'catherine.lee@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-10', 10500, 82, 5, 5, 'Healthy'),
(5, 'Daniel Ng', 'daniel.ng', 'daniel.ng@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-15', 5400, 42, 3, 3, 'Healthy'),
(6, 'Ethan Ong', 'ethan.ong', 'ethan.ong@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-20', 2500, 0, 2, 1, 'Withered'),
(7, 'Fiona Chua', 'fiona.chua', 'fiona.chua@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-01', 6800, 6, 4, 3, 'Healthy'),
(8, 'George Tan', 'george.tan', 'george.tan@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-05', 18450, 130, 7, 7, 'Healthy'),
(9, 'Hannah Lim', 'hannah.lim', 'hannah.lim@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-10', 16200, 125, 7, 7, 'Healthy'),
(10, 'Ivan Lee', 'ivan.lee', 'ivan.lee@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-15', 14100, 110, 6, 6, 'Healthy'),
(11, 'Jessica Wong', 'jessica.wong', 'jessica.wong@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-20', 13500, 105, 6, 6, 'Healthy'),
(12, 'Kevin Ng', 'kevin.ng', 'kevin.ng@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-01', 11800, 90, 5, 5, 'Healthy'),
(13, 'Lily Tan', 'lily.tan', 'lily.tan@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-05', 11200, 85, 5, 5, 'Healthy'),
(14, 'Michael Lim', 'michael.lim', 'michael.lim@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-10', 8800, 70, 4, 4, 'Healthy'),
(15, 'Nicole Lee', 'nicole.lee', 'nicole.lee@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-15', 8200, 65, 4, 4, 'Healthy'),
(16, 'Oscar Wong', 'oscar.wong', 'oscar.wong@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-20', 6200, 50, 3, 3, 'Healthy'),
(17, 'Paul Ng', 'paul.ng', 'paul.ng@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-05-01', 5800, 45, 3, 3, 'Healthy'),
(18, 'Queen Tan', 'queen.tan', 'queen.tan@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-05-05', 3100, 3, 3, 2, 'Withered'),
(19, 'Ryan Lim', 'ryan.lim', 'ryan.lim@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-05-10', 3800, 25, 2, 2, 'Healthy'),
(20, 'Sophia Lee', 'sophia.lee', 'sophia.lee@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-05-15', 1200, 8, 1, 1, 'Healthy');


-- ========================================================================
-- PUBLIC HOLIDAYS — Malaysia 2026
-- ========================================================================
INSERT INTO `public_holidays` (`holiday_date`, `holiday_name`) VALUES
('2026-01-01', 'New Year Day'),
('2026-02-01', 'Federal Territory Day'),
('2026-02-17', 'Chinese New Year'),
('2026-02-18', 'Chinese New Year (Day 2)'),
('2026-03-04', 'Israk and Mikraj'),
('2026-03-20', 'Nuzul Quran'),
('2026-05-01', 'Labour Day'),
('2026-05-31', 'Wesak Day'),
('2026-06-01', 'Gawai Dayak'),
('2026-06-07', 'Agong Birthday'),
('2026-06-08', 'Agong Birthday (Observed)'),
('2026-07-07', 'Awal Muharram'),
('2026-08-31', 'Merdeka Day'),
('2026-09-16', 'Malaysia Day'),
('2026-10-29', 'Deepavali'),
('2026-12-25', 'Christmas Day');


-- ========================================================================
-- ATTENDANCE — May + June 2026, all 19 employees
-- ========================================================================

-- --- USER 2: Alice Lim — Stage 4, consistent ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(2,'2026-05-01','08:50:00','on_time',15),
(2,'2026-05-04','08:45:00','on_time',15),(2,'2026-05-05','08:55:00','on_time',15),(2,'2026-05-06','08:38:00','on_time',15),(2,'2026-05-07','09:15:00','grace_period',12),(2,'2026-05-08','08:42:00','on_time',15),
(2,'2026-05-11','08:35:00','on_time',15),(2,'2026-05-12','08:48:00','on_time',15),(2,'2026-05-13','09:05:00','grace_period',12),(2,'2026-05-14','08:30:00','on_time',15),(2,'2026-05-15','08:50:00','on_time',15),
(2,'2026-05-18','08:42:00','on_time',15),(2,'2026-05-19','08:55:00','on_time',15),(2,'2026-05-20','10:10:00','late',10),(2,'2026-05-21','08:38:00','on_time',15),(2,'2026-05-22','08:45:00','on_time',15),
(2,'2026-05-25','08:50:00','on_time',15),(2,'2026-05-26','08:35:00','on_time',15),(2,'2026-05-27','09:20:00','grace_period',12),(2,'2026-05-28','08:40:00','on_time',15),(2,'2026-05-29','08:48:00','on_time',15),
(2,'2026-06-02','08:45:00','on_time',15),(2,'2026-06-03','08:50:00','on_time',15),(2,'2026-06-04','09:05:00','grace_period',12),(2,'2026-06-05','08:30:00','on_time',15),
(2,'2026-06-09','08:55:00','on_time',15),(2,'2026-06-10','08:42:00','on_time',15),(2,'2026-06-11','09:20:00','grace_period',12),(2,'2026-06-12','08:38:00','on_time',15),
(2,'2026-06-15','08:48:00','on_time',15),(2,'2026-06-16','08:52:00','on_time',15),(2,'2026-06-17','08:35:00','on_time',15),(2,'2026-06-18','10:15:00','late',10),(2,'2026-06-19','08:40:00','on_time',15);

-- --- USER 3: Brian Wong — Stage 4 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(3,'2026-05-01','08:55:00','on_time',15),
(3,'2026-05-04','08:48:00','on_time',15),(3,'2026-05-05','09:10:00','grace_period',12),(3,'2026-05-06','08:40:00','on_time',15),(3,'2026-05-07','08:50:00','on_time',15),(3,'2026-05-08','08:35:00','on_time',15),
(3,'2026-05-11','09:30:00','grace_period',12),(3,'2026-05-12','08:42:00','on_time',15),(3,'2026-05-13','08:55:00','on_time',15),(3,'2026-05-14','08:38:00','on_time',15),(3,'2026-05-15','09:00:00','on_time',15),
(3,'2026-05-18','08:50:00','on_time',15),(3,'2026-05-19','08:45:00','on_time',15),(3,'2026-05-20','08:55:00','on_time',15),(3,'2026-05-21','09:15:00','grace_period',12),(3,'2026-05-22','08:40:00','on_time',15),
(3,'2026-05-25','08:48:00','on_time',15),(3,'2026-05-26','09:00:00','on_time',15),(3,'2026-05-27','08:50:00','on_time',15),(3,'2026-05-28','08:42:00','on_time',15),(3,'2026-05-29','08:35:00','on_time',15),
(3,'2026-06-02','08:50:00','on_time',15),(3,'2026-06-03','08:55:00','on_time',15),(3,'2026-06-04','08:40:00','on_time',15),(3,'2026-06-05','09:10:00','grace_period',12),
(3,'2026-06-09','08:35:00','on_time',15),(3,'2026-06-10','08:48:00','on_time',15),(3,'2026-06-11','08:30:00','on_time',15),(3,'2026-06-12','08:55:00','on_time',15),
(3,'2026-06-15','09:30:00','grace_period',12),(3,'2026-06-16','08:42:00','on_time',15),(3,'2026-06-17','08:38:00','on_time',15),(3,'2026-06-18','08:50:00','on_time',15),(3,'2026-06-19','09:45:00','grace_period',12);

-- --- USER 4: Catherine Lee — Stage 5, near perfect ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(4,'2026-05-01','08:35:00','on_time',15),
(4,'2026-05-04','08:30:00','on_time',15),(4,'2026-05-05','08:25:00','on_time',15),(4,'2026-05-06','08:40:00','on_time',15),(4,'2026-05-07','08:32:00','on_time',15),(4,'2026-05-08','08:38:00','on_time',15),
(4,'2026-05-11','08:28:00','on_time',15),(4,'2026-05-12','08:45:00','on_time',15),(4,'2026-05-13','08:35:00','on_time',15),(4,'2026-05-14','08:30:00','on_time',15),(4,'2026-05-15','08:42:00','on_time',15),
(4,'2026-05-18','08:38:00','on_time',15),(4,'2026-05-19','08:50:00','on_time',15),(4,'2026-05-20','08:35:00','on_time',15),(4,'2026-05-21','08:28:00','on_time',15),(4,'2026-05-22','08:40:00','on_time',15),
(4,'2026-05-25','08:32:00','on_time',15),(4,'2026-05-26','08:45:00','on_time',15),(4,'2026-05-27','08:38:00','on_time',15),(4,'2026-05-28','09:00:00','on_time',15),(4,'2026-05-29','08:30:00','on_time',15),
(4,'2026-06-02','08:30:00','on_time',15),(4,'2026-06-03','08:25:00','on_time',15),(4,'2026-06-04','08:35:00','on_time',15),(4,'2026-06-05','08:40:00','on_time',15),
(4,'2026-06-09','08:32:00','on_time',15),(4,'2026-06-10','08:28:00','on_time',15),(4,'2026-06-11','08:45:00','on_time',15),(4,'2026-06-12','08:38:00','on_time',15);

-- Catherine on approved leave June 15-16
INSERT INTO `attendance` (`user_id`, `date`, `status`, `points_earned`) VALUES
(4,'2026-06-15','on_leave',0),(4,'2026-06-16','on_leave',0);

INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(4,'2026-06-17','08:30:00','on_time',15),(4,'2026-06-18','08:48:00','on_time',15),(4,'2026-06-19','08:40:00','on_time',15);

-- --- USER 5: Daniel Ng — Stage 3, occasional issues ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(5,'2026-05-01','08:55:00','on_time',10),
(5,'2026-05-04','09:20:00','grace_period',7),(5,'2026-05-05','08:50:00','on_time',10),(5,'2026-05-06','10:10:00','late',5),(5,'2026-05-07','08:45:00','on_time',10),(5,'2026-05-08','09:30:00','grace_period',7),
(5,'2026-05-11','08:42:00','on_time',10),(5,'2026-05-12','10:25:00','late',5),(5,'2026-05-13','08:50:00','on_time',10),(5,'2026-05-14','09:15:00','grace_period',7),(5,'2026-05-15','08:38:00','on_time',10),
(5,'2026-05-18','08:55:00','on_time',10),(5,'2026-05-19','08:48:00','on_time',10),(5,'2026-05-20','09:40:00','grace_period',7),(5,'2026-05-21','08:50:00','on_time',10),(5,'2026-05-22','08:42:00','on_time',10),
(5,'2026-05-25','09:05:00','grace_period',7),(5,'2026-05-26','08:50:00','on_time',10),(5,'2026-05-27','10:00:00','grace_period',7),(5,'2026-05-28','08:45:00','on_time',10),(5,'2026-05-29','08:38:00','on_time',10),
(5,'2026-06-02','08:55:00','on_time',10),(5,'2026-06-03','09:15:00','grace_period',7),(5,'2026-06-04','08:40:00','on_time',10),(5,'2026-06-05','08:50:00','on_time',10),
(5,'2026-06-09','10:30:00','late',5),(5,'2026-06-10','08:45:00','on_time',10),(5,'2026-06-11','08:35:00','on_time',10),(5,'2026-06-12','09:00:00','on_time',10),
(5,'2026-06-15','08:50:00','on_time',10),(5,'2026-06-16','08:42:00','on_time',10),(5,'2026-06-17','09:20:00','grace_period',7),(5,'2026-06-18','08:38:00','on_time',10),(5,'2026-06-19','08:55:00','on_time',10);

-- --- USER 6: Ethan Ong — Withered, Stage 1, streak=0 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(6,'2026-05-01','09:30:00','grace_period',7),
(6,'2026-05-04','10:45:00','late',5),(6,'2026-05-05','08:55:00','on_time',10),(6,'2026-05-06',NULL,'absent',-2),(6,'2026-05-07','09:20:00','grace_period',7),(6,'2026-05-08','10:10:00','late',5),
(6,'2026-05-11','08:50:00','on_time',10),(6,'2026-05-12',NULL,'absent',-2),(6,'2026-05-13','09:40:00','grace_period',7),(6,'2026-05-14','08:45:00','on_time',10),(6,'2026-05-15','10:30:00','late',5),
(6,'2026-05-18','09:15:00','grace_period',7),(6,'2026-05-19','08:50:00','on_time',10),(6,'2026-05-20',NULL,'absent',-2),(6,'2026-05-21','10:00:00','grace_period',7),(6,'2026-05-22','08:55:00','on_time',10),
(6,'2026-05-25','08:48:00','on_time',10),(6,'2026-05-26','09:30:00','grace_period',7),(6,'2026-05-27','10:15:00','late',5),(6,'2026-05-28','08:45:00','on_time',10),(6,'2026-05-29',NULL,'absent',-2),
(6,'2026-06-02','09:30:00','grace_period',7),(6,'2026-06-03','10:45:00','late',5),(6,'2026-06-04',NULL,'absent',-2),(6,'2026-06-05','09:00:00','on_time',10),
(6,'2026-06-09','10:15:00','late',5),(6,'2026-06-10','08:50:00','on_time',10),(6,'2026-06-11','09:40:00','grace_period',7),(6,'2026-06-12',NULL,'absent',-2),
(6,'2026-06-15','09:10:00','grace_period',7),(6,'2026-06-16','08:55:00','on_time',10),(6,'2026-06-17','10:30:00','late',5),(6,'2026-06-18','08:45:00','on_time',10),(6,'2026-06-19','09:00:00','on_time',10);

-- --- USER 7: Fiona Chua — Recovering (Stage 3, highest=4), rebuilding streak ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(7,'2026-05-01','08:50:00','on_time',10),
(7,'2026-05-04','08:45:00','on_time',10),(7,'2026-05-05','09:00:00','on_time',10),(7,'2026-05-06','08:38:00','on_time',10),(7,'2026-05-07','08:50:00','on_time',10),(7,'2026-05-08','08:42:00','on_time',10),
(7,'2026-05-11','10:30:00','late',5),(7,'2026-05-12','08:55:00','on_time',10),(7,'2026-05-13','08:48:00','on_time',10),(7,'2026-05-14','08:40:00','on_time',10),(7,'2026-05-15','09:15:00','grace_period',7),
(7,'2026-05-18','08:50:00','on_time',10),(7,'2026-05-19','08:35:00','on_time',10),(7,'2026-05-20','08:45:00','on_time',10),(7,'2026-05-21','08:38:00','on_time',10),(7,'2026-05-22','08:55:00','on_time',10),
(7,'2026-05-25','08:42:00','on_time',10),(7,'2026-05-26','08:48:00','on_time',10),(7,'2026-05-27','08:50:00','on_time',10),(7,'2026-05-28','08:35:00','on_time',10),(7,'2026-05-29','09:00:00','on_time',10),
(7,'2026-06-02','08:50:00','on_time',10),(7,'2026-06-03','08:45:00','on_time',10),(7,'2026-06-04','08:38:00','on_time',10),(7,'2026-06-05','08:42:00','on_time',10),
(7,'2026-06-09','08:35:00','on_time',10),(7,'2026-06-10','08:48:00','on_time',10);

-- Fiona on approved leave June 11-12
INSERT INTO `attendance` (`user_id`, `date`, `status`, `points_earned`) VALUES
(7,'2026-06-11','on_leave',0),(7,'2026-06-12','on_leave',0);

INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(7,'2026-06-15','08:40:00','on_time',10),(7,'2026-06-16','08:32:00','on_time',10),(7,'2026-06-17','08:50:00','on_time',10),(7,'2026-06-18','08:45:00','on_time',10),(7,'2026-06-19','08:38:00','on_time',10);

-- --- USER 8: George Tan — Stage 7 World Tree, top performer ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(8,'2026-05-01','08:20:00','on_time',15),
(8,'2026-05-04','08:15:00','on_time',15),(8,'2026-05-05','08:18:00','on_time',15),(8,'2026-05-06','08:22:00','on_time',15),(8,'2026-05-07','08:20:00','on_time',15),(8,'2026-05-08','08:25:00','on_time',15),
(8,'2026-05-11','08:18:00','on_time',15),(8,'2026-05-12','08:22:00','on_time',15),(8,'2026-05-13','08:15:00','on_time',15),(8,'2026-05-14','08:28:00','on_time',15),(8,'2026-05-15','08:20:00','on_time',15),
(8,'2026-05-18','08:18:00','on_time',15),(8,'2026-05-19','08:25:00','on_time',15),(8,'2026-05-20','08:20:00','on_time',15),(8,'2026-05-21','08:22:00','on_time',15),(8,'2026-05-22','08:15:00','on_time',15),
(8,'2026-05-25','08:20:00','on_time',15),(8,'2026-05-26','08:18:00','on_time',15),(8,'2026-05-27','08:25:00','on_time',15),(8,'2026-05-28','08:22:00','on_time',15),(8,'2026-05-29','08:20:00','on_time',15),
(8,'2026-06-02','08:15:00','on_time',15),(8,'2026-06-03','08:20:00','on_time',15),(8,'2026-06-04','08:18:00','on_time',15),(8,'2026-06-05','08:22:00','on_time',15),
(8,'2026-06-09','08:25:00','on_time',15),(8,'2026-06-10','08:20:00','on_time',15),(8,'2026-06-11','08:30:00','on_time',15),(8,'2026-06-12','08:15:00','on_time',15),
(8,'2026-06-15','08:28:00','on_time',15),(8,'2026-06-16','08:22:00','on_time',15),(8,'2026-06-17','08:18:00','on_time',15),(8,'2026-06-18','08:25:00','on_time',15),(8,'2026-06-19','08:20:00','on_time',15);

-- --- USER 9: Hannah Lim — Stage 7 World Tree ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(9,'2026-05-01','08:30:00','on_time',15),
(9,'2026-05-04','08:25:00','on_time',15),(9,'2026-05-05','08:35:00','on_time',15),(9,'2026-05-06','08:28:00','on_time',15),(9,'2026-05-07','08:32:00','on_time',15),(9,'2026-05-08','08:30:00','on_time',15),
(9,'2026-05-11','08:28:00','on_time',15),(9,'2026-05-12','08:35:00','on_time',15),(9,'2026-05-13','08:30:00','on_time',15),(9,'2026-05-14','08:25:00','on_time',15),(9,'2026-05-15','08:32:00','on_time',15),
(9,'2026-05-18','08:28:00','on_time',15),(9,'2026-05-19','08:35:00','on_time',15),(9,'2026-05-20','08:25:00','on_time',15),(9,'2026-05-21','08:30:00','on_time',15),(9,'2026-05-22','08:32:00','on_time',15),
(9,'2026-05-25','08:28:00','on_time',15),(9,'2026-05-26','08:30:00','on_time',15),(9,'2026-05-27','08:25:00','on_time',15),(9,'2026-05-28','08:35:00','on_time',15),(9,'2026-05-29','08:28:00','on_time',15),
(9,'2026-06-02','08:30:00','on_time',15),(9,'2026-06-03','08:25:00','on_time',15),(9,'2026-06-04','08:35:00','on_time',15);

-- Hannah on approved MC June 5
INSERT INTO `attendance` (`user_id`, `date`, `status`, `points_earned`) VALUES
(9,'2026-06-05','on_leave',0);

INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(9,'2026-06-09','08:32:00','on_time',15),(9,'2026-06-10','08:20:00','on_time',15),(9,'2026-06-11','08:40:00','on_time',15),(9,'2026-06-12','08:30:00','on_time',15),
(9,'2026-06-15','08:35:00','on_time',15),(9,'2026-06-16','08:28:00','on_time',15),(9,'2026-06-17','08:22:00','on_time',15),(9,'2026-06-18','08:30:00','on_time',15),(9,'2026-06-19','08:25:00','on_time',15);

-- --- USER 10: Ivan Lee — Stage 6 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(10,'2026-05-01','08:45:00','on_time',15),
(10,'2026-05-04','08:40:00','on_time',15),(10,'2026-05-05','08:50:00','on_time',15),(10,'2026-05-06','08:35:00','on_time',15),(10,'2026-05-07','09:10:00','grace_period',12),(10,'2026-05-08','08:42:00','on_time',15),
(10,'2026-05-11','08:38:00','on_time',15),(10,'2026-05-12','08:48:00','on_time',15),(10,'2026-05-13','09:05:00','grace_period',12),(10,'2026-05-14','08:50:00','on_time',15),(10,'2026-05-15','08:35:00','on_time',15),
(10,'2026-05-18','08:42:00','on_time',15),(10,'2026-05-19','08:55:00','on_time',15),(10,'2026-05-20','08:48:00','on_time',15),(10,'2026-05-21','09:20:00','grace_period',12),(10,'2026-05-22','08:40:00','on_time',15),
(10,'2026-05-25','08:45:00','on_time',15),(10,'2026-05-26','08:50:00','on_time',15),(10,'2026-05-27','08:38:00','on_time',15),(10,'2026-05-28','09:00:00','on_time',15),(10,'2026-05-29','08:42:00','on_time',15),
(10,'2026-06-02','08:40:00','on_time',15),(10,'2026-06-03','08:50:00','on_time',15),(10,'2026-06-04','08:35:00','on_time',15),(10,'2026-06-05','09:05:00','grace_period',12),
(10,'2026-06-09','08:45:00','on_time',15),(10,'2026-06-10','08:38:00','on_time',15),(10,'2026-06-11','08:30:00','on_time',15),(10,'2026-06-12','08:42:00','on_time',15),
(10,'2026-06-15','08:50:00','on_time',15),(10,'2026-06-16','09:10:00','grace_period',12),(10,'2026-06-17','08:35:00','on_time',15),(10,'2026-06-18','08:48:00','on_time',15),(10,'2026-06-19','08:40:00','on_time',15);

-- --- USER 11: Jessica Wong — Stage 6 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(11,'2026-05-01','08:35:00','on_time',15),
(11,'2026-05-04','08:30:00','on_time',15),(11,'2026-05-05','08:45:00','on_time',15),(11,'2026-05-06','08:38:00','on_time',15),(11,'2026-05-07','08:50:00','on_time',15),(11,'2026-05-08','08:42:00','on_time',15),
(11,'2026-05-11','08:35:00','on_time',15),(11,'2026-05-12','08:48:00','on_time',15),(11,'2026-05-13','08:40:00','on_time',15),(11,'2026-05-14','08:55:00','on_time',15),(11,'2026-05-15','08:30:00','on_time',15),
(11,'2026-05-18','08:45:00','on_time',15),(11,'2026-05-19','08:38:00','on_time',15),(11,'2026-05-20','08:50:00','on_time',15),(11,'2026-05-21','09:00:00','on_time',15),(11,'2026-05-22','08:35:00','on_time',15),
(11,'2026-05-25','08:42:00','on_time',15),(11,'2026-05-26','08:48:00','on_time',15),(11,'2026-05-27','08:38:00','on_time',15),(11,'2026-05-28','08:50:00','on_time',15),(11,'2026-05-29','08:45:00','on_time',15);

-- Jessica on approved MC June 2-3
INSERT INTO `attendance` (`user_id`, `date`, `status`, `points_earned`) VALUES
(11,'2026-06-02','on_leave',0),(11,'2026-06-03','on_leave',0);

INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(11,'2026-06-04','08:45:00','on_time',15),(11,'2026-06-05','08:35:00','on_time',15),
(11,'2026-06-09','08:28:00','on_time',15),(11,'2026-06-10','08:32:00','on_time',15),(11,'2026-06-11','08:50:00','on_time',15),(11,'2026-06-12','08:40:00','on_time',15),
(11,'2026-06-15','08:38:00','on_time',15),(11,'2026-06-16','08:30:00','on_time',15),(11,'2026-06-17','08:42:00','on_time',15),(11,'2026-06-18','08:35:00','on_time',15),(11,'2026-06-19','08:28:00','on_time',15);

-- --- USER 12: Kevin Ng — Stage 5 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(12,'2026-05-01','08:50:00','on_time',15),
(12,'2026-05-04','08:45:00','on_time',15),(12,'2026-05-05','08:38:00','on_time',15),(12,'2026-05-06','09:30:00','grace_period',12),(12,'2026-05-07','08:42:00','on_time',15),(12,'2026-05-08','08:35:00','on_time',15),
(12,'2026-05-11','08:48:00','on_time',15),(12,'2026-05-12','08:30:00','on_time',15),(12,'2026-05-13','08:55:00','on_time',15),(12,'2026-05-14','09:15:00','grace_period',12),(12,'2026-05-15','08:40:00','on_time',15),
(12,'2026-05-18','08:50:00','on_time',15),(12,'2026-05-19','08:45:00','on_time',15),(12,'2026-05-20','08:38:00','on_time',15),(12,'2026-05-21','09:00:00','on_time',15),(12,'2026-05-22','08:42:00','on_time',15),
(12,'2026-05-25','08:48:00','on_time',15),(12,'2026-05-26','08:35:00','on_time',15),(12,'2026-05-27','09:10:00','grace_period',12),(12,'2026-05-28','08:50:00','on_time',15),(12,'2026-05-29','08:42:00','on_time',15),
(12,'2026-06-02','08:50:00','on_time',15),(12,'2026-06-03','08:45:00','on_time',15),(12,'2026-06-04','08:38:00','on_time',15),(12,'2026-06-05','09:30:00','grace_period',12),
(12,'2026-06-09','08:42:00','on_time',15),(12,'2026-06-10','08:35:00','on_time',15),(12,'2026-06-11','08:48:00','on_time',15),(12,'2026-06-12','08:30:00','on_time',15),
(12,'2026-06-15','08:55:00','on_time',15),(12,'2026-06-16','09:15:00','grace_period',12),(12,'2026-06-17','08:40:00','on_time',15),(12,'2026-06-18','08:32:00','on_time',15),(12,'2026-06-19','08:50:00','on_time',15);

-- --- USER 13: Lily Tan — Stage 5 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(13,'2026-05-01','08:40:00','on_time',15),
(13,'2026-05-04','08:35:00','on_time',15),(13,'2026-05-05','08:45:00','on_time',15),(13,'2026-05-06','08:30:00','on_time',15),(13,'2026-05-07','08:50:00','on_time',15),(13,'2026-05-08','08:38:00','on_time',15),
(13,'2026-05-11','08:42:00','on_time',15),(13,'2026-05-12','08:48:00','on_time',15),(13,'2026-05-13','08:35:00','on_time',15),(13,'2026-05-14','08:50:00','on_time',15),(13,'2026-05-15','08:40:00','on_time',15),
(13,'2026-05-18','08:45:00','on_time',15),(13,'2026-05-19','08:38:00','on_time',15),(13,'2026-05-20','08:50:00','on_time',15),(13,'2026-05-21','08:42:00','on_time',15),(13,'2026-05-22','08:35:00','on_time',15),
(13,'2026-05-25','08:55:00','on_time',15),(13,'2026-05-26','08:48:00','on_time',15),(13,'2026-05-27','08:40:00','on_time',15);

-- Lily on approved leave May 25-27 corrected: she already has May 25,26,27 above with check-in. Let me remove those.
-- Actually, looking at the leave requests planned: Lily has approved AL May 25-27. So she should be on_leave those days, not checked in.
-- I'll just make sure the leave request dates don't conflict. The attendance data for Lily on May 25-27 can stay as-is since the leave could have been applied differently. Actually, for data consistency, let me keep the leave request as planned but put it on different dates. I'll handle this in the leave_requests section.

-- Continuing Lily:
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(13,'2026-05-28','08:35:00','on_time',15),(13,'2026-05-29','08:45:00','on_time',15),
(13,'2026-06-02','08:35:00','on_time',15),(13,'2026-06-03','08:40:00','on_time',15),(13,'2026-06-04','08:30:00','on_time',15),(13,'2026-06-05','08:45:00','on_time',15),
(13,'2026-06-09','08:38:00','on_time',15),(13,'2026-06-10','08:50:00','on_time',15),(13,'2026-06-11','08:32:00','on_time',15),(13,'2026-06-12','08:28:00','on_time',15),
(13,'2026-06-15','08:42:00','on_time',15),(13,'2026-06-16','08:35:00','on_time',15),(13,'2026-06-17','08:48:00','on_time',15),(13,'2026-06-18','08:30:00','on_time',15),(13,'2026-06-19','08:45:00','on_time',15);

-- --- USER 14: Michael Lim — Stage 4 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(14,'2026-05-01','08:55:00','on_time',15),
(14,'2026-05-04','08:48:00','on_time',15),(14,'2026-05-05','09:00:00','on_time',15),(14,'2026-05-06','08:50:00','on_time',15),(14,'2026-05-07','08:42:00','on_time',15),(14,'2026-05-08','09:20:00','grace_period',12),
(14,'2026-05-11','08:38:00','on_time',15),(14,'2026-05-12','08:45:00','on_time',15),(14,'2026-05-13','08:50:00','on_time',15),(14,'2026-05-14','08:35:00','on_time',15),(14,'2026-05-15','10:00:00','grace_period',12),
(14,'2026-05-18','08:40:00','on_time',15),(14,'2026-05-19','08:55:00','on_time',15),(14,'2026-05-20','09:15:00','grace_period',12),(14,'2026-05-21','08:48:00','on_time',15),(14,'2026-05-22','08:50:00','on_time',15),
(14,'2026-05-25','08:42:00','on_time',15),(14,'2026-05-26','09:30:00','grace_period',12),(14,'2026-05-27','08:50:00','on_time',15),(14,'2026-05-28','08:38:00','on_time',15),(14,'2026-05-29','08:45:00','on_time',15),
(14,'2026-06-02','08:55:00','on_time',15),(14,'2026-06-03','08:48:00','on_time',15),(14,'2026-06-04','09:00:00','on_time',15),(14,'2026-06-05','08:50:00','on_time',15),
(14,'2026-06-09','08:42:00','on_time',15),(14,'2026-06-10','09:20:00','grace_period',12),(14,'2026-06-11','08:38:00','on_time',15),(14,'2026-06-12','08:45:00','on_time',15),
(14,'2026-06-15','08:50:00','on_time',15),(14,'2026-06-16','08:35:00','on_time',15),(14,'2026-06-17','10:00:00','grace_period',12),(14,'2026-06-18','08:40:00','on_time',15),(14,'2026-06-19','08:55:00','on_time',15);

-- --- USER 15: Nicole Lee — Stage 4 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(15,'2026-05-01','08:45:00','on_time',15),
(15,'2026-05-04','08:40:00','on_time',15),(15,'2026-05-05','08:50:00','on_time',15),(15,'2026-05-06','08:35:00','on_time',15),(15,'2026-05-07','08:42:00','on_time',15),(15,'2026-05-08','09:05:00','grace_period',12),
(15,'2026-05-11','08:38:00','on_time',15),(15,'2026-05-12','08:48:00','on_time',15),(15,'2026-05-13','08:55:00','on_time',15),(15,'2026-05-14','08:30:00','on_time',15),(15,'2026-05-15','08:45:00','on_time',15),
(15,'2026-05-18','08:50:00','on_time',15),(15,'2026-05-19','08:35:00','on_time',15),(15,'2026-05-20','08:48:00','on_time',15),(15,'2026-05-21','09:10:00','grace_period',12),(15,'2026-05-22','08:40:00','on_time',15),
(15,'2026-05-25','08:42:00','on_time',15),(15,'2026-05-26','08:55:00','on_time',15),(15,'2026-05-27','08:48:00','on_time',15),(15,'2026-05-28','08:38:00','on_time',15),(15,'2026-05-29','09:00:00','on_time',15);

-- Nicole on approved leave June 9-10
INSERT INTO `attendance` (`user_id`, `date`, `status`, `points_earned`) VALUES
(15,'2026-06-09','on_leave',0),(15,'2026-06-10','on_leave',0);

INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(15,'2026-06-02','08:40:00','on_time',15),(15,'2026-06-03','08:35:00','on_time',15),(15,'2026-06-04','08:50:00','on_time',15),(15,'2026-06-05','08:45:00','on_time',15),
(15,'2026-06-11','08:55:00','on_time',15),(15,'2026-06-12','08:30:00','on_time',15),
(15,'2026-06-15','08:48:00','on_time',15),(15,'2026-06-16','08:35:00','on_time',15),(15,'2026-06-17','08:50:00','on_time',15),(15,'2026-06-18','09:10:00','grace_period',12),(15,'2026-06-19','08:40:00','on_time',15);

-- --- USER 16: Oscar Wong — Stage 3 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(16,'2026-05-01','08:55:00','on_time',10),
(16,'2026-05-04','09:30:00','grace_period',7),(16,'2026-05-05','08:45:00','on_time',10),(16,'2026-05-06','08:38:00','on_time',10),(16,'2026-05-07','09:45:00','grace_period',7),(16,'2026-05-08','08:50:00','on_time',10),
(16,'2026-05-11','08:42:00','on_time',10),(16,'2026-05-12','09:15:00','grace_period',7),(16,'2026-05-13','08:48:00','on_time',10),(16,'2026-05-14','08:55:00','on_time',10),(16,'2026-05-15','08:35:00','on_time',10),
(16,'2026-05-18','10:20:00','late',5),(16,'2026-05-19','08:50:00','on_time',10),(16,'2026-05-20','09:30:00','grace_period',7),(16,'2026-05-21','08:45:00','on_time',10),(16,'2026-05-22','08:55:00','on_time',10),
(16,'2026-05-25','08:48:00','on_time',10),(16,'2026-05-26','09:10:00','grace_period',7),(16,'2026-05-27','08:40:00','on_time',10),(16,'2026-05-28','08:50:00','on_time',10),(16,'2026-05-29','09:25:00','grace_period',7),
(16,'2026-06-02','08:50:00','on_time',10),(16,'2026-06-03','09:30:00','grace_period',7),(16,'2026-06-04','08:45:00','on_time',10),(16,'2026-06-05','08:38:00','on_time',10),
(16,'2026-06-09','09:45:00','grace_period',7),(16,'2026-06-10','08:50:00','on_time',10),(16,'2026-06-11','08:42:00','on_time',10),(16,'2026-06-12','09:15:00','grace_period',7),
(16,'2026-06-15','08:48:00','on_time',10),(16,'2026-06-16','08:55:00','on_time',10),(16,'2026-06-17','08:35:00','on_time',10),(16,'2026-06-18','10:20:00','late',5),(16,'2026-06-19','08:50:00','on_time',10);

-- --- USER 17: Paul Ng — Stage 3 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(17,'2026-05-01','08:50:00','on_time',10),
(17,'2026-05-04','08:45:00','on_time',10),(17,'2026-05-05','09:00:00','on_time',10),(17,'2026-05-06','08:42:00','on_time',10),(17,'2026-05-07','08:38:00','on_time',10),(17,'2026-05-08','08:55:00','on_time',10),
(17,'2026-05-11','08:48:00','on_time',10),(17,'2026-05-12','08:35:00','on_time',10),(17,'2026-05-13','09:05:00','grace_period',7),(17,'2026-05-14','08:50:00','on_time',10),(17,'2026-05-15','08:40:00','on_time',10),
(17,'2026-05-18','08:45:00','on_time',10),(17,'2026-05-19','09:20:00','grace_period',7),(17,'2026-05-20','08:50:00','on_time',10),(17,'2026-05-21','08:42:00','on_time',10),(17,'2026-05-22','08:55:00','on_time',10),
(17,'2026-05-25','08:38:00','on_time',10),(17,'2026-05-26','08:48:00','on_time',10),(17,'2026-05-27','08:50:00','on_time',10),(17,'2026-05-28','09:00:00','on_time',10),(17,'2026-05-29','08:45:00','on_time',10),
(17,'2026-06-02','08:45:00','on_time',10),(17,'2026-06-03','08:50:00','on_time',10),(17,'2026-06-04','09:00:00','on_time',10),(17,'2026-06-05','08:42:00','on_time',10),
(17,'2026-06-09','08:38:00','on_time',10),(17,'2026-06-10','08:55:00','on_time',10),(17,'2026-06-11','08:48:00','on_time',10),(17,'2026-06-12','08:35:00','on_time',10),
(17,'2026-06-15','09:05:00','grace_period',7),(17,'2026-06-16','08:50:00','on_time',10),(17,'2026-06-17','08:40:00','on_time',10),(17,'2026-06-18','08:45:00','on_time',10),(17,'2026-06-19','09:20:00','grace_period',7);

-- --- USER 18: Queen Tan — Withered Stage 2 (downgraded from 3), rebuilding ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(18,'2026-05-01','09:30:00','grace_period',7),
(18,'2026-05-04','10:15:00','late',5),(18,'2026-05-05','08:50:00','on_time',10),(18,'2026-05-06','08:45:00','on_time',10),(18,'2026-05-07','10:30:00','late',5),(18,'2026-05-08','09:10:00','grace_period',7),
(18,'2026-05-11','08:40:00','on_time',10),(18,'2026-05-12','08:50:00','on_time',10),(18,'2026-05-13','09:20:00','grace_period',7),(18,'2026-05-14','10:05:00','late',5),(18,'2026-05-15','08:55:00','on_time',10),
(18,'2026-05-18','09:30:00','grace_period',7),(18,'2026-05-19','08:48:00','on_time',10),(18,'2026-05-20','08:38:00','on_time',10),(18,'2026-05-21','10:20:00','late',5),(18,'2026-05-22','09:15:00','grace_period',7),
(18,'2026-05-25','08:50:00','on_time',10),(18,'2026-05-26','08:42:00','on_time',10),(18,'2026-05-27','09:05:00','grace_period',7),(18,'2026-05-28','08:48:00','on_time',10),(18,'2026-05-29','08:55:00','on_time',10),
(18,'2026-06-02','09:30:00','grace_period',7),(18,'2026-06-03','10:15:00','late',5),(18,'2026-06-04','08:50:00','on_time',10),(18,'2026-06-05','08:45:00','on_time',10),
(18,'2026-06-09','08:55:00','on_time',10),(18,'2026-06-10','09:10:00','grace_period',7),(18,'2026-06-11','08:40:00','on_time',10),(18,'2026-06-12','08:50:00','on_time',10),
(18,'2026-06-15','10:30:00','late',5),(18,'2026-06-16','09:20:00','grace_period',7),(18,'2026-06-17','08:48:00','on_time',10),(18,'2026-06-18','08:38:00','on_time',10),(18,'2026-06-19','08:55:00','on_time',10);

-- --- USER 19: Ryan Lim — Stage 2 ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(19,'2026-05-01','08:55:00','on_time',10),
(19,'2026-05-04','08:50:00','on_time',10),(19,'2026-05-05','09:10:00','grace_period',7),(19,'2026-05-06','08:45:00','on_time',10),(19,'2026-05-07','08:38:00','on_time',10),(19,'2026-05-08','09:30:00','grace_period',7),
(19,'2026-05-11','08:55:00','on_time',10),(19,'2026-05-12','08:42:00','on_time',10),(19,'2026-05-13','09:20:00','grace_period',7),(19,'2026-05-14','08:50:00','on_time',10),(19,'2026-05-15','08:48:00','on_time',10),
(19,'2026-05-18','08:40:00','on_time',10),(19,'2026-05-19','09:15:00','grace_period',7),(19,'2026-05-20','08:50:00','on_time',10),(19,'2026-05-21','08:38:00','on_time',10),(19,'2026-05-22','08:55:00','on_time',10),
(19,'2026-05-25','08:42:00','on_time',10),(19,'2026-05-26','09:00:00','on_time',10),(19,'2026-05-27','08:50:00','on_time',10),(19,'2026-05-28','09:20:00','grace_period',7),(19,'2026-05-29','08:45:00','on_time',10),
(19,'2026-06-02','08:50:00','on_time',10),(19,'2026-06-03','08:45:00','on_time',10),(19,'2026-06-04','09:10:00','grace_period',7),(19,'2026-06-05','08:38:00','on_time',10),
(19,'2026-06-09','08:55:00','on_time',10),(19,'2026-06-10','09:30:00','grace_period',7),(19,'2026-06-11','08:42:00','on_time',10),(19,'2026-06-12','08:50:00','on_time',10),
(19,'2026-06-15','08:48:00','on_time',10),(19,'2026-06-16','09:15:00','grace_period',7),(19,'2026-06-17','08:40:00','on_time',10),(19,'2026-06-18','08:35:00','on_time',10),(19,'2026-06-19','08:52:00','on_time',10);

-- --- USER 20: Sophia Lee — Stage 1 (newest), building streak ---
INSERT INTO `attendance` (`user_id`, `date`, `check_in_time`, `status`, `points_earned`) VALUES
(20,'2026-05-01','08:55:00','on_time',10),
(20,'2026-05-04','09:20:00','grace_period',7),(20,'2026-05-05','08:50:00','on_time',10),(20,'2026-05-06','09:00:00','on_time',10),(20,'2026-05-07','10:15:00','late',5),(20,'2026-05-08','08:45:00','on_time',10),
(20,'2026-05-11','09:10:00','grace_period',7),(20,'2026-05-12','08:55:00','on_time',10),(20,'2026-05-13','10:00:00','grace_period',7),(20,'2026-05-14','08:50:00','on_time',10),(20,'2026-05-15','08:40:00','on_time',10),
(20,'2026-05-18','09:30:00','grace_period',7),(20,'2026-05-19','08:48:00','on_time',10),(20,'2026-05-20','08:38:00','on_time',10),(20,'2026-05-21','09:15:00','grace_period',7),(20,'2026-05-22','08:55:00','on_time',10),
(20,'2026-05-25','08:50:00','on_time',10),(20,'2026-05-26','09:05:00','grace_period',7),(20,'2026-05-27','08:45:00','on_time',10),(20,'2026-05-28','08:55:00','on_time',10),(20,'2026-05-29','09:20:00','grace_period',7),
(20,'2026-06-02','08:50:00','on_time',10),(20,'2026-06-03','09:20:00','grace_period',7),(20,'2026-06-04','08:45:00','on_time',10),(20,'2026-06-05','09:00:00','on_time',10),
(20,'2026-06-09','08:55:00','on_time',10),(20,'2026-06-10','09:10:00','grace_period',7),(20,'2026-06-11','10:00:00','grace_period',7),(20,'2026-06-12','08:50:00','on_time',10),
(20,'2026-06-15','08:40:00','on_time',10),(20,'2026-06-16','09:30:00','grace_period',7),(20,'2026-06-17','08:48:00','on_time',10),(20,'2026-06-18','08:38:00','on_time',10),(20,'2026-06-19','08:55:00','on_time',10);


-- ========================================================================
-- LEAVE BALANCES — all 19 employees, 2026
-- ========================================================================
INSERT INTO `leave_balances` (`user_id`, `year`, `al_total`, `al_used`, `mc_total`, `mc_used`) VALUES
(2, 2026, 14, 2.0, 14, 1.0),
(3, 2026, 14, 1.0, 14, 0.0),
(4, 2026, 14, 5.0, 14, 0.0),
(5, 2026, 14, 0.0, 14, 2.0),
(6, 2026, 14, 3.0, 14, 1.0),
(7, 2026, 14, 5.0, 14, 0.0),
(8, 2026, 14, 6.0, 14, 2.0),
(9, 2026, 14, 3.0, 14, 1.0),
(10, 2026, 14, 3.0, 14, 0.0),
(11, 2026, 14, 1.0, 14, 3.0),
(12, 2026, 14, 4.0, 14, 0.0),
(13, 2026, 14, 2.0, 14, 1.0),
(14, 2026, 14, 0.0, 14, 0.0),
(15, 2026, 14, 3.0, 14, 2.0),
(16, 2026, 14, 1.0, 14, 0.0),
(17, 2026, 14, 0.0, 14, 1.0),
(18, 2026, 14, 2.0, 14, 0.0),
(19, 2026, 14, 0.0, 14, 0.0),
(20, 2026, 14, 0.0, 14, 0.0);


-- ========================================================================
-- LEAVE REQUESTS — 25 records (pending, approved, rejected)
-- ========================================================================
INSERT INTO `leave_requests` (`id`, `user_id`, `leave_type`, `start_date`, `end_date`, `reason`, `custom_reason`, `status`, `admin_remark`, `applied_at`) VALUES
-- PENDING (9)
(1, 2, 'AL', '2026-06-22', '2026-06-24', 'Vacation', 'Family trip to Penang', 'pending', NULL, '2026-06-15 09:30:00'),
(2, 3, 'AL', '2026-06-25', '2026-06-26', 'Family Event', NULL, 'pending', NULL, '2026-06-16 10:15:00'),
(3, 5, 'MC', '2026-06-22', '2026-06-22', 'Medical Appointment', 'Dental surgery follow-up', 'pending', NULL, '2026-06-17 08:45:00'),
(4, 8, 'AL', '2026-07-01', '2026-07-03', 'Vacation', NULL, 'pending', NULL, '2026-06-18 14:00:00'),
(5, 11, 'UL', '2026-06-29', '2026-06-30', 'Other', 'Personal errand', 'pending', NULL, '2026-06-17 11:20:00'),
(6, 14, 'AL', '2026-07-06', '2026-07-10', 'Vacation', 'Annual family holiday', 'pending', NULL, '2026-06-14 09:00:00'),
(7, 16, 'AL', '2026-06-23', '2026-06-23', 'Family Event', 'Sister graduation', 'pending', NULL, '2026-06-18 16:30:00'),
(8, 19, 'MC', '2026-06-24', '2026-06-24', 'Medical Appointment', 'Annual checkup', 'pending', NULL, '2026-06-19 08:00:00'),
(9, 7, 'AL', '2026-06-29', '2026-07-01', 'Vacation', 'Short getaway', 'pending', NULL, '2026-06-19 12:00:00'),

-- APPROVED (13)
(10, 4, 'AL', '2026-06-15', '2026-06-16', 'Vacation', NULL, 'approved', NULL, '2026-06-08 10:00:00'),
(11, 7, 'AL', '2026-06-11', '2026-06-12', 'Family Event', 'Cousin wedding', 'approved', NULL, '2026-06-01 09:00:00'),
(12, 9, 'MC', '2026-06-05', '2026-06-05', 'Medical Appointment', NULL, 'approved', NULL, '2026-06-03 14:30:00'),
(13, 11, 'MC', '2026-06-02', '2026-06-03', 'Medical Appointment', 'Physiotherapy', 'approved', NULL, '2026-05-28 11:00:00'),
(14, 13, 'AL', '2026-04-28', '2026-04-29', 'Vacation', 'Short getaway', 'approved', NULL, '2026-04-20 09:00:00'),
(15, 15, 'AL', '2026-06-09', '2026-06-10', 'Vacation', NULL, 'approved', NULL, '2026-05-30 10:00:00'),
(16, 18, 'MC', '2026-04-22', '2026-04-23', 'Medical Appointment', NULL, 'approved', NULL, '2026-04-18 08:30:00'),
(17, 2, 'AL', '2026-04-08', '2026-04-09', 'Family Event', 'Parents anniversary', 'approved', NULL, '2026-04-01 09:00:00'),
(18, 10, 'AL', '2026-04-15', '2026-04-15', 'Family Event', NULL, 'approved', NULL, '2026-04-10 10:00:00'),
(19, 6, 'MC', '2026-04-20', '2026-04-20', 'Medical Appointment', 'Eye checkup', 'approved', NULL, '2026-04-15 11:00:00'),
(20, 12, 'AL', '2026-04-13', '2026-04-15', 'Vacation', NULL, 'approved', NULL, '2026-04-05 09:30:00'),
(21, 8, 'AL', '2026-04-15', '2026-04-17', 'Vacation', 'Cherry blossom trip', 'approved', NULL, '2026-04-01 08:00:00'),
(22, 4, 'AL', '2026-04-08', '2026-04-08', 'Family Event', NULL, 'approved', NULL, '2026-03-25 10:00:00'),

-- REJECTED (3)
(23, 6, 'AL', '2026-06-10', '2026-06-12', 'Vacation', NULL, 'rejected', 'Team deadline — reschedule to following week', '2026-06-02 09:00:00'),
(24, 17, 'AL', '2026-05-15', '2026-05-15', 'Family Event', NULL, 'rejected', 'Sprint planning day — all hands required', '2026-05-08 10:00:00'),
(25, 19, 'UL', '2026-05-08', '2026-05-08', 'Other', 'Personal reasons', 'rejected', 'Unpaid leave requires 2-week notice per policy', '2026-05-01 14:00:00');


-- ========================================================================
-- REWARD ITEMS — 30 items
-- ========================================================================
INSERT INTO `reward_items` (`id`, `name`, `points_required`, `stock_quantity`, `is_active`) VALUES
(1, 'iPhone 15 Pro', 45000, 1, 1),
(2, 'AirPods Pro (2nd Gen)', 8500, 3, 1),
(3, 'Mechanical Keyboard (Keychron K8)', 3200, 5, 1),
(4, 'Starbucks RM50 Voucher', 500, 20, 1),
(5, 'Desk Plant Set', 800, 8, 1),
(6, 'Monitor Stand (Adjustable)', 2500, 4, 1),
(7, 'Company Hoodie', 1500, 10, 1),
(8, 'Steam Gift Card RM100', 1000, 12, 1),
(9, 'Noise-Cancelling Headphones', 6200, 2, 1),
(10, 'Standing Desk Converter', 7800, 2, 0),
(11, 'Wireless Mouse (Logitech MX)', 1800, 6, 1),
(12, 'External SSD 1TB', 3200, 4, 1),
(13, 'Coffee Machine (Nespresso)', 12000, 1, 1),
(14, 'Laptop Stand (Aluminum)', 1200, 15, 1),
(15, 'Amazon Gift Card RM200', 2000, 8, 1),
(16, 'Movie Ticket (2 pax)', 600, 25, 1),
(17, 'Fitness Tracker', 4200, 3, 1),
(18, 'Bluetooth Speaker (JBL)', 2800, 5, 1),
(19, 'Power Bank 20000mAh', 1500, 10, 1),
(20, 'Ergonomic Office Chair', 15000, 2, 1),
(21, 'Lunch with CEO', 3000, 3, 1),
(22, 'Extra Day Off', 5000, 5, 1),
(23, 'Coding Bootcamp Voucher', 8000, 2, 1),
(24, 'Premium Parking Spot (1 Month)', 2500, 3, 1),
(25, 'Noise-Cancelling Earbuds', 3500, 4, 1),
(26, 'Desk Lamp (BenQ ScreenBar)', 2200, 6, 1),
(27, 'Gaming Chair Cushion Set', 900, 12, 1),
(28, 'RM100 Touch n Go eWallet', 1000, 15, 1),
(29, 'Portable Monitor 15.6"', 5500, 2, 1),
(30, 'Team Building Pass', 1800, 8, 0);


-- ========================================================================
-- REWARD REDEMPTIONS — 20 records
-- ========================================================================
INSERT INTO `reward_redemptions` (`id`, `user_id`, `item_id`, `points_spent`, `status`, `created_at`, `updated_at`) VALUES
-- PENDING (6)
(1, 8, 1, 45000, 'pending', '2026-06-10 10:00:00', '2026-06-10 10:00:00'),
(2, 9, 2, 8500, 'pending', '2026-06-15 14:00:00', '2026-06-15 14:00:00'),
(3, 4, 4, 500, 'pending', '2026-06-18 09:30:00', '2026-06-18 09:30:00'),
(4, 11, 8, 1000, 'pending', '2026-06-19 11:00:00', '2026-06-19 11:00:00'),
(5, 2, 6, 2500, 'pending', '2026-06-17 16:00:00', '2026-06-17 16:00:00'),
(6, 10, 18, 2800, 'pending', '2026-06-16 13:00:00', '2026-06-16 13:00:00'),

-- COMPLETED (8)
(7, 8, 3, 3200, 'completed', '2026-05-20 10:00:00', '2026-05-25 09:00:00'),
(8, 9, 4, 500, 'completed', '2026-05-15 14:00:00', '2026-05-18 10:00:00'),
(9, 10, 8, 1000, 'completed', '2026-05-10 11:00:00', '2026-05-12 09:00:00'),
(10, 4, 7, 1500, 'completed', '2026-04-20 09:00:00', '2026-04-25 10:00:00'),
(11, 12, 3, 3200, 'completed', '2026-04-15 10:00:00', '2026-04-18 09:00:00'),
(12, 13, 9, 6200, 'completed', '2026-03-28 14:00:00', '2026-04-02 10:00:00'),
(13, 8, 14, 1200, 'completed', '2026-03-10 10:00:00', '2026-03-12 09:00:00'),
(14, 9, 17, 4200, 'completed', '2026-02-20 14:00:00', '2026-02-25 10:00:00'),

-- CANCELLED (4)
(15, 5, 5, 800, 'cancelled', '2026-06-05 09:00:00', '2026-06-06 10:00:00'),
(16, 15, 4, 500, 'cancelled', '2026-06-08 14:00:00', '2026-06-09 08:00:00'),
(17, 14, 16, 600, 'cancelled', '2026-05-22 11:00:00', '2026-05-23 10:00:00'),
(18, 19, 28, 1000, 'cancelled', '2026-05-12 15:00:00', '2026-05-13 09:00:00'),

-- REJECTED (2)
(19, 6, 6, 2500, 'rejected', '2026-05-25 11:00:00', '2026-05-28 09:00:00'),
(20, 18, 11, 1800, 'rejected', '2026-05-30 10:00:00', '2026-06-02 09:00:00');


-- ========================================================================
-- POINT TRANSACTIONS — 60+ records
-- ========================================================================
INSERT INTO `point_transactions` (`user_id`, `amount`, `description`, `created_at`) VALUES
-- Milestone bonuses
(8, 100, '30-Day Iron Man Bonus (+100)', '2026-02-28 08:20:00'),
(9, 100, '30-Day Iron Man Bonus (+100)', '2026-03-05 08:25:00'),
(10, 100, '30-Day Iron Man Bonus (+100)', '2026-04-10 08:30:00'),
(11, 100, '30-Day Iron Man Bonus (+100)', '2026-04-15 08:35:00'),
(4, 100, '30-Day Iron Man Bonus (+100)', '2026-03-20 08:40:00'),
(12, 100, '30-Day Iron Man Bonus (+100)', '2026-05-01 08:15:00'),
(13, 100, '30-Day Iron Man Bonus (+100)', '2026-05-05 08:20:00'),
(2, 100, '30-Day Iron Man Bonus (+100)', '2026-04-20 08:30:00'),

-- Completed redemptions (spends)
(8, -3200, 'Redeemed: Mechanical Keyboard (Keychron K8)', '2026-05-20 10:00:00'),
(9, -500, 'Redeemed: Starbucks RM50 Voucher', '2026-05-15 14:00:00'),
(10, -1000, 'Redeemed: Steam Gift Card RM100', '2026-05-10 11:00:00'),
(4, -1500, 'Redeemed: Company Hoodie', '2026-04-20 09:00:00'),
(12, -3200, 'Redeemed: Mechanical Keyboard (Keychron K8)', '2026-04-15 10:00:00'),
(13, -6200, 'Redeemed: Noise-Cancelling Headphones', '2026-03-28 14:00:00'),
(8, -1200, 'Redeemed: Laptop Stand (Aluminum)', '2026-03-10 10:00:00'),
(9, -4200, 'Redeemed: Fitness Tracker', '2026-02-20 14:00:00'),

-- Pending redemptions (spends)
(8, -45000, 'Redeemed: iPhone 15 Pro', '2026-06-10 10:00:00'),
(9, -8500, 'Redeemed: AirPods Pro (2nd Gen)', '2026-06-15 14:00:00'),
(4, -500, 'Redeemed: Starbucks RM50 Voucher', '2026-06-18 09:30:00'),
(11, -1000, 'Redeemed: Steam Gift Card RM100', '2026-06-19 11:00:00'),
(2, -2500, 'Redeemed: Monitor Stand (Adjustable)', '2026-06-17 16:00:00'),
(10, -2800, 'Redeemed: Bluetooth Speaker (JBL)', '2026-06-16 13:00:00'),

-- Initial spends (for cancelled/rejected, later refunded)
(5, -800, 'Redeemed: Desk Plant Set', '2026-06-05 09:00:00'),
(15, -500, 'Redeemed: Starbucks RM50 Voucher', '2026-06-08 14:00:00'),
(14, -600, 'Redeemed: Movie Ticket (2 pax)', '2026-05-22 11:00:00'),
(19, -1000, 'Redeemed: RM100 Touch n Go eWallet', '2026-05-12 15:00:00'),
(6, -2500, 'Redeemed: Monitor Stand (Adjustable)', '2026-05-25 11:00:00'),
(18, -1800, 'Redeemed: Wireless Mouse (Logitech MX)', '2026-05-30 10:00:00'),

-- Refunds
(5, 800, 'Refund: Cancelled item redemption', '2026-06-06 10:00:00'),
(15, 500, 'Refund: Cancelled item redemption', '2026-06-09 08:00:00'),
(14, 600, 'Refund: Cancelled item redemption', '2026-05-23 10:00:00'),
(19, 1000, 'Refund: Cancelled item redemption', '2026-05-13 09:00:00'),
(6, 2500, 'Refund: Boss rejected store order', '2026-05-28 09:00:00'),
(18, 1800, 'Refund: Boss rejected store order', '2026-06-02 09:00:00'),

-- Sample daily check-in descriptions
(8, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-02 08:15:00'),
(8, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-05 08:22:00'),
(8, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-10 08:20:00'),
(8, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-15 08:28:00'),
(8, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-19 08:20:00'),
(9, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-02 08:30:00'),
(9, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-10 08:20:00'),
(9, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-15 08:35:00'),
(10, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-04 08:35:00'),
(10, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-11 08:30:00'),
(11, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-05 08:35:00'),
(11, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-12 08:40:00'),
(12, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-03 08:45:00'),
(12, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-11 08:48:00'),
(13, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-02 08:35:00'),
(13, 15, 'Check-in (on_time) + 5-Day Streak Bonus', '2026-06-10 08:50:00'),
(6, -2, 'Check-in penalty: Absent', '2026-06-04 00:00:00'),
(6, -2, 'Check-in penalty: Absent', '2026-06-12 00:00:00'),
(6, -2, 'Check-in penalty: Absent', '2026-05-06 00:00:00'),
(6, -2, 'Check-in penalty: Absent', '2026-05-12 00:00:00'),
(6, -2, 'Check-in penalty: Absent', '2026-05-20 00:00:00'),
(6, -2, 'Check-in penalty: Absent', '2026-05-29 00:00:00');
