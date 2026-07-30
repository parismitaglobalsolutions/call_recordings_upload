<?php
/**
 * Authentication Helper Functions
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Start session with secure settings
 */
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    initSession();
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_username']);
}

/**
 * Require login - redirect to login page if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Authenticate admin user
 */
function authenticate($username, $password) {
    $db = getDB();
    $user = $db->fetch(
        "SELECT id, username, password FROM admin_users WHERE username = ?",
        [$username]
    );

    if ($user && password_verify($password, $user['password'])) {
        initSession();
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];

        // Fetch and store Graph API access token in session
        try {
            $_SESSION['graph_access_token'] = fetchGraphAccessToken();
        } catch (Exception $e) {
            // Login still succeeds even if token fetch fails; will retry on demand
            $_SESSION['graph_access_token'] = null;
        }

        return true;
    }

    return false;
}

/**
 * Logout user
 */
function logout() {
    initSession();
    session_unset();
    session_destroy();
}

/**
 * Get current admin username
 */
function getCurrentAdmin() {
    initSession();
    return $_SESSION['admin_username'] ?? null;
}

/**
 * Fetch a new Microsoft Graph API access token using client credentials flow
 */
function fetchGraphAccessToken() {
    $tokenUrl = 'https://login.microsoftonline.com/' . ONEDRIVE_TENANT_ID . '/oauth2/v2.0/token';

    $postData = http_build_query([
        'client_id'     => ONEDRIVE_CLIENT_ID,
        'client_secret' => ONEDRIVE_CLIENT_SECRET,
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $tokenUrl,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception('Token request failed: ' . $error);
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['access_token'])) {
        $errorMsg = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
        throw new Exception('Failed to get access token: ' . $errorMsg);
    }

    return $data['access_token'];
}

/**
 * Get Graph API access token from session, or regenerate if missing
 */
function getGraphAccessToken() {
    initSession();

    if (!empty($_SESSION['graph_access_token'])) {
        return $_SESSION['graph_access_token'];
    }

    // Token missing from session — regenerate
    $token = fetchGraphAccessToken();
    $_SESSION['graph_access_token'] = $token;
    return $token;
}

/**
 * Regenerate Graph API access token and update session
 */
function regenerateGraphAccessToken() {
    initSession();
    $token = fetchGraphAccessToken();
    $_SESSION['graph_access_token'] = $token;
    return $token;
}
