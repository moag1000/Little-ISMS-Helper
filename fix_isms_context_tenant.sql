-- Fix ISMSContext tenant_id column
-- Run this directly on the database if the migration didn't work
-- This script is safe to run multiple times (checks before adding)

-- First, check if the column already exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'isms_context'
      AND column_name = 'tenant_id'
);

-- Add column only if it doesn't exist
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE isms_context ADD tenant_id INT DEFAULT NULL',
    'SELECT "Column tenant_id already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint (only if column exists and constraint doesn't)
SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'isms_context'
      AND constraint_name = 'FK_isms_context_tenant'
);

SET @sql = IF(@fk_exists = 0 AND @column_exists = 1,
    'ALTER TABLE isms_context ADD CONSTRAINT FK_isms_context_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)',
    'SELECT "Foreign key already exists or column missing" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index (only if column exists and index doesn't)
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'isms_context'
      AND index_name = 'IDX_isms_context_tenant'
);

SET @sql = IF(@idx_exists = 0 AND @column_exists = 1,
    'CREATE INDEX IDX_isms_context_tenant ON isms_context (tenant_id)',
    'SELECT "Index already exists or column missing" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify the result
SELECT
    table_name,
    column_name,
    column_type,
    is_nullable,
    column_key
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'isms_context'
  AND column_name = 'tenant_id';

-- Show foreign keys
SELECT
    constraint_name,
    table_name,
    column_name,
    referenced_table_name,
    referenced_column_name
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE()
  AND table_name = 'isms_context'
  AND referenced_table_name = 'tenant';
