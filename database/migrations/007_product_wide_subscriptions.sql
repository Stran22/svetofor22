-- One subscription per user and product. Test subscriptions are intentionally discarded.
-- Run after 006 and only while push workers are stopped.
DELETE FROM subscriptions;
ALTER TABLE subscriptions
    DROP INDEX uq_subscription_user_product_branch,
    DROP INDEX idx_subscriptions_pending,
    MODIFY branch_id BIGINT UNSIGNED NULL DEFAULT NULL,
    ADD UNIQUE KEY uq_subscription_user_product (user_id, product_id),
    ADD KEY idx_subscriptions_product_status (product_id, status);

ALTER TABLE push_delivery_log
    ADD COLUMN branch_id BIGINT UNSIGNED NULL DEFAULT NULL,
    ADD KEY idx_push_delivery_branch (branch_id);
