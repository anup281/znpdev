-- ZNP Development v5.1.20
-- Safe, rerunnable upgrade: allow document delivery lifecycle values such as "revoked".

ALTER TABLE document_deliveries
    MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'sent';

-- Repair records that became blank when an older ENUM rejected "revoked".
UPDATE document_deliveries
SET status = 'revoked'
WHERE (status IS NULL OR status = '')
  AND accepted_at IS NULL;
