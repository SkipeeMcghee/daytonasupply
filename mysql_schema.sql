-- MySQL schema for Daytona Supply website
--
-- This file defines the MySQL tables used by the Daytona Supply application.
-- It has been updated to match the fields used in the PHP code (customers,
-- products, orders, order_items and admin) and to respect common MySQL
-- limitations.  In particular, the email field is limited to 191
-- characters so a UTF‑8 collation can be used with a UNIQUE index on
-- older MySQL installs where index keys cannot exceed 767 bytes.

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    business_name VARCHAR(255),
    phone VARCHAR(50),
    -- limit email to 191 chars so the UNIQUE index fits within 767 bytes
    email VARCHAR(191) NOT NULL,
    -- discrete address columns (line1/line2/city/state/postal)
    billing_line1 TEXT,
    billing_line2 TEXT,
    billing_city TEXT,
    billing_state TEXT,
    billing_postal_code TEXT,
    shipping_line1 TEXT,
    shipping_line2 TEXT,
    shipping_city TEXT,
    shipping_state TEXT,
    shipping_postal_code TEXT,
    password_hash VARCHAR(255) NOT NULL,
    -- email verification status (0 = unverified, 1 = verified)
    is_verified TINYINT(1) DEFAULT 0,
    -- verification token for new registrations
    verification_token VARCHAR(255),
    -- hashed password reset token and expiry
    reset_token VARCHAR(255),
    reset_token_expires DATETIME,
    UNIQUE KEY uq_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    -- order status: Pending, Approved or Rejected
    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    -- total price of the order at time of creation
    total DECIMAL(10,2) NOT NULL,
    -- date/time when the order was created
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- archived flag: 0 = active, 1 = archived
    archived TINYINT(1) NOT NULL DEFAULT 0,
    -- FK to customers table
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    -- index on customer_id to improve lookups
    KEY idx_orders_customer_id (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    -- foreign key constraints and indexes
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    KEY idx_order_items_order_id (order_id),
    KEY idx_order_items_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;