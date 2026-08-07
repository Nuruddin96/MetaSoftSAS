-- Personal Telegram AI assistant — conversation memory
CREATE TABLE IF NOT EXISTS assistant_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(30) NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_chat (chat_id, created_at)
);
