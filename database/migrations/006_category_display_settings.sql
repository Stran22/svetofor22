-- Category-based display control. Tracking availability is calculated automatically.
-- A restriction on any parent category also applies to descendants.
ALTER TABLE categories
    ADD COLUMN hide_store_stock TINYINT(1) NOT NULL DEFAULT 0;
