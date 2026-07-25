/**
 * VistaPanel AI Chat - Main Application
 * Handles: auth, conversations CRUD, streaming chat, markdown, images, themes
 */
(function() {
'use strict';

// ====================== State ======================
const state = {
    conversations: [],
    currentConvId: null,
    currentMessages: [],
    systemPrompt: null,
    currentModel: null,
    isStreaming: false,
    pendingImage: null,        // { dataUrl, file }
    lastError: null,
};

// ====================== DOM References ======================
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

const dom = {
    sidebar: $('#sidebar'),
    convList: $('#conv-list'),
    messagesList: $('#messages-list'),
    messagesContainer: $('#messages-container'),
    chatInput: $('#chat-input'),
    btnSend: $('#btn-send'),
    currentConvId: $('#current-conv-id'),
    convTitle: $('#conv-title'),
    systemModal: $('#system-modal'),
    systemPromptInput: $('#system-prompt-input'),
    imagePreviewContainer: $('#image-preview-container'),
    imagePreview: $('#image-preview'),
    fileInput: $('#file-input'),
    apikeyModal: $('#apikey-modal'),
    apiEndpointInput: $('#api-endpoint-input'),
    apiKeyInput: $('#api-key-input'),
    apiModelInput: $('#api-model-input'),
    apiKeyError: $('#apikey-error'),
    btnSaveApiKey: $('#btn-save-apikey'),
    btnDeleteApiKey: $('#btn-delete-apikey'),
    authScreen: $('#auth-screen'),
    chatInterface: $('#chat-interface'),
    btnErrorLog: $('#btn-error-log'),
    toastContainer: null,
    errorLogWindow: null,
};

// ====================== API Helpers ======================
const API = {
    async request(url, options = {}) {
        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        // Merge provided headers with defaults (provided wins)
        if (options.headers) {
            Object.assign(headers, options.headers);
        }
        const response = await fetch(APP_CONFIG.apiBase + url, {
            ...options,
            headers,
        });
        // Try JSON first, fall back to text for debugging
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            throw new Error(`Server returned non-JSON response (HTTP ${response.status}): ${text.substring(0, 200)}`);
        }
        if (!response.ok || data.error) {
            const error = new Error(data.error || `HTTP ${response.status}`);
            error.details = data.error_details || null;
            throw error;
        }
        return data;
    },

    // Auth
    login(login, password) {
        return this.request('/api/auth.php?action=login', {
            method: 'POST',
            body: JSON.stringify({ login, password }),
        });
    },
    register(username, email, password, displayName) {
        return this.request('/api/auth.php?action=register', {
            method: 'POST',
            body: JSON.stringify({ username, email, password, display_name: displayName }),
        });
    },
    logout() {
        return this.request('/api/auth.php?action=logout', { method: 'POST' });
    },
    me() {
        return this.request('/api/auth.php?action=me');
    },

    // Conversations
    listConversations() {
        return this.request('/api/conversations.php');
    },
    createConversation(title, systemPrompt, model) {
        return this.request('/api/conversations.php', {
            method: 'POST',
            body: JSON.stringify({ title, system_prompt: systemPrompt, model }),
        });
    },
    getConversation(id) {
        return this.request(`/api/conversations.php?id=${id}`);
    },
    updateConversation(id, fields) {
        return this.request(`/api/conversations.php?id=${id}`, {
            method: 'PUT',
            body: JSON.stringify(fields),
        });
    },
    deleteConversation(id) {
        return this.request(`/api/conversations.php?id=${id}`, { method: 'DELETE' });
    },

    // Chat - non-streaming
    sendMessage(convId, message, imageUrl) {
        return this.request(`/api/chat.php?conv_id=${convId}`, {
            method: 'POST',
            body: JSON.stringify({ message, image_url: imageUrl }),
        });
    },
    
    // Chat - streaming
    async sendMessageStream(convId, message, imageData, onToken, onComplete, onError) {
        const body = JSON.stringify({
            message,
            ...(imageData ? { image_data: imageData } : {}),
        });
        try {
            const response = await fetch(`${APP_CONFIG.apiBase}/api/chat.php?conv_id=${convId}&stream=1`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
                body,
            });
            if (!response.ok) {
                const err = await response.json().catch(() => ({ error: `HTTP ${response.status}` }));
                const error = new Error(err.error || `HTTP ${response.status}`);
                error.details = err.error_details || null;
                throw error;
            }
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                // Process SSE events
                const parts = buffer.split('\n\n');
                buffer = parts.pop(); // keep incomplete in buffer
                for (const part of parts) {
                    const lines = part.split('\n');
                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            const data = line.slice(6);
                            if (data === '[DONE]') { onComplete(); return; }
                            try {
                                const parsed = JSON.parse(data);
                                if (parsed.token) onToken(parsed.token);
                                if (parsed.error) {
                                    onError(parsed.error, parsed.error_details || null);
                                    return;
                                }
                            } catch(e) { /* skip parse errors */ }
                        }
                    }
                }
            }
            onComplete();
        } catch (e) {
            onError(e.message, e.details || null);
        }
    },

    // API Key management
    saveKey(endpoint, key, model) {
        return this.request('/api/auth.php?action=apikey', {
            method: 'POST',
            body: JSON.stringify({ api_endpoint: endpoint, api_key: key, preferred_model: model }),
        });
    },
    getKey() {
        return this.request('/api/auth.php?action=apikey');
    },
    deleteKey() {
        return this.request('/api/auth.php?action=apikey', { method: 'DELETE' });
    },
};

