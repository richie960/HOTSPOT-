<?php
date_default_timezone_set('Africa/Nairobi');

// 1. SET HEADER FOR STRICT JSON OUTPUT
header('Content-Type: application/json');

$sessionsFile = "bundle_sessions.json";
$archiveFile  = "archive_sessions.json";

if (!file_exists($sessionsFile)) {
    echo json_encode(['success' => false, 'message' => "Source file missing. Nothing to clean."]);
    exit;
}

// Open with explicit read/write access and a critical transactional lock
$fp = fopen($sessionsFile, "r+");
if (!$fp) {
    echo json_encode(['success' => false, 'message' => "Failed to establish a safe lock on the database."]);
    exit;
}

flock($fp, LOCK_EX);

$content = stream_get_contents($fp);
$sessions = json_decode($content, true) ?: [];

$now = time();
$thirtyDaysAgo = $now - (30 * 86400);

$activeSessions = [];
$archivedSessions = [];

foreach ($sessions as $s) {
    $expiry = intval($s['expiry'] ?? 0);
    $isDelivered = $s['delivered'] ?? false;

    // Keep active records, open/unpaid attempts, or very fresh expiries
    if (!$isDelivered || $expiry > $thirtyDaysAgo) {
        $activeSessions[] = $s;
    } else {
        $archivedSessions[] = $s;
    }
}

// Append data to your long-term storage ledger if any records qualified
if (!empty($archivedSessions)) {
    $currentArchive = [];
    if (file_exists($archiveFile)) {
        $currentArchive = json_decode(file_get_contents($archiveFile), true) ?: [];
    }
    
    $updatedArchive = array_merge($currentArchive, $archivedSessions);
    file_put_contents($archiveFile, json_encode($updatedArchive, JSON_PRETTY_PRINT));
}

// Wipe primary file clear, rewind track pointer, and save slimmed active records
ftruncate($fp, 0);
rewind($fp);
$writeSuccess = fwrite($fp, json_encode($activeSessions, JSON_PRETTY_PRINT));

flock($fp, LOCK_UN);
fclose($fp);

// 2. OUTPUT SUCCESS RESPONSE IN JSON FORMAT
if ($writeSuccess !== false) {
    echo json_encode([
        'success' => true,
        'message' => "Session database systematically optimized and archived.",
        'metrics' => [
            'archived_records' => count($archivedSessions),
            'active_records_remaining' => count($activeSessions)
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'message' => "Failed to write optimized data back to disk."]);
}
?>