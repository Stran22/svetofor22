SET NAMES utf8mb4;

ALTER TABLE stock
 ADD CONSTRAINT chk_stock_price_nonnegative CHECK (price IS NULL OR price >= 0),
 ADD CONSTRAINT chk_stock_quantity_nonnegative CHECK (quantity >= 0);

ALTER TABLE price_history
 ADD CONSTRAINT chk_price_history_price_nonnegative CHECK (price >= 0);
