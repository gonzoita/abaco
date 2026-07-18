-- Tablas para el Módulo de Control de Préstamos en Ábaco
USE control_finanzas;

-- 1. Tabla de Clientes de Préstamos
CREATE TABLE IF NOT EXISTS loan_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    document VARCHAR(50) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    email VARCHAR(100) NULL,
    address VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Tabla de Préstamos
CREATE TABLE IF NOT EXISTS loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    client_id INT NOT NULL,
    principal DECIMAL(15, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) NOT NULL,
    rate_type ENUM('mensual', 'periodo', 'anual') DEFAULT 'mensual',
    installments_count INT NOT NULL,
    frequency ENUM('diario', 'semanal', 'quincenal', 'mensual') NOT NULL,
    method ENUM('frances', 'aleman', 'americano', 'simple') NOT NULL,
    start_date DATE NOT NULL,
    status ENUM('activo', 'finalizado', 'vencido') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES loan_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Tabla de Cuotas (Plan de Amortización)
CREATE TABLE IF NOT EXISTS loan_installments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    number INT NOT NULL,
    date DATE NOT NULL,
    installment DECIMAL(15, 2) NOT NULL,
    interest DECIMAL(15, 2) NOT NULL,
    principal_paid DECIMAL(15, 2) NOT NULL,
    remaining_balance DECIMAL(15, 2) NOT NULL,
    status ENUM('pendiente', 'pagado', 'parcial') DEFAULT 'pendiente',
    paid_amount DECIMAL(15, 2) DEFAULT 0.00,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Tabla de Transacciones / Recaudos de Cuotas
CREATE TABLE IF NOT EXISTS loan_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    installment_number INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    date DATE NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB;
