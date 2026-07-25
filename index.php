<?php
/**
 * Main Entry Point - Serves the chat UI SPA
 */
require_once __DIR__ . '/config/init.php';
require_once __DIR__ . '/includes/Auth.php';

$auth = new Auth();
$isLoggedIn = $auth->isLoggedIn();
$user = $auth->currentUser();
$hasApiKey = $isLoggedIn ? $auth->hasApiKey($user['id']) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🤖</text></svg>">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/github-dark.min.css">
    <!-- Markdown rendering: marked.js -->
    <script src="assets/js/marked.min.js"></script>
    <!-- Syntax highlighting in code blocks -->
    <script src="assets/js/highlight.min.js"></script>
</head>
<body class="dark">
    <div id="app">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar">
            <div class="sidebar-header">
                <button id="btn-new-chat" class="btn btn-primary" title="New Chat">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Chat
                </button>
                <div class="sidebar-actions">
                    <button id="btn-toggle-sidebar" class="btn-icon" title="Close sidebar">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="9" x2="9" y2="9"/><line x1="15" y1="15" x2="9" y2="15"/></svg>
                    </button>
                </div>
            </div>
            <nav id="conv-list" class="conv-list">
                <!-- Conversations loaded via JS -->
            </nav>
            <div class="sidebar-footer">
                <?php if ($isLoggedIn): ?>
                <div class="user-info">
                    <div class="user-avatar"><?= strtoupper(substr($user['display_name'] ?? $user['username'], 0, 2)) ?></div>
                    <span class="user-name"><?= sanitize($user['display_name'] ?? $user['username']) ?></span>
                </div>
                <?php endif; ?>
                <div class="sidebar-footer-actions">
                    <button id="btn-theme" class="btn-icon" title="Toggle theme">
                        <svg id="icon-theme" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    </button>
                    <?php if ($isLoggedIn): ?>
                    <button id="btn-logout" class="btn-icon" title="Logout">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <!-- Floating toggle when sidebar collapsed -->
        <button id="btn-floating-toggle" class="btn-icon sidebar-floating-toggle" title="Open sidebar" style="display:none">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="15" y1="3" x2="15" y2="21"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        </button>

        <!-- Main Chat Area -->
        <main id="main-chat" class="main-chat">
            <?php if (!$isLoggedIn): ?>
            <!-- Login/Register Screen -->
            <div id="auth-screen" class="auth-screen">
                <div class="auth-card">
                    <div class="auth-logo">🤖</div>
                    <h1><?= SITE_NAME ?></h1>
                    <p class="auth-subtitle">AI Chat Powered by LLM</p>
                    <div id="auth-tabs" class="auth-tabs">
                        <button class="auth-tab active" data-tab="login">Login</button>
                        <button class="auth-tab" data-tab="register">Register</button>
                    </div>
                    <form id="login-form" class="auth-form active">
                        <div class="form-group">
                            <label for="login-username">Username or Email</label>
                            <input type="text" id="login-username" placeholder="Enter username or email" required>
                        </div>
                        <div class="form-group">
                            <label for="login-password">Password</label>
                            <input type="password" id="login-password" placeholder="Enter password" required>
                        </div>
                        <div id="login-error" class="form-error"></div>
                        <button type="submit" class="btn btn-primary btn-block">Sign In</button>
                    </form>
                    <form id="register-form" class="auth-form">
                        <div class="form-group">
                            <label for="reg-username">Username</label>
                            <input type="text" id="reg-username" placeholder="Choose a username" required minlength="3">
                        </div>
                        <div class="form-group">
                            <label for="reg-email">Email</label>
                            <input type="email" id="reg-email" placeholder="your@email.com" required>
                        </div>
                        <div class="form-group">
                            <label for="reg-password">Password</label>
                            <input type="password" id="reg-password" placeholder="At least 6 characters" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="reg-display">Display Name (optional)</label>
                            <input type="text" id="reg-display" placeholder="Your display name">
                        </div>
                        <div id="register-error" class="form-error"></div>
                        <button type="submit" class="btn btn-primary btn-block">Create Account</button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <!-- Chat Interface -->
            <div id="chat-interface" class="chat-interface">
                <!-- Top bar -->
                <header class="chat-header">
                    <button id="btn-mobile-sidebar" class="btn-icon mobile-only" title="Menu">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>
                    <h2 id="conv-title" class="chat-title">New Chat</h2>
                    <div class="chat-header-actions">
                        <button id="btn-error-log" class="btn-icon btn-error-log" title="Open API error log" aria-label="Open API error log">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        </button>
                        <button id="btn-api-settings" class="btn-icon" title="API Settings" style="<?= $hasApiKey ? 'color:var(--success)' : 'color:var(--danger)' ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                        </button>
                        <button id="btn-system-prompt" class="btn-icon" title="System Prompt">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        </button>
                        <button id="btn-delete-conv" class="btn-icon btn-danger" title="Delete conversation">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                        </button>
                    </div>
                </header>

                <!-- Messages container -->
                <div id="messages-container" class="messages-container">
                    <div id="messages-list" class="messages-list">
                        <div class="welcome-message">
                            <div class="welcome-icon">🤖</div>
                            <h3>How can I help you today?</h3>
                            <p>Start a conversation with the AI assistant</p>
                        </div>
                    </div>
                </div>

                <!-- System prompt modal -->
                <div id="system-modal" class="modal-overlay" style="display:none">
                    <div class="modal">
                        <div class="modal-header">
                            <h3>System Prompt</h3>
                            <button class="modal-close btn-icon">&times;</button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-hint">Customize the AI's behavior and personality</p>
                            <textarea id="system-prompt-input" rows="6" placeholder="You are a helpful assistant..."></textarea>
                        </div>
                        <div class="modal-footer">
                            <button id="btn-save-prompt" class="btn btn-primary">Save</button>
                            <button id="btn-reset-prompt" class="btn btn-secondary">Reset to Default</button>
                        </div>
                    </div>
                </div>

                <!-- API Key Settings modal -->
                <div id="apikey-modal" class="modal-overlay" style="display:none">
                    <div class="modal">
                        <div class="modal-header">
                            <h3>⚙️ LLM API Settings</h3>
                            <button class="modal-close btn-icon">&times;</button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-hint">
                                Your API key is encrypted at rest in the database. Only you can use it.
                                Compatible with OpenAI, LiteLLM, Ollama, and any OpenAI-compatible endpoint.
                            </p>
                            <div class="form-group">
                                <label for="api-endpoint-input">API Endpoint URL</label>
                                <input type="url" id="api-endpoint-input" placeholder="https://api.openai.com/v1/chat/completions or https://token.sensenova.cn/v1/messages" value="https://api.openai.com/v1/chat/completions">
                            </div>
                            <div class="form-group">
                                <label for="api-key-input">API Key (sk-...)</label>
                                <input type="password" id="api-key-input" placeholder="Enter your provider API key">
                            </div>
                            <div class="form-group">
                                <label for="api-model-input">Preferred Model</label>
                                <input type="text" id="api-model-input" placeholder="gpt-4o" value="gpt-4o">
                            </div>
                            <div id="apikey-error" class="form-error"></div>
                        </div>
                        <div class="modal-footer">
                            <button id="btn-delete-apikey" class="btn btn-danger" style="margin-right:auto">Delete Key</button>
                            <button id="btn-save-apikey" class="btn btn-primary">Save Settings</button>
                        </div>
                    </div>
                </div>

                <!-- Input area -->
                <div id="input-area" class="input-area">
                    <div id="image-preview-container" class="image-preview-container" style="display:none">
                        <img id="image-preview" src="" alt="Preview">
                        <button id="btn-remove-image" class="btn-icon" title="Remove image">&times;</button>
                    </div>
                    <div class="input-row">
                        <button id="btn-upload-image" class="btn-icon" title="Upload image">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        </button>
                        <input type="file" id="file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
                        <textarea id="chat-input" rows="1" placeholder="Type your message... (Shift+Enter for new line)" maxlength="32000"></textarea>
                        <button id="btn-send" class="btn-icon btn-send" title="Send message">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Hidden: current conversation ID -->
    <input type="hidden" id="current-conv-id" value="">

    <script>
    // Pass config values to JS
    const APP_CONFIG = {
        apiBase: '<?= SITE_URL ?>',
        isLoggedIn: <?= $isLoggedIn ? 'true' : 'false' ?>,
        maxFileSize: <?= MAX_FILE_UPLOAD_SIZE ?>,
        hasApiKey: <?= $hasApiKey ? 'true' : 'false' ?>
    };
    </script>
    <script src="assets/js/app.js"></script>
</body>
</html>