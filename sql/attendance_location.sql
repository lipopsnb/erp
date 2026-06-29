ALTER TABLE attendance_logs
    ADD COLUMN IF NOT EXISTS check_in_ip VARCHAR(45) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS check_in_lat DECIMAL(10,7) NULL AFTER check_in_ip,
    ADD COLUMN IF NOT EXISTS check_in_lng DECIMAL(10,7) NULL AFTER check_in_lat,
    ADD COLUMN IF NOT EXISTS check_in_location_flag ENUM('verified','outside','no_gps','unknown') DEFAULT 'unknown' AFTER check_in_lng,
    ADD COLUMN IF NOT EXISTS check_out_ip VARCHAR(45) NULL AFTER check_in_location_flag,
    ADD COLUMN IF NOT EXISTS check_out_lat DECIMAL(10,7) NULL AFTER check_out_ip,
    ADD COLUMN IF NOT EXISTS check_out_lng DECIMAL(10,7) NULL AFTER check_out_lat,
    ADD COLUMN IF NOT EXISTS check_out_location_flag ENUM('verified','outside','no_gps','unknown') DEFAULT 'unknown' AFTER check_out_lng;

CREATE TABLE IF NOT EXISTS company_ip_whitelist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    label VARCHAR(100) NULL COMMENT 'Mô tả: WiFi văn phòng, mạng LAN...',
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS company_location_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(50) NOT NULL UNIQUE,
    config_value VARCHAR(255) NOT NULL,
    label VARCHAR(100) NULL
);

-- Giá trị mặc định bên dưới chỉ là placeholder và cần đổi sang tọa độ thực tế của công ty sau khi triển khai.
INSERT IGNORE INTO company_location_config (config_key, config_value, label) VALUES
    ('lat', '21.0278', 'Vĩ độ công ty'),
    ('lng', '105.8342', 'Kinh độ công ty'),
    ('radius_meters', '500', 'Bán kính cho phép (mét)'),
    ('gps_required', '0', 'Bắt buộc GPS (1=có, 0=không)');
