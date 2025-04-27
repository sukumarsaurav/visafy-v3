<?php
// Set page variables
$page_title = "AI Assistant";
$page_header = "AI Assistant";

// Include header (handles session and authentication)
require_once 'includes/header.php';

// Get user_id from session (already verified in header.php)
$user_id = $_SESSION['user_id'];

// Get the entity_id for this user
$stmt = $conn->prepare("SELECT id FROM professional_entities WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$entity = $result->fetch_assoc();
$entity_id = $entity['id'] ?? null;

if (!$entity_id) {
    echo "<div class='alert alert-danger'>You don't have a professional profile. Please complete your profile first.</div>";
    require_once 'includes/footer.php';
    exit;
}

// Load environment variables
function loadEnv($path) {
    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Load the .env file
loadEnv(__DIR__ . '/../../config/.env');

// Get existing conversations
$sql = "SELECT c.*, m.content as last_message, m.created_at as last_message_time
        FROM ai_chat_conversations c 
        LEFT JOIN (
            SELECT conversation_id, content, created_at,
                   ROW_NUMBER() OVER (PARTITION BY conversation_id ORDER BY created_at DESC) as rn
            FROM ai_chat_messages
            WHERE role = 'user' AND deleted_at IS NULL
        ) m ON m.conversation_id = c.id AND m.rn = 1
        WHERE c.entity_id = ? AND c.deleted_at IS NULL 
        ORDER BY COALESCE(m.created_at, c.created_at) DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$conversations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get monthly usage
$month = date('Y-m');
$sql = "SELECT message_count FROM ai_chat_usage WHERE entity_id = ? AND month = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $entity_id, $month);
$stmt->execute();
$usage = $stmt->get_result()->fetch_assoc();
$messages_used = $usage ? $usage['message_count'] : 0;
$messages_remaining = 50 - $messages_used;
?>

<div class="chat-wrapper">
    <div class="chat-container">
        <!-- Sidebar -->
        <div class="chat-sidebar">
            <div class="sidebar-content">
                <button id="new-chat" class="new-chat-btn">
                    <i class="fas fa-plus"></i> Start New AI Chat
                </button>
                
                <div id="conversations-list" class="conversations-list">
                    <?php foreach ($conversations as $conv): ?>
                        <div class="conversation-item" data-id="<?php echo htmlspecialchars($conv['id']); ?>">
                            <div class="conversation-text">
                                <div class="conversation-title"><?php echo htmlspecialchars($conv['title']); ?></div>
                                <div class="conversation-preview"><?php echo htmlspecialchars($conv['last_message'] ?? ''); ?></div>
                            </div>
                            <button class="delete-chat" data-id="<?php echo htmlspecialchars($conv['id']); ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Main Chat Area -->
        <div class="chat-main">
            <div class="chat-header">
                <div class="header-left">
                    <button id="toggle-sidebar" class="toggle-sidebar-btn">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h4>New Conversation</h4>
                </div>
                <div class="messages-remaining">
                    <i class="fas fa-message"></i>
                    <span><?php echo $messages_remaining; ?> messages remaining</span>
                </div>
            </div>
            
            <!-- Chat Messages -->
            <div class="chat-messages" id="chat-messages">
                <div class="welcome-content">
                    <img src="../assets/images/ai-chat-bot.png" alt="AI Chat Bot" class="chat-bot-icon">
                    <h3>Welcome to AI Assistant</h3>
                    <p>I'm your visa and immigration consultant assistant. How can I help you today?</p>
                </div>
            </div>

            <!-- Chat Input -->
            <div class="chat-input-container">
                <form id="chat-form">
                    <input type="text" id="user-input" placeholder="Type your message here..." required>
                    <button type="submit" id="send-button">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let activeConversationId = null;
let isSidebarVisible = true;

// Toggle sidebar
document.getElementById('toggle-sidebar').addEventListener('click', function() {
    document.querySelector('.chat-container').classList.toggle('sidebar-hidden');
    isSidebarVisible = !isSidebarVisible;
});

function setActiveConversation(conversationId, title = null) {
    activeConversationId = conversationId;
    document.querySelectorAll('.conversation-item').forEach(item => {
        item.classList.remove('active');
    });
    if (conversationId) {
        document.querySelector(`.conversation-item[data-id="${conversationId}"]`)?.classList.add('active');
    }
    if (title) {
        document.querySelector('.chat-header h4').textContent = title;
    }
}

function updateUsageCounter() {
    fetch('ajax/chat_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_usage'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const remaining = 50 - data.usage;
            document.querySelector('.messages-remaining span').textContent = 
                `${remaining} messages remaining`;
        }
    });
}

function appendMessage(content, isUser = false, isError = false) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${isUser ? 'user-message' : 'ai-message'} ${isError ? 'error-message' : ''}`;
    
    // For AI messages, preserve formatting
    if (!isUser && !isError) {
        messageDiv.innerHTML = content;
    } else {
        messageDiv.textContent = content;
    }
    
    const chatMessages = document.getElementById('chat-messages');
    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function loadConversation(conversationId) {
    const chatMessages = document.getElementById('chat-messages');
    chatMessages.innerHTML = '<div class="loading-messages">Loading conversation...</div>';
    document.querySelector('.chat-header h4').textContent = 'Loading...';
    
    fetch('ajax/chat_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_conversation&conversation_id=${conversationId}`
    })
    .then(response => response.json())
    .then(data => {
        chatMessages.innerHTML = '';
        if (data.success) {
            setActiveConversation(conversationId, data.conversation.title);
            data.messages.forEach(msg => {
                appendMessage(msg.content, msg.role === 'user');
            });
        } else {
            appendMessage('Error loading conversation: ' + data.error, false, true);
        }
    })
    .catch(error => {
        chatMessages.innerHTML = '';
        appendMessage('Error loading conversation. Please try again.', false, true);
    });
}

