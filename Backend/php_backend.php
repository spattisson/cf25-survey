<?php
/**
 * CF25 Survey Backend API - Version 1.3 FIXED
 * 
 * Fixed issues:
 * - Better error handling and logging
 * - Proper WordPress integration
 * - Admin password management
 * - Database table creation
 */

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set proper headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Update this to your domain in production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Try to load WordPress - with better error handling
$wp_config_paths = [
    '../wp-config.php',           // If in subdirectory of WP root
    '../../wp-config.php',        // If in deeper subdirectory
    '../../../wp-config.php',     // If even deeper
    dirname(__FILE__) . '/../wp-config.php',
    dirname(__FILE__) . '/../../wp-config.php',
    dirname(__FILE__) . '/../../../wp-config.php'
];

$wp_loaded = false;
foreach ($wp_config_paths as $path) {
    if (file_exists($path)) {
        try {
            require_once($path);
            
            // Also try to load wp-load.php for full WordPress functionality
            $wp_load_path = str_replace('wp-config.php', 'wp-load.php', $path);
            if (file_exists($wp_load_path)) {
                require_once($wp_load_path);
            }
            
            $wp_loaded = true;
            error_log("CF25 Survey: Successfully loaded WordPress from: " . $path);
            break;
        } catch (Exception $e) {
            error_log("CF25 Survey: Failed to load WordPress from $path: " . $e->getMessage());
            continue;
        }
    }
}

if (!$wp_loaded) {
    error_log("CF25 Survey: Could not find WordPress installation");
    // Fall back to direct database configuration
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'your_database_name'); // UPDATE THIS
    define('DB_USER', 'your_username');      // UPDATE THIS
    define('DB_PASSWORD', 'your_password');  // UPDATE THIS
}

// Database configuration using WordPress credentials or fallback
class CF25_Database {
    private $conn;
    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function connect() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASSWORD,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            // Create tables if they don't exist
            $this->createTables();
            
