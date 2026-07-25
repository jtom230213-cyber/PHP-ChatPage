<?php
/**
 * Authentication API Endpoints
 * POST /api/auth.php?action=register
 * POST /api/auth.php?action=login
 * POST /api/auth.php?action=logout
 * GET  /api/auth.php?action=me
 */

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../includes/Auth.php';

$auth = new Auth();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {
        case 'register':
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $user = $auth->register(
                $data['username'] ?? '',
                $data['email'] ?? '',
                $data['password'] ?? '',
                $data['display_name'] ?? ''
            );
            jsonResponse(['success' => true, 'user' => $user]);
            break;
            
        case 'login':
            if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $user = $auth->login(
                $data['login'] ?? '',
                $data['password'] ?? ''
            );
            jsonResponse(['success' => true, 'user' => $user]);
            break;
            
        case 'logout':
            $auth->logout();
            jsonResponse(['success' => true]);
            break;
            
        case 'me':
            $user = $auth->currentUser();
            if (!$user) jsonResponse(['error' => 'Not authenticated'], 401);
            jsonResponse(['success' => true, 'user' => $user]);
            break;

        case 'apikey':
            $user = $auth->requireAuth();
            $uid = $user['id'];
            if ($method === 'GET') {
                $settings = $auth->getUserApiSettings($uid);
                jsonResponse([
                    'success' => true,
                    'has_key' => $settings !== null,
                    'api_endpoint' => $settings['endpoint'] ?? null,
                    'preferred_model' => $settings['model'] ?? null,
                ]);
            } elseif ($method === 'POST' || $method === 'PUT') {
                $data = json_decode(file_get_contents('php://input'), true);
                $auth->saveApiKey(
                    $uid,
                    $data['api_endpoint'] ?? '',
                    $data['api_key'] ?? '',
                    $data['preferred_model'] ?? ''
                );
                jsonResponse(['success' => true, 'message' => 'API key saved securely.']);
            } elseif ($method === 'DELETE') {
                $auth->deleteApiKey($uid);
                jsonResponse(['success' => true, 'message' => 'API key removed.']);
            } else {
                jsonResponse(['error' => 'Method not allowed'], 405);
            }
            break;
            
        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error'], 500);
}