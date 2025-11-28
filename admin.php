<?php
// xsukax File Hosting - Admin Panel
// Security & Configuration
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

define('UPLOAD_DIR', __DIR__ . '/downloads/');
define('DB_FILE', __DIR__ . '/xfh.db');

// Get database connection
function getDatabase() {
    try {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch (PDOException $e) {
        die('Database connection failed');
    }
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Handle login
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    
    try {
        $db = getDatabase();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_password'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && password_verify($password, $result['value'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['login_time'] = time();
            header('Location: admin.php');
            exit;
        } else {
            $loginError = 'Invalid password';
        }
    } catch (PDOException $e) {
        $loginError = 'Database error';
    }
}

// Handle AJAX requests
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    try {
        $db = getDatabase();
        $action = $_POST['action'] ?? '';
        
        // Delete file
        if ($action === 'delete_file') {
            $fileId = $_POST['file_id'] ?? '';
            
            if (!preg_match('/^[a-f0-9]{32}$/', $fileId)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file ID']);
                exit;
            }
            
            $stmt = $db->prepare("SELECT * FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($file) {
                // Delete physical file
                $filePath = UPLOAD_DIR . $file['stored_filename'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                
                // Delete from database
                $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
                $stmt->execute([$fileId]);
                
                echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found']);
            }
            exit;
        }
        
        // Update ad code
        if ($action === 'update_ad_code') {
            $adCode = $_POST['ad_code'] ?? '';
            
            $stmt = $db->prepare("UPDATE settings SET value = ? WHERE key = 'ad_code'");
            $stmt->execute([$adCode]);
            
            echo json_encode(['success' => true, 'message' => 'Advertisement code updated']);
            exit;
        }
        
        // Update wait time
        if ($action === 'update_wait_time') {
            $waitTime = (int)($_POST['wait_time'] ?? 5);
            
            if ($waitTime < 0 || $waitTime > 60) {
                echo json_encode(['success' => false, 'message' => 'Wait time must be between 0 and 60 seconds']);
                exit;
            }
            
            $stmt = $db->prepare("UPDATE settings SET value = ? WHERE key = 'download_wait_time'");
            $stmt->execute([$waitTime]);
            
            echo json_encode(['success' => true, 'message' => 'Download wait time updated']);
            exit;
        }
        
        // Change password
        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            
            // Verify current password
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'admin_password'");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && password_verify($currentPassword, $result['value'])) {
                if (strlen($newPassword) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit;
                }
                
                $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE settings SET value = ? WHERE key = 'admin_password'");
                $stmt->execute([$hashedPassword]);
                
                echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            }
            exit;
        }
        
        // Get files
        if ($action === 'get_files') {
            $page = (int)($_POST['page'] ?? 1);
            $perPage = 20;
            $offset = ($page - 1) * $perPage;
            
            $stmt = $db->query("SELECT COUNT(*) as total FROM files");
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            $stmt = $db->prepare("SELECT * FROM files ORDER BY upload_date DESC LIMIT ? OFFSET ?");
            $stmt->execute([$perPage, $offset]);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'files' => $files,
                'total' => $total,
                'page' => $page,
                'totalPages' => ceil($total / $perPage)
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Operation failed']);
        exit;
    }
}

