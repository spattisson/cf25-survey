<?php
// FILE NO LONGER NEEDED - QUEUED FOR DELETION
// config.php - Database configuration
// Copy this file and update with your actual database credentials

class DatabaseConfig {
    // Database credentials - UPDATE THESE
    const DB_HOST = 'localhost';           // Your MySQL server host
    const DB_NAME = 'cf25_survey';         // Database name
    const DB_USER = 'cf25_user';           // Database username
    const DB_PASS = 'your_secure_password'; // Database password
    
    // Security settings
    const ADMIN_PASSWORD = 'CarWashBoys!';  // Initial admin password
    const CORS_ORIGIN = 'https://spattisson.github.io'; // Your website domain
    
    // Optional: Enable/disable features
    const ENABLE_LOGGING = true;           // Enable error logging
    const LOG_FILE = 'survey_errors.log';  // Log file path
    
    public static function getConnection() {
        try {
            $pdo = new PDO(
                "mysql:host=" . self::DB_HOST . ";dbname=" . self::DB_NAME . ";charset=utf8mb4",
                self::DB_USER,
                self::DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            return $pdo;
        } catch(PDOException $e) {
            if (self::ENABLE_LOGGING) {
                error_log("Database Connection Error: " . $e->getMessage(), 3, self::LOG_FILE);
            }
            throw new Exception("Database connection failed");
        }
    }
}
?>