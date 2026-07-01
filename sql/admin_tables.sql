CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(200) NOT NULL,
    is_active TINYINT(1) DEFAULT 1
);

INSERT IGNORE INTO expense_categories (id, category_name) VALUES
(1, 'Tiền điện'),
(2, 'Tiền nước'),
(3, 'Internet'),
(4, 'Điện thoại'),
(5, 'Thuê văn phòng'),
(6, 'Chuyển phát nhanh'),
(7, 'Văn phòng phẩm'),
(8, 'Vệ sinh'),
(9, 'Mua sắm máy móc / Thiết bị'),
(10, 'Mua sắm vật tư tiêu hao'),
(11, 'Khác');

CREATE TABLE IF NOT EXISTS company_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(50) NOT NULL UNIQUE,
    asset_name VARCHAR(200) NOT NULL,
    category ENUM('computer','printer','furniture','machinery','vehicle','other') DEFAULT 'other',
    purchase_date DATE NULL,
    purchase_price DECIMAL(15,2) DEFAULT 0,
    supplier VARCHAR(200) NULL,
    location VARCHAR(200) NULL,
    status ENUM('active','assigned','maintenance','disposed') DEFAULT 'active',
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plate_number VARCHAR(20) NOT NULL UNIQUE,
    vehicle_name VARCHAR(200) NOT NULL,
    brand VARCHAR(100) NULL,
    model VARCHAR(100) NULL,
    year INT NULL,
    color VARCHAR(50) NULL,
    status ENUM('active','maintenance','disposed') DEFAULT 'active',
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS expense_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_no VARCHAR(50) NOT NULL UNIQUE,
    category_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    expense_date DATE NOT NULL,
    purpose TEXT NOT NULL,
    has_invoice TINYINT(1) DEFAULT 0,
    invoice_no VARCHAR(100) NULL,
    invoice_date DATE NULL,
    invoice_company VARCHAR(200) NULL,
    payment_method ENUM('cash','bank_transfer') DEFAULT 'cash',
    status ENUM('draft','submitted','approved','rejected') DEFAULT 'draft',
    requested_by INT NOT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    reject_reason TEXT NULL,
    note TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_er_category FOREIGN KEY (category_id) REFERENCES expense_categories(id),
    CONSTRAINT fk_er_requested_by FOREIGN KEY (requested_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS expense_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_method ENUM('cash','bank_transfer') DEFAULT 'cash',
    paid_by INT NULL,
    note TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ep_expense FOREIGN KEY (expense_id) REFERENCES expense_requests(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admin_budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    budget_year INT NOT NULL,
    budget_month INT NOT NULL,
    category_id INT NOT NULL,
    budget_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_budget (budget_year, budget_month, category_id),
    CONSTRAINT fk_ab_category FOREIGN KEY (category_id) REFERENCES expense_categories(id)
);

CREATE TABLE IF NOT EXISTS asset_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    user_id INT NOT NULL,
    assigned_date DATE NOT NULL,
    returned_date DATE NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aa_asset FOREIGN KEY (asset_id) REFERENCES company_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_aa_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS vehicle_fuel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    fuel_date DATE NOT NULL,
    invoice_no VARCHAR(100) NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    liters DECIMAL(8,2) NULL,
    odometer INT NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vf_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vehicle_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    doc_type ENUM('registration','insurance','maintenance') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    cost DECIMAL(15,2) DEFAULT 0,
    provider VARCHAR(200) NULL,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vd_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vehicle_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    trip_date DATE NOT NULL,
    driver_id INT NULL,
    origin VARCHAR(200) NULL,
    destination VARCHAR(200) NULL,
    km_start INT NULL,
    km_end INT NULL,
    toll_fee DECIMAL(15,2) DEFAULT 0,
    note TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vt_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);
