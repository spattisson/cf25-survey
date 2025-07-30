<?php
// Load WordPress configuration to access database credentials
require_once('../wp-config.php');

// Get WordPress database connection
global $wpdb;

// Or create your own MySQLi connection using WP credentials
$db_connection = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db_connection->connect_error) {
    die("Connection failed: " . $db_connection->connect_error);
}

// Optional: Set charset to match WordPress
$db_connection->set_charset("utf8mb4");

// You can now use either:
// - $wpdb (WordPress database class)
// - $db_connection (raw MySQLi connection)
?>