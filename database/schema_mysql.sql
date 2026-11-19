-- ========================================================
-- CSDL MYSQL / PHPMYADMIN: HỆ THỐNG QUẢN LÝ KHO ĐA ĐIỂM
-- Đề tài 10 - Phát triển phần mềm mã nguồn mở (FIT-DNU)
-- Tương thích: MySQL 5.7+ / MySQL 8.0+ / MariaDB 10+
-- ========================================================

CREATE DATABASE IF NOT EXISTS `warehouse_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `warehouse_db`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `stock_count_lines`;
DROP TABLE IF EXISTS `stock_counts`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `voucher_lines`;
DROP TABLE IF EXISTS `vouchers`;
DROP TABLE IF EXISTS `batches`;
DROP TABLE IF EXISTS `items`;
DROP TABLE IF EXISTS `item_categories`;
DROP TABLE IF EXISTS `user_warehouses`;
DROP TABLE IF EXISTS `warehouses`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Bảng Người dùng
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` ENUM('admin', 'manager', 'staff', 'auditor') NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Bảng Điểm kho
CREATE TABLE `warehouses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(120) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(30),
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Bảng Phân quyền theo kho (Server-side RBAC)
CREATE TABLE `user_warehouses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `can_view` TINYINT(1) DEFAULT 1,
    `can_create` TINYINT(1) DEFAULT 1,
    `can_approve` TINYINT(1) DEFAULT 0,
    UNIQUE KEY `uk_user_wh` (`user_id`, `warehouse_id`),
    CONSTRAINT `fk_uw_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_uw_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Bảng Nhóm hàng
CREATE TABLE `item_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Bảng Mặt hàng (30 sản phẩm)
CREATE TABLE `items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `category_id` INT NOT NULL,
    `unit` VARCHAR(30) NOT NULL DEFAULT 'Cái',
    `min_stock` INT NOT NULL DEFAULT 5,
    `description` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_item_cat` FOREIGN KEY (`category_id`) REFERENCES `item_categories`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Bảng Lô hàng (Batches)
CREATE TABLE `batches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL,
    `batch_number` VARCHAR(50) NOT NULL,
    `manufacture_date` DATE,
    `expiry_date` DATE,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_item_batch` (`item_id`, `batch_number`),
    CONSTRAINT `fk_batch_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Bảng Chứng từ Nhập, Xuất, Điều chuyển
CREATE TABLE `vouchers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `type` ENUM('IN', 'OUT', 'TRANSFER', 'ADJUST_IN', 'ADJUST_OUT') NOT NULL,
    `source_warehouse_id` INT DEFAULT NULL,
    `dest_warehouse_id` INT DEFAULT NULL,
    `status` ENUM('DRAFT', 'CONFIRMED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
    `created_by` INT NOT NULL,
    `approved_by` INT DEFAULT NULL,
    `note` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `approved_at` DATETIME DEFAULT NULL,
    CONSTRAINT `fk_v_src` FOREIGN KEY (`source_warehouse_id`) REFERENCES `warehouses`(`id`),
    CONSTRAINT `fk_v_dest` FOREIGN KEY (`dest_warehouse_id`) REFERENCES `warehouses`(`id`),
    CONSTRAINT `fk_v_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_v_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Bảng Dòng chi tiết chứng từ
CREATE TABLE `voucher_lines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `voucher_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `batch_id` INT DEFAULT NULL,
    `quantity` INT NOT NULL,
    `unit_price` DECIMAL(15, 2) DEFAULT 0.00,
    `note` TEXT,
    CONSTRAINT `fk_vl_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vl_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
    CONSTRAINT `fk_vl_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Sổ phát sinh kho (Stock Movements Ledger - Trọng tâm Kỹ thuật 1 & 2)
CREATE TABLE `stock_movements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `movement_code` VARCHAR(60) NOT NULL,
    `voucher_id` INT NOT NULL,
    `voucher_line_id` INT DEFAULT NULL,
    `warehouse_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `batch_id` INT DEFAULT NULL,
    `movement_type` ENUM('IN', 'OUT', 'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUST_IN', 'ADJUST_OUT') NOT NULL,
    `quantity_in` INT NOT NULL DEFAULT 0,
    `quantity_out` INT NOT NULL DEFAULT 0,
    `balance_after` INT NOT NULL DEFAULT 0,
    `note` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sm_wh_item` (`warehouse_id`, `item_id`),
    INDEX `idx_sm_voucher` (`voucher_id`),
    CONSTRAINT `fk_sm_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers`(`id`),
    CONSTRAINT `fk_sm_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`),
    CONSTRAINT `fk_sm_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
    CONSTRAINT `fk_sm_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Bảng Đợt kiểm kê
CREATE TABLE `stock_counts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `warehouse_id` INT NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `count_date` DATE NOT NULL,
    `status` ENUM('DRAFT', 'COMPLETED', 'ADJUSTED') NOT NULL DEFAULT 'DRAFT',
    `created_by` INT NOT NULL,
    `completed_by` INT DEFAULT NULL,
    `note` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sc_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`),
    CONSTRAINT `fk_sc_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_sc_completer` FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Bảng Dòng chi tiết kiểm kê & Chênh lệch
CREATE TABLE `stock_count_lines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `stock_count_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `batch_id` INT DEFAULT NULL,
    `system_quantity` INT NOT NULL DEFAULT 0,
    `actual_quantity` INT NOT NULL DEFAULT 0,
    `difference` INT NOT NULL DEFAULT 0,
    `adjustment_reason` TEXT,
    `adjustment_voucher_id` INT DEFAULT NULL,
    CONSTRAINT `fk_scl_count` FOREIGN KEY (`stock_count_id`) REFERENCES `stock_counts`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_scl_item` FOREIGN KEY (`item_id`) REFERENCES `items`(`id`),
    CONSTRAINT `fk_scl_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`),
    CONSTRAINT `fk_scl_adj_v` FOREIGN KEY (`adjustment_voucher_id`) REFERENCES `vouchers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