// ====================== Toast Notifications ======================
function showToast(message, type = 'error') {
    if (!dom.toastContainer) {
        dom.toastContainer = document.createElement('div');
        dom.toastContainer.className = 'toast-container';
        document.body.appendChild(dom.toastContainer);
    }
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    dom.toastContainer.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ====================== Theme ======================
function toggleTheme() {
    document.body.classList.toggle('light');
    document.body.classList.toggle('dark');
    const isLight = document.body.classList.contains('light');
    const icon = $('#icon-theme');
    if (isLight) {
        icon.innerHTML = '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>';
    } else {
        icon.innerHTML = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>';
    }
    localStorage.setItem('theme', isLight ? 'light' : 'dark');
}

function initTheme() {
    const saved = localStorage.getItem('theme') || 'dark';
    if (saved === 'light') {
        document.body.classList.add('light');
        document.body.classList.remove('dark');
        const icon = $('#icon-theme');
        if (icon) icon.innerHTML = '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>';
    }
}

// ====================== Markdown Rendering ======================
function renderMarkdown(text) {
    if (!text) return '';
    marked.setOptions({
        breaks: true,
        gfm: true,
    });
    let html = marked.parse(text);
    // Add copy buttons to code blocks
    html = html.replace(/<pre><code/g, '<div class="code-block-wrapper"><pre><code');
    html = html.replace(/<\/code><\/pre>/g, `</code></pre><button class="code-copy-btn" onclick="var t=this.parentElement.querySelector('code');navigator.clipboard.writeText(t.innerText).then(()=>{this.textContent='Copied!';setTimeout(()=>{this.textContent='Copy'},1500)});this.textContent||(this.textContent='Copy')">Copy</button></div>`);
    return html;
}

// Configure highlight.js
if (typeof hljs !== 'undefined') {
    hljs.configure({ cssSelector: 'pre code' });
}

// ====================== Sidebar / Conversations ======================
function renderConversationList() {
    if (!dom.convList) return;
    dom.convList.innerHTML = state.conversations.map(conv => {
        const isActive = conv.id === state.currentConvId;
        const date = new Date(conv.updated_at || conv.created_at);
        const dateStr = isToday(date) ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            : date.toLocaleDateString([], { month: 'short', day: 'numeric' });
        return `
            <div class="conv-item ${isActive ? 'active' : ''}" data-id="${conv.id}">
                <svg class="conv-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <span class="conv-title-text">${escapeHtml(conv.title)}</span>
                <span class="conv-date">${dateStr}</span>
                <div class="conv-actions">
                    <button class="btn-icon btn-rename-conv" data-id="${conv.id}" title="Rename">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </button>
                    <button class="btn-icon btn-archive-conv" data-id="${conv.id}" title="Delete">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    </button>
                </div>
            </div>`;
    }).join('');
    
    // Bind click events
    dom.convList.querySelectorAll('.conv-item').forEach(item => {
        item.addEventListener('click', (e) => {
            if (e.target.closest('.btn-icon')) return;
            const id = parseInt(item.dataset.id);
            if (id !== state.currentConvId) loadConversation(id);
        });
    });
    
    // Rename buttons
    dom.convList.querySelectorAll('.btn-rename-conv').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = parseInt(btn.dataset.id);
            const conv = state.conversations.find(c => c.id === id);
            if (!conv) return;
            const newTitle = prompt('Rename conversation:', conv.title);
            if (newTitle && newTitle.trim()) {
                renameConversation(id, newTitle.trim());
            }
        });
    });
    
    // Delete buttons
    dom.convList.querySelectorAll('.btn-archive-conv').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = parseInt(btn.dataset.id);
            if (confirm('Delete this conversation?')) {
                deleteConversation(id);
            }
        });
    });
}

