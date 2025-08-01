--------------------------------
-- CF25 Survey Database Setup
-- v1.1
--------------------------------
-- Create database (run this first)
CREATE DATABASE IF NOT EXISTS cf25_survey CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cf25_survey;

-- Table for storing survey responses
CREATE TABLE survey_responses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_timestamp (timestamp)
);

-- Table for storing rating responses
CREATE TABLE survey_ratings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT NOT NULL,
    question TEXT NOT NULL,
    rating TINYINT NOT NULL CHECK (rating >= 0 AND rating <= 5),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
    INDEX idx_response_id (response_id),
    INDEX idx_rating (rating)
);

-- Table for storing feedback text responses
CREATE TABLE survey_feedback (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
    INDEX idx_response_id (response_id)
);

-- Table for admin settings (optional - for storing admin password hash)
-- NOT USED
CREATE TABLE admin_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- NOT USED
-- Insert default admin password hash (you should change this)
-- This is a hash of 'CarWashBoys!' - you should update it
INSERT INTO admin_settings (setting_key, setting_value) VALUES 
('admin_password_hash', '$2y$10$example.hash.here');

-- NOT USED
-- Create a user for the application (recommended for security)
-- Replace 'your_password' with a strong password
CREATE USER IF NOT EXISTS 'cf25_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON cf25_survey.* TO 'cf25_user'@'localhost';
FLUSH PRIVILEGES;

-- NOT USED
-- Sample view for easy data retrieval
CREATE VIEW survey_summary AS
SELECT 
    sr.id,
    sr.category,
    sr.timestamp,
    COUNT(sra.id) as total_ratings,
    AVG(sra.rating) as avg_rating,
    COUNT(sf.id) as total_feedback_items
FROM survey_responses sr
LEFT JOIN survey_ratings sra ON sr.id = sra.response_id
LEFT JOIN survey_feedback sf ON sr.id = sf.response_id
GROUP BY sr.id, sr.category, sr.timestamp
ORDER BY sr.timestamp DESC;