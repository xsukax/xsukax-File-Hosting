<?php
// xsukax File Hosting - Upload Interface
// Security & Configuration
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('UPLOAD_DIR', __DIR__ . '/downloads/');
define('DB_FILE', __DIR__ . '/xfh.db');

// Initialize Database
function initDatabase() {
    try {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create files table with hash-based ID
        $db->exec("CREATE TABLE IF NOT EXISTS files (
            id TEXT PRIMARY KEY,
            filename TEXT NOT NULL,
            stored_filename TEXT NOT NULL UNIQUE,
            filesize INTEGER NOT NULL,
            upload_date TEXT NOT NULL,
            uploader_ip TEXT NOT NULL,
            download_count INTEGER DEFAULT 0
        )");
        
        // Create settings table
        $db->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
        
        // Initialize default settings
        $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute(['admin_password', password_hash('admin123', PASSWORD_BCRYPT)]);
        $stmt->execute(['ad_code', '']);
        $stmt->execute(['download_wait_time', '5']);
        
        return $db;
    } catch (PDOException $e) {
        die('Database error');
    }
}

// Generate secure unique ID
function generateSecureID() {
    // Generate a 32-character secure random hex string
    return bin2hex(random_bytes(16));
}

// Check if ID exists in database
function idExists($db, $id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM files WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() > 0;
}

// Get real IP address
function getRealIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Generate secure random filename
function generateSecureFilename($extension) {
    return bin2hex(random_bytes(16)) . '.' . $extension;
}