function isToday(date) {
    const now = new Date();
    return date.getDate() === now.getDate() &&
        date.getMonth() === now.getMonth() &&
        date.getFullYear() === now.getFullYear();
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

async function loadConversations() {
    try {
        const data = await API.listConversations();
        state.conversations = data.conversations || [];
        renderConversationList();
    } catch (e) {
        showToast('Failed to load conversations: ' + e.message);
    }
}

async function newConversation() {
    try {
        const data = await API.createConversation('New Chat');
        state.conversations.unshift(data.conversation);
        renderConversationList();
        loadConversation(data.conversation.id);
    } catch (e) {
        showToast('Failed to create conversation: ' + e.message);
    }
}

async function loadConversation(id) {
    try {
        const data = await API.getConversation(id);
        const conv = data.conversation;
        state.currentConvId = conv.id;
        state.currentMessages = conv.messages || [];
        state.systemPrompt = conv.system_prompt || null;
        state.currentModel = conv.model || null;
        
        dom.currentConvId.value = conv.id;
        dom.convTitle.textContent = conv.title;
        dom.systemPromptInput.value = conv.system_prompt || '';
        
        renderMessages();
        renderConversationList();
        scrollToBottom();
        
        // Highlight code blocks
        setTimeout(() => {
            if (typeof hljs !== 'undefined') hljs.highlightAll();
        }, 100);
    } catch (e) {
        showToast('Failed to load conversation: ' + e.message);
    }
}

async function renameConversation(id, title) {
    try {
        await API.updateConversation(id, { title });
        const conv = state.conversations.find(c => c.id === id);
        if (conv) conv.title = title;
        if (id === state.currentConvId) dom.convTitle.textContent = title;
        renderConversationList();
    } catch (e) {
        showToast('Failed to rename: ' + e.message);
    }
}

async function deleteConversation(id) {
    try {
        await API.deleteConversation(id);
        state.conversations = state.conversations.filter(c => c.id !== id);
        if (id === state.currentConvId) {
            state.currentConvId = null;
            state.currentMessages = [];
            dom.currentConvId.value = '';
            dom.convTitle.textContent = 'New Chat';
            renderMessages();
        }
        renderConversationList();
    } catch (e) {
        showToast('Failed to delete: ' + e.message);
    }
}

// ====================== Messages Rendering ======================
function renderMessages() {
    if (!dom.messagesList) return;
    
    if (state.currentMessages.length === 0) {
        dom.messagesList.innerHTML = `
            <div class="welcome-message">
                <div class="welcome-icon">🤖</div>
                <h3>How can I help you today?</h3>
                <p>Start a conversation with the AI assistant</p>
            </div>`;
        return;
    }
    
    dom.messagesList.innerHTML = state.currentMessages.map(msg => {
        if (msg.role === 'system') return '';
        const avatar = msg.role === 'user' ? (APP_CONFIG.isLoggedIn ? 'U' : '👤') : '🤖';
        const roleLabel = msg.role === 'user' ? 'You' : 'Assistant';
        const imageHtml = msg.image_url
            ? `<img src="${escapeHtml(msg.image_url)}" class="message-image" onclick="window.open(this.src)" loading="lazy">`
            : '';
        const contentHtml = imageHtml + (msg.content ? `<div class="message-content">${renderMarkdown(msg.content)}</div>` : '');
        return `
            <div class="message ${msg.role}" data-id="${msg.id}">
                <div class="message-avatar">${avatar}</div>
                <div class="message-body">
                    <div class="message-role">${roleLabel}</div>
                    ${contentHtml}
                </div>
            </div>`;
    }).join('');
    
    setTimeout(() => {
        if (typeof hljs !== 'undefined') hljs.highlightAll();
    }, 100);
}

function scrollToBottom() {
    if (dom.messagesContainer) {
        dom.messagesContainer.scrollTop = dom.messagesContainer.scrollHeight;
    }
}

// ====================== Chat / Streaming ======================
function addUserMessageBubble(content, imageUrl) {
    const avatar = '👤';
    const imageHtml = imageUrl
        ? `<img src="${escapeHtml(imageUrl)}" class="message-image" onclick="window.open(this.src)" loading="lazy">`
        : '';
    const bubble = document.createElement('div');
    bubble.className = 'message user';
    bubble.innerHTML = `
        <div class="message-avatar">${avatar}</div>
        <div class="message-body">
            <div class="message-role">You</div>
            ${imageHtml}
            ${content ? `<div class="message-content">${renderMarkdown(content)}</div>` : ''}
        </div>`;
    dom.messagesList.appendChild(bubble);
    
    // Remove welcome message if present
    const welcome = dom.messagesList.querySelector('.welcome-message');
    if (welcome) welcome.remove();
}

function createStreamingBubble() {
    const bubble = document.createElement('div');
    bubble.className = 'message assistant streaming';
    bubble.innerHTML = `
        <div class="message-avatar">🤖</div>
        <div class="message-body">
            <div class="message-role">Assistant</div>
            <div class="message-content"></div>
        </div>`;
    dom.messagesList.appendChild(bubble);
    return bubble.querySelector('.message-content');
}

function renderApiError(message, details) {
    const causes = (details?.possible_causes || []).map(cause => `<li>${escapeHtml(cause)}</li>`).join('');
    const metadata = [
        ['HTTP status', details?.http_status],
        ['Endpoint', details?.endpoint],
        ['Model', details?.model],
        ['Provider IP', details?.provider_ip],
        ['Request ID', details?.request_id],
    ].filter(([, value]) => value).map(([label, value]) => `<dt>${label}</dt><dd>${escapeHtml(String(value))}</dd>`).join('');
    const responseBody = details?.response_body
        ? `<div class="api-error-section"><strong>Provider response</strong><pre>${escapeHtml(details.response_body)}</pre></div>`
        : '';
    const headers = details?.response_headers && Object.keys(details.response_headers).length
        ? `<div class="api-error-section"><strong>Provider headers</strong><pre>${escapeHtml(JSON.stringify(details.response_headers, null, 2))}</pre></div>`
        : '';
    const diagnostic = details
        ? `<details class="api-error-details"><summary>Show diagnostic details</summary><dl>${metadata}</dl>${causes ? `<div class="api-error-section"><strong>Possible causes</strong><ul>${causes}</ul></div>` : ''}${responseBody}${headers}</details>`
        : '';

    return `<div class="api-error"><strong>Request failed</strong><p>${escapeHtml(message)}</p>${diagnostic}</div>`;
}

function showErrorLog(message, details) {
    if (message) {
        state.lastError = { message, details };
    }
    if (!dom.errorLogWindow) {
        const windowElement = document.createElement('section');
        windowElement.className = 'error-log-window';
        windowElement.setAttribute('role', 'alertdialog');
        windowElement.setAttribute('aria-label', 'API error log');
        windowElement.innerHTML = `
            <div class="error-log-header">
                <strong>API Error Log</strong>
                <button type="button" class="btn-icon error-log-close" title="Close error log" aria-label="Close error log">&times;</button>
            </div>
            <div class="error-log-content"></div>`;
        windowElement.querySelector('.error-log-close').addEventListener('click', () => {
            windowElement.remove();
            dom.errorLogWindow = null;
        });
        document.body.appendChild(windowElement);
        dom.errorLogWindow = windowElement;
    }

    const error = state.lastError;
    dom.errorLogWindow.querySelector('.error-log-content').innerHTML = error
        ? renderApiError(error.message, error.details)
        : '<div class="error-log-empty">No API errors have been captured in this browser session.</div>';
}

function removeTypingIndicator() {
    const indicator = dom.messagesList.querySelector('.typing-indicator-parent');
    if (indicator) indicator.remove();
}

async function sendMessage() {
    if (state.isStreaming) return;
    
    // Check if user has configured API key
    if (!APP_CONFIG.hasApiKey) {
        showToast('Please configure your API key in Settings first.');
        openApiKeyModal();
        return;
    }
    
    const message = dom.chatInput.value.trim();
    const hasImage = state.pendingImage !== null;
    
    if (!message && !hasImage) return;
    if (!state.currentConvId) {
        // Auto-create conversation
        try {
            const data = await API.createConversation('New Chat');
            state.conversations.unshift(data.conversation);
            state.currentConvId = data.conversation.id;
            dom.currentConvId.value = data.conversation.id;
            state.systemPrompt = null;
            state.currentModel = null;
            renderConversationList();
        } catch (e) {
            showErrorLog('Failed to create conversation: ' + e.message, e.details || null);
            showToast('Failed to create conversation: ' + e.message);
            return;
        }
    }
    
    // Clear input
    dom.chatInput.value = '';
    dom.chatInput.style.height = 'auto';
    
    // Prepare image
    let imageUrl = null;
    let imageData = null;
    if (state.pendingImage) {
        imageData = state.pendingImage.dataUrl;
        imageUrl = state.pendingImage.dataUrl; // For display bubble
        clearImagePreview();
    }
    
    // Add user bubble to UI
    addUserMessageBubble(message, imageUrl);
    scrollToBottom();
    
    // Disable send
    state.isStreaming = true;
    dom.btnSend.disabled = true;
    
    // Create streaming assistant bubble
    const contentEl = createStreamingBubble();
    let fullResponse = '';
    
    // Update conversation title during streaming
    dom.convTitle.textContent = message.slice(0, 50) || 'New Chat';
    
    try {
        await API.sendMessageStream(
            state.currentConvId,
            message,
            imageData,
            // onToken
            (token) => {
                fullResponse += token;
                contentEl.innerHTML = renderMarkdown(fullResponse);
                scrollToBottom();
            },
            // onComplete
            async () => {
                state.isStreaming = false;
                dom.btnSend.disabled = false;
                
                // Get final messages from server
                try {
                    const data = await API.getConversation(state.currentConvId);
                    state.currentMessages = data.conversation.messages || [];
                    dom.convTitle.textContent = data.conversation.title || dom.convTitle.textContent;
                    renderConversationList();
                    // Re-render to avoid duplicated messages from streaming
                    renderMessages();
                } catch(e) { /* silent */ }
                
                scrollToBottom();
                if (typeof hljs !== 'undefined') hljs.highlightAll();
            },
            // onError
            (error, details) => {
                state.isStreaming = false;
                dom.btnSend.disabled = false;
                contentEl.innerHTML = renderApiError(error, details);
                showErrorLog(error, details);
                showToast(error);
            }
        );
    } catch (e) {
        state.isStreaming = false;
        dom.btnSend.disabled = false;
        contentEl.innerHTML = renderApiError(e.message, e.details || null);
        showErrorLog(e.message, e.details || null);
        showToast(e.message);
    }
}

// ====================== Image Upload ======================
function handleImageUpload(file) {
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
        showToast('Unsupported image type. Please use JPEG, PNG, GIF, or WebP.');
        return;
    }
    if (file.size > APP_CONFIG.maxFileSize) {
        showToast(`Image too large. Max size is ${APP_CONFIG.maxFileSize / 1024 / 1024} MB.`);
        return;
    }
    
    const reader = new FileReader();
    reader.onload = (e) => {
        state.pendingImage = {
            dataUrl: e.target.result,
            file: file,
        };
        dom.imagePreview.src = e.target.result;
        dom.imagePreviewContainer.style.display = 'flex';
    };
    reader.readAsDataURL(file);
}

