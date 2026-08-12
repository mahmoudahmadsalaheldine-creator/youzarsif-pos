-- ============================================================
-- Youzarsif Sweets Management System
-- OFFICIAL Database Schema
-- Generated from live database screenshots
-- Database: u166789058_youzarsifsweet
-- Local DB name: youzarsifsweet
-- ============================================================

CREATE DATABASE IF NOT EXISTS youzarsifsweet
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE youzarsifsweet;

-- ============================================================
-- 1. CATEGORIES
-- ============================================================
CREATE TABLE categories (
    category_id     INT(11) AUTO_INCREMENT PRIMARY KEY,
    category_name   VARCHAR(150) NOT NULL,
    category_type   ENUM('ingredient','supporting_item','finished_product','expense','other') NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 2. UNITS
-- ============================================================
CREATE TABLE units (
    unit_id         INT(11) AUTO_INCREMENT PRIMARY KEY,
    unit_name       VARCHAR(100) NOT NULL,
    abbreviation    VARCHAR(20) NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 3. LOCATIONS
-- ============================================================
CREATE TABLE locations (
    location_id     INT(11) AUTO_INCREMENT PRIMARY KEY,
    location_name   VARCHAR(100) NOT NULL,
    location_type   ENUM('factory','store') NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 4. ITEM_TYPES
-- ============================================================
CREATE TABLE item_types (
    item_type_id    INT(11) AUTO_INCREMENT PRIMARY KEY,
    type_name       VARCHAR(100) NOT NULL,
    type_slug       VARCHAR(100) NOT NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 5. ITEMS
-- ============================================================
CREATE TABLE items (
    item_id         INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_name       VARCHAR(150) NOT NULL,
    item_code       VARCHAR(100) NOT NULL UNIQUE,
    family_code     VARCHAR(20) NOT NULL,
    category_id     INT(11) NOT NULL,
    unit_id         INT(11) NOT NULL,
    min_stock_qty   DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 6. STOCK_MOVEMENTS
-- ============================================================
CREATE TABLE stock_movements (
    movement_id     INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_id         INT(11) NOT NULL,
    location_id     INT(11) NOT NULL,
    movement_type   ENUM('purchase_in','production_consumption','adjustment_in','adjustment_out','waste_out','return_in') NOT NULL,
    direction       ENUM('in','out') NOT NULL,
    quantity        DECIMAL(12,3) NOT NULL,
    unit_cost_usd   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost_usd  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reference_no    VARCHAR(100) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    status          ENUM('active','voided') NOT NULL DEFAULT 'active',
    void_reason     TEXT DEFAULT NULL,
    voided_by       INT(11) DEFAULT NULL,
    voided_at       TIMESTAMP NULL DEFAULT NULL,
    created_by      INT(11) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 7. FINISHED_PRODUCTS
-- ============================================================
CREATE TABLE finished_products (
    product_id          INT(11) AUTO_INCREMENT PRIMARY KEY,
    product_name        VARCHAR(150) NOT NULL,
    product_code        VARCHAR(100) NOT NULL UNIQUE,
    family_code         VARCHAR(20) NOT NULL,
    category_id         INT(11) NOT NULL,
    unit_id             INT(11) NOT NULL,
    calculated_cost_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    profit_percentage   DECIMAL(5,2) NOT NULL DEFAULT 60.00,
    selling_price_usd   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    min_stock_qty       DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 8. FINISHED_PRODUCT_COMPONENTS
-- ============================================================
CREATE TABLE finished_product_components (
    component_id    INT(11) AUTO_INCREMENT PRIMARY KEY,
    product_id      INT(11) NOT NULL,
    item_id         INT(11) NOT NULL,
    quantity_used   DECIMAL(12,3) NOT NULL,
    unit_cost_usd   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost_usd  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 9. EXPENSE_CATEGORIES
-- ============================================================
CREATE TABLE expense_categories (
    expense_category_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    category_name       VARCHAR(150) NOT NULL,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 10. EXPENSES
-- ============================================================
CREATE TABLE expenses (
    expense_id          INT(11) AUTO_INCREMENT PRIMARY KEY,
    expense_category_id INT(11) NOT NULL,
    expense_title       VARCHAR(150) NOT NULL,
    amount_usd          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_lbp          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    exchange_rate       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_method      ENUM('cash_usd','cash_lbp','card','mixed') NOT NULL,
    expense_date        DATE NOT NULL,
    notes               TEXT DEFAULT NULL,
    created_by          INT(11) NOT NULL DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 11. EXCHANGE_RATES
-- ============================================================
CREATE TABLE exchange_rates (
    rate_id         INT(11) AUTO_INCREMENT PRIMARY KEY,
    usd_to_lbp      DECIMAL(15,2) NOT NULL,
    rate_date       DATE NOT NULL,
    notes           TEXT DEFAULT NULL,
    created_by      INT(11) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 12. USERS
-- ============================================================
CREATE TABLE users (
    user_id         INT(11) AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    role            ENUM('admin','factory_user','cashier','accountant') NOT NULL DEFAULT 'admin',
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- TABLES NEEDED NEXT (from CEO brief - not in live DB yet)
-- Run these when ready to build production batches
-- ============================================================

CREATE TABLE production_batches (
    batch_id            INT(11) AUTO_INCREMENT PRIMARY KEY,
    batch_code          VARCHAR(50) NOT NULL UNIQUE,
    product_id          INT(11) NOT NULL,
    location_id         INT(11) NOT NULL,
    quantity_produced   DECIMAL(12,3) NOT NULL,
    unit_cost_usd       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost_usd      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes               TEXT DEFAULT NULL,
    status              ENUM('completed','voided') NOT NULL DEFAULT 'completed',
    void_reason         TEXT DEFAULT NULL,
    voided_by           INT(11) DEFAULT NULL,
    voided_at           TIMESTAMP NULL DEFAULT NULL,
    created_by          INT(11) NOT NULL DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE production_batch_components (
    id              INT(11) AUTO_INCREMENT PRIMARY KEY,
    batch_id        INT(11) NOT NULL,
    item_id         INT(11) NOT NULL,
    quantity_used   DECIMAL(12,3) NOT NULL,
    unit_cost_usd   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost_usd  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE product_stock_movements (
    movement_id     INT(11) AUTO_INCREMENT PRIMARY KEY,
    product_id      INT(11) NOT NULL,
    location_id     INT(11) NOT NULL,
    movement_type   ENUM('production_in','transfer_in','transfer_out','sale_out','return_in','adjustment_in','adjustment_out') NOT NULL,
    direction       ENUM('in','out') NOT NULL,
    quantity        DECIMAL(12,3) NOT NULL,
    unit_cost_usd   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost_usd  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reference_no    VARCHAR(100) DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    status          ENUM('active','voided') NOT NULL DEFAULT 'active',
    void_reason     TEXT DEFAULT NULL,
    voided_by       INT(11) DEFAULT NULL,
    voided_at       TIMESTAMP NULL DEFAULT NULL,
    created_by      INT(11) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);