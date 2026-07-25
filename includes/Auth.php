<?php
/**
 * Authentication & User Management
 */

class Auth {
    private PDO $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Register a new user
     */
    public function register(string $username, string $email, string $password, string $displayName = ''): array {
        // Validate inputs
        $username = trim($username);
        $email = trim(strtolower($email));
        
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new RuntimeException('Username must be between 3 and 50 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address.');
        }
        if (strlen($password) < 6) {
            throw new RuntimeException('Password must be at least 6 characters.');
        }
        
        // Check duplicates
        $stmt = $this->db->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            throw new RuntimeException('Username or email already in use.');
        }
        
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$username, $email, $hash, $displayName ?: $username]);
        
        $userId = (int)$this->db->lastInsertId();
        
        // Auto-login
        $this->loginSession($userId);
        
        return $this->getUserById($userId);
    }
    
    /**
     * Login with username/email + password
     */
    public function login(string $login, string $password): array {
        $login = trim($login);
        
        $stmt = $this->db->prepare(
            'SELECT id, username, email, password_hash, display_name, avatar_url, is_admin 
             FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        $stmt->execute([$login, strtolower($login)]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Invalid credentials.');
        }
        
        $this->loginSession($user['id']);
        
        // Update last_login
        $stmt = $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $stmt->execute([$user['id']]);
        
        unset($user['password_hash']);
        return $user;
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }
    
    /**
     * Get current user or null
     */
    public function currentUser(): ?array {
        if (!$this->isLoggedIn()) return null;
        return $this->getUserById($_SESSION['user_id']);
    }
    
    /**
     * Require authentication - dies with 401 if not logged in
     */
    public function requireAuth(): array {
        $user = $this->currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Authentication required.'], 401);
        }
        return $user;
    }
    
    /**
     * Get user by ID
     */
    public function getUserById(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT id, username, email, display_name, avatar_url, is_admin, created_at, last_login 
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
    
    /**
     * Logout
     */
    public function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
    
    private function loginSession(int $userId): void {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_time'] = time();
    }

    // ====================== API Key Management ======================

    /**
     * Encrypt a string using AES-256-GCM
     */
    private function encrypt(string $plaintext): string {
        $key = hex2bin(ENCRYPTION_KEY);
        $iv = random_bytes(12); // 96-bit IV for GCM
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        // Store: base64(iv || ciphertext || tag)
        return base64_encode($iv . $ciphertext . $tag);
    }

    /**
     * Decrypt a string using AES-256-GCM
     */
    private function decrypt(string $encrypted): string {
        $key = hex2bin(ENCRYPTION_KEY);
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 12);
        $tag = substr($data, -16);
        $ciphertext = substr($data, 12, -16);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt API key.');
        }
        return $plaintext;
    }

    /**
     * Get a user's LLM API credentials (decrypted)
     */
    public function getUserApiCredentials(int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT api_endpoint, api_key_encrypted, preferred_model
             FROM user_api_keys WHERE user_id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return [
            'endpoint' => $row['api_endpoint'],
            'key'      => $this->decrypt($row['api_key_encrypted']),
            'model'    => $row['preferred_model'] ?: LLM_DEFAULT_MODEL,
        ];
    }

    /**
     * Return a user's saved LLM settings without exposing the API key.
     */
    public function getUserApiSettings(int $userId): ?array {
        $stmt = $this->db->prepare(
            'SELECT api_endpoint, preferred_model
             FROM user_api_keys WHERE user_id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return [
            'endpoint' => $row['api_endpoint'],
            'model' => $row['preferred_model'] ?: LLM_DEFAULT_MODEL,
        ];
    }

    /**
     * Get current user's API credentials (convenience)
     */
    public function getCurrentUserApiCredentials(): ?array {
        if (!$this->isLoggedIn()) return null;
        return $this->getUserApiCredentials($_SESSION['user_id']);
    }

    /**
     * Save or update a user's API key (encrypted)
     */
    public function saveApiKey(int $userId, string $apiEndpoint, string $apiKey, string $preferredModel = ''): void {
        // Validate
        $apiEndpoint = trim($apiEndpoint);
        if (empty($apiEndpoint) || !filter_var($apiEndpoint, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid API endpoint URL.');
        }
        if (empty(trim($apiKey))) {
            throw new RuntimeException('API key is required.');
        }

        $encryptedKey = $this->encrypt(trim($apiKey));
        $model = trim($preferredModel) ?: LLM_DEFAULT_MODEL;

        // Upsert
        $stmt = $this->db->prepare(
            'INSERT INTO user_api_keys (user_id, api_endpoint, api_key_encrypted, preferred_model)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                api_endpoint = VALUES(api_endpoint),
                api_key_encrypted = VALUES(api_key_encrypted),
                preferred_model = VALUES(preferred_model),
                is_active = 1,
                updated_at = NOW()'
        );
        $stmt->execute([$userId, $apiEndpoint, $encryptedKey, $model]);
    }

    /**
     * Check if user has API key configured
     */
    public function hasApiKey(int $userId): bool {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM user_api_keys WHERE user_id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Delete a user's API key
     */
    public function deleteApiKey(int $userId): void {
        $stmt = $this->db->prepare('DELETE FROM user_api_keys WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}