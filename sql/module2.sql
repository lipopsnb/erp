-- ============================================================
-- Module 2: Quản lý sản xuất & kho ERP
-- Chạy trên database hiện tại (ntnnew / liprolog_erp)
-- ============================================================

-- 1. Bảng giá theo từng khách hàng
CREATE TABLE IF NOT EXISTS customer_prices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT NOT NULL,
    product_code_id INT NOT NULL,
    unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0,
    note            VARCHAR(255),
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cust_prod (customer_id, product_code_id)
);

-- 2. Phiếu nhập kho NVL (khách gửi hàng đến để gia công)
CREATE TABLE IF NOT EXISTS warehouse_in (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    receipt_no  VARCHAR(50) UNIQUE,
    receipt_date DATE NOT NULL,
    customer_id INT NOT NULL,
    note        TEXT,
    status      ENUM('open','processing','done') DEFAULT 'open',
    created_by  INT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 3. Chi tiết phiếu nhập kho NVL (nhiều mã hàng / phiếu)
CREATE TABLE IF NOT EXISTS warehouse_in_items (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_in_id  INT NOT NULL,
    product_code_id  INT NOT NULL,
    quantity         DECIMAL(15,3) NOT NULL DEFAULT 0,
    note             VARCHAR(255)
);

-- 4. Tiến độ gia công (gắn với phiếu nhập kho NVL)
CREATE TABLE IF NOT EXISTS wo_processes (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_in_id      INT NOT NULL,
    warehouse_in_item_id INT,
    product_code_id      INT NOT NULL,
    quantity_input       DECIMAL(15,3) DEFAULT 0,
    quantity_done        DECIMAL(15,3) DEFAULT 0,
    quantity_rejected    DECIMAL(15,3) DEFAULT 0,
    status               ENUM('processing','done') DEFAULT 'processing',
    process_date         DATE,
    note                 TEXT,
    updated_by           INT,
    updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 5. Kho thành phẩm (kết quả gia công)
CREATE TABLE IF NOT EXISTS warehouse_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_in_id   INT,
    wo_process_id     INT,
    product_code_id   INT NOT NULL,
    customer_id       INT NOT NULL,
    quantity          DECIMAL(15,3) NOT NULL DEFAULT 0,
    quantity_delivered DECIMAL(15,3) DEFAULT 0,
    status            ENUM('done','waiting','delivered','rejected') DEFAULT 'done',
    note              TEXT,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 6. Phiếu xuất kho thành phẩm
CREATE TABLE IF NOT EXISTS warehouse_out (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    export_no   VARCHAR(50) UNIQUE,
    export_date DATE NOT NULL,
    customer_id INT NOT NULL,
    note        TEXT,
    status      ENUM('draft','confirmed') DEFAULT 'draft',
    created_by  INT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 7. Chi tiết phiếu xuất kho
CREATE TABLE IF NOT EXISTS warehouse_out_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_out_id  INT NOT NULL,
    warehouse_item_id INT NOT NULL,
    product_code_id   INT NOT NULL,
    quantity          DECIMAL(15,3) NOT NULL DEFAULT 0,
    note              VARCHAR(255)
);

-- 8. Phiếu giao hàng (link với phiếu xuất kho)
CREATE TABLE IF NOT EXISTS deliveries (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    delivery_no      VARCHAR(50) UNIQUE,
    delivery_date    DATE NOT NULL,
    customer_id      INT NOT NULL,
    warehouse_out_id INT,
    total_amount     DECIMAL(15,2) DEFAULT 0,
    note             TEXT,
    status           ENUM('draft','confirmed','invoiced') DEFAULT 'draft',
    created_by       INT,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 9. Chi tiết phiếu giao hàng
CREATE TABLE IF NOT EXISTS delivery_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id     INT NOT NULL,
    product_code_id INT NOT NULL,
    quantity        DECIMAL(15,3) NOT NULL DEFAULT 0,
    unit_price      DECIMAL(15,2) DEFAULT 0,
    total_price     DECIMAL(15,2) DEFAULT 0,
    note            VARCHAR(255)
);

-- 10. Hóa đơn (kiểm tra trước khi tạo — có thể đã tồn tại)
CREATE TABLE IF NOT EXISTS invoices (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no   VARCHAR(50) UNIQUE,
    invoice_date DATE NOT NULL,
    due_date     DATE,
    customer_id  INT NOT NULL,
    delivery_id  INT,
    subtotal     DECIMAL(15,2) DEFAULT 0,
    vat_rate     DECIMAL(5,2)  DEFAULT 0,
    vat_amount   DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) DEFAULT 0,
    status       ENUM('unpaid','partial','paid','cancelled') DEFAULT 'unpaid',
    note         TEXT,
    created_by   INT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 11. Chi tiết hóa đơn
CREATE TABLE IF NOT EXISTS invoice_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT NOT NULL,
    product_code_id INT NOT NULL,
    description     VARCHAR(500),
    unit            VARCHAR(50),
    quantity        DECIMAL(15,3) NOT NULL DEFAULT 0,
    unit_price      DECIMAL(15,2) DEFAULT 0,
    total_price     DECIMAL(15,2) DEFAULT 0
);

-- 12. Thanh toán (nhiều lần / hóa đơn)
CREATE TABLE IF NOT EXISTS payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id     INT NOT NULL,
    payment_date   DATE NOT NULL,
    amount         DECIMAL(15,2) NOT NULL,
    payment_method ENUM('cash','transfer','check') DEFAULT 'cash',
    note           VARCHAR(500),
    created_by     INT,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Bảng sinh số tự động (dùng chung toàn hệ thống)
CREATE TABLE IF NOT EXISTS document_sequences (
    doc_type  VARCHAR(20) NOT NULL,
    doc_date  DATE        NOT NULL,
    last_seq  INT         NOT NULL DEFAULT 0,
    PRIMARY KEY (doc_type, doc_date)
);

-- ============================================================
-- Khóa ngoại (chạy sau khi tất cả bảng đã tồn tại)
-- Bỏ qua nếu gặp lỗi (bảng customers/product_codes/users có thể đã tồn tại)
-- ============================================================

-- customer_prices
ALTER TABLE customer_prices
    ADD CONSTRAINT fk_cp_customer FOREIGN KEY (customer_id)     REFERENCES customers(id)     ON DELETE CASCADE,
    ADD CONSTRAINT fk_cp_product  FOREIGN KEY (product_code_id) REFERENCES product_codes(id) ON DELETE CASCADE;

-- warehouse_in
ALTER TABLE warehouse_in
    ADD CONSTRAINT fk_wi_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_wi_created_by FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL;

-- warehouse_in_items
ALTER TABLE warehouse_in_items
    ADD CONSTRAINT fk_wii_wi      FOREIGN KEY (warehouse_in_id)  REFERENCES warehouse_in(id)  ON DELETE CASCADE,
    ADD CONSTRAINT fk_wii_product FOREIGN KEY (product_code_id)  REFERENCES product_codes(id) ON DELETE RESTRICT;

-- wo_processes
ALTER TABLE wo_processes
    ADD CONSTRAINT fk_wop_wi     FOREIGN KEY (warehouse_in_id)      REFERENCES warehouse_in(id)       ON DELETE CASCADE,
    ADD CONSTRAINT fk_wop_wii    FOREIGN KEY (warehouse_in_item_id) REFERENCES warehouse_in_items(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_wop_pc     FOREIGN KEY (product_code_id)      REFERENCES product_codes(id)      ON DELETE RESTRICT,
    ADD CONSTRAINT fk_wop_user   FOREIGN KEY (updated_by)           REFERENCES users(id)              ON DELETE SET NULL;

-- warehouse_items
ALTER TABLE warehouse_items
    ADD CONSTRAINT fk_witm_wi    FOREIGN KEY (warehouse_in_id)  REFERENCES warehouse_in(id)  ON DELETE SET NULL,
    ADD CONSTRAINT fk_witm_wop   FOREIGN KEY (wo_process_id)    REFERENCES wo_processes(id)  ON DELETE SET NULL,
    ADD CONSTRAINT fk_witm_pc    FOREIGN KEY (product_code_id)  REFERENCES product_codes(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_witm_cust  FOREIGN KEY (customer_id)      REFERENCES customers(id)     ON DELETE RESTRICT;

-- warehouse_out
ALTER TABLE warehouse_out
    ADD CONSTRAINT fk_wo_customer   FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_wo_created_by FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL;

-- warehouse_out_items
ALTER TABLE warehouse_out_items
    ADD CONSTRAINT fk_woi_wo     FOREIGN KEY (warehouse_out_id)  REFERENCES warehouse_out(id)   ON DELETE CASCADE,
    ADD CONSTRAINT fk_woi_witm   FOREIGN KEY (warehouse_item_id) REFERENCES warehouse_items(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_woi_pc     FOREIGN KEY (product_code_id)   REFERENCES product_codes(id)   ON DELETE RESTRICT;

-- deliveries
ALTER TABLE deliveries
    ADD CONSTRAINT fk_dl_customer FOREIGN KEY (customer_id)      REFERENCES customers(id)    ON DELETE RESTRICT,
    ADD CONSTRAINT fk_dl_wo       FOREIGN KEY (warehouse_out_id) REFERENCES warehouse_out(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_dl_user     FOREIGN KEY (created_by)       REFERENCES users(id)         ON DELETE SET NULL;

-- delivery_items
ALTER TABLE delivery_items
    ADD CONSTRAINT fk_dli_dl  FOREIGN KEY (delivery_id)     REFERENCES deliveries(id)    ON DELETE CASCADE,
    ADD CONSTRAINT fk_dli_pc  FOREIGN KEY (product_code_id) REFERENCES product_codes(id) ON DELETE RESTRICT;

-- invoices
ALTER TABLE invoices
    ADD CONSTRAINT fk_inv_customer  FOREIGN KEY (customer_id)  REFERENCES customers(id)  ON DELETE RESTRICT,
    ADD CONSTRAINT fk_inv_delivery  FOREIGN KEY (delivery_id)  REFERENCES deliveries(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_inv_user      FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE SET NULL;

-- invoice_items
ALTER TABLE invoice_items
    ADD CONSTRAINT fk_ii_inv FOREIGN KEY (invoice_id)      REFERENCES invoices(id)      ON DELETE CASCADE,
    ADD CONSTRAINT fk_ii_pc  FOREIGN KEY (product_code_id) REFERENCES product_codes(id) ON DELETE RESTRICT;

-- payments
ALTER TABLE payments
    ADD CONSTRAINT fk_pay_inv  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_pay_user FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL;
