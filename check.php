<?php
header('Content-Type: application/json');

// We tell the JS which local IP to "ping" (the hAP lite)
echo json_encode([
    "target_ip" => "192.168.88.1",
    "timeout" => 2000 // 2 seconds to respond
]);
exit();