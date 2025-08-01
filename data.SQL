-- EV Charging System Database Schema
-- Created: 2025-08-02
-- Version: 1.0

CREATE DATABASE IF NOT EXISTS ev_charging_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ev_charging_system;

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    wallet_balance DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('active', 'suspended', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- Employees table
CREATE TABLE employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_code VARCHAR(20) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    position VARCHAR(100) NOT NULL,
    role ENUM('admin', 'manager', 'technician', 'support') NOT NULL,
    phone VARCHAR(20),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_code (employee_code),
    INDEX idx_role (role)
);

-- Vehicles table
CREATE TABLE vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    license_plate VARCHAR(20) NOT NULL,
    brand VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,
    battery_capacity DECIMAL(5,2) NOT NULL, -- kWh
    max_charging_power DECIMAL(5,2) DEFAULT 22.00, -- kW
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_license_plate (license_plate)
);

-- Charging stations table
CREATE TABLE charging_stations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    station_code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    location_name VARCHAR(255) NOT NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    max_power DECIMAL(5,2) NOT NULL, -- kW
    connector_type ENUM('Type1', 'Type2', 'CCS1', 'CCS2', 'CHAdeMO') NOT NULL,
    ocpp_endpoint VARCHAR(255),
    modbus_address VARCHAR(50),
    status ENUM('available', 'occupied', 'faulted', 'offline') DEFAULT 'available',
    last_heartbeat TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_station_code (station_code),
    INDEX idx_status (status),
    INDEX idx_location (latitude, longitude)
);

-- Solar system table
CREATE TABLE solar_systems (
    id INT PRIMARY KEY AUTO_INCREMENT,
    system_name VARCHAR(100) NOT NULL,
    inverter_model VARCHAR(100) DEFAULT 'SUN2000',
    inverter_ip VARCHAR(15) NOT NULL,
    modbus_port INT DEFAULT 502,
    modbus_unit_id INT DEFAULT 1,
    max_power DECIMAL(8,2) NOT NULL, -- kW
    battery_capacity DECIMAL(8,2) DEFAULT 0, -- kWh
    status ENUM('online', 'offline', 'error') DEFAULT 'offline',
    last_update TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
);

-- Electricity pricing table
CREATE TABLE electricity_pricing (
    id INT PRIMARY KEY AUTO_INCREMENT,
    source_type ENUM('pea', 'solar') NOT NULL,
    price_per_kwh DECIMAL(6,4) NOT NULL,
    time_of_use_start TIME NULL,
    time_of_use_end TIME NULL,
    effective_date DATE NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_source_type (source_type),
    INDEX idx_effective_date (effective_date)
);

-- Charging transactions table
CREATE TABLE charging_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_code VARCHAR(50) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    station_id INT NOT NULL,
    ocpp_transaction_id INT,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NULL,
    start_meter_value DECIMAL(10,3) DEFAULT 0, -- kWh
    end_meter_value DECIMAL(10,3) DEFAULT 0, -- kWh
    energy_delivered DECIMAL(10,3) DEFAULT 0, -- kWh
    max_power_delivered DECIMAL(5,2) DEFAULT 0, -- kW
    total_cost DECIMAL(10,2) DEFAULT 0,
    pea_cost DECIMAL(10,2) DEFAULT 0,
    solar_cost DECIMAL(10,2) DEFAULT 0,
    status ENUM('preparing', 'charging', 'suspended', 'finishing', 'completed', 'faulted') DEFAULT 'preparing',
    stop_reason ENUM('user', 'emergency', 'ev_disconnected', 'de_authorized', 'hard_reset', 'power_loss', 'other') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (station_id) REFERENCES charging_stations(id) ON DELETE CASCADE,
    INDEX idx_transaction_code (transaction_code),
    INDEX idx_user_id (user_id),
    INDEX idx_station_id (station_id),
    INDEX idx_status (status),
    INDEX idx_start_time (start_time)
);

