<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Nairobi');

/* ================= LOAD CONFIG ================= */
$configPath = __DIR__ . '/config.json';
if (!file_exists($configPath)) {
    die(json_encode(["status"=>"error","message"=>"config.json missing"]));
}

$config = json_decode(file_get_contents($configPath), true);
if (!isset($config['accounts']) || !isset($config['files'])) {
    die(json_encode(["status"=>"error","message"=>"Invalid config.json"]));
}

$accounts = $config['accounts'];
$usersFile = $config['files']['usersFile'] ?? (__DIR__.'/users.json');
$balancesFile = $config['files']['balancesFile'] ?? (__DIR__.'/balances.json');

/* ================= ENSURE JSON FILES ================= */
function ensureJsonFile($path, $default){
    if(!file_exists($path)){
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT));
    } else {
        $c = @file_get_contents($path);
        $j = @json_decode($c, true);
        if(!is_array($j)){
            file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT));
        }
    }
}

ensureJsonFile($usersFile, ["users"=>[]]);
ensureJsonFile($balancesFile, []);

/* ================= LOAD / SAVE HELPERS ================= */
function loadUsers($file){
    $c = file_get_contents($file);
    $j = json_decode($c, true);
    if(!is_array($j)) $j=["users"=>[]];
    if(!isset($j['users'])) $j['users']=[]; 
    return $j;
}
function saveUsers($file,$data){
    file_put_contents($file,json_encode($data,JSON_PRETTY_PRINT));
}

/* ================= HELPERS ================= */
function detectSite($referral){
    $r = strtolower($referral);
    if(strpos($r,"betfalme")!==false) return "betfalme";
    if(strpos($r,"sofabets")!==false) return "sofabets";
    if(strpos($r,"safibets")!==false) return "safibets";
    if(strpos($r,"spurbets")!==false) return "spurbets";
    return false;
}

function callAPI($url,$post=[],$headers=[]){
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_USERAGENT=>"Mozilla/5.0"
    ]);
    if(!empty($post)){
        curl_setopt($ch,CURLOPT_POST,true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($post));
        $headers[]="Content-Type: application/json";
    }
    if(!empty($headers)) curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
    $res=curl_exec($ch);
    if($res===false){
        $err=curl_error($ch);
        curl_close($ch);
        return ["__curl_error"=>$err];
    }
    curl_close($ch);
    $json=json_decode($res,true);
    return $json===null?["__raw"=>$res]:$json;
}

/* ================= SMS ================= */
function sendSMS($to,$message){
    $payload=[
        "partnerID"=>"8854",
        "apikey"=>"70efa65617bcc559666d74e884c3abb6",
        "shortcode"=>"Savvy_sms",
        "mobile"=>$to,
        "message"=>$message
    ];
    $ch=curl_init("https://sms.savvybulksms.com/api/services/sendsms");
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_HTTPHEADER=>["Content-Type: application/json"]
    ]);
    $res=curl_exec($ch);
    curl_close($ch);
    return $res;
}

/* ================= SITE BALANCE CHECK ================= */
function checkSiteBalance($siteKey,$accounts){
    if(!isset($accounts[$siteKey])) return ["error"=>"Invalid site"];

    $s=$accounts[$siteKey];

    $login=callAPI($s['login'],[
        "phone"=>$s['user'],
        "password"=>$s['pass']
    ]);

    if(empty($login['token'])) return ["error"=>"Site login failed"];

    $user=callAPI(
        $s['user_api'],
        [],
        ["Authorization: Bearer ".$login['token']]
    );

    $balance =
        $user['balance']
        ?? $user['wallet_balance']
        ?? $user['data']['balance']
        ?? 0;

    return [
        "balance"=>floatval($balance),
        "mikrotik_user"=>$s['mikrotik_user'],
        "mikrotik_pass"=>$s['mikrotik_pass']
    ];
}

/* ================= ROUTING ================= */
$action = strtolower(trim($_POST['action'] ?? ($_POST['referral']??'' ? 'verify' : '')));

