-- ========================================================================
-- Attendance System
-- ========================================================================

-- 1. Create and Select Database
DROP DATABASE IF EXISTS `attendance_db`;
CREATE DATABASE `attendance_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `attendance_db`;

-- ========================================================================
-- TABLE 1: users (Handles Auth, Roles, and The 'World Tree' Gamification)
-- ========================================================================
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Login identifier',
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('employee', 'admin') DEFAULT 'employee',
    `join_date` DATE NOT NULL,
    `remember_token` VARCHAR(255) NULL COMMENT 'For seamless QR code login',
    `reset_token` VARCHAR(64) NULL COMMENT 'Password reset token',
    `reset_token_expiry` DATETIME NULL COMMENT 'Reset token expiry (1 hour)',
    
    -- Gamification & Plant Evolution Variables
    `total_points` INT DEFAULT 0 COMMENT 'Currency for the Rewards Store',
    `current_streak` INT DEFAULT 0 COMMENT 'Current consecutive on-time days',
    `plant_highest_stage` INT DEFAULT 1 COMMENT '1:Sprout -> 7:World Tree',
    `plant_current_stage` INT DEFAULT 1 COMMENT 'Can drop if absent, but recovers to highest',
    `plant_status` ENUM('Healthy', 'Withered') DEFAULT 'Healthy' COMMENT 'Changes to Withered on first late/absent',
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 2: system_settings (For Boss-configurable variables)
-- ========================================================================
CREATE TABLE `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE,
    `setting_value` VARCHAR(255) NOT NULL,
    `description` VARCHAR(255) NULL
) ENGINE=InnoDB;

-- Insert default settings (GPS coords, IP, and Leave Window)
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('office_lat', '3.141592', 'Company Office Latitude'),
('office_lng', '101.686530', 'Company Office Longitude'),
('office_ip', '192.168.1.100', 'Company Public Wi-Fi IP'),
('leave_rolling_months', '3', 'Max months ahead employee can apply for leave'),
('enable_ip_validation', '0', 'Enable/disable office IP validation for check-in (0=off, 1=on)'),
('office_radius', '200', 'Office GPS radius in meters for attendance validation'),
('enable_gps_validation', '0', 'Enable/disable GPS validation for check-in (0=off, 1=on)');

-- ========================================================================
-- TABLE 3: attendance (Tracks daily clock-in/out, GPS, IP, and Points)
-- ========================================================================
CREATE TABLE `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `check_in_time` TIME NULL,
    `check_out_time` TIME NULL COMMENT 'NULL means forgot to checkout',
    
    `status` ENUM('on_time', 'grace_period', 'late', 'absent', 'on_leave', 'public_holiday') NOT NULL,
    `points_earned` INT DEFAULT 0 COMMENT '+10, +7, +5, or negative penalties',
    
    -- Anti-Fraud Data
    `location_lat` DECIMAL(10, 8) NULL,
    `location_lng` DECIMAL(10, 8) NULL,
    `ip_address` VARCHAR(45) NULL,
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_daily_attendance` (`user_id`, `date`) -- Prevents multiple check-ins per day
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 4: leave_balances (Tracks remaining AL/MC per year)
-- ========================================================================
CREATE TABLE `leave_balances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `year` YEAR NOT NULL,
    `al_total` INT DEFAULT 14 COMMENT 'Annual Leave Quota',
    `al_used` DECIMAL(5,1) DEFAULT 0.0 COMMENT 'Decimal allows half-day leaves',
    `mc_total` INT DEFAULT 14 COMMENT 'Medical Leave Quota',
    `mc_used` DECIMAL(5,1) DEFAULT 0.0,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_year` (`user_id`, `year`)
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 5: leave_requests (Handles leave applications & Admin approval)
-- ========================================================================
CREATE TABLE `leave_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `leave_type` ENUM('AL', 'MC', 'UL') NOT NULL COMMENT 'Annual, Medical, Unpaid',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    
    `reason` VARCHAR(255) NOT NULL COMMENT 'Predefined reason dropdown value',
    `custom_reason` TEXT NULL COMMENT 'If employee selects Others',
    
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `admin_remark` TEXT NULL COMMENT 'Reason if rejected by Boss',
    
    `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 6: public_holidays (Prevents streak break and corrects Calendar)