-- Payment transactions table
CREATE TABLE payment_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    charging_transaction_id INT NULL,
    payment_type ENUM('topup', 'charging', 'refund') NOT NULL,
    payment_method ENUM('qr_promptpay', 'credit_card', 'bank_transfer', 'wallet') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    qr_code_data TEXT NULL,
    qr_image_url VARCHAR(255) NULL,
    reference_id VARCHAR(100) NULL,
    external_transaction_id VARCHAR(100) NULL,
    status ENUM('pending', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (charging_transaction_id) REFERENCES charging_transactions(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_charging_transaction_id (charging_transaction_id),
    INDEX idx_payment_type (payment_type),
    INDEX idx_status (status)
);

-- Real-time energy data table
CREATE TABLE energy_data (
    id INT PRIMARY KEY AUTO_INCREMENT,
    source_type ENUM('solar', 'grid', 'battery') NOT NULL,
    source_id INT NULL, -- solar_systems.id or charging_stations.id
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    power_generated DECIMAL(8,3) DEFAULT 0, -- kW
    power_consumed DECIMAL(8,3) DEFAULT 0, -- kW
    energy_total DECIMAL(12,3) DEFAULT 0, -- kWh
    battery_soc DECIMAL(5,2) DEFAULT 0, -- % State of Charge
    voltage DECIMAL(6,2) DEFAULT 0, -- V
    current DECIMAL(6,2) DEFAULT 0, -- A
    temperature DECIMAL(5,2) DEFAULT 0, -- °C
    INDEX idx_source (source_type, source_id),
    INDEX idx_timestamp (timestamp)
);

-- OCPP messages log table
CREATE TABLE ocpp_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    station_id INT NOT NULL,
    message_type ENUM('call', 'callresult', 'callerror') NOT NULL,
    action VARCHAR(50) NOT NULL,
    message_id VARCHAR(36) NOT NULL,
    payload JSON,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (station_id) REFERENCES charging_stations(id) ON DELETE CASCADE,
    INDEX idx_station_id (station_id),
    INDEX idx_message_type (message_type),
    INDEX idx_action (action),
    INDEX idx_timestamp (timestamp)
);

-- System logs table
CREATE TABLE system_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    log_level ENUM('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug') NOT NULL,
    category VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    context JSON NULL,
    user_id INT NULL,
    employee_id INT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    INDEX idx_log_level (log_level),
    INDEX idx_category (category),
    INDEX idx_user_id (user_id),
    INDEX idx_employee_id (employee_id),
    INDEX idx_created_at (created_at)
);

-- WebSocket sessions table
CREATE TABLE websocket_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(255) UNIQUE NOT NULL,
    user_type ENUM('admin', 'employee', 'user') NOT NULL,
    user_id INT NULL,
    employee_id INT NULL,
    connection_data JSON,
    last_ping TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session_id (session_id),
    INDEX idx_user_type (user_type),
    INDEX idx_last_ping (last_ping)
);

-- Notifications table
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    recipient_type ENUM('user', 'employee', 'admin', 'all') NOT NULL,
    recipient_id INT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    channels JSON, -- ['email', 'sms', 'push', 'websocket']
    is_read BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient_type, recipient_id),
    INDEX idx_is_read (is_read),
    INDEX idx_priority (priority)
);

-- Settings table
CREATE TABLE settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'float', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    is_public BOOLEAN DEFAULT FALSE,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES employees(id) ON DELETE SET NULL,
    INDEX idx_setting_key (setting_key),
    INDEX idx_is_public (is_public)
);

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, setting_type, description, is_public) VALUES
('system_name', 'EV Charging Management System', 'string', 'System name displayed in UI', true),
('default_pea_price', '4.50', 'float', 'Default PEA electricity price per kWh', false),
('default_solar_price', '3.80', 'float', 'Default solar electricity price per kWh', false),
('max_charging_time', '480', 'integer', 'Maximum charging time in minutes', false),
('session_timeout', '3600', 'integer', 'User session timeout in seconds', false),
('websocket_port', '8080', 'integer', 'WebSocket server port', false),
('ocpp_heartbeat_interval', '300', 'integer', 'OCPP heartbeat interval in seconds', false),
('modbus_timeout', '5', 'integer', 'Modbus communication timeout in seconds', false),
('enable_sms_notifications', 'false', 'boolean', 'Enable SMS notifications', false),
('enable_email_notifications', 'true', 'boolean', 'Enable email notifications', false);

-- Insert default electricity pricing
INSERT INTO electricity_pricing (source_type, price_per_kwh, effective_date, status) VALUES
('pea', 4.50, CURDATE(), 'active'),
('solar', 3.80, CURDATE(), 'active');