// Handle file upload
$response = ['success' => false, 'message' => '', 'url' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    try {
        $db = initDatabase();
        
        // Create upload directory if not exists
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }
        
        $file = $_FILES['file'];
        
        // Validate file upload with detailed error messages
        if ($file['error'] !== UPLOAD_ERR_OK) {
            switch ($file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    throw new Exception('File exceeds server upload limit. Please contact administrator to increase upload_max_filesize in php.ini');
                case UPLOAD_ERR_FORM_SIZE:
                    throw new Exception('File exceeds maximum allowed size');
                case UPLOAD_ERR_PARTIAL:
                    throw new Exception('File was only partially uploaded. Please try again');
                case UPLOAD_ERR_NO_FILE:
                    throw new Exception('No file was uploaded');
                case UPLOAD_ERR_NO_TMP_DIR:
                    throw new Exception('Missing temporary folder on server');
                case UPLOAD_ERR_CANT_WRITE:
                    throw new Exception('Failed to write file to disk');
                case UPLOAD_ERR_EXTENSION:
                    throw new Exception('File upload stopped by extension');
                default:
                    throw new Exception('File upload failed with error code: ' . $file['error']);
            }
        }
        
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new Exception('File size exceeds maximum limit (100MB)');
        }
        
        if ($file['size'] === 0) {
            throw new Exception('File is empty');
        }
        
        // Get original filename and sanitize
        $originalFilename = basename($file['name']);
        $originalFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFilename);
        
        // Get file extension
        $pathInfo = pathinfo($originalFilename);
        $extension = strtolower($pathInfo['extension'] ?? 'bin');
        
        // Generate secure stored filename
        $storedFilename = generateSecureFilename($extension);
        $targetPath = UPLOAD_DIR . $storedFilename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Failed to save file');
        }
        
        // Set proper permissions
        chmod($targetPath, 0644);
        
        // Generate unique secure ID
        do {
            $fileId = generateSecureID();
        } while (idExists($db, $fileId));
        
        // Store file info in database
        $stmt = $db->prepare("INSERT INTO files (id, filename, stored_filename, filesize, upload_date, uploader_ip) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $fileId,
            $originalFilename,
            $storedFilename,
            $file['size'],
            date('Y-m-d H:i:s'),
            getRealIP()
        ]);
        
        $downloadUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                      '://' . $_SERVER['HTTP_HOST'] . 
                      dirname($_SERVER['PHP_SELF']) . '/download.php?id=' . $fileId;
        
        $response = [
            'success' => true,
            'message' => 'File uploaded successfully',
            'url' => $downloadUrl,
            'filename' => htmlspecialchars($originalFilename),
            'size' => number_format($file['size'] / 1024, 2) . ' KB'
        ];
        
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
} else {
    initDatabase(); // Initialize DB on first load
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>xsukax File Hosting</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #f6f8fa; color: #24292f; }
        .container { max-width: 800px; margin: 0 auto; padding: 2rem 1rem; }
        .card { background: white; border: 1px solid #d0d7de; border-radius: 6px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .header { text-align: center; margin-bottom: 2rem; }
        .header h1 { font-size: 2rem; font-weight: 600; color: #24292f; margin-bottom: 0.5rem; }
        .header p { color: #57606a; font-size: 0.95rem; }
        .upload-area { border: 2px dashed #d0d7de; border-radius: 6px; padding: 3rem 2rem; text-align: center; transition: all 0.3s; cursor: pointer; background: #f6f8fa; }
        .upload-area:hover { border-color: #0969da; background: #ddf4ff; }
        .upload-area.drag-over { border-color: #0969da; background: #ddf4ff; }
        .upload-icon { font-size: 3rem; margin-bottom: 1rem; color: #57606a; }
        .btn { display: inline-block; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; border-radius: 6px; border: 1px solid; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: #2da44e; color: white; border-color: #2da44e; }
        .btn-primary:hover { background: #2c974b; }
        .btn-secondary { background: white; color: #24292f; border-color: #d0d7de; }
        .btn-secondary:hover { background: #f6f8fa; }
        .file-input { display: none; }
        .file-info { margin-top: 1rem; padding: 1rem; background: #f6f8fa; border-radius: 6px; text-align: left; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 6px; max-width: 500px; width: 90%; padding: 2rem; box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
        .modal-header { font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #24292f; }
        .modal-body { color: #57606a; margin-bottom: 1.5rem; line-height: 1.6; }
        .modal-footer { display: flex; gap: 0.5rem; justify-content: flex-end; }
        .notification { position: fixed; top: 1rem; right: 1rem; padding: 1rem 1.5rem; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 2000; display: none; max-width: 400px; }
        .notification.show { display: block; animation: slideIn 0.3s ease-out; }
        .notification.success { background: #dafbe1; border: 1px solid #2da44e; color: #1a7f37; }
        .notification.error { background: #ffebe9; border: 1px solid #d1242f; color: #cf222e; }
        @keyframes slideIn { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .url-display { background: #f6f8fa; padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid #d0d7de; font-family: monospace; font-size: 0.875rem; word-break: break-all; margin: 1rem 0; }
        .footer { text-align: center; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #d0d7de; color: #57606a; font-size: 0.875rem; }
        .footer a { color: #0969da; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        .loading { display: inline-block; width: 1rem; height: 1rem; border: 2px solid #f3f3f3; border-top: 2px solid #2da44e; border-radius: 50%; animation: spin 1s linear infinite; margin-left: 0.5rem; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>xsukax File Hosting</h1>
                <p>Secure and fast file sharing service</p>
            </div>
            
            <div class="upload-area" id="uploadArea">
                <div class="upload-icon">📁</div>
                <h3 style="margin-bottom: 0.5rem; font-weight: 600;">Drop your file here or click to browse</h3>
                <p style="color: #57606a; font-size: 0.875rem;">Maximum file size: 100MB</p>
                <input type="file" id="fileInput" class="file-input">
            </div>
            
            <div id="fileInfo" class="file-info" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong id="selectedFileName"></strong>
                        <div style="color: #57606a; font-size: 0.875rem; margin-top: 0.25rem;" id="selectedFileSize"></div>
                    </div>
                    <button class="btn btn-primary" id="uploadBtn">
                        Upload File
                    </button>
                </div>
            </div>
            
            <div class="footer">
                <a href="admin.php">Admin Panel</a> | Powered by xsukax
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">✓ Upload Successful</div>
            <div class="modal-body">
                <p style="margin-bottom: 1rem;">Your file has been uploaded successfully!</p>
                <div style="margin-bottom: 0.5rem; font-weight: 600;">Download URL:</div>
                <div class="url-display" id="downloadUrl"></div>
                <button class="btn btn-secondary" style="width: 100%;" id="copyUrlBtn">Copy URL</button>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" id="closeModal">Done</button>
            </div>
        </div>
    </div>

    <!-- Notification -->
    <div id="notification" class="notification"></div>

    <script>
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const fileInfo = document.getElementById('fileInfo');
        const uploadBtn = document.getElementById('uploadBtn');
        const selectedFileName = document.getElementById('selectedFileName');
        const selectedFileSize = document.getElementById('selectedFileSize');
        const successModal = document.getElementById('successModal');
        const downloadUrl = document.getElementById('downloadUrl');
        const closeModal = document.getElementById('closeModal');
        const copyUrlBtn = document.getElementById('copyUrlBtn');
        const notification = document.getElementById('notification');

        let selectedFile = null;

        // Show notification
        const showNotification = (message, type = 'success') => {
            notification.textContent = message;
            notification.className = `notification ${type} show`;
            setTimeout(() => {
                notification.classList.remove('show');
            }, 4000);
        };

        // Copy to clipboard function with fallback
        const copyToClipboard = (text) => {
            // Try modern clipboard API first
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            
            // Fallback to older method
            return new Promise((resolve, reject) => {
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                
                try {
                    const successful = document.execCommand('copy');
                    document.body.removeChild(textArea);
                    if (successful) {
                        resolve();
                    } else {
                        reject(new Error('Copy command failed'));
                    }
                } catch (err) {
                    document.body.removeChild(textArea);
                    reject(err);
                }
            });
        };

        // Upload area click
        uploadArea.addEventListener('click', () => {
            fileInput.click();
        });

        // File input change
        fileInput.addEventListener('change', (e) => {
            handleFileSelect(e.target.files[0]);
        });

        // Drag and drop
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('drag-over');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('drag-over');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('drag-over');
            handleFileSelect(e.dataTransfer.files[0]);
        });

        // Handle file selection
        const handleFileSelect = (file) => {
            if (!file) return;
            
            const maxSize = 100 * 1024 * 1024; // 100MB
            if (file.size > maxSize) {
                showNotification('File size exceeds 100MB limit', 'error');
                return;
            }

            selectedFile = file;
            selectedFileName.textContent = file.name;
            selectedFileSize.textContent = formatFileSize(file.size);
            fileInfo.style.display = 'block';
        };

        // Format file size
        const formatFileSize = (bytes) => {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        };

        // Upload file
        uploadBtn.addEventListener('click', async () => {
            if (!selectedFile) return;

            const formData = new FormData();
            formData.append('file', selectedFile);

            uploadBtn.disabled = true;
            uploadBtn.innerHTML = 'Uploading<span class="loading"></span>';

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    downloadUrl.textContent = result.url;
                    successModal.classList.add('active');
                    fileInfo.style.display = 'none';
                    fileInput.value = '';
                    selectedFile = null;
                } else {
                    showNotification(result.message || 'Upload failed', 'error');
                }
            } catch (error) {
                showNotification('Network error occurred', 'error');
            } finally {
                uploadBtn.disabled = false;
                uploadBtn.textContent = 'Upload File';
            }
        });

        // Copy URL
        copyUrlBtn.addEventListener('click', () => {
            copyToClipboard(downloadUrl.textContent).then(() => {
                showNotification('URL copied to clipboard');
                copyUrlBtn.textContent = 'Copied!';
                setTimeout(() => {
                    copyUrlBtn.textContent = 'Copy URL';
                }, 2000);
            }).catch(() => {
                showNotification('Failed to copy URL', 'error');
            });
        });

        // Close modal
        closeModal.addEventListener('click', () => {
            successModal.classList.remove('active');
        });

        // Close modal on outside click
        successModal.addEventListener('click', (e) => {
            if (e.target === successModal) {
                successModal.classList.remove('active');
            }
        });
    </script>
</body>
</html>
