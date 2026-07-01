CREATE TABLE IF NOT EXISTS inv_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) NOT NULL UNIQUE,
    item_name VARCHAR(255) NOT NULL,
    category ENUM('consumable','stationery','equipment','machinery','other') NOT NULL DEFAULT 'other',
    unit VARCHAR(50) NOT NULL DEFAULT 'Cái',
    min_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inv_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_no VARCHAR(30) NOT NULL UNIQUE,
    item_id INT NOT NULL,
    import_date DATE NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    vat_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) GENERATED ALWAYS AS (quantity * unit_price * (1 + vat_percent / 100)) STORED,
    invoice_no VARCHAR(100) NULL,
    supplier VARCHAR(255) NULL,
    payment_status ENUM('paid','unpaid') NOT NULL DEFAULT 'unpaid',
    note TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inv_items(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS inv_exports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    export_no VARCHAR(30) NOT NULL UNIQUE,
    item_id INT NOT NULL,
    export_date DATE NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    department VARCHAR(100) NULL,
    requested_by_name VARCHAR(150) NULL,
    approved_by INT NULL,
    note TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inv_items(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);
