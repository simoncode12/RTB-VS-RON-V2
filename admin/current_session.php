<?php
// Current session information
define('CURRENT_TIMESTAMP', '2025-06-23 05:53:18'); // Current UTC time
define('CURRENT_USER', 'simoncode12');              // Current logged in user

// Prevent direct access
if (!defined('SYSTEM_ACCESS') || SYSTEM_ACCESS !== true) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access forbidden');
}
?>