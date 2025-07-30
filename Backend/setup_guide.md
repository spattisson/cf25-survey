# CF25 Survey Setup Guide

## Overview
This guide will help you set up the CF25 Survey system with MySQL database backend to replace the current in-memory storage solution.

## Prerequisites
- Web server with PHP 7.4+ support
- MySQL 5.7+ or MariaDB 10.2+
- Basic knowledge of FTP/SFTP for file upload

## Step 1: Database Setup

### 1.1 Create the Database
Run the provided SQL script to create your database:

```bash
mysql -u root -p < cf25_survey_setup.sql
```

Or execute the SQL commands directly in phpMyAdmin/MySQL Workbench.

### 1.2 Update Database Credentials
Edit the `config.php` file with your actual database credentials:

```php
const DB_HOST = 'your_mysql_host';     // Usually 'localhost'
const DB_NAME = 'cf25_survey';
const DB_USER = 'cf25_user';           // Or your MySQL username
const DB_PASS = 'your_secure_password';
```

## Step 2: Server File Structure

Upload these files to your web server:

```
your-domain.com/
├── cf25-survey/
│   ├── index.html          (Updated frontend)
│   ├── api.php             (Backend API)
│   ├── config.php          (Database config)
│   └── .htaccess           (Optional - for clean URLs)
```

## Step 3: Frontend Configuration

### 3.1 Update API Endpoint
In your `index.html`, update the API base URL:

```javascript
const API_BASE_URL = '/cf25-survey/api.php'; // Update this path
```

If your API is on a different domain:
```javascript
const API_BASE_URL = 'https://your-api-domain.com/api.php';
```

### 3.2 CORS Configuration
Update the CORS origin in `api.php`:

```php
header('Access-Control-Allow-Origin: https://spattisson.github.io');
```

## Step 4: Security Considerations

### 4.1 Database User Permissions
Create a dedicated database user with minimal permissions:

```sql
CREATE USER 'cf25_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT SELECT, INSERT, UPDATE, DELETE ON cf25_survey.* TO 'cf25_user'@'localhost';
FLUSH PRIVILEGES;
```

### 4.2 Admin Password Security
For production, consider storing the admin password hash in the database:

```sql
-- Generate hash in PHP: password_hash('your_password', PASSWORD_DEFAULT)
UPDATE admin_settings 
SET setting_value = '$2y$10$your_hash_here' 
WHERE setting_key = 'admin_password_hash';
```

### 4.3 File Security
Create `.htaccess` file to protect sensitive files:

```apache
# Deny access to config files
<Files "config.php">
    Order Allow,Deny
    Deny from all
</Files>

# Deny access to log files
<Files "*.log">
    Order Allow,Deny
    Deny from all
</Files>
```

## Step 5: Testing the Setup

### 5.1 Test Database Connection
Create a simple test file:

```php
<?php
require_once 'config.php';
try {
    $db = DatabaseConfig::getConnection();
    echo "Database connection successful!";
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>
```

### 5.2 Test API Endpoints
Use a tool like Postman or curl to test:

```bash
# Test survey submission
curl -X POST -H "Content-Type: application/json" \
  -d '{"action":"submit","category":"first-time","timestamp":"2024-01-01T10:00:00Z","ratings":{"test":5},"feedback":{"test":"Great!"}}' \
  https://your-domain.com/cf25-survey/api.php?action=submit

# Test data retrieval
curl https://your-domain.com/cf25-survey/api.php?action=surveys
```

## Step 6: Migration from Current System

### 6.1 Export Existing Data
If you have existing survey data in the current system:
1. Use the "Export All Data" function
2. Save the JSON file

### 6.2 Import to Database
Create a simple import script:

```php
<?php
require_once 'config.php';

$jsonData = file_get_contents('exported_survey_data.json');
$surveys = json_decode($jsonData, true);

$db = DatabaseConfig::getConnection();

foreach ($surveys as $survey) {
    // Insert into database using the same logic as the API
    // ... implementation details
}
?>
```

## Step 7: Admin Functions

### 7.1 Reset Data Function
The reset function will:
- Delete all survey responses
- Delete all ratings
- Delete all feedback
- Reset auto-increment counters

### 7.2 Export Functions
Two export options available:
- **Raw Data Export**: Complete JSON dump of all survey data
- **Summary Report**: Formatted text report with statistics

### 7.3 Password Management
Admin can change password through the interface, but you may also want to set up:
- Password recovery mechanism
- Multiple admin users
- Session timeouts

## Step 8: Maintenance

### 8.1 Regular Backups
Set up automated backups:

```bash
# Daily backup script
mysqldump -u cf25_user -p cf25_survey > cf25_backup_$(date +%Y%m%d).sql
```

### 8.2 Log Monitoring
Monitor the error log file for issues:

```bash
tail -f survey_errors.log
```

### 8.3 Database Optimization
Regularly optimize your database:

```sql
OPTIMIZE TABLE survey_responses, survey_ratings, survey_feedback;
```

## Troubleshooting

### Common Issues

1. **Connection Failed**
   - Check database credentials
   - Verify MySQL service is running
   - Check firewall settings

2. **CORS Errors**
   - Update CORS origin in api.php
   - Check browser developer console

3. **Permission Denied**
   - Check file permissions (755 for directories, 644 for files)
   - Verify database user permissions

4. **Charts Not Loading**
   - Check browser console for JavaScript errors
   - Verify Chart.js is loading correctly

### Debug Mode
Enable debug mode in api.php for detailed error messages:

```php
// Add at top of api.php for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## Performance Optimization

### Database Indexing
The provided SQL includes indexes on commonly queried fields:
- `survey_responses.category`
- `survey_responses.timestamp`
- `survey_ratings.response_id`
- `survey_ratings.rating`

### Caching
Consider implementing caching for statistics:

```php
// Cache stats for 5 minutes
$cacheFile = 'stats_cache.json';
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
    return json_decode(file_get_contents($cacheFile), true);
}
```

## Scaling Considerations

If you expect high traffic:
- Use connection pooling
- Implement proper caching
- Consider read replicas for reporting
- Add rate limiting to prevent abuse

## Support

For issues with this setup:
1. Check the error logs
2. Verify all configuration settings
3. Test individual components
4. Review database permissions

Remember to remove any debug settings and test files before going to production!