function clearImagePreview() {
    state.pendingImage = null;
    dom.imagePreview.src = '';
    dom.imagePreviewContainer.style.display = 'none';
    dom.fileInput.value = '';
}

// ====================== System Prompt ======================
async function saveSystemPrompt() {
    const prompt = dom.systemPromptInput.value.trim();
    if (!state.currentConvId) {
        try {
            const data = await API.createConversation('New Chat', prompt || null);
            const conversation = data.conversation;
            state.conversations.unshift(conversation);
            state.currentConvId = conversation.id;
            state.currentMessages = conversation.messages || [];
            state.systemPrompt = prompt || null;
            state.currentModel = conversation.model || null;
            dom.currentConvId.value = conversation.id;
            dom.convTitle.textContent = conversation.title;
            renderConversationList();
        } catch (e) {
            showToast('Failed to save: ' + e.message);
            return;
        }
    } else {
        try {
            await API.updateConversation(state.currentConvId, { system_prompt: prompt || null });
            state.systemPrompt = prompt || null;
        } catch (e) {
            showToast('Failed to save: ' + e.message);
            return;
        }
    }
    dom.systemModal.style.display = 'none';
    showToast(prompt ? 'System prompt saved.' : 'System prompt cleared.', 'success');
}

// ====================== API Key Management ======================
function openApiKeyModal() {
    if (!dom.apikeyModal) return;
    dom.apiKeyError.textContent = '';
    // Load current config
    API.getKey().then(data => {
        if (data.has_key) {
            dom.apiKeyInput.placeholder = '•••••••• (key already saved)';
        }
        if (data.api_endpoint) dom.apiEndpointInput.value = data.api_endpoint;
        if (data.preferred_model) dom.apiModelInput.value = data.preferred_model;
    }).catch(() => {});
    dom.apikeyModal.style.display = 'flex';
}

