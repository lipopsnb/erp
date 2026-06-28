-- Thêm ngày áp dụng và ngày hết hạn vào customer_prices
-- Bỏ UNIQUE KEY cũ (vì giờ 1 cặp customer+product có nhiều dòng theo thời gian)
ALTER TABLE customer_prices
    DROP INDEX IF EXISTS uk_cust_prod;

ALTER TABLE customer_prices
    ADD COLUMN effective_date DATE NOT NULL DEFAULT '2025-01-01' AFTER unit_price,
    ADD COLUMN expired_date   DATE NULL                          AFTER effective_date;

-- Index mới: tìm giá hiện tại nhanh
CREATE INDEX idx_cp_cust_prod_date ON customer_prices(customer_id, product_code_id, effective_date);
