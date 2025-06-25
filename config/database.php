<?php
// Database configuration
$DB_HOST = 'localhost';
$DB_USER = 'user_up';
$DB_PASS = 'Puputchen12$';
$DB_NAME = 'user_up';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper functions
function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}

function formatNumber($number) {
    return number_format($number);
}

function getBannerSizes() {
    return [
        '300x250' => 'Medium Rectangle (300x250)',
        '300x100' => 'Mobile Banner (300x100)',
        '300x50' => 'Mobile Banner Small (300x50)',
        '300x500' => 'Half Page (300x500)',
        '900x250' => 'Billboard (900x250)',
        '728x90' => 'Leaderboard (728x90)',
        '160x600' => 'Wide Skyscraper (160x600)'
    ];
}

function getCountries() {
    return [
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'CA' => 'Canada',
        'AU' => 'Australia',
        'DE' => 'Germany',
        'FR' => 'France',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'NL' => 'Netherlands',
        'BE' => 'Belgium',
        'CH' => 'Switzerland',
        'AT' => 'Austria',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'IE' => 'Ireland',
        'PT' => 'Portugal',
        'GR' => 'Greece',
        'PL' => 'Poland',
        'CZ' => 'Czech Republic',
        'HU' => 'Hungary',
        'RO' => 'Romania',
        'BG' => 'Bulgaria',
        'HR' => 'Croatia',
        'SI' => 'Slovenia',
        'SK' => 'Slovakia',
        'LT' => 'Lithuania',
        'LV' => 'Latvia',
        'EE' => 'Estonia',
        'BR' => 'Brazil',
        'MX' => 'Mexico',
        'AR' => 'Argentina',
        'CL' => 'Chile',
        'CO' => 'Colombia',
        'PE' => 'Peru',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'CN' => 'China',
        'IN' => 'India',
        'TH' => 'Thailand',
        'ID' => 'Indonesia',
        'MY' => 'Malaysia',
        'PH' => 'Philippines',
        'SG' => 'Singapore',
        'VN' => 'Vietnam',
        'ZA' => 'South Africa',
        'EG' => 'Egypt',
        'MA' => 'Morocco',
        'NG' => 'Nigeria',
        'KE' => 'Kenya',
        'RU' => 'Russia',
        'UA' => 'Ukraine',
        'TR' => 'Turkey',
        'IL' => 'Israel',
        'AE' => 'UAE',
        'SA' => 'Saudi Arabia'
    ];
}

function getBrowsers() {
    return [
        'chrome' => 'Chrome',
        'firefox' => 'Firefox',
        'safari' => 'Safari',
        'edge' => 'Microsoft Edge',
        'opera' => 'Opera',
        'ie' => 'Internet Explorer'
    ];
}

function getDevices() {
    return [
        'desktop' => 'Desktop',
        'mobile' => 'Mobile',
        'tablet' => 'Tablet'
    ];
}

function getOperatingSystems() {
    return [
        'windows' => 'Windows',
        'macos' => 'macOS',
        'linux' => 'Linux',
        'android' => 'Android',
        'ios' => 'iOS'
    ];
}
?>