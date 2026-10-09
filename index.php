<?php
// ============================================================================
// 1. BACKEND ROUTER ENGINE: PASSWORD AUTHENTICATION & SECURITY CONTROLS
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'check_password') {
    header('Content-Type: application/json');
    $inputData = json_decode(file_get_contents('php://input'), true);
    $userPassword = $inputData['password'] ?? '';

    if (file_exists('auth.json')) {
        $auth = json_decode(file_get_contents('auth.json'), true);
        if (!empty($userPassword) && $userPassword === ($auth['password'] ?? '')) {
            // Secure cookie signature validation to bypass broken server session modules
            setcookie('vault_auth_signature', hash('sha256', $userPassword), time() + 86400, "/", "", false, true);
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Incorrect management authentication key.']);
    exit;
}

// Verify auth state using secure persistent signatures
$isAuthorized = false;
if (file_exists('auth.json') && isset($_COOKIE['vault_auth_signature'])) {
    $auth = json_decode(file_get_contents('auth.json'), true);
    if (!empty($auth['password']) && $_COOKIE['vault_auth_signature'] === hash('sha256', $auth['password'])) {
        $isAuthorized = true;
    }
}

// ============================================================================
// 2. BACKEND ROUTER ENGINE: SERVER-TO-SERVER DATA TRANSIT PROXY (JSON FORMAT)
// ============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'proxy_reconcile') {
    header('Content-Type: application/json');
    
    $from = $_POST['from'] ?? 'No Sender';
    $message = $_POST['message'] ?? 'No Message';

    // Extracted metadata fields for analytical transaction history logging
    $mpesa_code = $_POST['mpesa_code'] ?? 'UNKNOWN';
    $amount = $_POST['amount'] ?? '0.00';
    $name = $_POST['name'] ?? 'Unknown';
    $phone = $_POST['phone'] ?? 'Unknown';
    $timestamp = $_POST['timestamp'] ?? '';

    // Construct the exact JSON structure expected by api.php (Source 1)
    $payloadData = [
        'sender'     => $from,
        'body'       => $message,
        'timestamp'  => round(microtime(true) * 1000), // Android app millisecond timestamp format[cite: 1]
        'message_id' => 'RECON-' . strtoupper($mpesa_code) . '-' . time() // Unique identifier for deduplication[cite: 1]
    ];

    $payloadJson = json_encode($payloadData);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n" .
                         "Content-Length: " . strlen($payloadJson) . "\r\n",
            'content' => $payloadJson,
            'timeout' => 12
        ]
    ];

    $context = stream_context_create($opts);
    $remoteUrl = "http://bizfire.mywebcommunity.org/api.php";
    
    $result = @file_get_contents($remoteUrl, false, $context);

    // Build the history frame payload object
    $logEntry = [
        'timestamp' => !empty($timestamp) ? $timestamp : date('d/m/y \a\t h:i A'),
        'mpesa_code' => $mpesa_code,
        'amount' => $amount,
        'name' => $name,
        'phone' => $phone,
        'success' => ($result !== false && trim($result) === 'Data Received'),
        'response' => ($result !== false) ? trim($result) : 'The target web server failed to respond.'
    ];

    // Commit entry to local tracking logs
    $logFile = 'reconcile_logs.json';
    $existingLogs = [];
    if (file_exists($logFile)) {
        $existingLogs = json_decode(file_get_contents($logFile), true);
        if (!is_array($existingLogs)) {
            $existingLogs = [];
        }
    }
    array_unshift($existingLogs, $logEntry); // Push newest entries to the top
    if (count($existingLogs) > 300) { 
        $existingLogs = array_slice($existingLogs, 0, 300); // Caps logs size
    }
    file_put_contents($logFile, json_encode($existingLogs, JSON_PRETTY_PRINT));

    if ($result !== false && trim($result) === 'Data Received') {
        echo json_encode(['success' => true, 'server_response' => trim($result)]);
    } else {
        echo json_encode(['success' => false, 'message' => ($result !== false ? trim($result) : 'The target web server failed to respond.')]);
    }
    exit;
}

// ============================================================================
// 3. STORAGE SYNC DATA STREAMS: LOAD JSON ARRAYS NATIVELY (CURRENT & ARCHIVE)
// ============================================================================
$local_sessions_path = "../bundle_sessions.json";
$archive_sessions_path = "../archive_sessions.json"; // External JSON for yearly archives
$reconcile_logs_path = "reconcile_logs.json";

// Handle permanently purging ONLY unused sessions (expiry exactly 0)
if (isset($_GET['action']) && $_GET['action'] === 'purge_unused') {
    header('Content-Type: application/json');
    if (!$isAuthorized) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized action requested. Access denied.']);
        exit;
    }

    if (file_exists($local_sessions_path)) {
        $sessions = json_decode(file_get_contents($local_sessions_path), true);
        if (is_array($sessions)) {
            $initialCount = count($sessions);
            
            // STRICT FILTER: Keep only sessions where expiry is NOT exactly 0.
            $filtered = array_values(array_filter($sessions, function($s) {
                return (isset($s['expiry']) && (int)$s['expiry'] !== 0);
            }));
            
            if ($initialCount !== count($filtered)) {
                file_put_contents($local_sessions_path, json_encode($filtered, JSON_PRETTY_PRINT));
            }
            
            echo json_encode(['success' => true, 'removed' => ($initialCount - count($filtered))]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Database file not found.']);
    exit;
}

// Handle updating login count via dynamic state requests safely
if (isset($_GET['action']) && $_GET['action'] === 'update_login_count') {
    header('Content-Type: application/json');
    if (!$isAuthorized) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized action requested. Access denied.']);
        exit;
    }
    
    $inputData = json_decode(file_get_contents('php://input'), true);
    $phone = $inputData['phone'] ?? '';
    $newCount = isset($inputData['login_count']) ? (int)$inputData['login_count'] : -1;
    
    if (!empty($phone) && $newCount >= 0 && file_exists($local_sessions_path)) {
        $sessions = json_decode(file_get_contents($local_sessions_path), true);
        if (is_array($sessions)) {
            $updated = false;
            foreach ($sessions as &$session) {
                if (($session['phone'] ?? '') === $phone) {
                    $session['login_count'] = $newCount;
                    $updated = true;
                    break;
                }
            }
            if ($updated && file_put_contents($local_sessions_path, json_encode($sessions, JSON_PRETTY_PRINT)) !== false) {
                echo json_encode(['success' => true]);
                exit;
            }
        }
    }
    echo json_encode(['success' => false, 'message' => 'Failed to adjust persistent log state details.']);
    exit;
}

// Handle completely deleting a transaction session record across both json sources
if (isset($_GET['action']) && $_GET['action'] === 'delete_session') {
    header('Content-Type: application/json');
    if (!$isAuthorized) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized record purge blocked.']);
        exit;
    }

    $inputData = json_decode(file_get_contents('php://input'), true);
    $phone = $inputData['phone'] ?? '';

    if (empty($phone)) {
        echo json_encode(['success' => false, 'message' => 'Invalid structural profile target missing phone context.']);
        exit;
    }

    $localDeleted = false;
    if (file_exists($local_sessions_path)) {
        $localSessions = json_decode(file_get_contents($local_sessions_path), true);
        if (is_array($localSessions)) {
            $filteredLocal = array_values(array_filter($localSessions, function($session) use ($phone) {
                return ($session['phone'] ?? '') !== $phone;
            }));
            if (count($localSessions) !== count($filteredLocal)) {
                file_put_contents($local_sessions_path, json_encode($filteredLocal, JSON_PRETTY_PRINT));
                $localDeleted = true;
            }
        }
    }

    $archiveDeleted = false;
    if (file_exists($archive_sessions_path)) {
        $archiveSessions = json_decode(file_get_contents($archive_sessions_path), true);
        if (is_array($archiveSessions)) {
            $filteredArchive = array_values(array_filter($archiveSessions, function($session) use ($phone) {
                return ($session['phone'] ?? '') !== $phone;
            }));
            if (count($archiveSessions) !== count($filteredArchive)) {
                file_put_contents($archive_sessions_path, json_encode($filteredArchive, JSON_PRETTY_PRINT));
                $archiveDeleted = true;
            }
        }
    }

    if ($localDeleted || $archiveDeleted) {
        echo json_encode(['success' => true, 'message' => 'Target vectors systematically eradicated from active records.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active log trace references matched the input pointer parameters.']);
    }
    exit;
}

