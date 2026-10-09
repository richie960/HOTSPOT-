<?php
header('Content-Type: application/json');
date_default_timezone_set('Africa/Nairobi');

/* ================= CONFIG ================= */

$config = json_decode(@file_get_contents("config.json"), true);
$accounts = $config['accounts'] ?? [];

$sessionsFile = "bundle_sessions.json";
$walletFile   = "wallet_balances.json";
$smsUrl        = "https://bizfire.mywebcommunity.org/sms_data.json";

if(!file_exists($sessionsFile)) file_put_contents($sessionsFile, "[]");
if(!file_exists($walletFile))   file_put_contents($walletFile, "{}");

/* ================= SAFE FILE FUNCTIONS ================= */

function loadJson($file) {
    if(!file_exists($file)) return [];
    $fp = fopen($file, "r");
    if(!$fp) return [];
    flock($fp, LOCK_SH);
    $content = file_get_contents($file);
    $data = json_decode($content, true) ?: [];
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

function saveJson($file, $data) {
    $fp = fopen($file, "w");
    if(!$fp) return;
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
}

$sessions = loadJson($sessionsFile);
$wallets  = loadJson($walletFile);

/* ================= HELPERS ================= */
function normalizePhone($p) {
    $p = preg_replace('/\D/', '', $p);
    
    if (strlen($p) == 10) {
        $prefix = substr($p, 0, 2);
        if ($prefix === '07' || $prefix === '01') {
            return '254' . substr($p, 1);
        }
    }
    
    if (strlen($p) == 12 && str_starts_with($p, '254')) {
        return $p;
    }
    
    return null;
}

function parseMpesa($msg) {
    if(!preg_match('/\breceived from\b/i', $msg)) return false;
    if(!preg_match('/^([A-Z0-9]{10})/', $msg, $c)) return false;
    if(!preg_match('/KSH\s*([\d,]+(\.\d+)?)/i', $msg, $a)) return false;
    if(!preg_match('/received from\s+(?:[^0-9]+)?(254\d{9}|0\d{9})/i', $msg, $p)) return false;

    $cleanPhone = normalizePhone($p[1]);
    if(!$cleanPhone) return false;

    return [
        "code"   => $c[1],
        "amount" => floatval(str_replace(',', '', $a[1])),
        "phone"  => $cleanPhone
    ];
}

/* 🔒 GLOBAL AIRTIGHT MPESA DUPLICATE CHECK */
function mpesaUsed($code, $sessions) {
    // 1. Check primary active/recent sessions file
    foreach($sessions as $s) {
        if(isset($s['codes']) && in_array($code, $s['codes'])) {
            return true;
        }
    }
    
    // 2. Check long-term archived sessions file safely
    $archiveFile = "archive_sessions.json";
    if (file_exists($archiveFile)) {
        $fp = @fopen($archiveFile, "r");
        if ($fp) {
            @flock($fp, LOCK_SH);
            $archiveData = json_decode(@stream_get_contents($fp), true) ?: [];
            @flock($fp, LOCK_UN);
            @fclose($fp);
            
            foreach($archiveData as $as) {
                if(isset($as['codes']) && in_array($code, $as['codes'])) {
                    return true;
                }
            }
        }
    }
    return false;
}

/* Helper to safely retrieve remote URL content across restricted hosting environments */
function fetchRemoteData($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
        
        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && !empty($output)) {
            return $output;
        }
    }

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n" .
                        "Accept: application/json\r\n"
        ],
        "ssl" => [
            "verify_peer" => false,
            "verify_peer_name" => false,
        ]
    ];
    $context = stream_context_create($opts);
    return @file_get_contents($url, false, $context);
}

/* ================= ACTION ================= */

$action = $_POST['action'] ?? '';
if($action !== "bundle_check") {
    echo json_encode(["status"=>"error","message"=>"Invalid action"]);
    exit;
}

$phone    = normalizePhone($_POST['phone'] ?? '');
$expected = intval($_POST['expected'] ?? 0);
$now      = time();

if(!$phone || !$expected) {
    echo json_encode(["status"=>"error","message"=>"Invalid phone or plan"]);
    exit;
}

/* ================= PLAN CONFIG ================= */

$durations = [
    10 => 1 * 3600,
    20=> 12 * 3600,
    40  => 1 * 86400,
    150=> 7 * 86400,
    500 => 30 * 86400
];

