-- Gab's Bakeshop schema for database: gabsdatabase
-- Import in phpMyAdmin or: mysql -u root < database/gabsdatabase.sql

CREATE DATABASE IF NOT EXISTS `gabsdatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gabsdatabase`;

CREATE TABLE IF NOT EXISTS `roles` (
  `role_ID` int NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL,
  PRIMARY KEY (`role_ID`),
  UNIQUE KEY `role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `accounts` (
  `user_ID` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(150) NOT NULL,
  `Location` varchar(150) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`user_ID`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `userroles` (
  `user_ID` int NOT NULL,
  `role_ID` int NOT NULL,
  PRIMARY KEY (`user_ID`),
  KEY `role_ID` (`role_ID`),
  CONSTRAINT `userroles_ibfk_1` FOREIGN KEY (`user_ID`) REFERENCES `accounts` (`user_ID`) ON DELETE CASCADE,
  CONSTRAINT `userroles_ibfk_2` FOREIGN KEY (`role_ID`) REFERENCES `roles` (`role_ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ingredients` (
  `ingredients_ID` int NOT NULL AUTO_INCREMENT,
  `ingredients` varchar(150) NOT NULL,
  `stock` double NOT NULL DEFAULT 0,
  `type` enum('Dry','Wet') NOT NULL,
  PRIMARY KEY (`ingredients_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `products` (
  `product_ID` int NOT NULL AUTO_INCREMENT,
  `product_name` varchar(150) NOT NULL,
  `stock` int NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `Cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`product_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `product_ingredients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_ID` int NOT NULL,
  `ingredient_ID` int NOT NULL,
  `qty_per_unit` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `product_ID` (`product_ID`),
  KEY `ingredient_ID` (`ingredient_ID`),
  CONSTRAINT `product_ingredients_ibfk_1` FOREIGN KEY (`product_ID`) REFERENCES `products` (`product_ID`) ON DELETE CASCADE,
  CONSTRAINT `product_ingredients_ibfk_2` FOREIGN KEY (`ingredient_ID`) REFERENCES `ingredients` (`ingredients_ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `orders` (
  `order_ID` int NOT NULL AUTO_INCREMENT,
  `branch_ID` int NOT NULL,
  `product_ID` int NOT NULL,
  `quantity` int NOT NULL,
  `order_date` datetime NOT NULL,
  `status` enum('Pending','Approved','Delivered','Denied','Cancelled') NOT NULL DEFAULT 'Pending',
  PRIMARY KEY (`order_ID`),
  KEY `branch_ID` (`branch_ID`),
  KEY `product_ID` (`product_ID`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`branch_ID`) REFERENCES `accounts` (`user_ID`),
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`product_ID`) REFERENCES `products` (`product_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `receipt` (
  `receipt_ID` int NOT NULL AUTO_INCREMENT,
  `branch_ID` int NOT NULL,
  `order_date` datetime NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `approved_by` int NOT NULL,
  `receipt_date` datetime NOT NULL,
  `Names` varchar(150) DEFAULT NULL,
  `proof_image` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`receipt_ID`),
  KEY `branch_ID` (`branch_ID`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `receipt_ibfk_1` FOREIGN KEY (`branch_ID`) REFERENCES `accounts` (`user_ID`),
  CONSTRAINT `receipt_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `accounts` (`user_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `inventory_history` (
  `history_ID` int NOT NULL AUTO_INCREMENT,
  `ingredients_ID` int NOT NULL,
  `product_ID` int NOT NULL DEFAULT 0,
  `user_ID` int NOT NULL,
  `action` varchar(100) NOT NULL,
  `old_stock` double NOT NULL DEFAULT 0,
  `new_stock` double NOT NULL DEFAULT 0,
  `action_date` datetime NOT NULL,
  PRIMARY KEY (`history_ID`),
  KEY `ingredients_ID` (`ingredients_ID`),
  KEY `user_ID` (`user_ID`),
  CONSTRAINT `inventory_history_ibfk_1` FOREIGN KEY (`ingredients_ID`) REFERENCES `ingredients` (`ingredients_ID`) ON DELETE CASCADE,
  CONSTRAINT `inventory_history_ibfk_2` FOREIGN KEY (`user_ID`) REFERENCES `accounts` (`user_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `product_history` (
  `history_ID` int NOT NULL AUTO_INCREMENT,
  `product_ID` int NOT NULL,
  `user_ID` int NOT NULL,
  `action` varchar(100) NOT NULL,
  `old_stock` int NOT NULL DEFAULT 0,
  `new_stock` int NOT NULL DEFAULT 0,
  `action_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_ID`),
  KEY `product_ID` (`product_ID`),
  KEY `user_ID` (`user_ID`),
  CONSTRAINT `product_history_ibfk_1` FOREIGN KEY (`product_ID`) REFERENCES `products` (`product_ID`) ON DELETE CASCADE,
  CONSTRAINT `product_history_ibfk_2` FOREIGN KEY (`user_ID`) REFERENCES `accounts` (`user_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `roles` (`role_ID`, `role`) VALUES
(1, 'Admin'),
(2, 'Moderator'),
(3, 'Branch'),
(4, 'Delivery');

-- Default admin login: admin / admin123 (change after first login)
INSERT IGNORE INTO `accounts` (`user_ID`, `username`, `password`, `name`, `Location`, `email`) VALUES
(1, 'admin', '$2y$10$XIkbXvRxOFHPCzn5iVWvveT4GD1TV2GEIc3S.NFYOSywOWhMS7CjG', 'Administrator', 'Main Office', 'admin@gabs.local');

INSERT IGNORE INTO `userroles` (`user_ID`, `role_ID`) VALUES (1, 1);
