-- MySQL initialization script for Octomind Bot
-- This script is executed when the MySQL container starts for the first time

-- Create octomind user with full permissions
CREATE USER IF NOT EXISTS 'octomind'@'%' IDENTIFIED BY 'octomind_password';
GRANT ALL PRIVILEGES ON octomind.* TO 'octomind'@'%';

-- Create additional database user with limited permissions
CREATE USER IF NOT EXISTS 'octomind_readonly'@'%' IDENTIFIED BY 'readonly_password';
GRANT SELECT ON octomind.* TO 'octomind_readonly'@'%';

-- Flush privileges to ensure they take effect
FLUSH PRIVILEGES;

-- Set timezone
SET time_zone = '+00:00';

-- Enable JSON functions (MySQL 5.7+)
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- Log successful initialization
SELECT 'Octomind MySQL database initialized successfully' AS status, NOW() AS timestamp; 