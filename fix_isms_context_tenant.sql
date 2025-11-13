-- Fix ISMSContext tenant_id column
-- Run this directly on the database if the migration didn't work

-- Check if column exists
SELECT COUNT(*)
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'isms_context'
  AND column_name = 'tenant_id';

-- Add column if it doesn't exist
ALTER TABLE isms_context
ADD COLUMN IF NOT EXISTS tenant_id INT DEFAULT NULL;

-- Add foreign key constraint if it doesn't exist
ALTER TABLE isms_context
ADD CONSTRAINT IF NOT EXISTS FK_isms_context_tenant
FOREIGN KEY (tenant_id) REFERENCES tenant (id);

-- Add index for performance
CREATE INDEX IF NOT EXISTS IDX_isms_context_tenant
ON isms_context (tenant_id);

-- Verify the column was added
DESCRIBE isms_context;