-- ========================================================================
CREATE TABLE `public_holidays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `holiday_date` DATE NOT NULL UNIQUE,
    `holiday_name` VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 7: reward_items (The physical items Boss adds to the store)
-- ========================================================================
CREATE TABLE `reward_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL COMMENT 'e.g., iPhone 15 Pro',
    `image_url` VARCHAR(255) NULL,
    `points_required` INT NOT NULL,
    `stock_quantity` INT NOT NULL DEFAULT 1 COMMENT 'Must reduce by 1 immediately on Pending',
    `is_active` BOOLEAN DEFAULT TRUE COMMENT 'Boss can hide items',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 8: reward_redemptions (The Escrow & Refund Logic Table)
-- ========================================================================
CREATE TABLE `reward_redemptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    
    `points_spent` INT NOT NULL COMMENT 'Locked price at the time of redemption',
    `status` ENUM('pending', 'completed', 'cancelled', 'rejected') DEFAULT 'pending',
    -- pending: Waiting for Annual Dinner
    -- completed: Handed over by Boss
    -- cancelled: Employee cancelled, refund points & stock
    -- rejected: Boss denied, refund points & stock
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`item_id`) REFERENCES `reward_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ========================================================================
-- TABLE 9: point_transactions (Ledger/History for Points Tracking)
-- ========================================================================
-- This table is CRUCIAL so employees know exactly WHY their points changed.
CREATE TABLE `point_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `amount` INT NOT NULL COMMENT 'Positive (Earned) or Negative (Spent/Penalty)',
    `description` VARCHAR(255) NOT NULL COMMENT 'e.g., Daily Check-in (10) + Streak Bonus (5)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;


-- insert users
INSERT INTO `users` (`name`, `username`, `email`, `password_hash`, `role`, `join_date`) VALUES
-- Admin (Boss)
('John Tan', 'john.tan', 'john.tan@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'admin', '2024-01-01'),

-- Employees
('Alice Lim', 'alice.lim', 'alice.lim@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-01'),
('Brian Wong', 'brian.wong', 'brian.wong@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-05'),
('Catherine Lee', 'catherine.lee', 'catherine.lee@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-10'),
('Daniel Ng', 'daniel.ng', 'daniel.ng@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-15'),
('Ethan Ong', 'ethan.ong', 'ethan.ong@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-02-20'),
('Fiona Chua', 'fiona.chua', 'fiona.chua@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-01'),
('George Tan', 'george.tan', 'george.tan@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-05'),
('Hannah Lim', 'hannah.lim', 'hannah.lim@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-10'),
('Ivan Lee', 'ivan.lee', 'ivan.lee@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-15'),
('Jessica Wong', 'jessica.wong', 'jessica.wong@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-03-20'),
('Kevin Ng', 'kevin.ng', 'kevin.ng@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-01'),
('Lily Tan', 'lily.tan', 'lily.tan@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-05'),
('Michael Lim', 'michael.lim', 'michael.lim@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-10'),
('Nicole Lee', 'nicole.lee', 'nicole.lee@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-15'),
('Oscar Wong', 'oscar.wong', 'oscar.wong@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-04-20'),
('Paul Ng', 'paul.ng', 'paul.ng@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-05-01'),
('Queen Tan', 'queen.tan', 'queen.tan@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-05-05'),
('Ryan Lim', 'ryan.lim', 'ryan.lim@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-05-10'),
('Sophia Lee', 'sophia.lee', 'sophia.lee@company.com', '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG', 'employee', '2024-05-15');