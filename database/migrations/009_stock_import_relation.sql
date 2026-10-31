SET NAMES utf8mb4;

ALTER TABLE stock
 ADD INDEX idx_stock_last_import(last_import_id),
 ADD CONSTRAINT fk_stock_last_import
   FOREIGN KEY(last_import_id) REFERENCES import_log(id) ON DELETE SET NULL;
