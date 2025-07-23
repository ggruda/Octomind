-- MySQL initialization script for Octomind Bot
-- This script is executed when the MySQL container starts for the first time

-- Create additional database user with limited permissions
CREATE USER IF NOT EXISTS 'octomind_readonly'@'%' IDENTIFIED BY 'readonly_password';

-- Grant read-only access to octomind_readonly
GRANT SELECT ON octomind.* TO 'octomind_readonly'@'%';

-- Set timezone
SET time_zone = '+00:00';

-- Enable JSON functions (MySQL 5.7+)
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- Log successful initialization
SELECT 'Octomind MySQL database initialized successfully' AS status, NOW() AS timestamp; 