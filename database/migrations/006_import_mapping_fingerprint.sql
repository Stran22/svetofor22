-- Preserve the original XLSX file SHA-256 separately from the effective column mapping.
-- Existing rows retain NULL because their mapping cannot be reconstructed reliably.
-- Run once after migration 005, before deploying the matching PHP code.
ALTER TABLE upload_log
    ADD COLUMN mapping_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
        AFTER file_sha256;

CREATE INDEX idx_upload_duplicate_mapping
    ON upload_log (branch_id, recorded_date, file_sha256, mapping_sha256, status);
