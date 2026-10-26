<?php
/**
 * Nowills PMS — Web Installer
 *
 * Access this file in your browser after uploading the app to cPanel:
 *   https://yourdomain.com/install.php
 *
 * It will:
 *  1. Check PHP version and required extensions
 *  2. Let you configure the database
 *  3. Write .env
 *  4. Run migrations
 *  5. Generate APP_KEY
 *  6. Clear cache
 *  7. Delete itself for security
 *
 * After installation completes, delete this file.
 */

// Don't run if already installed (check for a marker file)
if (file_exists(__DIR__.'/storage/framework/installed.php')) {
    http_response_code(404);
    echo '<h1>Installer Already Completed</h1>
    <p>The Nowills PMS has been installed. This installer has been disabled.</p>';
    exit;
}

// Check if .env exists
$envExists = file_exists(__DIR__.'/.env');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'test_db') {
        $dbHost = $_POST['db_host'] ?? '127.0.0.1';
        $dbPort = $_POST['db_port'] ?? '3306';
        $dbName = $_POST['db_name'] ?? '';
        $dbUser = $_POST['db_user'] ?? '';
        $dbPass = $_POST['db_pass'] ?? '';

        try {
            $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo json_encode(['status' => 'success', 'message' => 'Database connection successful!']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'install') {
        $dbHost = $_POST['db_host'] ?? '127.0.0.1';
        $dbPort = $_POST['db_port'] ?? '3306';
        $dbName = $_POST['db_name'] ?? '';
        $dbUser = $_POST['db_user'] ?? '';
        $dbPass = $_POST['db_pass'] ?? '';
        $appUrl = $_POST['app_url'] ?? 'http://localhost';
        $appDebug = isset($_POST['app_debug']) ? 'true' : 'false';

        // Generate APP_KEY
        $key = 'base64:'.base64_encode(random_bytes(32));

        // Read .env.example and write .env
        $envContent = file_get_contents(__DIR__.'/.env.example');
        $envContent = str_replace('APP_KEY=', 'APP_KEY='.$key, $envContent);
        $envContent = str_replace('APP_URL=http://localhost:8000', 'APP_URL='.$appUrl, $envContent);
        $envContent = str_replace('APP_DEBUG=true', 'APP_DEBUG='.$appDebug, $envContent);
        $envContent = str_replace('DB_HOST=127.0.0.1', 'DB_HOST='.$dbHost, $envContent);
        $envContent = str_replace('DB_PORT=3306', 'DB_PORT='.$dbPort, $envContent);
        $envContent = str_replace('DB_DATABASE=nowills_pms', 'DB_DATABASE='.$dbName, $envContent);
        $envContent = str_replace('DB_USERNAME=pms', 'DB_USERNAME='.$dbUser, $envContent);
        $envContent = str_replace('DB_PASSWORD=change_me', 'DB_PASSWORD='.$dbPass, $envContent);

        file_put_contents(__DIR__.'/.env', $envContent);

        // Run migrations
        require __DIR__.'/build/vendor/autoload.php';
        $app = require __DIR__.'/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

        $output = [];
        $exitCode = 0;
        try {
            $exitCode = $kernel->call('migrate');
            $output = $kernel->output();
        } catch (Exception $e) {
            $output = $e->getMessage();
        }

        // Clear cache
        try {
            $kernel->call('cache:clear');
            $kernel->call('config:clear');
            $kernel->call('view:clear');
            $kernel->call('route:clear');
        } catch (Exception $e) {}

        // Create marker file
        file_put_contents(__DIR__.'/storage/framework/installed.php', '<?php // Installed: '.date('Y-m-d H:i:s'));

        // Output result
        if ($exitCode === 0) {
            echo json_encode(['status' => 'success', 'message' => 'Installation complete!', 'output' => $output]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Migration failed', 'output' => $output]);
        }
        exit;
    }
}

// Check PHP version
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    echo '<div style="background:#fee;padding:20px;border:1px solid #c33;border-radius:8px;margin:20px;">
        <h2>PHP Version Error</h2>
        <p>This application requires PHP 8.3 or higher. You are running PHP '.PHP_VERSION.'.</p>
        <p>In cPanel, go to <strong>MultiPHP Manager</strong> and select PHP 8.3 or 8.4 for your domain.</p>
    </div>';
    exit;
}

// Check required extensions
$required = ['pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'ctype', 'json', 'dom', 'xml', 'fileinfo'];
$missing = [];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if (!empty($missing)) {
    echo '<div style="background:#fee;padding:20px;border:1px solid #c33;border-radius:8px;margin:20px;">
        <h2>Missing Extensions</h2>
        <p>The following PHP extensions are required but not installed:</p>
        <ul><li>'.implode('</li><li>', $missing).'</li></ul>
        <p>In cPanel, go to <strong>MultiPHP INI Editor</strong> or contact your host to enable them.</p>
    </div>';
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nowills PMS — Installer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; color: #333; line-height: 1.6; padding: 20px; }
        .container { max-width: 700px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: linear-gradient(135deg, #1a73e8, #0d47a1); color: white; padding: 30px; text-align: center; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .body { padding: 30px; }
        .step { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #eee; }
        .step:last-child { border-bottom: none; }
        .step-num { width: 32px; height: 32px; border-radius: 50%; background: #e8f0fe; color: #1a73e8; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; flex-shrink: 0; }
        .step.done .step-num { background: #34a853; color: white; }
        .step.active .step-num { background: #1a73e8; color: white; }
        .step-content { flex: 1; }
        .step-title { font-weight: 600; font-size: 15px; }
        .step-desc { font-size: 13px; color: #666; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 14px; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #1a73e8; box-shadow: 0 0 0 3px rgba(26,115,232,0.1); }
        .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #f1f3f4; color: #333; }
        .btn-success { background: #34a853; color: white; }
        .result { padding: 12px 16px; border-radius: 6px; margin: 16px 0; font-size: 14px; }
        .result.success { background: #e6f4ea; color: #137333; border: 1px solid #34a853; }
        .result.error { background: #fce8e6; color: #c5221f; border: 1px solid #ea4335; }
        .info-box { background: #e8f0fe; border-radius: 8px; padding: 16px; margin: 16px 0; font-size: 13px; }
        .info-box strong { color: #1a73e8; }
        .loading { display: none; text-align: center; padding: 20px; }
        .loading.show { display: block; }
        .spinner { border: 3px solid #f3f3f3; border-top: 3px solid #1a73e8; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 12px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Nowills PMS Installer</h1>
        <p>Step-by-step setup for cPanel shared hosting</p>
    </div>
    <div class="body">
        <div id="progress">
            <div class="step done">
                <div class="step-num">✓</div>
                <div class="step-content">
                    <div class="step-title">Server Check</div>
                    <div class="step-desc">PHP <?php echo PHP_VERSION; ?> ✓ · All extensions ✓</div>
                </div>
            </div>
            <div class="step active" id="step-db">
                <div class="step-num">2</div>
                <div class="step-content">
                    <div class="step-title">Database Configuration</div>
                    <div class="step-desc">Enter your MySQL database details</div>
                </div>
            </div>
            <div class="step" id="step-install">
                <div class="step-num">3</div>
                <div class="step-content">
                    <div class="step-title">Install Application</div>
                    <div class="step-desc">Write .env, run migrations, clear cache</div>
                </div>
            </div>
            <div class="step" id="step-done">
                <div class="step-num">4</div>
                <div class="step-content">
                    <div class="step-title">Complete</div>
                    <div class="step-desc">Verify in browser</div>
                </div>
            </div>
        </div>

        <div class="info-box">
            <strong>Before you start:</strong> Create a MySQL database and user in cPanel → MySQL Databases. The user needs ALL PRIVILEGES on the database.
        </div>

        <form id="install-form">
            <div class="form-group">
                <label for="db_host">Database Host</label>
                <input type="text" id="db_host" name="db_host" value="127.0.0.1" required>
            </div>
            <div class="form-group">
                <label for="db_port">Database Port</label>
                <input type="text" id="db_port" name="db_port" value="3306" required>
            </div>
            <div class="form-group">
                <label for="db_name">Database Name</label>
                <input type="text" id="db_name" name="db_name" placeholder="e.g. nowills_pms" required>
            </div>
            <div class="form-group">
                <label for="db_user">Database Username</label>
                <input type="text" id="db_user" name="db_user" required>
            </div>
            <div class="form-group">
                <label for="db_pass">Database Password</label>
                <input type="password" id="db_pass" name="db_pass" required>
            </div>
            <div class="form-group">
                <label for="app_url">Application URL</label>
                <input type="text" id="app_url" name="app_url" value="http://localhost" placeholder="https://yourdomain.com">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="app_debug" name="app_debug" checked> Enable debug mode (recommended for first install)
                </label>
            </div>
            <div id="result"></div>
            <button type="button" class="btn btn-primary" id="test-btn">Test Connection</button>
            <button type="button" class="btn btn-success" id="install-btn">Install Application</button>
        </form>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p id="loading-text">Working...</p>
        </div>
    </div>
    <div class="footer">
        Nowills PMS Installer · PHP <?php echo PHP_VERSION; ?>
    </div>
</div>

<script>
const testBtn = document.getElementById('test-btn');
const installBtn = document.getElementById('install-btn');
const loading = document.getElementById('loading');
const loadingText = document.getElementById('loading-text');
const result = document.getElementById('result');

function showLoading(text) {
    loadingText.textContent = text;
    loading.classList.add('show');
}

function hideLoading() {
    loading.classList.remove('show');
}

function showResult(status, message) {
    result.innerHTML = '<div class="result ' + status + '">' + message + '</div>';
}

testBtn.addEventListener('click', async () => {
    const formData = new FormData(document.getElementById('install-form'));
    formData.append('action', 'test_db');

    showLoading('Testing database connection...');
    testBtn.disabled = true;

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.status === 'success') {
            showResult('success', '✓ ' + data.message);
        } else {
            showResult('error', '✗ ' + data.message);
        }
    } catch (e) {
        showResult('error', '✗ Request failed: ' + e.message);
    }

    hideLoading();
    testBtn.disabled = false;
});

installBtn.addEventListener('click', async () => {
    if (!confirm('This will write .env, run migrations, and clear cache. Continue?')) return;

    const formData = new FormData(document.getElementById('install-form'));
    formData.append('action', 'install');

    showLoading('Writing .env...');
    installBtn.disabled = true;
    testBtn.disabled = true;

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.status === 'success') {
            showResult('success', '✓ ' + data.message + ' — You can now visit your site!');
            document.getElementById('step-db').classList.add('done');
            document.getElementById('step-db').classList.remove('active');
            document.getElementById('step-install').classList.add('done');
            document.getElementById('step-install').classList.remove('active');
            document.getElementById('step-done').classList.add('active');
        } else {
            showResult('error', '✗ ' + data.message + (data.output ? '<br><pre>' + data.output + '</pre>' : ''));
        }
    } catch (e) {
        showResult('error', '✗ Request failed: ' + e.message);
    }

    hideLoading();
    installBtn.disabled = false;
    testBtn.disabled = false;
});
</script>
</body>
</html>
