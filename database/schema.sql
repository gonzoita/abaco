-- Base de datos para la Plataforma de Finanzas Personales SaaS
CREATE DATABASE IF NOT EXISTS control_finanzas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE control_finanzas;

-- Tabla de Usuarios
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    currency VARCHAR(10) DEFAULT 'COP',
    subscription_status VARCHAR(20) DEFAULT 'trial', -- trial, active, expired
    subscription_expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabla de Cuentas (Efectivo, Banco, Tarjetas de Crédito)
CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('efectivo', 'banco', 'tarjeta_credito', 'otro') NOT NULL,
    balance DECIMAL(15, 2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'COP',
    credit_limit DECIMAL(15, 2) DEFAULT 0.00, -- Límite para TC
    billing_day INT DEFAULT NULL, -- Día de corte para TC (1-31)
    due_day INT DEFAULT NULL, -- Día de pago para TC (1-31)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla de Categorías
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, -- NULL indica que es una categoría global por defecto
    name VARCHAR(50) NOT NULL,
    icon VARCHAR(50) DEFAULT 'tag', -- Nombre del icono (ej: 'shopping-cart', 'coffee')
    color VARCHAR(20) DEFAULT '#cccccc', -- Color hexadecimal para UI
    type ENUM('ingreso', 'egreso') NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla de Pagos Recurrentes / Suscripciones / Servicios
CREATE TABLE IF NOT EXISTS recurrences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NOT NULL,
    category_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    description VARCHAR(255) NULL,
    frequency ENUM('diario', 'semanal', 'quincenal', 'mensual', 'anual') NOT NULL,
    start_date DATE NOT NULL,
    next_due_date DATE NOT NULL,
    active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla de Transacciones (Ingresos, Egresos, Transferencias)
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NOT NULL,
    category_id INT NULL,
    type ENUM('ingreso', 'egreso', 'transferencia') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    description VARCHAR(255) NULL,
    date DATE NOT NULL,
    receipt_url VARCHAR(255) NULL, -- URL de la imagen del ticket escaneado
    recurrence_id INT NULL, -- Enlace si fue generado automáticamente
    installments_total INT DEFAULT 1, -- Para compras con TC (total de cuotas)
    installments_current INT DEFAULT 1, -- Cuota actual
    transfer_to_account_id INT NULL, -- Si es transferencia, cuenta destino
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (recurrence_id) REFERENCES recurrences(id) ON DELETE SET NULL,
    FOREIGN KEY (transfer_to_account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabla de Metas de Ahorro
CREATE TABLE IF NOT EXISTS savings_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    target_amount DECIMAL(15, 2) NOT NULL,
    current_amount DECIMAL(15, 2) DEFAULT 0.00,
    target_date DATE NULL,
    account_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabla de Presupuestos
CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    month INT NOT NULL,
    year INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla de Recordatorios
CREATE TABLE IF NOT EXISTS reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    due_date DATE NOT NULL,
    status ENUM('pendiente', 'completado') DEFAULT 'pendiente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Insertar categorías globales por defecto
INSERT INTO categories (user_id, name, icon, color, type) VALUES
(NULL, 'Salario', 'briefcase', '#10B981', 'ingreso'),
(NULL, 'Inversiones', 'trending-up', '#059669', 'ingreso'),
(NULL, 'Otros Ingresos', 'plus-circle', '#34D399', 'ingreso'),
(NULL, 'Alimentación', 'coffee', '#EF4444', 'egreso'),
(NULL, 'Vivienda', 'home', '#F59E0B', 'egreso'),
(NULL, 'Transporte', 'truck', '#3B82F6', 'egreso'),
(NULL, 'Salud', 'activity', '#EC4899', 'egreso'),
(NULL, 'Entretenimiento', 'film', '#8B5CF6', 'egreso'),
(NULL, 'Servicios Públicos', 'zap', '#6366F1', 'egreso'),
(NULL, 'Educación', 'book-open', '#14B8A6', 'egreso'),
(NULL, 'Compras', 'shopping-bag', '#6B7280', 'egreso');