async function saveApiKey() {
    const endpoint = dom.apiEndpointInput.value.trim();
    const key = dom.apiKeyInput.value.trim();
    const model = dom.apiModelInput.value.trim();
    
    dom.apiKeyError.textContent = '';
    
    if (!endpoint) {
        dom.apiKeyError.textContent = 'API Endpoint URL is required.';
        return;
    }
    if (!key) {
        dom.apiKeyError.textContent = 'API Key is required.';
        return;
    }
    if (!model) {
        dom.apiKeyError.textContent = 'Preferred Model is required.';
        return;
    }
    
    try {
        await API.saveKey(endpoint, key, model);
        dom.apikeyModal.style.display = 'none';
        APP_CONFIG.hasApiKey = true;
        const btn = $('#btn-api-settings');
        if (btn) btn.style.color = 'var(--success)';
        showToast('API key saved securely.', 'success');
    } catch (e) {
        dom.apiKeyError.textContent = e.message;
    }
}

async function deleteApiKey() {
    if (!confirm('Remove your API key? You will need to re-enter it to use the chat.')) return;
    try {
        await API.deleteKey();
        dom.apikeyModal.style.display = 'none';
        APP_CONFIG.hasApiKey = false;
        dom.apiKeyInput.value = '';
        const btn = $('#btn-api-settings');
        if (btn) btn.style.color = 'var(--danger)';
        showToast('API key removed.', 'success');
    } catch (e) {
        dom.apiKeyError.textContent = e.message;
    }
}

