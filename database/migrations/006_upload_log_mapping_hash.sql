-- Distinguish identical XLSX bytes imported with different effective column mappings.
ALTER TABLE upload_log
    ADD COLUMN mapping_sha256 CHAR(64) NULL AFTER file_sha256;

CREATE INDEX idx_upload_duplicate_mapping
    ON upload_log (branch_id, recorded_date, file_sha256, mapping_sha256, status);
