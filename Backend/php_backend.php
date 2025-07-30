<?php
// verson 1.1
// Load WordPress configuration to access database credentials
require_once('../wp-config.php');

// Load WordPress functions so $wpdb is available
require_once('../wp-load.php');

// Get WordPress database connection
global $wpdb;

// api.php - Main API endpoint for CF25 Survey
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Update this to your domain in production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


// Database configuration using WordPress credentials
class Database {
    private $conn;

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
        } catch(PDOException $e) {
            error_log("Connection Error: " . $e->getMessage());
        }
        return $this->conn;
    }
}

// Survey Response Handler
class SurveyAPI {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Submit a new survey response
    public function submitSurvey($data) {
        try {
            $this->conn->beginTransaction();

            // Insert main response
            $stmt = $this->conn->prepare("
                INSERT INTO survey_responses (category, timestamp) 
                VALUES (?, ?)
            ");
            $stmt->execute([
                $data['category'],
                $data['timestamp']
            ]);
            
            $responseId = $this->conn->lastInsertId();

            // Insert ratings
            if (!empty($data['ratings'])) {
                $stmt = $this->conn->prepare("
                    INSERT INTO survey_ratings (response_id, question, rating) 
                    VALUES (?, ?, ?)
                ");
                foreach ($data['ratings'] as $question => $rating) {
                    $stmt->execute([$responseId, $question, $rating]);
                }
            }

            // Insert feedback
            if (!empty($data['feedback'])) {
                $stmt = $this->conn->prepare("
                    INSERT INTO survey_feedback (response_id, question, answer) 
                    VALUES (?, ?, ?)
                ");
                foreach ($data['feedback'] as $question => $answer) {
                    if (!empty(trim($answer))) {
                        $stmt->execute([$responseId, $question, $answer]);
                    }
                }
            }

            $this->conn->commit();
            return ['success' => true, 'id' => $responseId];

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Submit Survey Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to submit survey'];
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

            return ['success' => true, 'data' => $surveyData];

        } catch (Exception $e) {
            error_log("Get All Surveys Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to retrieve surveys'];
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
            $stats['dataSizeKB'] = round($stmt->fetch()['estimated_size_bytes'] / 1024, 2);

            return ['success' => true, 'stats' => $stats];

        } catch (Exception $e) {
            error_log("Get Summary Stats Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to get statistics'];
        }
    }

    // Validate admin password
    public function validateAdmin($password) {
        return $this->verifyAdminPassword($password);
    }

    private function verifyAdminPassword($input_password) {
        global $wpdb;

        // Get the hashed password from WordPress options
        $stored_hash = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'myapp_admin_password')
        );

        if (!$stored_hash) {
            error_log('Admin password not found in database');
            return false;
        }

        return password_verify($input_password, $stored_hash);
    }

    // Reset all data (admin only)
    public function resetAllData($password) {
        if (!$this->validateAdmin($password)) {
            return ['success' => false, 'error' => 'Invalid admin password'];
        }

        try {
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
            return ['success' => true, 'message' => 'All data reset successfully'];

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Reset Data Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to reset data'];
        }
    }

    // Change admin password
    public function changeAdminPassword($currentPassword, $newPassword) {
        if (!$this->validateAdmin($currentPassword)) {
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'New password must be at least 6 characters'];
        }

        try {
                global $wpdb;
                $new_hash = password_hash($newPassword, PASSWORD_DEFAULT);

                $updated = $wpdb->update(
                    $wpdb->options,
                    ['option_value' => $new_hash],
                    ['option_name' => 'myapp_admin_password'],
                    ['%s'],
                    ['%s']
                );

                if ($updated === false) {
                    return ['success' => false, 'error' => 'Failed to update password'];
                }

                return ['success' => true, 'message' => 'Password changed successfully'];
            } catch (Exception $e) {
                error_log("Change Password Error: " . $e->getMessage());
                return ['success' => false, 'error' => 'Failed to change password'];
            }
    }
}

// Main API routing
try {
    $database = new Database();
    $db = $database->connect();
    
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $api = new SurveyAPI($db);
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Get action from URL parameter or POST data
    $action = $_GET['action'] ?? $input['action'] ?? '';
    
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
            $result = ['success' => $api->validateAdmin($input['password'])];
            break;
            
        case 'POST:reset_data':
            $result = $api->resetAllData($input['password']);
            break;
            
        case 'POST:change_password':
            $result = $api->changeAdminPassword($input['current_password'], $input['new_password']);
            break;
            
        default:
            $result = ['success' => false, 'error' => 'Invalid action'];
            http_response_code(400);
    }
    
    echo json_encode($result);

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>