// ====================== Auth ======================
function setupAuth() {
    if (APP_CONFIG.isLoggedIn) return;
    
    const loginForm = $('#login-form');
    const registerForm = $('#register-form');
    const tabs = $$('.auth-tab');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            const target = tab.dataset.tab;
            $$('.auth-form').forEach(f => f.classList.remove('active'));
            if (target === 'login') loginForm.classList.add('active');
            else registerForm.classList.add('active');
        });
    });
    
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const loginErr = $('#login-error');
        loginErr.textContent = '';
        try {
            const data = await API.login(
                $('#login-username').value,
                $('#login-password').value
            );
            window.location.reload();
        } catch (err) {
            loginErr.textContent = err.message;
        }
    });
    
    registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const regErr = $('#register-error');
        regErr.textContent = '';
        try {
            const data = await API.register(
                $('#reg-username').value,
                $('#reg-email').value,
                $('#reg-password').value,
                $('#reg-display').value
            );
            window.location.reload();
        } catch (err) {
            regErr.textContent = err.message;
        }
    });
}

// ====================== Event Bindings ======================
function setSidebarCollapsed(collapsed) {
    dom.sidebar?.classList.toggle('collapsed', collapsed);
    const floatingBtn = $('#btn-floating-toggle');
    if (floatingBtn) floatingBtn.style.display = collapsed ? 'flex' : 'none';
}