$planMap = [
    10   => "plan1hr",
    20=> "plan12hr",
    40=> "plan1d",
    150=> "plan7d",
    500 => "plan30d"
];

$pending_sid = null;

/* ================= ACTIVE SESSION CHECK ================= */

foreach($sessions as $i => $s) {
    if($s['phone'] !== $phone) continue;

    if(($s['delivered'] ?? false) && ($s['expiry'] ?? 0) > $now) {
        $login_count = $s['login_count'] ?? 0;

        if($login_count >= 2) {
            echo json_encode([
                "status"=>"error", 
                "message"=>"Device limit reached (2/2). Wait for expiry or buy new session."
            ]);
            exit;
        }

        $sessions[$i]['login_count'] = $login_count + 1;
        saveJson($sessionsFile, $sessions);

        $plan = $planMap[$s['expected']] ?? "plan5";

        echo json_encode([
            "status"=>"success",
            "message"=>"Session active. Device ".($login_count+1)." of 2.",
            "expiry"=>date("Y-m-d H:i:s", $s['expiry']),
            "mikrotik"=>[
                "username"=>$accounts[$plan]['mikrotik_user'] ?? "",
                "password"=>$accounts[$plan]['mikrotik_pass'] ?? ""
            ]
        ]);
        exit;
    }

    if(!($s['delivered'] ?? false)) {
        $pending_sid = $i;
    }
}

/* ================= SYSTEM LIFECYCLE MANAGEMENT ================= */

if($pending_sid === null) {
    $sessions[] = [
        "phone"=>$phone,
        "expected"=>$expected,
        "paid"=>0,
        "codes"=>[],
        "start"=>$now,
        "expiry"=>0,
        "delivered"=>false,
        "login_count"=>0
    ];
    $sid = array_key_last($sessions);
} else {
    $sid = $pending_sid;
    $sessions[$sid]['expected'] = $expected; 
}

/* ================= WALLET APPLY ================= */

if(!isset($wallets[$phone])) $wallets[$phone] = 0;

if($wallets[$phone] > 0) {
    $use = min($wallets[$phone], $expected - $sessions[$sid]['paid']);
    $sessions[$sid]['paid'] += $use;
    $wallets[$phone] -= $use;
}

/* ================= MPESA CHECK ================= */

$smsRaw = fetchRemoteData($smsUrl);
$smsData = json_decode($smsRaw, true) ?: [];

foreach($smsData as $m) {
    if(($m['from'] ?? '') !== "MPESA") continue;

    $p = parseMpesa($m['msg']);
    if(!$p) continue;
    if($p['phone'] !== $phone) continue;

    if(mpesaUsed($p['code'], $sessions)) continue;

    $sessions[$sid]['paid'] += $p['amount'];
    $sessions[$sid]['codes'][] = $p['code'];
}

/* ================= FINAL DELIVERY ================= */

if($sessions[$sid]['paid'] >= $expected) {
    $extra = $sessions[$sid]['paid'] - $expected;
    if($extra > 0) $wallets[$phone] += $extra;

    $sessions[$sid]['expiry'] = $now + ($durations[$expected] ?? 86400);
    $sessions[$sid]['delivered'] = true;
    $sessions[$sid]['login_count'] = 1;

    $plan = $planMap[$expected] ?? "plan5";

    saveJson($sessionsFile, $sessions);
    saveJson($walletFile, $wallets);

    echo json_encode([
        "status"=>"success",
        "message"=>"Payment successful. Device 1 of 3.",
        "expiry"=>date("Y-m-d H:i:s", $sessions[$sid]['expiry']),
        "mikrotik"=>[
            "username"=>$accounts[$plan]['mikrotik_user'] ?? "",
            "password"=>$accounts[$plan]['mikrotik_pass'] ?? ""
        ]
    ]);
    exit;
}

/* ================= SAVE PARTIAL / TRACKED LOGS ================= */

saveJson($sessionsFile, $sessions);
saveJson($walletFile, $wallets);

echo json_encode([
    "status"=>"partial",
    "paid"=>$sessions[$sid]['paid'],
    "balance"=>max(0, $expected - $sessions[$sid]['paid']),
    "wallet"=>$wallets[$phone]
]);
exit;