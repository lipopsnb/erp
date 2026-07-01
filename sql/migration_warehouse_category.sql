-- Migration: Tách biệt warehouse_stock theo loại hàng
-- Thêm cột category để phân loại:
-- - raw_material: NVL gia công
-- - consumable: Vật tư tiêu hao
-- - office: Văn phòng phẩm
-- - equipment: Máy móc, thiết bị
-- - other: Khác

ALTER TABLE warehouse_stock 
ADD COLUMN category ENUM('raw_material','consumable','office','equipment','other') 
NOT NULL DEFAULT 'raw_material' 
AFTER product_code_id;

-- Thêm index để tìm kiếm nhanh theo category
ALTER TABLE warehouse_stock
ADD INDEX idx_category (category);

-- Thêm index kết hợp (product_code_id, category)
ALTER TABLE warehouse_stock
ADD INDEX idx_product_category (product_code_id, category);

-- Cập nhật product_codes với category nếu có
ALTER TABLE product_codes
ADD COLUMN product_category ENUM('finished_goods','raw_material','consumable','office','equipment','other') 
NOT NULL DEFAULT 'finished_goods' 
AFTER product_code;

-- Cập nhật index cho product_codes
ALTER TABLE product_codes
ADD INDEX idx_product_category (product_category);