function bindEvents() {
    // New chat
    $('#btn-new-chat')?.addEventListener('click', newConversation);

    dom.btnErrorLog?.addEventListener('click', () => showErrorLog());
    
    // Toggle sidebar — keep button visible so user can re-open
    $('#btn-toggle-sidebar')?.addEventListener('click', () => {
        const collapsed = !dom.sidebar.classList.contains('collapsed');
        setSidebarCollapsed(collapsed);
        // Update icon direction
        const icon = $('#btn-toggle-sidebar svg');
        if (icon) {
            if (collapsed) {
                icon.innerHTML = '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="15" y1="3" x2="15" y2="21"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="15" x2="15" y2="15"/>';
            } else {
                icon.innerHTML = '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="9" x2="9" y2="9"/><line x1="15" y1="15" x2="9" y2="15"/>';
            }
        }
    });
    
    // Floating toggle to re-open sidebar
    $('#btn-floating-toggle')?.addEventListener('click', () => {
        setSidebarCollapsed(false);
        // Reset sidebar toggle icon
        const icon = $('#btn-toggle-sidebar svg');
        if (icon) {
            icon.innerHTML = '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="9" x2="9" y2="9"/><line x1="15" y1="15" x2="9" y2="15"/>';
        }
    });
    
    // Mobile sidebar
    $('#btn-mobile-sidebar')?.addEventListener('click', () => {
        dom.sidebar.classList.toggle('mobile-open');
    });
    
    // Close sidebar on mobile when clicking main area
    $('#main-chat')?.addEventListener('click', () => {
        dom.sidebar.classList.remove('mobile-open');
    });
    
    // Theme toggle
    $('#btn-theme')?.addEventListener('click', toggleTheme);
    
    // Logout
    $('#btn-logout')?.addEventListener('click', async () => {
        try {
            await API.logout();
            window.location.reload();
        } catch (e) {
            showToast('Logout failed');
        }
    });
    
    // Delete current conversation
    $('#btn-delete-conv')?.addEventListener('click', () => {
        if (state.currentConvId && confirm('Delete this conversation?')) {
            deleteConversation(state.currentConvId);
        }
    });
    
    // Send message
    $('#btn-send')?.addEventListener('click', sendMessage);
    
    // Chat input: Enter to send, Shift+Enter for newline
    dom.chatInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    // Auto-resize textarea
    dom.chatInput?.addEventListener('input', () => {
        dom.chatInput.style.height = 'auto';
        dom.chatInput.style.height = Math.min(dom.chatInput.scrollHeight, 200) + 'px';
    });
    
    // System prompt modal
    $('#btn-system-prompt')?.addEventListener('click', () => {
        dom.systemPromptInput.value = state.systemPrompt || '';
        dom.systemModal.style.display = 'flex';
    });
    
    // System modal close button
    dom.systemModal?.querySelector('.modal-close')?.addEventListener('click', () => {
        dom.systemModal.style.display = 'none';
    });
    
    // System modal overlay click to close
    dom.systemModal?.addEventListener('click', (e) => {
        if (e.target === dom.systemModal) dom.systemModal.style.display = 'none';
    });
    
    // Save system prompt
    $('#btn-save-prompt')?.addEventListener('click', saveSystemPrompt);
    
    // Reset system prompt
    $('#btn-reset-prompt')?.addEventListener('click', () => {
        dom.systemPromptInput.value = '';
        if (state.currentConvId) saveSystemPrompt();
    });
    
    // API Key settings modal
    $('#btn-api-settings')?.addEventListener('click', () => {
        openApiKeyModal();
    });
    
    dom.apikeyModal?.querySelector('.modal-close')?.addEventListener('click', () => {
        dom.apikeyModal.style.display = 'none';
    });
    
    dom.apikeyModal?.addEventListener('click', (e) => {
        if (e.target === dom.apikeyModal) dom.apikeyModal.style.display = 'none';
    });
    
    dom.btnSaveApiKey?.addEventListener('click', saveApiKey);
    dom.btnDeleteApiKey?.addEventListener('click', deleteApiKey);
    
    // Allow Enter to save in API key modal fields
    dom.apiEndpointInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); dom.apiKeyInput.focus(); }
    });
    dom.apiKeyInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); dom.apiModelInput.focus(); }
    });
    dom.apiModelInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); saveApiKey(); }
    });
}

// ====================== Keyboard Shortcuts ======================
function bindKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // Escape to close modal
        if (e.key === 'Escape') {
            if (dom.systemModal?.style.display === 'flex') {
                dom.systemModal.style.display = 'none';
            }
            if (dom.apikeyModal?.style.display === 'flex') {
                dom.apikeyModal.style.display = 'none';
            }
        }
        // Ctrl+N for new chat
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            newConversation();
        }
        // Ctrl+B toggle sidebar
        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            setSidebarCollapsed(!dom.sidebar?.classList.contains('collapsed'));
        }
    });
}

function bindErrorCapture() {
    window.addEventListener('error', (event) => {
        showErrorLog('Browser runtime error: ' + event.message, {
            provider_message: event.message,
            response_body: event.filename ? `${event.filename}:${event.lineno}:${event.colno}` : null,
            possible_causes: ['A JavaScript runtime error prevented the chat interface from completing the request.'],
        });
    });
    window.addEventListener('unhandledrejection', (event) => {
        const message = event.reason?.message || String(event.reason);
        showErrorLog('Unhandled browser request error: ' + message, {
            provider_message: message,
            possible_causes: ['A request or asynchronous UI operation failed before the error could be handled normally.'],
        });
    });
}

// ====================== Initialization ======================
async function init() {
    initTheme();
    setupAuth();
    bindEvents();
    bindKeyboardShortcuts();
    bindErrorCapture();
    
    if (APP_CONFIG.isLoggedIn) {
        await loadConversations();
    }
    
    // Focus chat input
    setTimeout(() => dom.chatInput?.focus(), 300);
}

// Start
document.addEventListener('DOMContentLoaded', init);

})();