function createNewChat() {
    fetch('ajax/chat_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=create_conversation'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            activeConversationId = data.conversation_id;
            
            // Create new conversation item
            const newConv = document.createElement('div');
            newConv.className = 'conversation-item active';
            newConv.dataset.id = data.conversation_id;
            newConv.innerHTML = `
                <div class="conversation-text">
                    <div class="conversation-title">New Chat</div>
                    <div class="conversation-preview"></div>
                </div>
                <button class="delete-chat" data-id="${data.conversation_id}">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            
            // Add to list
            const conversationsList = document.getElementById('conversations-list');
            conversationsList.insertBefore(newConv, conversationsList.firstChild);
            
            // Reset chat area
            document.getElementById('chat-messages').innerHTML = `
                <div class="welcome-content">
                    <img src="../assets/images/ai-chat-bot.png" alt="AI Chat Bot" class="chat-bot-icon">
                    <h3>Welcome to AI Assistant</h3>
                    <p>${data.welcome_message}</p>
                </div>
            `;
            document.querySelector('.chat-header h4').textContent = 'New Conversation';
            
            // Focus input
            document.getElementById('user-input').focus();
        } else {
            appendMessage('Error creating new chat: ' + data.error, false, true);
        }
    })
    .catch(error => {
        appendMessage('Error creating new chat. Please try again.', false, true);
    });
}

// New Chat button
document.getElementById('new-chat').addEventListener('click', function() {
    createNewChat();
});

// Select conversation
document.addEventListener('click', function(e) {
    if (e.target.closest('.conversation-item') && !e.target.closest('.delete-chat')) {
        const item = e.target.closest('.conversation-item');
        const conversationId = item.dataset.id;
        loadConversation(conversationId);
    }
});

// Delete conversation
document.addEventListener('click', function(e) {
    if (e.target.closest('.delete-chat')) {
        e.stopPropagation();
        const button = e.target.closest('.delete-chat');
        const conversationId = button.dataset.id;
        if (confirm('Are you sure you want to delete this conversation?')) {
            fetch('ajax/chat_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_conversation&conversation_id=${conversationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const item = button.closest('.conversation-item');
                    item.style.opacity = '0';
                    setTimeout(() => {
                        item.remove();
                        if (conversationId === activeConversationId) {
                            createNewChat();
                        }
                    }, 300);
                } else {
                    appendMessage('Error deleting conversation: ' + data.error, false, true);
                }
            })
            .catch(error => {
                appendMessage('Error deleting conversation. Please try again.', false, true);
            });
        }
    }
});

// Send message
document.getElementById('chat-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const input = document.getElementById('user-input');
    const sendButton = document.getElementById('send-button');
    const message = input.value.trim();
    
    if (!message) return;
    
    // Disable input and button
    input.disabled = true;
    sendButton.disabled = true;
    
    // If no active conversation, create one
    if (!activeConversationId) {
        fetch('ajax/chat_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=create_conversation'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                activeConversationId = data.conversation_id;
                sendMessage(message);
            } else {
                input.disabled = false;
                sendButton.disabled = false;
                appendMessage('Error creating conversation: ' + data.error, false, true);
            }
        })
        .catch(error => {
            input.disabled = false;
            sendButton.disabled = false;
            appendMessage('Error creating conversation. Please try again.', false, true);
        });
    } else {
        sendMessage(message);
    }
});

function sendMessage(message) {
    const input = document.getElementById('user-input');
    const sendButton = document.getElementById('send-button');
    input.value = '';
    
    // Clear welcome message if present
    const welcomeContent = document.querySelector('.welcome-content');
    if (welcomeContent) {
        welcomeContent.remove();
    }
    
    appendMessage(message, true);
    
    fetch('ajax/chat_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=send_message&conversation_id=${activeConversationId}&message=${encodeURIComponent(message)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            appendMessage(data.message);
            updateUsageCounter();
            
            // Update conversation preview
            const conversationItem = document.querySelector(`.conversation-item[data-id="${activeConversationId}"]`);
            if (conversationItem) {
                const preview = conversationItem.querySelector('.conversation-preview');
                if (preview) {
                    preview.textContent = message;
                }
                // Move conversation to top
                const parent = conversationItem.parentNode;
                parent.insertBefore(conversationItem, parent.firstChild);
            }
            
            // Update header
            document.querySelector('.chat-header h4').textContent = 
                message.substring(0, 30) + (message.length > 30 ? '...' : '');
        } else {
            appendMessage('Error: ' + (data.error || 'Failed to get response'), false, true);
        }
    })
    .catch(error => {
        appendMessage('Error: Failed to send message', false, true);
    })
    .finally(() => {
        input.disabled = false;
        sendButton.disabled = false;
        input.focus();
    });
}

// Create initial chat if no conversations exist
if (!document.querySelector('.conversation-item')) {
    createNewChat();
}
</script>

<?php
// Include footer
require_once 'includes/footer.php';
?>
