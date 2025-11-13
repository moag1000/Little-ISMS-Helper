-- Fix missing tenant_id columns in database
-- This script adds tenant_id columns to tables where they are missing
-- Safe to run multiple times (uses IF NOT EXISTS where supported)

-- For MySQL 8.0+, you can run this entire file
-- For older MySQL versions, remove "IF NOT EXISTS" and run statements individually

-- Check if column exists and add if needed (manual approach for compatibility)

-- Business Process
SET @dbname = DATABASE();
SET @tablename = 'business_process';
SET @columnname = 'tenant_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ISMS Context
SET @tablename = 'isms_context';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- ISMS Objective
SET @tablename = 'isms_objective';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Internal Audit
SET @tablename = 'internal_audit';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Management Review
SET @tablename = 'management_review';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Training
SET @tablename = 'training';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Vulnerabilities
SET @tablename = 'vulnerabilities';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Patches
SET @tablename = 'patches';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Crisis Teams
SET @tablename = 'crisis_teams';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Audit Checklist
SET @tablename = 'audit_checklist';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Workflows
SET @tablename = 'workflows';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Workflow Instances
SET @tablename = 'workflow_instances';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Workflow Steps
SET @tablename = 'workflow_steps';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE table_schema = @dbname
   AND table_name = @tablename
   AND column_name = @columnname) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT DEFAULT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Now add foreign keys and indexes (using similar pattern)
-- We'll create a simpler version that checks for existence

-- Add foreign keys
ALTER TABLE business_process ADD CONSTRAINT FK_business_process_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE isms_context ADD CONSTRAINT FK_isms_context_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE isms_objective ADD CONSTRAINT FK_isms_objective_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE internal_audit ADD CONSTRAINT FK_internal_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE management_review ADD CONSTRAINT FK_management_review_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE training ADD CONSTRAINT FK_training_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE vulnerabilities ADD CONSTRAINT FK_vulnerabilities_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE patches ADD CONSTRAINT FK_patches_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE crisis_teams ADD CONSTRAINT FK_crisis_teams_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE audit_checklist ADD CONSTRAINT FK_audit_checklist_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE workflows ADD CONSTRAINT FK_workflows_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE workflow_instances ADD CONSTRAINT FK_workflow_instances_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);
ALTER TABLE workflow_steps ADD CONSTRAINT FK_workflow_steps_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id);

-- Add indexes
CREATE INDEX IDX_business_process_tenant ON business_process (tenant_id);
CREATE INDEX IDX_isms_context_tenant ON isms_context (tenant_id);
CREATE INDEX IDX_isms_objective_tenant ON isms_objective (tenant_id);
CREATE INDEX IDX_internal_audit_tenant ON internal_audit (tenant_id);
CREATE INDEX IDX_management_review_tenant ON management_review (tenant_id);
CREATE INDEX IDX_training_tenant ON training (tenant_id);
CREATE INDEX IDX_vulnerabilities_tenant ON vulnerabilities (tenant_id);
CREATE INDEX IDX_patches_tenant ON patches (tenant_id);
CREATE INDEX IDX_crisis_teams_tenant ON crisis_teams (tenant_id);
CREATE INDEX IDX_audit_checklist_tenant ON audit_checklist (tenant_id);
CREATE INDEX IDX_workflows_tenant ON workflows (tenant_id);
CREATE INDEX IDX_workflow_instances_tenant ON workflow_instances (tenant_id);
CREATE INDEX IDX_workflow_steps_tenant ON workflow_steps (tenant_id);