$sessions_json_raw = "[]";
if (file_exists($local_sessions_path)) {
    $sessions_json_raw = file_get_contents($local_sessions_path);
}

$archive_json_raw = "[]";
if (file_exists($archive_sessions_path)) {
    $archive_json_raw = file_get_contents($archive_sessions_path);
}

$reconcile_logs_raw = "[]";
if (file_exists($reconcile_logs_path)) {
    $reconcile_logs_raw = file_get_contents($reconcile_logs_path);
}

// ============================================================================
// 4. OVERHEAD CONFIGURATION DATA STREAMS: PERSISTENT JSON PERSISTENCE ENGINE
// ============================================================================
$settings_file = 'settings.json';

if (isset($_GET['action']) && $_GET['action'] === 'save_settings') {
    header('Content-Type: application/json');
    if (!$isAuthorized) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized parameter write attempt blocked.']);
        exit;
    }

    $inputData = file_get_contents('php://input');
    if (json_decode($inputData) !== null) {
        if (file_put_contents($settings_file, $inputData) !== false) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save runtime configurations to disk.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data payload scheme discovered.']);
    }
    exit;
}

$default_settings = ['internet' => 3500, 'electricityUnits' => 8, 'caretaker' => 1500];
$current_settings = $default_settings;

if (file_exists($settings_file)) {
    $saved_data = json_decode(file_get_contents($settings_file), true);
    if (is_array($saved_data)) {
        $current_settings = array_merge($default_settings, $saved_data);
    }
}

// ============================================================================
// 5. EXEMPTIONS DATA STREAM: PERSISTENT JSON
// ============================================================================
$exemptions_file = 'exemptions.json';