            error_log("CF25 Survey: Database connected successfully");
        } catch(PDOException $e) {
            error_log("CF25 Survey Database Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
        return $this->conn;
    }

    private function createTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS survey_responses (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(50) NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS survey_ratings (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            response_id BIGINT NOT NULL,
            question TEXT NOT NULL,
            rating TINYINT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
            INDEX idx_response_id (response_id),
            INDEX idx_rating (rating),
            CHECK (rating >= 0 AND rating <= 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS survey_feedback (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            response_id BIGINT NOT NULL,
            question TEXT NOT NULL,
            answer TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
            INDEX idx_response_id (response_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $statements = explode(';', $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $this->conn->exec($statement);
                } catch (PDOException $e) {
                    error_log("CF25 Survey Table Creation Error: " . $e->getMessage());
                    // Continue even if table creation fails (might already exist)
                }
            }
        }
        
        error_log("CF25 Survey: Database tables checked/created");
    }
}

// Survey Response Handler
class CF25_SurveyAPI {
    private $conn;
    private $wpdb;

    public function __construct($db) {
        $this->conn = $db;
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Ensure admin password is set
        $this->ensureAdminPassword();
    }

    private function ensureAdminPassword() {
        // Check if admin password exists, if not create it
        $password_exists = false;
        
        if ($this->wpdb) {
            $stored_hash = $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", 'cf25_admin_password')
            );
            $password_exists = !empty($stored_hash);
        }

        if (!$password_exists) {
            // Set default password: CarWashBoys!
            $default_password = 'CarWashBoys!';
            $hash = password_hash($default_password, PASSWORD_DEFAULT);
            
            if ($this->wpdb) {
                $this->wpdb->insert(
                    $this->wpdb->options,
                    [
                        'option_name' => 'cf25_admin_password',
                        'option_value' => $hash,
                        'autoload' => 'no'
                    ]
                );
                error_log("CF25 Survey: Default admin password set");
            }
        }
    }

    // Submit a new survey response
    public function submitSurvey($data) {
        try {
            // Validate input
            if (empty($data['category'])) {
                throw new Exception('Category is required');
            }

            $this->conn->beginTransaction();

            // Insert main response
            $stmt = $this->conn->prepare("
                INSERT INTO survey_responses (category, timestamp) 
                VALUES (?, ?)
            ");
            $stmt->execute([
                sanitize_text_field($data['category']),
                date('Y-m-d H:i:s')
            ]);
            
            $responseId = $this->conn->lastInsertId();

            // Insert ratings
            if (!empty($data['ratings']) && is_array($data['ratings'])) {
                $stmt = $this->conn->prepare("
                    INSERT INTO survey_ratings (response_id, question, rating) 
                    VALUES (?, ?, ?)
                ");
                foreach ($data['ratings'] as $question => $rating) {
                    $rating = intval($rating);
                    if ($rating >= 0 && $rating <= 5) {
                        $stmt->execute([$responseId, sanitize_text_field($question), $rating]);
                    }
                }
            }

            // Insert feedback
            if (!empty($data['feedback']) && is_array($data['feedback'])) {
                $stmt = $this->conn->prepare("
                    INSERT INTO survey_feedback (response_id, question, answer) 
                    VALUES (?, ?, ?)
                ");
                foreach ($data['feedback'] as $question => $answer) {
                    $answer = trim($answer);
                    if (!empty($answer)) {
                        $stmt->execute([
                            $responseId, 
                            sanitize_text_field($question), 
                            sanitize_textarea_field($answer)
                        ]);
                    }
                }
            }

            $this->conn->commit();
            error_log("CF25 Survey: Survey submitted successfully, ID: " . $responseId);
            return ['success' => true, 'id' => $responseId];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("CF25 Survey Submit Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to submit survey: ' . $e->getMessage()];
        }
    }

    // Get all survey data
    public function getAllSurveys() {
        try {
            // Get all responses
            $stmt = $this->conn->prepare("
                SELECT id, category, timestamp 
                FROM survey_responses 
                ORDER BY timestamp DESC
            ");
            $stmt->execute();
            $responses = $stmt->fetchAll();

            $surveyData = [];
            foreach ($responses as $response) {
                // Get ratings for this response
                $stmt = $this->conn->prepare("
                    SELECT question, rating 
                    FROM survey_ratings 
                    WHERE response_id = ?
                ");
                $stmt->execute([$response['id']]);
                $ratings = [];
                while ($row = $stmt->fetch()) {
                    $ratings[$row['question']] = (int)$row['rating'];
                }

                // Get feedback for this response
                $stmt = $this->conn->prepare("
                    SELECT question, answer 
                    FROM survey_feedback 
                    WHERE response_id = ?
                ");
                $stmt->execute([$response['id']]);
                $feedback = [];
                while ($row = $stmt->fetch()) {
                    $feedback[$row['question']] = $row['answer'];
                }

                $surveyData[] = [
                    'id' => (int)$response['id'],
                    'category' => $response['category'],
                    'timestamp' => $response['timestamp'],
                    'ratings' => $ratings,
                    'feedback' => $feedback
                ];
            }

            error_log("CF25 Survey: Retrieved " . count($surveyData) . " survey responses");
            return ['success' => true, 'data' => $surveyData];

        } catch (Exception $e) {
            error_log("CF25 Survey Get All Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to retrieve surveys: ' . $e->getMessage()];
        }
    }

    // Get summary statistics
    public function getSummaryStats() {
        try {
            $stats = [];

            // Total responses
            $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM survey_responses");
            $stmt->execute();
            $stats['totalResponses'] = (int)$stmt->fetch()['total'];

            // Average satisfaction
            $stmt = $this->conn->prepare("
                SELECT AVG(rating) as avg_rating, COUNT(*) as rating_count 
                FROM survey_ratings 
                WHERE rating > 0
            ");
            $stmt->execute();
            $ratingData = $stmt->fetch();
            $stats['avgSatisfaction'] = $ratingData['avg_rating'] ? round($ratingData['avg_rating'], 1) : 0;
            $stats['totalRatings'] = (int)$ratingData['rating_count'];

            // Recommendation rate (4-5 star ratings)
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as high_ratings 
                FROM survey_ratings 
                WHERE rating >= 4
            ");
            $stmt->execute();
            $highRatings = (int)$stmt->fetch()['high_ratings'];
            $stats['recommendationRate'] = $stats['totalRatings'] > 0 ? 
                round(($highRatings / $stats['totalRatings']) * 100) : 0;

            // Category breakdown
            $stmt = $this->conn->prepare("
                SELECT category, COUNT(*) as count 
                FROM survey_responses 
                GROUP BY category
            ");
            $stmt->execute();
            $stats['categoryBreakdown'] = $stmt->fetchAll();

            // Data size estimate
            $stmt = $this->conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM survey_responses) * 100 +
                    (SELECT COUNT(*) FROM survey_ratings) * 50 +
                    (SELECT COUNT(*) FROM survey_feedback) * 200 
                as estimated_size_bytes
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            $stats['dataSizeKB'] = round(($result['estimated_size_bytes'] ?? 0) / 1024, 2);

            error_log("CF25 Survey: Stats retrieved - " . $stats['totalResponses'] . " responses");
            return ['success' => true, 'stats' => $stats];

        } catch (Exception $e) {
            error_log("CF25 Survey Stats Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to get statistics: ' . $e->getMessage()];
        }
    }

    // Validate admin password
    public function validateAdmin($password) {
        try {
            return $this->verifyAdminPassword($password);
        } catch (Exception $e) {
            error_log("CF25 Survey Admin Validation Error: " . $e->getMessage());
            return false;
        }
    }

    private function verifyAdminPassword($input_password) {
        if (empty($input_password)) {
            return false;
        }

        $stored_hash = null;
        
        // Try to get from WordPress options first
        if ($this->wpdb) {
            $stored_hash = $this->wpdb->get_var(
                $this->wpdb->prepare("SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", 'cf25_admin_password')
            );
        }

        if (empty($stored_hash)) {
            error_log('CF25 Survey: Admin password not found in database');
            return false;
        }

        $is_valid = password_verify($input_password, $stored_hash);
        error_log("CF25 Survey: Admin password validation " . ($is_valid ? "successful" : "failed"));
        return $is_valid;
    }

    // Reset all data (admin only)
    public function resetAllData($password) {
        try {
            if (!$this->validateAdmin($password)) {
                error_log("CF25 Survey: Unauthorized reset attempt");
                return ['success' => false, 'error' => 'Invalid admin password'];
            }

            $this->conn->beginTransaction();

            // Delete in reverse order due to foreign key constraints
            $this->conn->exec("DELETE FROM survey_feedback");
            $this->conn->exec("DELETE FROM survey_ratings");
            $this->conn->exec("DELETE FROM survey_responses");

            // Reset auto-increment
            $this->conn->exec("ALTER TABLE survey_responses AUTO_INCREMENT = 1");
            $this->conn->exec("ALTER TABLE survey_ratings AUTO_INCREMENT = 1");
            $this->conn->exec("ALTER TABLE survey_feedback AUTO_INCREMENT = 1");

            $this->conn->commit();
            error_log("CF25 Survey: All data reset successfully");
            return ['success' => true, 'message' => 'All data reset successfully'];

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("CF25 Survey Reset Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to reset data: ' . $e->getMessage()];
        }
    }

    // Change admin password
    public function changeAdminPassword($currentPassword, $newPassword) {
        try {
            if (!$this->validateAdmin($currentPassword)) {
                return ['success' => false, 'error' => 'Current password is incorrect'];
            }

            if (strlen($newPassword) < 6) {
                return ['success' => false, 'error' => 'New password must be at least 6 characters'];
            }

            $new_hash = password_hash($newPassword, PASSWORD_DEFAULT);

            if ($this->wpdb) {
                $updated = $this->wpdb->update(
                    $this->wpdb->options,
                    ['option_value' => $new_hash],
                    ['option_name' => 'cf25_admin_password'],
                    ['%s'],
                    ['%s']
                );

                if ($updated === false) {
                    throw new Exception('Failed to update password in database');
                }
            } else {
                throw new Exception('WordPress database not available');
            }

            error_log("CF25 Survey: Admin password changed successfully");
            return ['success' => true, 'message' => 'Password changed successfully'];
        } catch (Exception $e) {
            error_log("CF25 Survey Change Password Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to change password: ' . $e->getMessage()];
        }
    }
}

// Helper function for sanitization when WordPress functions aren't available
function sanitize_text_field($text) {
    if (function_exists('sanitize_text_field')) {
        return \sanitize_text_field($text);
    }
    return trim(strip_tags($text));
}

function sanitize_textarea_field($text) {
    if (function_exists('sanitize_textarea_field')) {
        return \sanitize_textarea_field($text);
    }
    return trim(strip_tags($text));
}

// Main API routing
try {
    $database = new CF25_Database();
    $db = $database->connect();
    
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $api = new CF25_SurveyAPI($db);
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Log the incoming request for debugging
    error_log("CF25 Survey API Request: " . $method . " - Action: " . ($_GET['action'] ?? 'none'));
    
    // Get action from URL parameter or POST data
    $action = $_GET['action'] ?? $input['action'] ?? '';
    
    $result = null;
    
    switch ($method . ':' . $action) {
        case 'POST:submit':
            $result = $api->submitSurvey($input);
            break;
            
        case 'GET:surveys':
            $result = $api->getAllSurveys();
            break;
            
        case 'GET:stats':
            $result = $api->getSummaryStats();
            break;
            
        case 'POST:validate_admin':
            $result = ['success' => $api->validateAdmin($input['password'] ?? '')];
            break;
            
        case 'POST:reset_data':
            $result = $api->resetAllData($input['password'] ?? '');
            break;
            
        case 'POST:change_password':
            $result = $api->changeAdminPassword(
                $input['current_password'] ?? '', 
                $input['new_password'] ?? ''
            );
            break;
            
        default:
            $result = ['success' => false, 'error' => 'Invalid action: ' . $action];
            http_response_code(400);
            error_log("CF25 Survey: Invalid action requested: " . $action);
    }
    
    echo json_encode($result);

} catch (Exception $e) {
    error_log("CF25 Survey API Fatal Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>
