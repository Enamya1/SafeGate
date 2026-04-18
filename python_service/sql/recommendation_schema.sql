-- Recommendation system schema updates (MySQL 8+)
-- Run this file once on the same database used by python_service.

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS views_count BIGINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS likes_count BIGINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS clicks_count BIGINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS trending_score DECIMAL(12,4) NOT NULL DEFAULT 0.0000;

CREATE INDEX IF NOT EXISTS idx_products_trending_score ON products (trending_score DESC);
CREATE INDEX IF NOT EXISTS idx_products_category_price ON products (category_id, price);
CREATE INDEX IF NOT EXISTS idx_products_created_at ON products (created_at);

CREATE TABLE IF NOT EXISTS behavioral_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    product_id BIGINT NULL,
    category_id BIGINT NULL,
    seller_id BIGINT NULL,
    event_type VARCHAR(40) NOT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_behavioral_user_time (user_id, occurred_at DESC),
    INDEX idx_behavioral_product_type_time (product_id, event_type, occurred_at DESC),
    INDEX idx_behavioral_event_type_time (event_type, occurred_at DESC)
);

-- Optional: collaborative filtering speedup for "users also viewed"
CREATE INDEX IF NOT EXISTS idx_behavioral_user_product ON behavioral_events (user_id, product_id);
CREATE INDEX IF NOT EXISTS idx_behavioral_product_user ON behavioral_events (product_id, user_id);
