<?php
/**
 * Chat API Endpoint - The main LLM interaction handler
 * POST /api/chat.php?conv_id=X              - Send message (non-streaming)
 * POST /api/chat.php?conv_id=X&stream=1     - Send message (SSE streaming)
 * POST /api/chat.php?conv_id=X&title=1      - Auto-generate a title
 */

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/ChatManager.php';
require_once __DIR__ . '/../includes/LLMClient.php';

$auth = new Auth();
$chat = new ChatManager();
$user = $auth->requireAuth();
$userId = $user['id'];

// Get user's own API credentials
$creds = $auth->getUserApiCredentials($userId);
if (!$creds) {
    jsonResponse(['error' => 'API key not configured. Please set your LLM endpoint and API key in Settings.'], 400);
}

$llm = new LLMClient($creds['endpoint'], $creds['key'], $creds['model']);

$convId = isset($_GET['conv_id']) ? (int)$_GET['conv_id'] : 0;
$stream = isset($_GET['stream']) && $_GET['stream'] === '1';
$autoTitle = isset($_GET['title']) && $_GET['title'] === '1';

if ($convId <= 0) {
    jsonResponse(['error' => 'Conversation ID required'], 400);
}

// Verify ownership
$conv = $chat->getConversation($convId, $userId);
if (!$conv) {
    jsonResponse(['error' => 'Conversation not found'], 404);
}

// --- Auto-title mode ---
if ($autoTitle) {
    $chat->autoTitle($convId, $userId);
    $conv = $chat->getConversation($convId, $userId);
    jsonResponse(['success' => true, 'title' => $conv['title']]);
    exit;
}

// --- Normal chat mode ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');
$imageUrl = $input['image_url'] ?? null;

if (empty($userMessage) && empty($imageUrl)) {
    jsonResponse(['error' => 'Message is required'], 400);
}

// Handle image upload if base64 data provided
if (!empty($input['image_data'])) {
    $imageUrl = handleImageUpload($input['image_data']);
}

try {
    // Build context for LLM
    $apiMessages = $chat->buildApiMessages($convId, $userMessage, $imageUrl);
    $selectedModel = $creds['model'];
    
    // Save user message to DB
    $savedUserMsgId = $chat->saveMessage($convId, 'user', $userMessage, $imageUrl);
    
    if ($stream) {
        // --- STREAMING MODE ---
        $fullResponse = '';
        $assistantMsgId = null;
        $firstToken = true;
        
        $llm->chatStream(
            $apiMessages,
            // onToken
            function(string $token) use ($chat, $convId, &$fullResponse, &$assistantMsgId, &$firstToken) {
                $fullResponse .= $token;
                if ($firstToken) {
                    // Create the assistant message on first token
                    $assistantMsgId = $chat->saveMessage($convId, 'assistant', '');
                    $firstToken = false;
                }
                // Append token to DB message
                if ($assistantMsgId) {
                    $chat->appendMessageContent($assistantMsgId, $token);
                }
            },
            // onComplete
            function() use ($chat, $convId, $userId, &$fullResponse) {
                // Update conversation's updated_at timestamp
                $chat->updateConversation($convId, $userId, []);
                
                // Auto-generate title if first exchange
                $messages = $chat->getMessages($convId);
                if (count($messages) <= 3) {  // system + user + assistant
                    $chat->autoTitle($convId, $userId);
                }
            },
            $selectedModel,
            LLM_TEMPERATURE,
            LLM_MAX_TOKENS
        );
    } else {
        // --- NON-STREAMING MODE ---
        $result = $llm->chat($apiMessages, $selectedModel, LLM_TEMPERATURE, LLM_MAX_TOKENS);
        
        // Save assistant response
        $chat->saveMessage($convId, 'assistant', $result['content'], null, $result['usage']['total_tokens'] ?? null);
        
        // Auto-generate title if first exchange
        $messages = $chat->getMessages($convId);
        if (count($messages) <= 3) {
            $chat->autoTitle($convId, $userId);
        }
        
        jsonResponse([
            'success'  => true,
            'message'  => $result['content'],
            'model'    => $result['model'],
            'usage'    => $result['usage'],
        ]);
    }
} catch (LLMApiException $e) {
    jsonResponse(['error' => $e->getMessage(), 'error_details' => $e->details], 502);
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 502);
} catch (Exception $e) {
    jsonResponse(['error' => 'Internal server error'], 500);
}

// --- Helper: save base64 image to uploads ---
function handleImageUpload(string $base64Data): ?string {
    // Decode data URL
    if (preg_match('/^data:(image\/\w+);base64,(.+)$/', $base64Data, $matches)) {
        $mimeType = $matches[1];
        $data = base64_decode($matches[2]);
        
        if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
            throw new RuntimeException('Unsupported image type: ' . $mimeType);
        }
        
        $ext = explode('/', $mimeType)[1];
        if ($ext === 'jpeg') $ext = 'jpg';
        $filename = uniqid('img_', true) . '.' . $ext;
        $filepath = UPLOAD_DIR . $filename;
        
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }
        
        file_put_contents($filepath, $data);
        
        return SITE_URL . '/uploads/' . $filename;
    }
    return null;
}