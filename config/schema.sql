-- phpChatGPT Database Schema
-- Import this file into an existing MySQL or MariaDB database.
-- Run only the CREATE TABLE statements below.

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) DEFAULT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login DATETIME DEFAULT NULL,
    INDEX idx_email (email),
    INDEX idx_username (username)
) ENGINE=InnoDB;

-- Conversations table
CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT 'New Chat',
    system_prompt TEXT DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_archived (user_id, is_archived),
    INDEX idx_updated (updated_at DESC)
) ENGINE=InnoDB;

-- Messages table
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    role ENUM('user', 'assistant', 'system') NOT NULL,
    content LONGTEXT NOT NULL,
    image_url VARCHAR(2000) DEFAULT NULL,
    token_count INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    INDEX idx_conversation (conversation_id, created_at)
) ENGINE=InnoDB;

-- User API Keys table (encrypted at rest)
-- Each user stores their own OpenAI-compatible endpoint + API key
CREATE TABLE IF NOT EXISTS user_api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    api_endpoint VARCHAR(500) NOT NULL DEFAULT 'https://api.openai.com/v1/chat/completions',
    api_key_encrypted TEXT NOT NULL,
    preferred_model VARCHAR(100) DEFAULT 'gpt-4o',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB;