-- Badge persistence table
-- Run once to enable persistent badge tracking
USE `attendance_db`;

CREATE TABLE IF NOT EXISTS `badges` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `badge_key` VARCHAR(50) NOT NULL,
    `badge_name` VARCHAR(100) NOT NULL,
    `badge_icon` VARCHAR(10) NOT NULL,
    `earned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_badge` (`user_id`, `badge_key`)
) ENGINE=InnoDB;

-- Seed badges for users with streak >= 30 (Iron Man) or streak >= 5 (Streak Starter)
INSERT IGNORE INTO `badges` (`user_id`, `badge_key`, `badge_name`, `badge_icon`)
SELECT id, 'iron_man_30', '30-Day Iron Man', '🛡️'
FROM users
WHERE role = 'employee' AND current_streak >= 30;

INSERT IGNORE INTO `badges` (`user_id`, `badge_key`, `badge_name`, `badge_icon`)
SELECT id, 'streak_5', '5-Day Streak Starter', '🔥'
FROM users
WHERE role = 'employee' AND current_streak >= 5;

INSERT IGNORE INTO `badges` (`user_id`, `badge_key`, `badge_name`, `badge_icon`)
SELECT id, 'tree_stage_4', 'Bloom Seeker', '🌸'
FROM users
WHERE role = 'employee' AND plant_current_stage >= 4;

INSERT IGNORE INTO `badges` (`user_id`, `badge_key`, `badge_name`, `badge_icon`)
SELECT id, 'tree_stage_7', 'World Tree Legend', '🌍'
FROM users
WHERE role = 'employee' AND plant_current_stage >= 7;
