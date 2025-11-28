<?php
// xsukax File Hosting - Download Page
// Security & Configuration
ini_set('display_errors', 0);
error_reporting(E_ALL);

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

// Get file information
$fileInfo = null;
$errorMessage = null;
$adCode = '';
$waitTime = 5;

if (isset($_GET['id']) && preg_match('/^[a-f0-9]{32}$/', $_GET['id'])) {
    $fileId = $_GET['id'];
    
    try {
        $db = getDatabase();
        
        // Get file info
        $stmt = $db->prepare("SELECT * FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $fileInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get advertisement code
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'ad_code'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $adCode = $result ? $result['value'] : '';
        
        // Get wait time
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'download_wait_time'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $waitTime = $result ? (int)$result['value'] : 5;
        
        if (!$fileInfo) {
            $errorMessage = 'File not found';
        }
    } catch (PDOException $e) {
        $errorMessage = 'Database error occurred';
    }
} else {
    $errorMessage = 'Invalid file ID';
}

// Handle download request
if (isset($_GET['download']) && $_GET['download'] === '1' && $fileInfo) {
    $filePath = UPLOAD_DIR . $fileInfo['stored_filename'];
    
    if (file_exists($filePath)) {
        try {
            // Increment download count
            $db = getDatabase();
            $stmt = $db->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = ?");
            $stmt->execute([$fileId]);
            
            // Security headers
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($fileInfo['filename']) . '"');
            header('Content-Length: ' . $fileInfo['filesize']);
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('X-Content-Type-Options: nosniff');
            
            // Clear output buffer and read file
            ob_clean();
            flush();
            readfile($filePath);
            exit;
        } catch (Exception $e) {
            $errorMessage = 'Download failed';
        }
    } else {
        $errorMessage = 'File no longer exists on server';
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
    <title><?php echo $fileInfo ? htmlspecialchars($fileInfo['filename']) : 'File Not Found'; ?> - xsukax File Hosting</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #f6f8fa; color: #24292f; min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }
        .card { background: white; border: 1px solid #d0d7de; border-radius: 6px; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 1.5rem; }
        .header { text-align: center; margin-bottom: 2rem; }
        .header h1 { font-size: 1.5rem; font-weight: 600; color: #24292f; margin-bottom: 0.5rem; }
        .header p { color: #57606a; font-size: 0.875rem; }
        .file-icon { font-size: 4rem; text-align: center; margin-bottom: 1rem; }
        .file-name { font-size: 1.5rem; font-weight: 600; color: #24292f; margin-bottom: 1rem; text-align: center; word-break: break-word; }
        .file-details { background: #f6f8fa; border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; }
        .detail-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #d0d7de; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: #57606a; font-weight: 500; }
        .detail-value { color: #24292f; font-weight: 600; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; font-size: 1rem; font-weight: 500; border-radius: 6px; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; text-align: center; }
        .btn-primary { background: #2da44e; color: white; }
        .btn-primary:hover { background: #2c974b; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(45,164,78,0.3); }
        .btn-secondary { background: white; color: #24292f; border: 1px solid #d0d7de; }
        .btn-secondary:hover { background: #f6f8fa; }
        .btn-full { width: 100%; margin-bottom: 0.75rem; }
        .error-card { background: #fff1f0; border: 1px solid #ffccc7; color: #cf222e; }
        .error-icon { font-size: 3rem; text-align: center; margin-bottom: 1rem; }
        .error-title { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; text-align: center; }
        .error-message { text-align: center; color: #82071e; }
        .ad-container { background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 6px; padding: 1.5rem; margin: 1.5rem 0; text-align: center; overflow: visible; display: block !important; visibility: visible !important; opacity: 1 !important; }
        .ad-container > * { display: inline-block !important; visibility: visible !important; opacity: 1 !important; }
        .ad-container img { max-width: 100%; height: auto; }
        .ad-container iframe { max-width: 100%; border: none; }
        .ad-container a { text-decoration: none; }
        .footer { text-align: center; padding-top: 2rem; color: #57606a; font-size: 0.875rem; }
        .footer a { color: #0969da; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        .countdown { font-size: 2rem; font-weight: 600; color: #2da44e; text-align: center; margin: 1rem 0; }
        .countdown-label { text-align: center; color: #57606a; font-size: 0.875rem; margin-bottom: 1rem; }
        @media (max-width: 640px) { .file-name { font-size: 1.25rem; } .file-icon { font-size: 3rem; } }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($errorMessage): ?>
            <div class="card error-card">
                <div class="error-icon">⚠️</div>
                <div class="error-title">Error</div>
                <div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
                <div style="text-align: center; margin-top: 1.5rem;">
                    <a href="index.php" class="btn btn-secondary">← Back to Upload</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="header">
                    <h1>📥 File Ready for Download</h1>
                    <p>xsukax File Hosting</p>
                </div>

                <div class="file-icon">📄</div>
                <div class="file-name"><?php echo htmlspecialchars($fileInfo['filename']); ?></div>

                <?php if (!empty($adCode)): ?>
                <!-- Advertisement Section -->
                <div style="background: #ffffff; border: 2px solid #0969da; border-radius: 6px; padding: 1.5rem; margin: 1.5rem 0; text-align: center; min-height: 50px; display: flex; align-items: center; justify-content: center; flex-direction: column; overflow: hidden;">
                    <div style="width: 100%; max-width: 100%; display: flex; justify-content: center; align-items: center;">
                        <?php 
                        // Output ad code directly without escaping
                        echo $adCode; 
                        ?>
                    </div>
                </div>
                <style>
                    /* Responsive ad styles - works with any image size */
                    div[style*="border: 2px solid #0969da"] img {
                        max-width: 100% !important;
                        height: auto !important;
                        display: block !important;
                        margin: 0 auto !important;
                        border-radius: 4px;
                    }
                    div[style*="border: 2px solid #0969da"] a {
                        display: inline-block;
                        max-width: 100%;
                        text-align: center;
                    }
                    div[style*="border: 2px solid #0969da"] iframe {
                        max-width: 100% !important;
                        display: block !important;
                        margin: 0 auto !important;
                    }
                    div[style*="border: 2px solid #0969da"] > div {
                        max-width: 100%;
                        margin: 0 auto;
                    }
                    /* Mobile responsive */
                    @media (max-width: 768px) {
                        div[style*="border: 2px solid #0969da"] {
                            padding: 1rem !important;
                        }
                        div[style*="border: 2px solid #0969da"] img {
                            max-width: 100% !important;
                        }
                    }
                </style>
                <?php else: ?>
                <!-- Debug: No ad code found -->
                <?php endif; ?>

                <div class="file-details">
                    <div class="detail-row">
                        <span class="detail-label">File Size:</span>
                        <span class="detail-value"><?php echo formatFileSize($fileInfo['filesize']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Uploaded:</span>
                        <span class="detail-value"><?php echo date('M d, Y H:i', strtotime($fileInfo['upload_date'])); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Downloads:</span>
                        <span class="detail-value"><?php echo number_format($fileInfo['download_count']); ?></span>
                    </div>
                </div>

                <div id="countdownContainer" style="display: none;">
                    <div class="countdown-label">Download will be available in:</div>
                    <div class="countdown" id="countdown"><?php echo $waitTime; ?></div>
                </div>

                <div id="downloadButtonContainer">
                    <a href="?id=<?php echo htmlspecialchars($fileId); ?>&download=1" class="btn btn-primary btn-full" id="downloadBtn" style="pointer-events: none; opacity: 0.5;">
                        ⬇ Download File
                    </a>
                </div>

                <a href="index.php" class="btn btn-secondary btn-full">Upload Another File</a>
            </div>
        <?php endif; ?>

        <div class="footer">
            <a href="index.php">Upload Files</a> | <a href="admin.php">Admin Panel</a> | Powered by xsukax
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const downloadBtn = document.getElementById('downloadBtn');
            const countdownContainer = document.getElementById('countdownContainer');
            const countdownElement = document.getElementById('countdown');
            let timeLeft = <?php echo $waitTime; ?>;

            if (downloadBtn && timeLeft > 0) {
                countdownContainer.style.display = 'block';

                const countdown = setInterval(() => {
                    timeLeft--;
                    countdownElement.textContent = timeLeft;

                    if (timeLeft <= 0) {
                        clearInterval(countdown);
                        countdownContainer.style.display = 'none';
                        downloadBtn.style.pointerEvents = 'auto';
                        downloadBtn.style.opacity = '1';
                        downloadBtn.innerHTML = '⬇ Download File (Click to Start)';
                    }
                }, 1000);
            } else if (downloadBtn && timeLeft === 0) {
                downloadBtn.style.pointerEvents = 'auto';
                downloadBtn.style.opacity = '1';
                downloadBtn.innerHTML = '⬇ Download File (Click to Start)';
            }
        });
    </script>
</body>
</html>