if (isset($_GET['action']) && $_GET['action'] === 'save_exemptions') {
    header('Content-Type: application/json');
    if (!$isAuthorized) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized action.']);
        exit;
    }
    
    $inputData = file_get_contents('php://input');
    if (json_decode($inputData) !== null) {
        file_put_contents($exemptions_file, $inputData);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

$exemptions_raw = "[]";
if (file_exists($exemptions_file)) {
    $exemptions_raw = file_get_contents($exemptions_file);
}

// ============================================================================
// 6. ROUTER CONNECTION CONTROL PLANE
// ============================================================================
// ============================================================================
// 6. ROUTER CONNECTION CONTROL PLANE
// ============================================================================
$router_connections_url = 'http://bizfire.mywebcommunity.org/router_connections.json';
$router_commands_url = 'http://bizfire.mywebcommunity.org/router_commands.json';

if (isset($_GET['action']) && $_GET['action'] === 'router_connections') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAuthorized) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $connections = [];
    $remote_data = @file_get_contents($router_connections_url);
    if ($remote_data !== false) {
        $decoded = json_decode($remote_data, true);
        if (is_array($decoded)) {
            $connections = $decoded;
        }
    }

    if (isset($connections['connections']) && is_array($connections['connections'])) {
        $connections = $connections['connections'];
    }

    echo json_encode([
        'success' => true,
        'server_time' => time(),
        'connections' => array_values($connections)
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'disconnect_device') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAuthorized) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'message' => 'Invalid command payload.']);
        exit;
    }

    $mac = trim((string)($input['mac'] ?? ''));
    $ip = trim((string)($input['ip'] ?? ''));
    $username = trim((string)($input['username'] ?? ''));
    $session_id = trim((string)($input['session_id'] ?? ''));

    if ($mac === '' && $ip === '' && $username === '' && $session_id === '') {
        echo json_encode(['success' => false, 'message' => 'A device identifier is required.']);
        exit;
    }

    $commands = [];
    $remote_commands_data = @file_get_contents($router_commands_url);
    if ($remote_commands_data !== false) {
        $existing = json_decode($remote_commands_data, true);
        if (is_array($existing)) {
            $commands = $existing;
        }
    }

    $command = [
        'id' => 'CMD-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)),
        'type' => 'DISCONNECT_USER',
        'status' => 'queued',
        'created_at' => time(),
        'created_at_iso' => date('c'),
        'target' => [
            'mac' => $mac,
            'ip' => $ip,
            'username' => $username,
            'session_id' => $session_id
        ]
    ];

    $commands[] = $command;
    
    // Note: If your local PHP backend needs to write the commands file back locally for Termux, 
    // keep 'router_commands.json' for file_put_contents, or point it to your remote write mechanism if needed.
    $router_commands_file = 'router_commands.json'; 
    if (file_put_contents($router_commands_file, json_encode($commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        echo json_encode(['success' => false, 'message' => 'Could not queue the disconnect command.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Disconnect command queued.', 'command' => $command]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BizMtandaoni Security Protected Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'Space Mono', monospace; }
    </style>
</head>
<body class="bg-[#0b0f19] text-slate-100 min-h-screen antialiased selection:bg-indigo-500/30 selection:text-indigo-200">

    <div id="authGatekeeper" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[#070a12] backdrop-blur-xl">
        <div class="bg-[#111827] w-full max-w-sm rounded-2xl border border-slate-800 shadow-2xl p-6 text-center">
            <div class="mx-auto w-12 h-12 bg-emerald-500/10 text-emerald-400 rounded-full flex items-center justify-center mb-4">
                <i data-lucide="lock" class="w-6 h-6"></i>
            </div>
            <h2 class="text-xl font-bold text-slate-100">Authentication Required</h2>
            <p class="text-xs text-slate-400 mt-1">Provide system credentials to load transaction analytics streams.</p>
            
            <form id="authForm" class="mt-4 space-y-3">
                <input type="password" id="gatekeeperPassword" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-center tracking-widest font-mono text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-emerald-500/40 text-sm" placeholder="••••••••" required />
                <div id="authFeedback" class="hidden p-2 text-rose-400 font-medium text-xs bg-rose-950/40 border border-rose-900 rounded-xl"></div>
                <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-medium rounded-xl text-sm transition-all shadow-lg shadow-emerald-900/20 cursor-pointer">
                    Unlock Management Vault
                </button>
            </form>
        </div>
    </div>

    <div id="dashboardContainer" class="opacity-0 pointer-events-none transition-opacity duration-500">
        <nav class="border-b border-slate-800 bg-[#0f172a]/60 backdrop-blur-md sticky top-0 z-40 px-6 py-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <button id="menuBtn" class="p-2 hover:bg-slate-800 rounded-lg text-slate-300 transition-colors cursor-pointer">
                    <i data-lucide="menu" class="w-6 h-6"></i>
                </button>
                <div>
                    <h1 class="text-base font-bold tracking-tight text-white flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500 inline-block"></span> BizMtandaoni Admin
                    </h1>
                    <p class="text-xs text-slate-400 font-medium pl-3.5">MikroTik Hotspot Automation Panel</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="purgeUnusedSessions()" class="bg-amber-900/40 hover:bg-amber-900/60 text-amber-400 border border-amber-900/60 font-medium px-4 py-2 rounded-xl flex items-center gap-2 text-sm transition-all cursor-pointer">
                    <i data-lucide="trash" class="w-4 h-4"></i> Purge Unused
                </button>
                <button onclick="openModal('reconcileLogsModal')" class="bg-slate-800/80 hover:bg-slate-700 text-slate-200 border border-slate-700/60 font-medium px-4 py-2 rounded-xl flex items-center gap-2 text-sm transition-colors cursor-pointer">
                    <i data-lucide="history" class="w-4 h-4"></i> Dispatch History
                </button>
                <button onclick="openModal('reconcileModal')" class="bg-indigo-600 hover:bg-indigo-500 text-white font-medium px-4 py-2 rounded-xl flex items-center gap-2 text-sm transition-all shadow-lg shadow-indigo-900/20 cursor-pointer">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i> Reconcile SMS
                </button>
            </div>
        </nav>

        <div id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-[#111827] border-r border-slate-800/80 z-50 transform -translate-x-full transition-transform duration-300 ease-in-out shadow-2xl">
            <div class="p-6 border-b border-slate-800/80 flex justify-between items-center">
                <span class="font-bold text-slate-300 text-sm tracking-wide uppercase">Navigation</span>
                <button id="closeSidebar" class="p-1 hover:bg-slate-400 rounded-lg text-slate-400 cursor-pointer">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="p-4 space-y-2">
                <button onclick="closeSidebarDrawer();" class="w-full flex items-center gap-3 px-4 py-3 text-slate-300 bg-slate-800/60 border border-slate-700/50 rounded-xl font-medium text-left cursor-pointer">
                    <i data-lucide="pie-chart" class="w-5 h-5 text-indigo-400"></i> Main Metrics
                </button>
                <button onclick="openModal('reconcileLogsModal')" class="w-full flex items-center gap-3 px-4 py-3 text-slate-400 hover:bg-slate-800/40 hover:text-slate-200 rounded-xl text-left transition-colors cursor-pointer">
                    <i data-lucide="history" class="w-5 h-5 text-purple-400"></i> Dispatch History
                </button>
                <button onclick="openModal('settingsModal')" class="w-full flex items-center gap-3 px-4 py-3 text-slate-400 hover:bg-slate-800/40 hover:text-slate-200 rounded-xl text-left transition-colors cursor-pointer">
                    <i data-lucide="sliders" class="w-5 h-5 text-blue-400"></i> Cost Parameters
                </button>
                <button onclick="logoutVault()" class="w-full flex items-center gap-3 px-4 py-3 text-rose-400 hover:bg-rose-950/20 rounded-xl text-left transition-colors mt-8 border border-rose-900/30 cursor-pointer">
                    <i data-lucide="log-out" class="w-5 h-5"></i> Close Vault
                </button>
            </div>
        </div>

        <main class="max-w-7xl mx-auto p-4 md:p-8 space-y-8">
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-6 relative overflow-hidden group hover:border-slate-700/80 transition-all shadow-xl shadow-black/20">
                    <div class="flex justify-between items-start">
                        <p class="text-sm font-medium text-slate-400">Monthly Gross</p>
                        <div id="monthlyGrossDelta" class="text-xs font-semibold px-2 py-0.5 rounded-md flex items-center gap-0.5"></div>
                    </div>
                    <div class="flex items-baseline gap-1.5 mt-2">
                        <span class="text-xs font-bold text-slate-500">KSH</span>
                        <span id="monthlyGrossDisplay" class="text-3xl font-bold tracking-tight text-white">0.00</span>
                    </div>
                    <span class="text-xs text-emerald-400 flex items-center gap-1 mt-2 font-medium">vs Last Month</span>
                </div>

                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-6 relative overflow-hidden group bg-gradient-to-br from-[#111827] to-emerald-950/10 shadow-xl shadow-black/20">
                    <div class="flex justify-between items-start">
                        <p class="text-sm font-medium text-indigo-400 uppercase tracking-wider font-semibold">Monthly Net</p>
                        <div id="monthlyNetDelta" class="text-xs font-semibold px-2 py-0.5 rounded-md flex items-center gap-0.5"></div>
                    </div>
                    <div class="flex items-baseline gap-1.5 mt-2">
                        <span class="text-xs font-bold text-slate-500">KSH</span>
                        <span id="monthlyNetDisplay" class="text-3xl font-bold tracking-tight text-emerald-400">0.00</span>
                    </div>
                    <span class="text-xs text-slate-400 block mt-2">After Fixed Overhead Adjustments</span>
                </div>
                
                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-6 relative overflow-hidden group hover:border-slate-700/80 transition-all shadow-xl shadow-black/20">
                    <div class="flex justify-between items-start">
                        <p class="text-sm font-medium text-slate-400">Yearly Gross</p>
                        <div id="yearlyGrossDelta" class="text-xs font-semibold px-2 py-0.5 rounded-md flex items-center gap-0.5"></div>
                    </div>
                    <div class="flex items-baseline gap-1.5 mt-2">
                        <span class="text-xs font-bold text-slate-500">KSH</span>
                        <span id="yearlyGrossDisplay" class="text-3xl font-bold tracking-tight text-white">0.00</span>
                    </div>
                    <span class="text-xs text-indigo-400 flex items-center gap-1 mt-2 font-medium">vs Previous Year Archive</span>
                </div>

                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-6 relative overflow-hidden group bg-gradient-to-br from-[#111827] to-indigo-950/20 shadow-xl shadow-black/20">
                    <div class="flex justify-between items-start">
                        <p class="text-sm font-medium text-indigo-400 uppercase tracking-wider font-semibold">Yearly Net</p>
                        <div id="yearlyNetDelta" class="text-xs font-semibold px-2 py-0.5 rounded-md flex items-center gap-0.5"></div>
                    </div>
                    <div class="flex items-baseline gap-1.5 mt-2">
                        <span class="text-xs font-bold text-slate-500">KSH</span>
                        <span id="yearlyNetDisplay" class="text-3xl font-bold tracking-tight text-indigo-400">0.00</span>
                    </div>
                    <span class="text-xs text-slate-400 block mt-2">Adjusted YTD Costs</span>
                </div>

            </div>

            <div class="bg-gradient-to-r from-slate-900 via-indigo-950/20 to-slate-900 border border-indigo-500/30 rounded-2xl p-6 shadow-xl relative overflow-hidden">
                <div class="absolute right-4 top-4 text-indigo-500/20">
                    <i data-lucide="sparkles" class="w-12 h-12"></i>
                </div>
                <div class="max-w-2xl">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-400 flex items-center gap-2">
                        <i data-lucide="trending-up" class="w-4 h-4"></i> Next Month Predictive Analysis (Active Renewals)
                    </h3>
                    <p class="text-xs text-slate-400 mt-1">
                        If all currently <span class="text-emerald-400 font-semibold">Active</span> users purchase their exact same package next month, here is the projected income after subtracting your runtime configurations:
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-4">
                        <div class="bg-[#0b0f19]/60 rounded-xl p-4 border border-slate-800/80">
                            <span class="text-xs text-slate-400 block">Projected Gross Income</span>
                            <div class="flex items-baseline gap-1.5 mt-1">
                                <span class="text-xs font-bold text-slate-500">KSH</span>
                                <span id="predictiveGross" class="text-2xl font-bold text-white">0.00</span>
                            </div>
                            <span id="predictiveCount" class="text-[11px] text-slate-500 block mt-1">Based on 0 active profiles</span>
                        </div>
                        <div class="bg-emerald-950/20 rounded-xl p-4 border border-emerald-900/30">
                            <span class="text-xs text-emerald-400 block">Projected Net Profit</span>
                            <div class="flex items-baseline gap-1.5 mt-1">
                                <span class="text-xs font-bold text-emerald-600">KSH</span>
                                <span id="predictiveNet" class="text-2xl font-bold text-emerald-400">0.00</span>
                            </div>
                            <span class="text-[11px] text-slate-500 block mt-1">Adjusted for monthly overhead offsets</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-6 shadow-xl lg:col-span-2">
                    <div>
                        <h2 class="text-base font-bold text-white">Historical Revenue Velocity</h2>
                        <p class="text-xs text-slate-400 mb-6">Visual tracking logs aggregated systematically across all active and archived data</p>
                    </div>
                    <div class="h-64 w-full relative">
                        <canvas id="trajectoryChart"></canvas>
                    </div>
                </div>

                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-6 shadow-xl flex flex-col justify-between">
                    <div>
                        <h2 class="text-base font-bold text-white">Current Month Session Status Distribution</h2>
                        <p class="text-xs text-slate-400 mb-4">Breakdown analysis of active bundles, unused vouchers, and expired packages within this calendar month</p>
                    </div>
                    <div class="h-52 w-full relative mx-auto flex items-center justify-center">
                        <canvas id="distributionPieChart"></canvas>
                    </div>
                    <div class="grid grid-cols-3 text-center text-xs mt-4 pt-4 border-t border-slate-800/60">
                        <div>
                            <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block mr-1"></span>
                            <span class="text-slate-400">Active</span>
                            <div id="pieCountActive" class="font-bold text-slate-200 mt-0.5">0</div>
                        </div>
                        <div>
                            <span class="w-2 h-2 rounded-full bg-amber-500 inline-block mr-1"></span>
                            <span class="text-slate-400">Unused</span>
                            <div id="pieCountUnused" class="font-bold text-slate-200 mt-0.5">0</div>
                        </div>
                        <div>
                            <span class="w-2 h-2 rounded-full bg-rose-500 inline-block mr-1"></span>
                            <span class="text-slate-400">Expired</span>
                            <div id="pieCountExpired" class="font-bold text-slate-200 mt-0.5">0</div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-5 shadow-xl shadow-black/10">
                    <p class="text-xs font-medium text-slate-400">Overhead ISP Cost (Monthly)</p>
                    <div class="flex items-baseline gap-1.5 mt-1">
                        <span class="text-xs font-bold text-slate-500">KSH</span>
                        <span id="internetCostDisplay" class="text-2xl font-bold text-white">0.00</span>
                    </div>
                </div>
                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-5 shadow-xl shadow-black/10">
                    <p class="text-xs font-medium text-slate-400">Electricity Est. (Monthly)</p>
                    <div class="flex items-baseline gap-1.5 mt-1">
                        <span class="text-xs font-bold text-slate-500">KSH</span>
                        <span id="electricityCostDisplay" class="text-2xl font-bold text-white">0.00</span>
                    </div>
                </div>
                <div class="bg-[#111827] border border-slate-800/80 rounded-2xl p-5 shadow-xl shadow-black/10">
                    <p class="text-xs font-medium text-slate-400">Caretaker Stipend (Monthly)</p>
                    <div class="flex items-baseline gap-1.5 mt-1">
                        <span class="text-xs font-bold text-slate-500">KSH</span>
                        <span id="caretakerCostDisplay" class="text-2xl font-bold text-white">0.00</span>
                    </div>
                </div>
            </div>

            <!-- ================================================================= -->
            <!-- LIVE ROUTER CONNECTIONS - ADMIN CONTROL LAYER                  -->
            <!-- ================================================================= -->
            <div class="bg-[#111827] border border-slate-800/80 rounded-2xl shadow-xl shadow-black/25 overflow-hidden">
                <div class="p-5 border-b border-slate-800/80 bg-[#151f32]/40 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold text-white">Live Router Connections</h2>
                            <span id="routerLiveBadge" class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-slate-800 text-slate-400 border border-slate-700">OFFLINE</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">Every device currently connected through MikroTik, regardless of which payment method was used.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-[#0b0f19] border border-slate-800">
                            <span class="text-[10px] uppercase tracking-wider text-slate-500">Online</span>
                            <span id="routerOnlineCount" class="font-bold text-emerald-400">0</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-[#0b0f19] border border-slate-800">
                            <span class="text-[10px] uppercase tracking-wider text-slate-500">Unknown</span>
                            <span id="routerUnknownCount" class="font-bold text-amber-400">0</span>
                        </div>
                        <button onclick="refreshRouterConnections(true)" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-semibold flex items-center gap-2 cursor-pointer">
                            <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="p-4 border-b border-slate-800/70 bg-[#0f172a]/30">
                    <div class="relative max-w-md">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500"></i>
                        <input type="text" id="routerConnectionSearch" oninput="renderRouterConnections()" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl pl-9 pr-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-xs" placeholder="Search phone, MAC, IP or username..." />
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[1050px]">
                        <thead>
                            <tr class="bg-[#0f172a]/40 text-slate-400 text-[10px] font-semibold uppercase tracking-wider border-b border-slate-800">
                                <th class="p-4">Customer / Phone</th>
                                <th class="p-4">Device</th>
                                <th class="p-4">Network</th>
                                <th class="p-4">Connected</th>
                                <th class="p-4">Access Method</th>
                                <th class="p-4">Expiry</th>
                                <th class="p-4">Status</th>
                                <th class="p-4 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="routerConnectionsBody" class="divide-y divide-slate-800/60 text-xs">
                            <tr><td colspan="8" class="p-8 text-center text-slate-500">Waiting for the MikroTik agent...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3 border-t border-slate-800/70 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <span id="routerLastUpdate" class="text-[10px] text-slate-500">No connection snapshot received yet.</span>
                    <span class="text-[10px] text-slate-600">Disconnect only terminates the live session; it does not delete the customer.</span>
                </div>
            </div>

            <div class="bg-[#111827] border border-slate-800/80 rounded-2xl shadow-xl shadow-black/25 overflow-hidden">
                <div class="p-5 border-b border-slate-800/80 bg-[#151f32]/40 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 class="text-base font-bold text-white">Database Session Tracing Logs</h2>
                        <p class="text-xs text-slate-400">Real-time dynamic logs with newly activated devices displayed chronologically</p>
                    </div>
                    <div>
                        <input type="text" id="sessionSearchInput" oninput="initDashboardComponents()" class="w-full sm:w-64 bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-xs" placeholder="Search phone number..." />
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-[#0f172a]/40 text-slate-400 text-xs font-semibold uppercase tracking-wider border-b border-slate-800">
                                <th class="p-4">Target Registration</th>
                                <th class="p-4">Expected</th>
                                <th class="p-4">Paid Realized</th>
                                <th class="p-4">Voucher Codes</th>
                                <th class="p-4 text-center">Logins (Limit: 4)</th>
                                <th class="p-4">Calculated Expiry</th>
                                <th class="p-4">Status</th>
                                <th class="p-4 text-center">Exempt</th>
                                <th class="p-4 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="sessionTableBody" class="divide-y divide-slate-800/60 text-sm">
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div id="reconcileModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
        <div class="bg-[#111827] w-full max-w-lg rounded-2xl border border-slate-800 shadow-2xl overflow-hidden">
            <div class="p-6 border-b border-slate-800 flex justify-between items-center bg-[#151f32]/40">
                <h3 class="text-lg font-bold text-slate-100 flex items-center gap-2">
                    <i data-lucide="refresh-cw" class="text-indigo-400 w-5 h-5"></i> Force System Reconciliation
                </h3>
                <button onclick="closeModal('reconcileModal')" class="text-slate-400 hover:text-slate-200 p-1 hover:bg-slate-800 rounded-lg cursor-pointer">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <form id="reconciliationForm" class="p-6 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">MPESA Code</label>
                        <input type="text" id="reconMpesaCode" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-sm font-mono uppercase" placeholder="e.g. UFL8S5AWX2" required />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Amount Paid (KSH)</label>
                        <input type="text" id="reconAmount" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-sm font-mono" placeholder="e.g. 1.00" required />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Customer Full Name</label>
                    <input type="text" id="reconCustomerName" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-sm" placeholder="e.g. Richard Wanjohi Mwangi" required />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Customer Phone Number</label>
                    <input type="text" id="reconPhone" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-sm font-mono" placeholder="e.g. 2547XXXXXXXX" required />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Sender Profile Tag</label>
                        <input type="text" id="sender" value="MPESA" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-sm" required />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Transaction Timestamp</label>
                        <input type="text" id="smsTime" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-sm font-mono" placeholder="21/5/26 at 5:45 PM" required />
                    </div>
                </div>
                <div id="formFeedback" class="hidden p-3 rounded-xl text-xs font-medium"></div>
                <div class="pt-2 flex justify-end gap-3">
                    <button type="button" onclick="closeModal('reconcileModal')" class="px-4 py-2.5 border border-slate-800 text-slate-300 rounded-xl text-sm font-medium hover:bg-slate-800/60 transition-colors cursor-pointer">Cancel</button>
                    <button type="submit" id="submitBtn" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-sm font-medium transition-all shadow-lg shadow-indigo-900/30 flex items-center gap-2 cursor-pointer">
                        <span>Compile & Dispatch</span> <i data-lucide="send" class="w-4 h-4"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="reconcileLogsModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
        <div class="bg-[#111827] w-full max-w-4xl rounded-2xl border border-slate-800 shadow-2xl overflow-hidden">
            <div class="p-6 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-[#151f32]/40">
                <h3 class="text-lg font-bold text-slate-100 flex items-center gap-2">
                    <i data-lucide="history" class="text-indigo-400 w-5 h-5"></i> SMS Reconciliation Dispatch History
                </h3>
                <div class="flex items-center gap-3">
                    <input type="text" id="logSearchInput" oninput="renderReconcileLogsComponent()" class="bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-1.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-xs" placeholder="Search by customer name..." />
                    <button onclick="closeModal('reconcileLogsModal')" class="text-slate-400 hover:text-slate-200 p-1 hover:bg-slate-800 rounded-lg cursor-pointer">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div class="overflow-x-auto max-h-[55vh] overflow-y-auto border border-slate-800 rounded-xl">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-[#0f172a]/90 text-slate-400 text-xs font-semibold uppercase tracking-wider border-b border-slate-800 sticky top-0 z-10 backdrop-blur-md">
                                <th class="p-4">Timestamp</th>
                                <th class="p-4">MPESA Code</th>
                                <th class="p-4">Target Customer</th>
                                <th class="p-4">Amount</th>
                                <th class="p-4">Status</th>
                                <th class="p-4">Gateway Proxy Response</th>
                            </tr>
                        </thead>
                        <tbody id="reconcileLogsTableBody" class="divide-y divide-slate-800/60 text-sm">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="settingsModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
        <div class="bg-[#111827] w-full max-w-md rounded-2xl border border-slate-800 shadow-2xl overflow-hidden">
            <div class="p-6 border-b border-slate-800 flex justify-between items-center bg-[#151f32]/40">
                <h3 class="text-lg font-bold text-slate-100 flex items-center gap-2">
                    <i data-lucide="sliders" class="text-blue-400 w-5 h-5"></i> Resource Overhead Parameters
                </h3>
                <button onclick="closeModal('settingsModal')" class="text-slate-400 hover:text-slate-200 p-1 hover:bg-slate-800 rounded-lg cursor-pointer">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Monthly Bandwidth Connection Rate (KSH)</label>
                    <input type="number" id="inputInternet" value="<?php echo htmlspecialchars($current_settings['internet']); ?>" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500/40 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Power Metric Allocations (kWh units)</label>
                    <input type="number" id="inputElectricityUnits" value="<?php echo htmlspecialchars($current_settings['electricityUnits']); ?>" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500/40 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Caretaker Stipend Profile Allocation (KSH)</label>
                    <input type="number" id="inputCaretaker" value="<?php echo htmlspecialchars($current_settings['caretaker']); ?>" class="w-full bg-[#0b0f19] border border-slate-700/80 rounded-xl px-4 py-2.5 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500/40 text-sm" />
                </div>
                <div class="pt-2">
                    <button id="saveSettingsBtn" onclick="applyCostParameters()" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-sm font-medium transition-colors w-full shadow-lg shadow-blue-900/20 cursor-pointer flex justify-center items-center gap-2">
                        Update Dashboard Computations
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let bundleSessions = <?php echo $sessions_json_raw; ?>;
        let archiveSessions = <?php echo $archive_json_raw; ?>;
        let reconcileLogs = <?php echo $reconcile_logs_raw; ?>;
        let trajectoryChartInstance = null;
        let distributionPieChartInstance = null;
        let routerConnections = [];
        let routerRefreshTimer = null;
        
        let exemptSessionsList = <?php echo $exemptions_raw; ?>;

        if (exemptSessionsList.length === 0 && localStorage.getItem('exempt_sessions')) {
            exemptSessionsList = JSON.parse(localStorage.getItem('exempt_sessions'));
            syncExemptionsToBackend();
        }

        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
            setSmsTimeNow();
            
            if (document.cookie.split('; ').find(row => row.startsWith('vault_auth_signature='))) {
                bypassGatekeeper();
            }

            document.getElementById('authForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const inputPass = document.getElementById('gatekeeperPassword').value;
                const feedback = document.getElementById('authFeedback');

                fetch('index.php?action=check_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: inputPass })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        bypassGatekeeper();
                    } else {
                        feedback.innerText = data.message;
                        feedback.classList.remove('hidden');
                    }
                })
                .catch(() => {
                    feedback.innerText = "Error linking to backend.";
                    feedback.classList.remove('hidden');
                });
            });

            document.getElementById('reconciliationForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const feedback = document.getElementById('formFeedback');
                const submitBtn = document.getElementById('submitBtn');
                
                const mpesaCode = document.getElementById('reconMpesaCode').value.trim().toUpperCase();
                const amount = parseFloat(document.getElementById('reconAmount').value.trim()).toFixed(2);
                const name = document.getElementById('reconCustomerName').value.trim();
                const phone = document.getElementById('reconPhone').value.trim();
                const timestamp = document.getElementById('smsTime').value.trim();
                const senderTag = document.getElementById('sender').value.trim();

                const constructedSms = `${mpesaCode} Confirmed.on ${timestamp} KSH${amount} received from ${phone} ${name}. New Account balance is KSH${amount}. Transaction cost, KSH0.00.`;

                feedback.className = "p-3 rounded-xl text-xs font-medium bg-indigo-950/40 border border-indigo-900 text-indigo-300 block";
                feedback.innerText = "Routing to gateway backend proxy...";
                submitBtn.disabled = true;

                const formData = new FormData();
                formData.append('from', senderTag);
                formData.append('message', constructedSms);
                formData.append('mpesa_code', mpesaCode);
                formData.append('amount', amount);
                formData.append('name', name);
                formData.append('phone', phone);
                formData.append('timestamp', timestamp);

                fetch('index.php?action=proxy_reconcile', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    submitBtn.disabled = false;
                    if (data.success) {
                        feedback.className = "p-3 rounded-xl text-xs font-medium bg-emerald-950/50 border border-emerald-900 text-emerald-400 block";
                        feedback.innerText = "Success! " + data.server_response;
                        setTimeout(() => {
                            closeModal('reconcileModal');
                            window.location.reload();
                        }, 2000);
                    } else {
                        feedback.className = "p-3 rounded-xl text-xs font-medium bg-rose-950/50 border border-rose-900 text-rose-400 block";
                        feedback.innerText = data.message;
                    }
                })
                .catch(() => {
                    submitBtn.disabled = false;
                    feedback.className = "p-3 rounded-xl text-xs font-medium bg-rose-950/50 border border-rose-900 text-rose-400 block";
                    feedback.innerText = "Proxy execution error.";
                });
            });

            setupNavigationEventListeners();
        });

        function purgeUnusedSessions() {
            if (!confirm("Permanently erase ONLY 'Unused' (expiry: 0) sessions?")) return;
            fetch('index.php?action=purge_unused', { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`Removed ${data.removed} unused records.`);
                    window.location.reload();
                } else {
                    alert(data.message || "Failed to execute purge.");
                }
            })
            .catch(() => alert("Network error."));
        }

        function bypassGatekeeper() {
            document.getElementById('authGatekeeper').classList.add('hidden');
            const container = document.getElementById('dashboardContainer');
            container.classList.remove('opacity-0', 'pointer-events-none');
            initDashboardComponents();
        }

        function logoutVault() {
            document.cookie = "vault_auth_signature=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            window.location.reload();
        }

        function setSmsTimeNow() {
            const now = new Date();
            const day = now.getDate();
            const month = now.getMonth() + 1;
            const year = String(now.getFullYear()).slice(-2);
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            document.getElementById('smsTime').value = `${day}/${month}/${year} at ${hours}:${minutes} ${ampm}`;
        }

        function setupNavigationEventListeners() {
            document.getElementById('menuBtn').addEventListener('click', () => {
                document.getElementById('sidebar').classList.remove('-translate-x-full');
            });
            document.getElementById('closeSidebar').addEventListener('click', closeSidebarDrawer);
        }

        function closeSidebarDrawer() {
            document.getElementById('sidebar').classList.add('-translate-x-full');
        }

        document.getElementById('dashboardContainer').addEventListener('click', (e) => {
            if (window.innerWidth < 1024 && !document.getElementById('sidebar').classList.contains('-translate-x-full') && !e.target.closest('#sidebar') && !e.target.closest('#menuBtn')) {
                closeSidebarDrawer();
            }
        });

        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
            document.getElementById(id).classList.add('flex');
            closeSidebarDrawer();
            if (id === 'reconcileLogsModal') {
                renderReconcileLogsComponent();
            }
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }

        function toggleExemption(exemptionKey) {
            if (exemptSessionsList.includes(exemptionKey)) {
                exemptSessionsList = exemptSessionsList.filter(k => k !== exemptionKey);
            } else {
                exemptSessionsList.push(exemptionKey);
            }
            syncExemptionsToBackend();
            initDashboardComponents();
        }

        function syncExemptionsToBackend() {
            localStorage.setItem('exempt_sessions', JSON.stringify(exemptSessionsList));
            fetch('index.php?action=save_exemptions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(exemptSessionsList)
            }).catch(() => console.error("Sync error."));
        }

        function applyCostParameters() {
            const btn = document.getElementById('saveSettingsBtn');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = 'Syncing...';
            btn.disabled = true;

            const payload = {
                internet: parseFloat(document.getElementById('inputInternet').value) || 0,
                electricityUnits: parseFloat(document.getElementById('inputElectricityUnits').value) || 0,
                caretaker: parseFloat(document.getElementById('inputCaretaker').value) || 0
            };

            fetch('index.php?action=save_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                if (data.success) {
                    initDashboardComponents();
                    closeModal('settingsModal');
                } else {
                    alert(data.message || "Failed to commit metrics.");
                }
            })
            .catch(() => {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                alert("Network communication failure.");
            });
        }

        function mutateLoginCount(phone, incrementalOffset) {
            const targetInput = document.getElementById(`loginInput_${phone}`);
            if(!targetInput) return;
            
            let currentVal = parseInt(targetInput.value) || 0;
            let newVal = currentVal + incrementalOffset;
            if(newVal < 0) newVal = 0; 
            
            targetInput.value = newVal;

            fetch('index.php?action=update_login_count', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone: phone, login_count: newVal })
            })
            .then(res => res.json())
            .then(data => {
                if(!data.success) {
                    alert(data.message || "Failed to sync adjusted login state.");
                    targetInput.value = currentVal; 
                }
            })
            .catch(() => {
                alert("Network communication failure.");
                targetInput.value = currentVal;
            });
        }

        function executeSessionErase(phone) {
            if (!confirm(`Erase session ${phone}? This cannot be undone.`)) return;

            fetch('index.php?action=delete_session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone: phone })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    bundleSessions = bundleSessions.filter(s => s.phone !== phone);
                    archiveSessions = archiveSessions.filter(s => s.phone !== phone);
                    initDashboardComponents();
                } else {
                    alert(data.message || "Deletion failure.");
                }
            })
            .catch(() => alert("Network communication failure."));
        }

        // ========================================================================
        // LIVE MIKROTIK CONNECTION MONITOR
        // ========================================================================
        function escapeRouterHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function routerConnectionValue(connection, keys, fallback = '') {
            for (const key of keys) {
                if (connection && connection[key] !== undefined && connection[key] !== null && String(connection[key]).trim() !== '') {
                    return connection[key];
                }
            }
            return fallback;
        }

        function formatRouterTime(value) {
            if (value === undefined || value === null || value === '') return '--';
            let date;
            if (typeof value === 'number' || /^\d+$/.test(String(value))) {
                const n = Number(value);
                date = new Date(n < 100000000000 ? n * 1000 : n);
            } else {
                date = new Date(value);
            }
            if (Number.isNaN(date.getTime())) return escapeRouterHtml(value);
            return date.toLocaleString('en-KE', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        function routerUptime(connection) {
            const direct = routerConnectionValue(connection, ['uptime', 'session_uptime'], '');
            if (direct) return String(direct);
            const since = routerConnectionValue(connection, ['connected_since', 'login_time', 'start'], '');
            if (!since) return '--';
            let t = Number(since);
            if (!Number.isNaN(t) && t < 100000000000) t *= 1000;
            else if (Number.isNaN(t)) t = new Date(since).getTime();
            if (!t || Number.isNaN(t)) return '--';
            const seconds = Math.max(0, Math.floor((Date.now() - t) / 1000));
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            return h > 0 ? `${h}h ${m}m` : `${m}m`;
        }

        function renderRouterConnections() {
            const body = document.getElementById('routerConnectionsBody');
            if (!body) return;

            const query = (document.getElementById('routerConnectionSearch')?.value || '').trim().toLowerCase();
            let rows = Array.isArray(routerConnections) ? [...routerConnections] : [];

            rows.sort((a, b) => {
                const aa = Number(routerConnectionValue(a, ['connected_since', 'login_time', 'start'], 0)) || 0;
                const bb = Number(routerConnectionValue(b, ['connected_since', 'login_time', 'start'], 0)) || 0;
                return bb - aa;
            });

            if (query) {
                rows = rows.filter(c => JSON.stringify(c).toLowerCase().includes(query));
            }

            const online = Array.isArray(routerConnections) ? routerConnections.length : 0;
            const unknown = (Array.isArray(routerConnections) ? routerConnections : []).filter(c => {
                const phone = routerConnectionValue(c, ['phone', 'customer_phone', 'client_phone'], '');
                const name = routerConnectionValue(c, ['name', 'customer_name', 'customer'], '');
                return !phone && !name;
            }).length;

            document.getElementById('routerOnlineCount').innerText = online;
            document.getElementById('routerUnknownCount').innerText = unknown;

            if (!rows.length) {
                body.innerHTML = `<tr><td colspan="8" class="p-8 text-center text-slate-500">${query ? 'No connected devices match your search.' : 'No active MikroTik connections reported.'}</td></tr>`;
                return;
            }

            body.innerHTML = rows.map((c, index) => {
                const phone = routerConnectionValue(c, ['phone', 'customer_phone', 'client_phone'], 'Unknown');
                const name = routerConnectionValue(c, ['name', 'customer_name', 'customer'], 'Unidentified device');
                const mac = routerConnectionValue(c, ['mac', 'mac_address', 'mac-address'], '--');
                const ip = routerConnectionValue(c, ['ip', 'address'], '--');
                const username = routerConnectionValue(c, ['username', 'user', 'hotspot_user'], '--');
                const method = routerConnectionValue(c, ['access_method', 'method', 'source', 'payment_method'], 'MikroTik');
                const expiry = routerConnectionValue(c, ['expiry', 'expires_at', 'expires'], '');
                const sessionId = routerConnectionValue(c, ['session_id', 'id', '.id'], '');
                const unknown = phone === 'Unknown' && name === 'Unidentified device';
                const target = encodeURIComponent(JSON.stringify({ mac, ip, username, session_id: sessionId }));

                return `<tr class="hover:bg-slate-800/30 transition-colors">
                    <td class="p-4">
                        <div class="font-semibold ${unknown ? 'text-amber-300' : 'text-slate-200'}">${escapeRouterHtml(name)}</div>
                        <div class="text-[10px] text-slate-500 font-mono mt-1">${escapeRouterHtml(phone)}</div>
                    </td>
                    <td class="p-4">
                        <div class="font-mono text-[11px] text-indigo-300">${escapeRouterHtml(mac)}</div>
                        <div class="text-[10px] text-slate-600 mt-1">Session: ${escapeRouterHtml(sessionId || '--')}</div>
                    </td>
                    <td class="p-4">
                        <div class="font-mono text-slate-300">${escapeRouterHtml(ip)}</div>
                        <div class="text-[10px] text-slate-500 mt-1">User: ${escapeRouterHtml(username)}</div>
                    </td>
                    <td class="p-4">
                        <div class="text-slate-300">${formatRouterTime(routerConnectionValue(c, ['connected_since', 'login_time', 'start'], ''))}</div>
                        <div class="text-[10px] text-slate-500 mt-1">${escapeRouterHtml(routerUptime(c))}</div>
                    </td>
                    <td class="p-4">
                        <span class="px-2 py-1 rounded-lg bg-indigo-950/40 border border-indigo-900/40 text-indigo-300 text-[10px] font-semibold">${escapeRouterHtml(method)}</span>
                    </td>
                    <td class="p-4 text-[11px] text-slate-300">${expiry ? formatRouterTime(expiry) : 'Session based'}</td>
                    <td class="p-4">
                        <span class="px-2 py-1 text-[10px] font-semibold rounded-full bg-emerald-950 text-emerald-400 border border-emerald-800 inline-flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Online
                        </span>
                    </td>
                    <td class="p-4 text-center">
                        <button onclick="disconnectRouterDevice(decodeURIComponent('${target}'))" class="px-3 py-2 bg-rose-950/40 hover:bg-rose-900/50 text-rose-400 border border-rose-900/50 rounded-xl text-[10px] font-semibold inline-flex items-center gap-1.5 cursor-pointer" title="Disconnect live session only">
                            <i data-lucide="wifi-off" class="w-3.5 h-3.5"></i> Disconnect
                        </button>
                    </td>
                </tr>`;
            }).join('');

            lucide.createIcons();
        }

        async function refreshRouterConnections(showNotice = false) {
            try {
                const response = await fetch('?action=router_connections&t=' + Date.now(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store'
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.message || 'Connection snapshot request failed.');

                routerConnections = Array.isArray(data.connections) ? data.connections : [];
                renderRouterConnections();

                const badge = document.getElementById('routerLiveBadge');
                badge.innerText = 'AGENT ONLINE';
                badge.className = 'px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-950 text-emerald-400 border border-emerald-800';
                document.getElementById('routerLastUpdate').innerText = 'Last snapshot: ' + new Date().toLocaleTimeString('en-KE');
            } catch (error) {
                const badge = document.getElementById('routerLiveBadge');
                badge.innerText = 'AGENT OFFLINE';
                badge.className = 'px-2 py-0.5 text-[10px] font-bold rounded-full bg-rose-950/60 text-rose-400 border border-rose-900/60';
                document.getElementById('routerLastUpdate').innerText = 'Agent/snapshot unavailable. Existing dashboard data is unaffected.';
                if (showNotice) alert('Could not read the MikroTik connection snapshot. The Termux agent may not be reporting yet.');
            }
        }

        async function disconnectRouterDevice(encodedTarget) {
            let target;
            try {
                target = JSON.parse(encodedTarget);
            } catch (e) {
                alert('Invalid device target.');
                return;
            }

            const label = target.username || target.mac || target.ip || target.session_id || 'this device';
            if (!confirm(`Disconnect ${label}?\n\nThis will terminate the live MikroTik session only. The customer record and custom account will NOT be deleted.`)) return;

            try {
                const response = await fetch('?action=disconnect_device', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(target)
                });
                const data = await response.json();
                if (!data.success) throw new Error(data.message || 'Unable to queue command.');
                alert('Disconnect command queued. The Termux agent will execute it on the MikroTik.');
                setTimeout(() => refreshRouterConnections(false), 1200);
            } catch (error) {
                alert('Disconnect failed: ' + error.message);
            }
        }

        function startRouterConnectionMonitor() {
            refreshRouterConnections(false);
            if (routerRefreshTimer) clearInterval(routerRefreshTimer);
            routerRefreshTimer = setInterval(() => refreshRouterConnections(false), 10000);
        }

        function renderReconcileLogsComponent() {
            const logsTableBody = document.getElementById('reconcileLogsTableBody');
            if (!logsTableBody) return;
            logsTableBody.innerHTML = '';

            const searchValue = (document.getElementById('logSearchInput')?.value || '').trim().toLowerCase();

            if (reconcileLogs && reconcileLogs.length > 0) {
                let displayedLogs = reconcileLogs;
                if (searchValue !== '') {
                    displayedLogs = reconcileLogs.filter(log => (log.name || '').toLowerCase().includes(searchValue));
                }

                if (displayedLogs.length > 0) {
                    displayedLogs.forEach(log => {
                        const tr = document.createElement('tr');
                        tr.className = 'hover:bg-slate-800/30 text-slate-400 transition-colors';
                        
                        const statusBadge = log.success 
                            ? `<span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-950 text-emerald-400 border border-emerald-800 flex items-center gap-1 w-fit"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 block"></span> Dispatched</span>`
                            : `<span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-rose-950/60 text-rose-400 border border-rose-900/60 flex items-center gap-1 w-fit"><span class="w-1.5 h-1.5 rounded-full bg-rose-500 block"></span> Failed</span>`;

                        tr.innerHTML = `
                            <td class="p-4 font-mono text-xs whitespace-nowrap">${log.timestamp}</td>
                            <td class="p-4 font-mono text-xs text-indigo-300 uppercase tracking-wider">${log.mpesa_code}</td>
                            <td class="p-4">
                                <div class="text-slate-200 font-medium">${log.name}</div>
                                <div class="text-xs text-slate-500 font-mono">${log.phone}</div>
                            </td>
                            <td class="p-4 font-bold text-slate-200 whitespace-nowrap">KSH ${parseFloat(log.amount).toFixed(2)}</td>
                            <td class="p-4">${statusBadge}</td>
                            <td class="p-4 font-mono text-xs text-slate-300 max-w-xs break-words">${log.response}</td>
                        `;
                        logsTableBody.appendChild(tr);
                    });
                } else {
                    logsTableBody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-500 text-sm">No historical reconciliation records found matching that name.</td></tr>`;
                }
            } else {
                logsTableBody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-500 text-sm">No records found in active dispatch pipelines.</td></tr>`;
            }
        }

        function renderDeltaBadge(elementId, current, previous) {
            const el = document.getElementById(elementId);
            if (!el) return;
            
            if (previous === 0) {
                el.innerText = "—";
                el.className = "text-xs font-semibold px-2 py-0.5 rounded-md bg-slate-800 text-slate-400";
                return;
            }

            const percentChange = ((current - previous) / previous) * 100;
            const sign = percentChange >= 0 ? "+" : "";
            el.innerText = `${sign}${percentChange.toFixed(1)}%`;

            if (percentChange >= 0) {
                el.className = "text-xs font-semibold px-2 py-0.5 rounded-md bg-emerald-950/80 text-emerald-400 border border-emerald-800/40 flex items-center gap-0.5";
            } else {
                el.className = "text-xs font-semibold px-2 py-0.5 rounded-md bg-rose-950/80 text-rose-400 border border-rose-900/40 flex items-center gap-0.5";
            }
        }

        function initDashboardComponents() {
            const internetCost = parseFloat(document.getElementById('inputInternet').value) || 0;
            const electricityUnits = parseFloat(document.getElementById('inputElectricityUnits').value) || 0;
            const caretakerCost = parseFloat(document.getElementById('inputCaretaker').value) || 0;
            const electricityCost = electricityUnits * 28;

            let monthlyGross = 0;
            let lastMonthGross = 0;
            let yearlyGross = 0;
            let lastYearGross = 0;

            let countActive = 0;
            let countUnused = 0;
            let countExpired = 0;

            let predictiveGrossIncome = 0;
            let predictiveUserCount = 0;

            const dailyRevenueMap = {};
            const tableBody = document.getElementById('sessionTableBody');
            tableBody.innerHTML = '';

            const now = new Date();
            const currentMonth = now.getMonth();
            const currentYear = now.getFullYear();
            const currentTimestampSec = Math.floor(now.getTime() / 1000);

            const lastMonthTarget = currentMonth === 0 ? 11 : currentMonth - 1;
            const lastMonthYearTarget = currentMonth === 0 ? currentYear - 1 : currentYear;
            const lastYearTarget = currentYear - 1;

            const sessionSearchQuery = (document.getElementById('sessionSearchInput')?.value || '').trim().toLowerCase();

            const processRevenue = (sessionList) => {
                if(sessionList && sessionList.length > 0) {
                    sessionList.forEach(session => {
                        const paidAmount = parseFloat(session.paid || 0);
                        const phoneTarget = session.phone || 'N/A';
                        
                        const exemptionKey = (session.codes && session.codes.length > 0) 
                            ? session.codes[0] 
                            : `${phoneTarget}_${session.start}`;
                        
                        if (exemptSessionsList.includes(exemptionKey)) {
                            return;
                        }

                        if (session.start) {
                            const dateObj = new Date(session.start * 1000);
                            const sessionMonth = dateObj.getMonth();
                            const sessionYear = dateObj.getFullYear();
                            const expirySec = parseInt(session.expiry) || 0;
                            
                            const sortKey = `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`; 
                            
                            if (paidAmount > 0) {
                                if (!dailyRevenueMap[sortKey]) {
                                    dailyRevenueMap[sortKey] = {
                                        label: dateObj.toLocaleDateString('en-KE', { month: 'short', day: 'numeric', year: 'numeric' }),
                                        amount: 0
                                    };
                                }
                                dailyRevenueMap[sortKey].amount += paidAmount;
                            }
                            
                            if(sessionYear === currentYear) {
                                yearlyGross += paidAmount;
                                
                                if(sessionMonth === currentMonth) {
                                    monthlyGross += paidAmount;

                                    if (expirySec === 0) {
                                        countUnused++;
                                    } else if (expirySec > currentTimestampSec) {
                                        countActive++;
                                        if (paidAmount > 0) {
                                            predictiveGrossIncome += paidAmount;
                                            predictiveUserCount++;
                                        }
                                    } else {
                                        countExpired++;
                                    }
                                }
                            }
                            
                            if (sessionYear === lastMonthYearTarget && sessionMonth === lastMonthTarget) {
                                lastMonthGross += paidAmount;
                            }
                            
                            if (sessionYear === lastYearTarget) {
                                lastYearGross += paidAmount;
                            }
                        }
                    });
                }
            };

            processRevenue(archiveSessions);
            processRevenue(bundleSessions);

            if(bundleSessions && bundleSessions.length > 0) {
                let displayedSessions = [...bundleSessions];
                displayedSessions.sort((a, b) => (parseInt(b.start) || 0) - (parseInt(a.start) || 0));

                if (sessionSearchQuery !== '') {
                    displayedSessions = displayedSessions.filter(s => (s.phone || '').toLowerCase().includes(sessionSearchQuery));
                }

                if (displayedSessions.length > 0) {
                    displayedSessions.forEach(session => {
                        const paidAmount = parseFloat(session.paid || 0);
                        const sessionExpiry = parseInt(session.expiry) || 0;
                        const phoneTarget = session.phone || 'N/A';
                        const loginCount = session.login_count || 0;
                        const expectedAmount = session.expected || 0;

                        const exemptionKey = (session.codes && session.codes.length > 0) 
                            ? session.codes[0] 
                            : `${phoneTarget}_${session.start}`;
                        const isExempt = exemptSessionsList.includes(exemptionKey);
                        
                        const codesDisplay = (session.codes && session.codes.length > 0) 
                            ? session.codes.join('<br>') 
                            : '---';

                        let expiryDisplay = '--';
                        let statusBadge = '';
                        let rowClass = 'hover:bg-slate-800/30 text-slate-400 transition-colors';

                        if (sessionExpiry === 0) {
                            expiryDisplay = 'Not Activated';
                            statusBadge = `<span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-950/60 text-amber-400 border border-amber-900/60 flex items-center gap-1 w-fit"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 block"></span> Unused</span>`;
                            rowClass = 'hover:bg-slate-800/40 bg-amber-950/5 text-slate-300 transition-colors';
                        } else if (sessionExpiry > currentTimestampSec) {
                            const expiryDateObj = new Date(sessionExpiry * 1000);
                            expiryDisplay = expiryDateObj.toLocaleString('en-KE', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                            statusBadge = `<span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-950 text-emerald-400 border border-emerald-800 flex items-center gap-1 w-fit"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 block animate-pulse"></span> Active</span>`;
                            rowClass = 'hover:bg-slate-800/30 bg-emerald-950/10 border-l-2 border-emerald-500/60 text-slate-200 transition-colors font-medium';
                        } else {
                            const expiryDateObj = new Date(sessionExpiry * 1000);
                            expiryDisplay = expiryDateObj.toLocaleString('en-KE', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                            statusBadge = `<span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-rose-950/60 text-rose-400 border border-rose-900/60 flex items-center gap-1 w-fit"><span class="w-1.5 h-1.5 rounded-full bg-rose-500 block"></span> Expired</span>`;
                            rowClass = 'hover:bg-slate-800/30 text-slate-500 transition-colors opacity-75';
                        }

                        const tr = document.createElement('tr');
                        tr.className = rowClass;
                        tr.innerHTML = `
                            <td class="p-4 font-mono text-xs">${phoneTarget}</td>
                            <td class="p-4">${expectedAmount} KSH</td>
                            <td class="p-4 font-bold text-slate-200">${paidAmount.toFixed(2)}</td>
                            <td class="p-4 font-mono text-indigo-300 text-[11px] leading-tight">${codesDisplay}</td>
                            <td class="p-4 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <button onclick="mutateLoginCount('${phoneTarget}', -1)" class="w-6 h-6 rounded bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-300 text-xs font-bold flex items-center justify-center cursor-pointer select-none border border-slate-700/60">-</button>
                                    <input type="text" id="loginInput_${phoneTarget}" value="${loginCount}" readonly class="w-8 text-center bg-transparent border-0 font-bold text-slate-100 text-xs focus:ring-0 p-0" />
                                    <button onclick="mutateLoginCount('${phoneTarget}', 1)" class="w-6 h-6 rounded bg-slate-800 hover:bg-slate-700 active:bg-slate-600 text-slate-300 text-xs font-bold flex items-center justify-center cursor-pointer select-none border border-slate-700/60">+</button>
                                </div>
                            </td>
                            <td class="p-4 text-xs">${expiryDisplay}</td>
                            <td class="p-4">${statusBadge}</td>
                            <td class="p-4 text-center">
                                <input type="checkbox" ${isExempt ? 'checked' : ''} onclick="toggleExemption('${exemptionKey}')" class="w-4 h-4 rounded text-indigo-600 border-slate-700 bg-[#0b0f19] focus:ring-indigo-500/40 cursor-pointer" />
                            </td>
                            <td class="p-4 text-center">
                                <button onclick="executeSessionErase('${phoneTarget}')" class="p-2 bg-rose-950/40 hover:bg-rose-900/40 text-rose-400 border border-rose-900/40 rounded-xl transition-all cursor-pointer inline-flex items-center justify-center" title="Wipe Record">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </td>
                        `;
                        tableBody.appendChild(tr);
                    });
                } else {
                    tableBody.innerHTML = `<tr><td colspan="9" class="p-8 text-center text-slate-500 text-sm">No transaction sessions found matching search keys.</td></tr>`;
                }
            } else {
                tableBody.innerHTML = `<tr><td colspan="9" class="p-8 text-center text-slate-500 text-sm">No current logs found.</td></tr>`;
            }

            const monthsPassedThisYear = currentMonth + 1; 
            const compoundedMonthlyOverhead = internetCost + electricityCost + caretakerCost;

            const monthlyNet = monthlyGross - compoundedMonthlyOverhead;
            const lastMonthNet = lastMonthGross - compoundedMonthlyOverhead;

            const yearlyNet = yearlyGross - (compoundedMonthlyOverhead * monthsPassedThisYear);
            const lastYearNet = lastYearGross - (compoundedMonthlyOverhead * 12);

            const predictiveNetIncome = predictiveGrossIncome - compoundedMonthlyOverhead;

            document.getElementById('monthlyGrossDisplay').innerText = monthlyGross.toFixed(2);
            document.getElementById('monthlyNetDisplay').innerText = monthlyNet.toFixed(2);
            document.getElementById('yearlyGrossDisplay').innerText = yearlyGross.toFixed(2);
            document.getElementById('yearlyNetDisplay').innerText = yearlyNet.toFixed(2);
            document.getElementById('internetCostDisplay').innerText = internetCost.toFixed(2);
            document.getElementById('electricityCostDisplay').innerText = electricityCost.toFixed(2);
            document.getElementById('caretakerCostDisplay').innerText = caretakerCost.toFixed(2);

            document.getElementById('predictiveGross').innerText = predictiveGrossIncome.toFixed(2);
            document.getElementById('predictiveNet').innerText = predictiveNetIncome.toFixed(2);
            document.getElementById('predictiveCount').innerText = `Based on ${predictiveUserCount} recurring active profiles`;

            document.getElementById('pieCountActive').innerText = countActive;
            document.getElementById('pieCountUnused').innerText = countUnused;
            document.getElementById('pieCountExpired').innerText = countExpired;

            renderDeltaBadge('monthlyGrossDelta', monthlyGross, lastMonthGross);
            renderDeltaBadge('monthlyNetDelta', monthlyNet, lastMonthNet);
            renderDeltaBadge('yearlyGrossDelta', yearlyGross, lastYearGross);
            renderDeltaBadge('yearlyNetDelta', yearlyNet, lastYearNet);

            const sortedKeys = Object.keys(dailyRevenueMap).sort(); 
            const labels = sortedKeys.map(key => dailyRevenueMap[key].label);
            const dataValues = sortedKeys.map(key => dailyRevenueMap[key].amount);

            if(trajectoryChartInstance) trajectoryChartInstance.destroy();

            const ctxLine = document.getElementById('trajectoryChart').getContext('2d');
            const lineGradient = ctxLine.createLinearGradient(0, 0, 0, 400);
            lineGradient.addColorStop(0, 'rgba(99, 102, 241, 0.4)'); 
            lineGradient.addColorStop(1, 'rgba(99, 102, 241, 0)');

            trajectoryChartInstance = new Chart(ctxLine, {
                type: 'line',
                data: {
                    labels: labels.length > 0 ? labels : ['No Data Available'],
                    datasets: [{
                        label: 'Daily Revenue Volume (KSH)',
                        data: dataValues.length > 0 ? dataValues : [0],
                        borderColor: '#6366f1',
                        backgroundColor: lineGradient,
                        borderWidth: 2,
                        pointBackgroundColor: '#818cf8',
                        pointBorderColor: '#fff',
                        pointRadius: 4,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: '#1e293b', drawBorder: false }, ticks: { color: '#64748b' } },
                        y: { grid: { color: '#1e293b', drawBorder: false }, ticks: { color: '#64748b' }, beginAtZero: true }
                    }
                }
            });

            if(distributionPieChartInstance) distributionPieChartInstance.destroy();

            const ctxPie = document.getElementById('distributionPieChart').getContext('2d');
            distributionPieChartInstance = new Chart(ctxPie, {
                type: 'pie',
                data: {
                    labels: ['Active', 'Unused', 'Expired'],
                    datasets: [{
                        data: [countActive, countUnused, countExpired],
                        backgroundColor: [
                            '#10b981', 
                            '#f59e0b', 
                            '#ef4444'  
                        ],
                        borderWidth: 1,
                        borderColor: '#111827'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            borderColor: '#334155',
                            borderWidth: 1,
                            titleColor: '#f8fafc',
                            bodyColor: '#cbd5e1',
                            padding: 10,
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const val = context.raw || 0;
                                    const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                    return ` ${context.label}: ${val} (${pct}%)`;
                                }
                            }
                        }
                    }
                }
            });

            lucide.createIcons();
        }
        initDashboardComponents();
        startRouterConnectionMonitor();
    </script>
</body>
</html>