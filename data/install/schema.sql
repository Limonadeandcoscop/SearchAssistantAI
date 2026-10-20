-- Search Assistant AI Module Database Schema
-- Synchronization queue

-- Table: search_assistant_sync_queue
-- Purpose: Stores pending synchronization operations for async/batch processing
-- Used by: SyncService for queue-based synchronization modes
CREATE TABLE search_assistant_sync_queue (
    id INT AUTO_INCREMENT NOT NULL,
    resource_id INT NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    action VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    processed_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    PRIMARY KEY(id),
    INDEX IDX_STATUS (status),
    INDEX IDX_CREATED_AT (created_at),
    INDEX IDX_STATUS_CREATED (status, created_at)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