/* ================= REGISTER ================= */
if($action==='register'){
    $username=trim($_POST['username']??'');
    if($username===''){echo json_encode(["status"=>"error","message"=>"Username required"]);exit;}
    $plan=trim($_POST['plan']??'5mbps');

    $u=loadUsers($usersFile);

    // Prevent duplicate registration
    foreach($u['users'] as $x){ 
        if($x['username']===$username){
            echo json_encode(["status"=>"error","message"=>"User exists"]);
            exit;
        }
    }

    $pass=bin2hex(random_bytes(4));
    $now=new DateTime();
    $u['users'][]=[
        "username"=>$username,
        "password"=>$pass,
        "plan"=>$plan,
        "created_on"=>$now->format(DateTime::ATOM),
        "expires_on"=>$now->modify('+30 days')->format(DateTime::ATOM),
        "last_login"=>null,
        "token"=>null,
        "claim_sent"=>0,
        "login_status"=>0
    ];
    saveUsers($usersFile,$u);
    echo json_encode(["status"=>"success","message"=>"Registered successfully"]);
    exit;
}

/* ================= LOGIN ================= */
if($action==='login'){
    $username=trim($_POST['username']??'');
    $password=trim($_POST['password']??'');
    $u=loadUsers($usersFile);

    foreach($u['users'] as &$x){
        if($x['username']===$username && $x['password']===$password){
            $token=bin2hex(random_bytes(16));
            $x['token']=$token;
            saveUsers($usersFile,$u);

            $planMap=["5mbps"=>"plan5","10mbps"=>"plan10","20mbps"=>"plan20","30mbps"=>"plan30"];
            $acc=$accounts[$planMap[$x['plan']] ?? 'plan5'];

            echo json_encode([
                "status"=>"success",
                "message"=>"Login successful",
                "token"=>$token,
                "mikrotik"=>[
                    "username"=>$acc['mikrotik_user'],
                    "password"=>$acc['mikrotik_pass']
                ]
            ]);
            exit;
        }
    }
    echo json_encode(["status"=>"error","message"=>"Invalid login"]);
    exit;
}

/* ================= VERIFY (SITE CHECK) ================= */
if($action==='verify'){
    $ref=trim($_POST['referral']??'');
    if($ref===''){echo json_encode(["status"=>"error","message"=>"Referral required"]);exit;}

    $site=detectSite($ref);
    if(!$site){echo json_encode(["status"=>"error","message"=>"Unsupported site"]);exit;}

    $chk=checkSiteBalance($site,$accounts);
    if(isset($chk['error'])){echo json_encode(["status"=>"error","message"=>$chk['error']);exit;}
    if($chk['balance']<10){
        echo json_encode(["status"=>"error","message"=>"Insufficient balance"]);
        exit;
    }

    echo json_encode([
        "status"=>"success",
        "message"=>"Balance verified",
        "mikrotik"=>[
            "username"=>$chk['mikrotik_user'],
            "password"=>$chk['mikrotik_pass']
        ]
    ]);
    exit;
}

/* ================= CLAIM PAYMENT ================= */
if($action==='claim_payment'){
    $username = trim($_POST['username'] ?? '');
    if($username===''){echo json_encode(["status"=>"error","message"=>"Username required"]);exit;}

    $u=loadUsers($usersFile);

    // Check if claim already sent for this username to prevent spamming
    $userFound=false;
    foreach($u['users'] as &$x){
        if($x['username']===$username){
            if($x['claim_sent']==1){
                echo json_encode(["status"=>"error","message"=>"Claim already sent"]);
                exit;
            }
            $x['claim_sent']=1;
            $userFound=true;
            break;
        }
    }

    if(!$userFound){
        // Add temporary entry to prevent repeated claims without registering
        $u['users'][]=[
            "username"=>$username,
            "password"=>"N/A",
            "plan"=>"N/A",
            "created_on"=>(new DateTime())->format(DateTime::ATOM),
            "expires_on"=>null,
            "last_login"=>null,
            "token"=>null,
            "claim_sent"=>1,
            "login_status"=>0
        ];
    }

    saveUsers($usersFile,$u);

    // Send SMS to admin
    $res=sendSMS("2547XXXXXXXX","User {$username} claims payment. No verification done.");
    echo json_encode([
        "status"=>"success",
        "message"=>"Claim sent to admin",
        "sms_response"=>$res
    ]);
    exit;
}

echo json_encode(["status"=>"error","message"=>"Invalid action"]);
