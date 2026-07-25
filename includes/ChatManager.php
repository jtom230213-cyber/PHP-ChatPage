<?php
/**
 * Conversation & Message Management
 */

class ChatManager {
    private PDO $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    // ====================== Conversations ======================
    
    /**
     * Create a new conversation
     */
    public function createConversation(int $userId, string $title = 'New Chat', ?string $systemPrompt = null, ?string $model = null): array {
        $stmt = $this->db->prepare(
            'INSERT INTO conversations (user_id, title, system_prompt, model) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $title, $systemPrompt, $model ?? LLM_DEFAULT_MODEL]);
        return $this->getConversation((int)$this->db->lastInsertId(), $userId);
    }
    
    /**
     * Get all conversations for a user
     */
    public function getUserConversations(int $userId, bool $includeArchived = false): array {
        $sql = 'SELECT id, title, model, is_archived, created_at, updated_at FROM conversations WHERE user_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND is_archived = 0';
        }
        $sql .= ' ORDER BY updated_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get a single conversation
     */
    public function getConversation(int $id, int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, title, system_prompt, model, is_archived, created_at, updated_at 
             FROM conversations WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $conv = $stmt->fetch();
        if ($conv) {
            $conv['messages'] = $this->getMessages($id);
        }
        return $conv ?: null;
    }
    
    /**
     * Update conversation title, system prompt, or model
     */
    public function updateConversation(int $id, int $userId, array $fields): bool {
        $allowed = ['title', 'system_prompt', 'model', 'is_archived'];
        $sets = [];
        $values = [];
        foreach ($fields as $key => $val) {
            if (in_array($key, $allowed)) {
                $sets[] = "$key = ?";
                $values[] = $val;
            }
        }
        // Always update the timestamp
        $sets[] = 'updated_at = NOW()';
        
        $values[] = $id;
        $values[] = $userId;
        $stmt = $this->db->prepare(
            'UPDATE conversations SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute($values);
    }
    
    /**
     * Delete a conversation
     */
    public function deleteConversation(int $id, int $userId): bool {
        $stmt = $this->db->prepare('DELETE FROM conversations WHERE id = ? AND user_id = ?');
        return $stmt->execute([$id, $userId]);
    }
    
    // ====================== Messages ======================
    
    /**
     * Get all messages for a conversation
     */
    public function getMessages(int $conversationId): array {
        $stmt = $this->db->prepare(
            'SELECT id, role, content, image_url, token_count, created_at 
             FROM messages WHERE conversation_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$conversationId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Save a message
     */
    public function saveMessage(int $conversationId, string $role, string $content, ?string $imageUrl = null, ?int $tokens = null): int {
        $stmt = $this->db->prepare(
            'INSERT INTO messages (conversation_id, role, content, image_url, token_count) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$conversationId, $role, $content, $imageUrl, $tokens]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Append content to a message (for streaming)
     */
    public function appendMessageContent(int $messageId, string $content): void {
        $stmt = $this->db->prepare(
            'UPDATE messages SET content = CONCAT(IFNULL(content, \'\'), ?) WHERE id = ?'
        );
        $stmt->execute([$content, $messageId]);
    }
    
    /**
     * Build messages array for LLM API call
     */
    public function buildApiMessages(int $conversationId, string $newUserMessage, ?string $imageUrl = null): array {
        $conv = $this->db->prepare(
            'SELECT system_prompt, model FROM conversations WHERE id = ? LIMIT 1'
        );
        $conv->execute([$conversationId]);
        $convData = $conv->fetch();
        
        $apiMessages = [];
        
        // System prompt
        if (!empty($convData['system_prompt'])) {
            $apiMessages[] = ['role' => 'system', 'content' => $convData['system_prompt']];
        }
        
        // Previous messages
        $prev = $this->getMessages($conversationId);
        foreach ($prev as $msg) {
            $apiMsg = ['role' => $msg['role'], 'content' => $msg['content']];
            
            // Handle image in previous messages
            if ($msg['image_url'] && $msg['role'] === 'user') {
                $apiMsg['content'] = [
                    ['type' => 'image_url', 'image_url' => ['url' => $msg['image_url'], 'detail' => 'auto']],
                    ['type' => 'text', 'text' => $msg['content'] ?: 'Describe this image.'],
                ];
            }
            $apiMessages[] = $apiMsg;
        }
        
        // New user message
        if ($imageUrl) {
            $apiMessages[] = [
                'role' => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'auto']],
                    ['type' => 'text', 'text' => $newUserMessage ?: 'Describe this image.'],
                ],
            ];
        } else {
            $apiMessages[] = ['role' => 'user', 'content' => $newUserMessage];
        }
        
        return $apiMessages;
    }
    
    /**
     * Auto-generate a title from the first exchange
     */
    public function autoTitle(int $conversationId, int $userId): void {
        $messages = $this->getMessages($conversationId);
        if (count($messages) >= 2) {
            $title = mb_substr(strip_tags($messages[1]['content'] ?? $messages[0]['content'] ?? ''), 0, 70);
            $title = $title ?: 'New Chat';
            $this->updateConversation($conversationId, $userId, ['title' => $title]);
        }
    }
}