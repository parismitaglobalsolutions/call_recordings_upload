-- Call Recording Compliance & Analytics System
-- Database Schema

CREATE DATABASE IF NOT EXISTS call_recording_db;
USE call_recording_db;

-- Admin users table for dashboard login
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin user (password: admin123)
INSERT INTO admin_users (username, password) VALUES
('admin', '$2y$10$rsmClYgM6tM1h83rqvoin.1zR4dzN4tnmpY40Ov..g4ILWNH6.3O.');

-- Users table (mobile app users)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
);

-- Calls table
CREATE TABLE IF NOT EXISTS calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL,
    call_id VARCHAR(100) NOT NULL,
    call_start_time DATETIME NOT NULL,
    call_duration INT NOT NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    sim_slot TINYINT DEFAULT 1,
    date DATE NOT NULL,
    is_compliant TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_call (user_id, call_id, date),
    INDEX idx_user_date (user_id, date),
    INDEX idx_date (date),
    INDEX idx_direction (direction)
);

-- Recordings table
CREATE TABLE IF NOT EXISTS recordings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id VARCHAR(100) NOT NULL,
    user_id VARCHAR(100) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    recording_start_time DATETIME NOT NULL,
    recording_duration INT NOT NULL,
    date DATE NOT NULL,
    matched_call_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_call_id (call_id),
    INDEX idx_user_date (user_id, date),
    INDEX idx_matched (matched_call_id)
);

-- Compliance results table
CREATE TABLE IF NOT EXISTS compliance_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL,
    date DATE NOT NULL,
    total_calls INT DEFAULT 0,
    recorded_calls INT DEFAULT 0,
    incoming_total INT DEFAULT 0,
    incoming_recorded INT DEFAULT 0,
    incoming_compliance DECIMAL(5,2) DEFAULT 0.00,
    outgoing_total INT DEFAULT 0,
    outgoing_recorded INT DEFAULT 0,
    outgoing_compliance DECIMAL(5,2) DEFAULT 0.00,
    overall_compliance DECIMAL(5,2) DEFAULT 0.00,
    status ENUM('green', 'yellow', 'red') DEFAULT 'red',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_date (user_id, date),
    INDEX idx_date (date),
    INDEX idx_status (status)
);

-- Talk time statistics table
CREATE TABLE IF NOT EXISTS talk_time_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL,
    date DATE NOT NULL,
    direction ENUM('incoming', 'outgoing') NOT NULL,
    total_duration INT DEFAULT 0,
    bucket_0_2 INT DEFAULT 0,
    bucket_2_5 INT DEFAULT 0,
    bucket_5_10 INT DEFAULT 0,
    bucket_10_plus INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_date_direction (user_id, date, direction),
    INDEX idx_user_date (user_id, date)
);

-- Upload logs table
CREATE TABLE IF NOT EXISTS upload_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL,
    date DATE NOT NULL,
    upload_time DATETIME NOT NULL,
    calls_count INT DEFAULT 0,
    recordings_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, date)
);

-- Raw data table for storing unprocessed JSON payloads
CREATE TABLE IF NOT EXISTS raw_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    json_data JSON NOT NULL,
    is_processed TINYINT DEFAULT 0,
    process_error TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX idx_is_processed (is_processed),
    INDEX idx_created_at (created_at)
);
