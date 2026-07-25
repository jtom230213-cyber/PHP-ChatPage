<?php
/**
 * Conversations API Endpoints
 * GET    /api/conversations.php                    - List all conversations
 * POST   /api/conversations.php                    - Create new conversation
 * GET    /api/conversations.php?id=X               - Get conversation + messages
 * PUT    /api/conversations.php?id=X               - Update conversation
 * DELETE /api/conversations.php?id=X               - Delete conversation
 */

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/ChatManager.php';

$auth = new Auth();
$chat = new ChatManager();
$user = $auth->requireAuth();
$userId = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];
$convId = isset($_GET['id']) ? (int)$_GET['id'] : null;

try {
    switch ($method) {
        case 'GET':
            if ($convId) {
                $conv = $chat->getConversation($convId, $userId);
                if (!$conv) jsonResponse(['error' => 'Conversation not found'], 404);
                jsonResponse(['success' => true, 'conversation' => $conv]);
            } else {
                $convs = $chat->getUserConversations($userId);
                jsonResponse(['success' => true, 'conversations' => $convs]);
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            $apiSettings = $auth->getUserApiSettings($userId);
            $conv = $chat->createConversation(
                $userId,
                $data['title'] ?? 'New Chat',
                $data['system_prompt'] ?? null,
                $data['model'] ?? ($apiSettings['model'] ?? LLM_DEFAULT_MODEL)
            );
            jsonResponse(['success' => true, 'conversation' => $conv], 201);
            break;
            
        case 'PUT':
            if (!$convId) jsonResponse(['error' => 'Conversation ID required'], 400);
            $data = json_decode(file_get_contents('php://input'), true);
            $ok = $chat->updateConversation($convId, $userId, $data);
            jsonResponse(['success' => $ok]);
            break;
            
        case 'DELETE':
            if (!$convId) jsonResponse(['error' => 'Conversation ID required'], 400);
            $ok = $chat->deleteConversation($convId, $userId);
            jsonResponse(['success' => $ok]);
            break;
            
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error'], 500);
}