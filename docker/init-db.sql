-- PostgreSQL initialization script for Octomind Bot
-- This script is executed when the database container starts for the first time

-- Create additional database user with limited permissions
CREATE USER octomind_readonly WITH PASSWORD 'readonly_password';

-- Grant read-only access to octomind_readonly
GRANT CONNECT ON DATABASE octomind TO octomind_readonly;
GRANT USAGE ON SCHEMA public TO octomind_readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO octomind_readonly;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO octomind_readonly;

-- Create extensions if needed
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_stat_statements";

-- Set timezone
SET timezone = 'UTC';

-- Create indexes for better performance (will be applied after migrations)
-- These will be created by Laravel migrations, but we can prepare the database

-- Log successful initialization
DO $$
BEGIN
    RAISE NOTICE 'Octomind database initialized successfully at %', NOW();
END $$; 