// Get statistics and settings if logged in
$stats = null;
$adCode = '';
$waitTime = 5;
if (isLoggedIn()) {
    try {
        $db = getDatabase();
        
        // Get statistics
        $stmt = $db->query("SELECT 
            COUNT(*) as total_files,
            SUM(filesize) as total_size,
            SUM(download_count) as total_downloads
            FROM files");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get ad code
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'ad_code'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $adCode = $result ? $result['value'] : '';
        
        // Get wait time
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'download_wait_time'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $waitTime = $result ? (int)$result['value'] : 5;
        
    } catch (PDOException $e) {
        $stats = ['total_files' => 0, 'total_size' => 0, 'total_downloads' => 0];
    }
}

// Format file size
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 2) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / (1024 * 1024), 2) . ' MB';
    return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - xsukax File Hosting</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #f6f8fa; color: #24292f; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1rem; }
        .card { background: white; border: 1px solid #d0d7de; border-radius: 6px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 1.5rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .header h1 { font-size: 1.5rem; font-weight: 600; color: #24292f; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 6px; padding: 1rem; text-align: center; }
        .stat-value { font-size: 1.75rem; font-weight: 600; color: #2da44e; }
        .stat-label { color: #57606a; font-size: 0.875rem; margin-top: 0.25rem; }
        .btn { display: inline-block; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; border-radius: 6px; border: 1px solid; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: #2da44e; color: white; border-color: #2da44e; }
        .btn-primary:hover { background: #2c974b; }
        .btn-secondary { background: white; color: #24292f; border-color: #d0d7de; }
        .btn-secondary:hover { background: #f6f8fa; }
        .btn-danger { background: #d1242f; color: white; border-color: #d1242f; }
        .btn-danger:hover { background: #a40e26; }
        .btn-small { padding: 0.25rem 0.75rem; font-size: 0.75rem; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f6f8fa; padding: 0.75rem; text-align: left; font-weight: 600; border-bottom: 2px solid #d0d7de; font-size: 0.875rem; }
        td { padding: 0.75rem; border-bottom: 1px solid #d0d7de; font-size: 0.875rem; }
        tr:hover { background: #f6f8fa; }
        .input { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d0d7de; border-radius: 6px; font-size: 0.875rem; }
        .input:focus { outline: none; border-color: #0969da; box-shadow: 0 0 0 3px rgba(9,105,218,0.1); }
        .textarea { width: 100%; min-height: 150px; padding: 0.75rem; border: 1px solid #d0d7de; border-radius: 6px; font-family: monospace; font-size: 0.875rem; resize: vertical; }
        .textarea:focus { outline: none; border-color: #0969da; box-shadow: 0 0 0 3px rgba(9,105,218,0.1); }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: #24292f; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 6px; max-width: 600px; width: 90%; padding: 2rem; box-shadow: 0 8px 24px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto; }
        .modal-header { font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #24292f; }
        .modal-footer { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1.5rem; }
        .notification { position: fixed; top: 1rem; right: 1rem; padding: 1rem 1.5rem; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 2000; display: none; max-width: 400px; }
        .notification.show { display: block; animation: slideIn 0.3s ease-out; }
        .notification.success { background: #dafbe1; border: 1px solid #2da44e; color: #1a7f37; }
        .notification.error { background: #ffebe9; border: 1px solid #d1242f; color: #cf222e; }
        @keyframes slideIn { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .loading { display: inline-block; width: 1rem; height: 1rem; border: 2px solid #f3f3f3; border-top: 2px solid #2da44e; border-radius: 50%; animation: spin 1s linear infinite; margin-left: 0.5rem; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .pagination { display: flex; justify-content: center; gap: 0.5rem; margin-top: 1rem; }
        .login-card { max-width: 400px; margin: 4rem auto; }
        .login-header { text-align: center; margin-bottom: 2rem; }
        .login-header h1 { font-size: 1.75rem; font-weight: 600; margin-bottom: 0.5rem; }
        .tabs { display: flex; border-bottom: 1px solid #d0d7de; margin-bottom: 1rem; }
        .tab { padding: 0.75rem 1rem; cursor: pointer; border-bottom: 2px solid transparent; font-weight: 500; color: #57606a; transition: all 0.2s; }
        .tab:hover { color: #24292f; }
        .tab.active { color: #0969da; border-bottom-color: #0969da; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .badge { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: #dafbe1; color: #1a7f37; }
        .badge-info { background: #ddf4ff; color: #0969da; }
        .hash-id { font-family: monospace; font-size: 0.75rem; background: #f6f8fa; padding: 0.25rem 0.5rem; border-radius: 4px; }
        @media (max-width: 768px) { .header { flex-direction: column; align-items: stretch; } th, td { padding: 0.5rem; font-size: 0.75rem; } }
    </style>
</head>
<body>
    <?php if (!isLoggedIn()): ?>
        <!-- Login Form -->
        <div class="container">
            <div class="card login-card">
                <div class="login-header">
                    <h1>🔐 Admin Login</h1>
                    <p style="color: #57606a; font-size: 0.875rem;">xsukax File Hosting</p>
                </div>
                
                <?php if ($loginError): ?>
                    <div style="background: #ffebe9; border: 1px solid #d1242f; color: #cf222e; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; text-align: center;">
                        <?php echo htmlspecialchars($loginError); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="input" placeholder="Enter admin password" required autofocus>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary" style="width: 100%;">Login</button>
                </form>
                
                <div style="text-align: center; margin-top: 1rem; color: #57606a; font-size: 0.875rem;">
                    Default password: <code style="background: #f6f8fa; padding: 0.25rem 0.5rem; border-radius: 4px;">admin123</code>
                </div>
                
                <div style="text-align: center; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #d0d7de;">
                    <a href="index.php" class="btn btn-secondary" style="width: 100%;">← Back to Upload</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Admin Dashboard -->
        <div class="container">
            <div class="header">
                <h1>🛠️ Admin Control Panel</h1>
                <a href="?logout=1" class="btn btn-secondary">Logout</a>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($stats['total_files']); ?></div>
                    <div class="stat-label">Total Files</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo formatFileSize($stats['total_size']); ?></div>
                    <div class="stat-label">Storage Used</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($stats['total_downloads']); ?></div>
                    <div class="stat-label">Total Downloads</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="card">
                <div class="tabs">
                    <div class="tab active" data-tab="files">Files Management</div>
                    <div class="tab" data-tab="ads">Advertisement</div>
                    <div class="tab" data-tab="settings">Settings</div>
                </div>

                <!-- Files Tab -->
                <div class="tab-content active" id="files">
                    <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="font-weight: 600;">Uploaded Files</h3>
                        <button class="btn btn-secondary btn-small" id="refreshFilesBtn">Refresh</button>
                    </div>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>File ID</th>
                                    <th>Filename</th>
                                    <th>Size</th>
                                    <th>Uploader IP</th>
                                    <th>Upload Date</th>
                                    <th>Downloads</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="filesTableBody">
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: #57606a;">
                                        Loading files...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="pagination" id="pagination"></div>
                </div>

                <!-- Advertisement Tab -->
                <div class="tab-content" id="ads">
                    <h3 style="font-weight: 600; margin-bottom: 1rem;">Advertisement Code</h3>
                    <p style="color: #57606a; font-size: 0.875rem; margin-bottom: 1rem;">
                        Enter HTML/JavaScript code to display on the download page. The code will be rendered directly after the file name.
                    </p>
                    
                    <div style="background: #ddf4ff; border: 1px solid #54aeff; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.875rem;">
                        <strong>💡 Pro Tip:</strong> The system automatically centers and scales all images! Just paste your image tag - no styling needed. Works with any size: 300x250, 728x90, 1200x400, etc.
                    </div>

                    <div style="background: #fff8c5; border: 1px solid #d4a72c; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.875rem;">
                        <strong>Quick Test Examples:</strong>
                        <pre style="margin-top: 0.5rem; padding: 0.5rem; background: white; border-radius: 4px; overflow-x: auto; font-size: 0.75rem;">Large: &lt;img src="https://via.placeholder.com/1200x400/0969da/fff?text=Large+Ad"&gt;
Small: &lt;img src="https://via.placeholder.com/300x250/2da44e/fff?text=Small+Ad"&gt;
Vertical: &lt;img src="https://via.placeholder.com/300x600/764ba2/fff?text=Tall+Ad"&gt;</pre>
                    </div>
                    
                    <form id="adForm">
                        <div class="form-group">
                            <label class="form-label">Advertisement Code (HTML/JS)</label>
                            <textarea id="adCodeInput" class="textarea" placeholder="<div>Your advertisement code here...</div>"><?php echo htmlspecialchars($adCode); ?></textarea>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="submit" class="btn btn-primary">Update Advertisement</button>
                            <button type="button" class="btn btn-secondary" id="previewAdBtn">Preview Ad</button>
                        </div>
                    </form>

                    <div id="adPreviewContainer" style="display: none; margin-top: 1.5rem;">
                        <h4 style="font-weight: 600; margin-bottom: 0.5rem;">Ad Preview (Exactly as it appears on download pages):</h4>
                        <div style="background: #ffffff; border: 2px solid #0969da; border-radius: 6px; padding: 1.5rem; margin: 1rem 0; text-align: center; min-height: 50px; display: flex; align-items: center; justify-content: center; flex-direction: column; overflow: hidden;" id="adPreviewContent">
                        </div>
                    </div>
                    <style>
                        /* Preview ad styles - matches download page exactly */
                        #adPreviewContent img {
                            max-width: 100% !important;
                            height: auto !important;
                            display: block !important;
                            margin: 0 auto !important;
                            border-radius: 4px;
                        }
                        #adPreviewContent a {
                            display: inline-block;
                            max-width: 100%;
                            text-align: center;
                        }
                        #adPreviewContent iframe {
                            max-width: 100% !important;
                            display: block !important;
                            margin: 0 auto !important;
                        }
                        #adPreviewContent > div {
                            max-width: 100%;
                            margin: 0 auto;
                        }
                    </style>
                </div>

                <!-- Settings Tab -->
                <div class="tab-content" id="settings">
                    <h3 style="font-weight: 600; margin-bottom: 1rem;">Server Configuration</h3>
                    <div style="background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem;">
                        <div style="font-weight: 600; margin-bottom: 0.5rem;">Current PHP Upload Limits:</div>
                        <div style="display: grid; gap: 0.5rem; font-size: 0.875rem;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #57606a;">Max Upload Size:</span>
                                <code style="background: white; padding: 0.25rem 0.5rem; border-radius: 4px;"><?php echo ini_get('upload_max_filesize'); ?></code>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #57606a;">Max POST Size:</span>
                                <code style="background: white; padding: 0.25rem 0.5rem; border-radius: 4px;"><?php echo ini_get('post_max_size'); ?></code>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #57606a;">Memory Limit:</span>
                                <code style="background: white; padding: 0.25rem 0.5rem; border-radius: 4px;"><?php echo ini_get('memory_limit'); ?></code>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: #57606a;">Max Execution Time:</span>
                                <code style="background: white; padding: 0.25rem 0.5rem; border-radius: 4px;"><?php echo ini_get('max_execution_time'); ?>s</code>
                            </div>
                        </div>
                        <?php 
                        $uploadMax = ini_get('upload_max_filesize');
                        $uploadMaxBytes = (int)$uploadMax * (stripos($uploadMax, 'g') !== false ? 1073741824 : (stripos($uploadMax, 'm') !== false ? 1048576 : 1024));
                        if ($uploadMaxBytes < 104857600): // Less than 100MB
                        ?>
                        <div style="margin-top: 1rem; padding: 0.75rem; background: #fff8c5; border: 1px solid #d4a72c; border-radius: 6px; font-size: 0.875rem;">
                            <strong>⚠️ Warning:</strong> Upload limit is below 100MB. To increase, add to your php.ini or .htaccess:
                            <pre style="margin-top: 0.5rem; padding: 0.5rem; background: white; border-radius: 4px; overflow-x: auto;">upload_max_filesize = 100M
post_max_size = 100M
memory_limit = 256M
max_execution_time = 300</pre>
                        </div>
                        <?php endif; ?>
                    </div>

                    <h3 style="font-weight: 600; margin-bottom: 1rem;">Download Settings</h3>
                    <p style="color: #57606a; font-size: 0.875rem; margin-bottom: 1rem;">
                        Configure download wait time before users can download files.
                    </p>
                    
                    <form id="waitTimeForm">
                        <div class="form-group">
                            <label class="form-label">Download Wait Time (seconds)</label>
                            <input type="number" id="waitTimeInput" class="input" value="<?php echo $waitTime; ?>" min="0" max="60" placeholder="5">
                            <div style="color: #57606a; font-size: 0.75rem; margin-top: 0.5rem;">Set to 0 for instant download. Maximum 60 seconds.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Wait Time</button>
                    </form>

                    <hr style="margin: 2rem 0; border: none; border-top: 1px solid #d0d7de;">

                    <h3 style="font-weight: 600; margin-bottom: 1rem;">Change Password</h3>
                    <p style="color: #57606a; font-size: 0.875rem; margin-bottom: 1rem;">
                        Update your admin panel password. Minimum 6 characters required.
                    </p>
                    
                    <form id="passwordForm">
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" id="currentPassword" class="input" placeholder="Enter current password" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" id="newPassword" class="input" placeholder="Enter new password (min 6 characters)" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" id="confirmPassword" class="input" placeholder="Confirm new password" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </form>
                </div>
            </div>

            <div style="text-align: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #d0d7de; color: #57606a; font-size: 0.875rem;">
                <a href="index.php" style="color: #0969da; text-decoration: none;">← Back to Upload</a> | Powered by xsukax
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="modal">
            <div class="modal-content" style="max-width: 400px;">
                <div class="modal-header">Confirm Deletion</div>
                <div style="color: #57606a; margin-bottom: 1rem;">
                    Are you sure you want to delete this file? This action cannot be undone.
                </div>
                <div style="background: #fff8c5; border: 1px solid #d4a72c; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.875rem;">
                    <strong id="deleteFileName"></strong>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" id="cancelDelete">Cancel</button>
                    <button class="btn btn-danger" id="confirmDelete">Delete File</button>
                </div>
            </div>
        </div>

        <!-- Notification -->
        <div id="notification" class="notification"></div>

        <script>
            let currentPage = 1;
            let fileToDelete = null;

            // Show notification
            const showNotification = (message, type = 'success') => {
                const notification = document.getElementById('notification');
                notification.textContent = message;
                notification.className = `notification ${type} show`;
                setTimeout(() => {
                    notification.classList.remove('show');
                }, 4000);
            };

            // Tab switching
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    
                    tab.classList.add('active');
                    document.getElementById(tab.dataset.tab).classList.add('active');
                });
            });

            // Load files
            const loadFiles = async (page = 1) => {
                try {
                    const formData = new FormData();
                    formData.append('ajax', '1');
                    formData.append('action', 'get_files');
                    formData.append('page', page);

                    const response = await fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        const tbody = document.getElementById('filesTableBody');
                        
                        if (result.files.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem; color: #57606a;">No files found</td></tr>';
                        } else {
                            tbody.innerHTML = result.files.map(file => `
                                <tr>
                                    <td><span class="hash-id" title="${escapeHtml(file.id)}">${escapeHtml(file.id.substring(0, 8))}...</span></td>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(file.filename)}">
                                        ${escapeHtml(file.filename)}
                                    </td>
                                    <td>${formatFileSize(file.filesize)}</td>
                                    <td><code style="background: #f6f8fa; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">${escapeHtml(file.uploader_ip)}</code></td>
                                    <td style="white-space: nowrap;">${formatDate(file.upload_date)}</td>
                                    <td><span class="badge badge-info">${file.download_count}</span></td>
                                    <td style="white-space: nowrap;">
                                        <a href="download.php?id=${escapeHtml(file.id)}" class="btn btn-secondary btn-small" target="_blank">View</a>
                                        <button class="btn btn-danger btn-small" onclick="showDeleteModal('${escapeHtml(file.id)}', '${escapeHtml(file.filename)}')">Delete</button>
                                    </td>
                                </tr>
                            `).join('');
                        }

                        // Update pagination
                        updatePagination(result.page, result.totalPages);
                        currentPage = result.page;
                    }
                } catch (error) {
                    showNotification('Failed to load files', 'error');
                }
            };

            // Update pagination
            const updatePagination = (page, totalPages) => {
                const pagination = document.getElementById('pagination');
                
                if (totalPages <= 1) {
                    pagination.innerHTML = '';
                    return;
                }

                let html = '';
                
                if (page > 1) {
                    html += `<button class="btn btn-secondary btn-small" onclick="loadFiles(${page - 1})">Previous</button>`;
                }

                for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
                    html += `<button class="btn ${i === page ? 'btn-primary' : 'btn-secondary'} btn-small" onclick="loadFiles(${i})">${i}</button>`;
                }

                if (page < totalPages) {
                    html += `<button class="btn btn-secondary btn-small" onclick="loadFiles(${page + 1})">Next</button>`;
                }

                pagination.innerHTML = html;
            };

            // Format file size
            const formatFileSize = (bytes) => {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
                if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
                return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
            };

            // Format date
            const formatDate = (dateString) => {
                const date = new Date(dateString);
                return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            };

            // Escape HTML
            const escapeHtml = (text) => {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            };

            // Show delete modal
            window.showDeleteModal = (fileId, fileName) => {
                fileToDelete = fileId;
                document.getElementById('deleteFileName').textContent = fileName;
                document.getElementById('deleteModal').classList.add('active');
            };

            // Delete file
            document.getElementById('confirmDelete').addEventListener('click', async () => {
                if (!fileToDelete) return;

                try {
                    const formData = new FormData();
                    formData.append('ajax', '1');
                    formData.append('action', 'delete_file');
                    formData.append('file_id', fileToDelete);

                    const response = await fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showNotification(result.message);
                        document.getElementById('deleteModal').classList.remove('active');
                        loadFiles(currentPage);
                    } else {
                        showNotification(result.message, 'error');
                    }
                } catch (error) {
                    showNotification('Delete operation failed', 'error');
                }

                fileToDelete = null;
            });

            // Cancel delete
            document.getElementById('cancelDelete').addEventListener('click', () => {
                document.getElementById('deleteModal').classList.remove('active');
                fileToDelete = null;
            });

            // Update ad code
            document.getElementById('adForm').addEventListener('submit', async (e) => {
                e.preventDefault();

                const adCode = document.getElementById('adCodeInput').value;

                try {
                    const formData = new FormData();
                    formData.append('ajax', '1');
                    formData.append('action', 'update_ad_code');
                    formData.append('ad_code', adCode);

                    const response = await fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showNotification(result.message);
                    } else {
                        showNotification(result.message, 'error');
                    }
                } catch (error) {
                    showNotification('Update failed', 'error');
                }
            });

            // Preview ad code
            document.getElementById('previewAdBtn').addEventListener('click', () => {
                const adCode = document.getElementById('adCodeInput').value;
                const previewContainer = document.getElementById('adPreviewContainer');
                const previewContent = document.getElementById('adPreviewContent');

                if (!adCode.trim()) {
                    showNotification('Enter ad code to preview', 'error');
                    return;
                }

                previewContent.innerHTML = adCode;
                previewContainer.style.display = 'block';
                showNotification('Ad preview loaded');
            });

            // Update wait time
            document.getElementById('waitTimeForm').addEventListener('submit', async (e) => {
                e.preventDefault();

                const waitTime = document.getElementById('waitTimeInput').value;

                try {
                    const formData = new FormData();
                    formData.append('ajax', '1');
                    formData.append('action', 'update_wait_time');
                    formData.append('wait_time', waitTime);

                    const response = await fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showNotification(result.message);
                    } else {
                        showNotification(result.message, 'error');
                    }
                } catch (error) {
                    showNotification('Update failed', 'error');
                }
            });

            // Change password
            document.getElementById('passwordForm').addEventListener('submit', async (e) => {
                e.preventDefault();

                const currentPassword = document.getElementById('currentPassword').value;
                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;

                if (newPassword !== confirmPassword) {
                    showNotification('New passwords do not match', 'error');
                    return;
                }

                if (newPassword.length < 6) {
                    showNotification('Password must be at least 6 characters', 'error');
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('ajax', '1');
                    formData.append('action', 'change_password');
                    formData.append('current_password', currentPassword);
                    formData.append('new_password', newPassword);

                    const response = await fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showNotification(result.message);
                        document.getElementById('passwordForm').reset();
                    } else {
                        showNotification(result.message, 'error');
                    }
                } catch (error) {
                    showNotification('Password change failed', 'error');
                }
            });

            // Refresh files button
            document.getElementById('refreshFilesBtn').addEventListener('click', () => {
                loadFiles(currentPage);
            });

            // Initial load
            loadFiles();
        </script>
    <?php endif; ?>
</body>
</html>