-- Insert sample admin user
INSERT INTO employees (employee_code, email, password_hash, first_name, last_name, position, role) VALUES
('ADMIN001', 'admin@evcharging.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', 'Administrator', 'System Administrator', 'admin');

-- Insert sample charging stations
INSERT INTO charging_stations (station_code, name, location_name, latitude, longitude, max_power, connector_type, status) VALUES
('ST001', 'Station 001', 'หน้าอาคาร A', 14.0583, 100.6000, 22.00, 'Type2', 'available'),
('ST002', 'Station 002', 'หน้าอาคาร B', 14.0584, 100.6001, 22.00, 'Type2', 'available'),
('ST003', 'Station 003', 'หน้าอาคาร C', 14.0585, 100.6002, 50.00, 'CCS2', 'available'),
('ST004', 'Station 004', 'ลานจอดรถ D', 14.0586, 100.6003, 22.00, 'Type2', 'offline'),
('ST005', 'Station 005', 'ลานจอดรถ E', 14.0587, 100.6004, 22.00, 'Type2', 'available'),
('ST006', 'Station 006', 'ลานจอดรถ F', 14.0588, 100.6005, 50.00, 'CCS2', 'available');

-- Insert sample solar system
INSERT INTO solar_systems (system_name, inverter_model, inverter_ip, max_power, battery_capacity) VALUES
('Main Solar Array', 'SUN2000-30KTL-M3', '192.168.1.100', 30.00, 50.00);

-- Create views for reporting
CREATE VIEW v_station_status AS
SELECT 
    cs.id,
    cs.station_code,
    cs.name,
    cs.status,
    cs.max_power,
    CASE 
        WHEN ct.id IS NOT NULL THEN 'occupied'
        ELSE cs.status
    END as current_status,
    ct.user_id as current_user_id,
    u.first_name as current_user_name,
    ct.start_time as charging_start_time,
    TIMESTAMPDIFF(MINUTE, ct.start_time, NOW()) as charging_duration_minutes
FROM charging_stations cs
LEFT JOIN charging_transactions ct ON cs.id = ct.station_id 
    AND ct.status IN ('preparing', 'charging', 'suspended')
LEFT JOIN users u ON ct.user_id = u.id;

CREATE VIEW v_daily_statistics AS
SELECT 
    DATE(ct.start_time) as date_stat,
    COUNT(*) as total_sessions,
    SUM(ct.energy_delivered) as total_energy_kwh,
    AVG(ct.energy_delivered) as avg_energy_per_session,
    SUM(ct.total_cost) as total_revenue,
    COUNT(DISTINCT ct.user_id) as unique_users,
    AVG(TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time)) as avg_session_duration_minutes
FROM charging_transactions ct
WHERE ct.status = 'completed'
    AND ct.start_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY DATE(ct.start_time)
ORDER BY date_stat DESC;

CREATE VIEW v_user_statistics AS
SELECT 
    u.id,
    u.email,
    u.first_name,
    u.last_name,
    u.wallet_balance,
    COUNT(ct.id) as total_sessions,
    SUM(ct.energy_delivered) as total_energy_consumed,
    SUM(ct.total_cost) as total_spent,
    MAX(ct.start_time) as last_charging_session,
    COUNT(v.id) as registered_vehicles
FROM users u
LEFT JOIN charging_transactions ct ON u.id = ct.user_id AND ct.status = 'completed'
LEFT JOIN vehicles v ON u.id = v.user_id AND v.status = 'active'
GROUP BY u.id, u.email, u.first_name, u.last_name, u.wallet_balance
ORDER BY total_spent DESC;

-- Create indexes for performance
CREATE INDEX idx_charging_transactions_composite ON charging_transactions(status, start_time, station_id);
CREATE INDEX idx_energy_data_composite ON energy_data(source_type, timestamp, source_id);
CREATE INDEX idx_payment_transactions_composite ON payment_transactions(status, payment_type, created_at);

-- Create triggers for logging
DELIMITER //

CREATE TRIGGER tr_user_balance_update
    AFTER UPDATE ON users
    FOR EACH ROW
BEGIN
    IF OLD.wallet_balance != NEW.wallet_balance THEN
        INSERT INTO system_logs (log_level, category, message, context, user_id)
        VALUES ('info', '
