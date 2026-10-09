<?php
$ROUTER_IP = "192.168.88.1";
$PORT = 80; // The port your MikroTik router uses for its web interface
$TIMEOUT = 2; // Maximum seconds to wait for a response before giving up

// Function to ping the router over the network
function isRouterAlive($ip, $port, $timeout) {
    // Suppress errors with @ so the page doesn't crash if the connection fails
    $connection = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    
    if ($connection) {
        fclose($connection);
        return true; // Router is responding!
    }
    return false; // Router is dead or unreachable
}

// Execute the check
if (!isRouterAlive($ROUTER_IP, $PORT, $TIMEOUT)) {
    // SCENARIO A: Router is NOT responding (User is away from the network)
    // Break the connection instantly with a 403 Forbidden error
    header('HTTP/1.1 403 Forbidden');
    echo "<h2>Connection Dropped: You must be connected to the local router network to access this portal.</h2>";
    exit(); 
}

// SCENARIO B: Router IS responding (User is on the local network)
// Do nothing! PHP will naturally "escape" this script and continue running your portal.
?>