<?php
/**
 * auth_helpers.php — Shared auth utilities
 * Copy this file to auth_helpers.php and fill in your credentials
 */

define('GOOGLE_CLIENT_ID',     'YOUR_GOOGLE_CLIENT_ID_HERE');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET_HERE');

function ensureValidToken(): void {
    if (!isset($_SESSION['token_expires'])) return;
    if (time() > $_SESSION['token_expires'] - 60) {
        refreshAccessToken();
    }
}

function refreshAccessToken(): void {
    if (empty($_SESSION['refresh_token'])) return;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $_SESSION['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true) ?? [];

    if (!empty($data['access_token'])) {
        $_SESSION['access_token']  = $data['access_token'];
        $_SESSION['token_expires'] = time() + ($data['expires_in'] ?? 3600);
    }
}
