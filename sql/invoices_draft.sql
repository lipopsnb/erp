-- Migration: Hỗ trợ hoá đơn nháp (draft)
-- 1. Cho phép invoice_date = NULL (hoá đơn nháp chưa xuất)
ALTER TABLE invoices MODIFY COLUMN invoice_date DATE NULL DEFAULT NULL;

-- 2. Thêm trạng thái 'draft' vào enum status
ALTER TABLE invoices MODIFY COLUMN status ENUM('draft','unpaid','partial','paid','cancelled') DEFAULT 'unpaid';
