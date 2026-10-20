/**
 * Search Assistant AI Widget JavaScript for Omeka S
 *
 * Handles interactive search assistant functionality including UI management, API communication,
 * conversation state management, and message rendering with Markdown support.
 *
 * Features:
 * - Widget open/close and minimize/maximize state management
 * - Real-time message send/receive with typing indicators
 * - Conversation history persistence using sessionStorage
 * - Markdown rendering with XSS sanitization
 * - Clickable collection item links from search results
 * - Quick suggestion buttons and response feedback (👍👎)
 * - Conversation export (.txt) and sharing (URL encoding)
 * - Auto-scroll and textarea auto-resize
 * - API error handling with retry logic
 */

(function() {
    'use strict';

    // ==================== Logging Utility ====================

    /**
     * Logging utility with [SearchAssistantAI] prefix for easy identification in browser console
     */
    const Logger = {
        PREFIX: '[SearchAssistantAI]',

        /**
         * Log an info message
         * @param {...any} args - Arguments to log
         */
        log: function(...args) {
            console.log(this.PREFIX, ...args);
        },

        /**
         * Log an error message
         * @param {...any} args - Arguments to log
         */
        error: function(...args) {
            console.error(this.PREFIX, ...args);
        },

        /**
         * Log a warning message
         * @param {...any} args - Arguments to log
         */
        warn: function(...args) {
            console.warn(this.PREFIX, ...args);
        },

        /**
         * Log a debug message (for verbose debugging)
         * @param {...any} args - Arguments to log
         */
        debug: function(...args) {
            console.debug(this.PREFIX, ...args);
        }
    };

    // ==================== Configuration ====================

    const CONFIG = {
        API_ENDPOINT: '/search-assistant/api/query',     // Backend API endpoint for chat queries
        TIMEOUT: 300000,                         // API request timeout (5 min: the RAG pipeline runs two LLM calls; large self-hosted models can take 1-2 min each)
        MAX_RETRIES: 1,                          // Maximum number of retry attempts on failure
        MAX_HISTORY_MESSAGES: 50,                // Maximum conversation messages to store
        RETRY_DELAY: 2000,                       // Delay before retry (2 seconds)
        AUTO_RESIZE_DEBOUNCE: 100,               // Debounce delay for textarea auto-resize (ms)
        SEND_BUTTON_DEBOUNCE: 300,               // Debounce delay for send button clicks (ms)
        MESSAGE_ANIMATION_DELAY: 150,            // Delay for message appearance animation (ms)
        STORAGE_KEY_HISTORY: 'search_assistant_history',  // sessionStorage key for conversation history
        STORAGE_KEY_FEEDBACK: 'search_assistant_feedback',// localStorage key for feedback data
        STORAGE_KEY_WIDGET_STATE: 'search_assistant_widget_open', // localStorage key for widget open state
        STREAMING_ENABLED: true                  // Request SSE streaming responses when the browser supports it
    };

    // Extract site prefix from current URL (e.g. "/s/fr" from "/s/fr/page/home")
    const SITE_PREFIX = (function() {
        const match = window.location.pathname.match(/^(\/s\/[^/]+)/);
        return match ? match[1] : '';
    })();

    // ==================== Global State ====================

    let isSending = false;                       // Flag to prevent duplicate sends
    let currentRetryCount = 0;                   // Current retry attempt counter
    let autoResizeDebounceTimer = null;          // Timer for debounced textarea resize
    let lastSendClickTime = 0;                   // Timestamp of last send button click (for debouncing)

    // DOM element cache (populated on initialization)
    let elements = {
        toggleButton: null,
        chatWindow: null,
        messagesContainer: null,
        inputTextarea: null,
        sendButton: null,
        closeButton: null,
        clearButton: null,
        exportButton: null,
        shareButton: null,
        typingIndicator: null  // Reference to typing indicator element
    };

    // ==================== Initialization ====================

    /**
     * Initialize the search assistant widget
     * Sets up event listeners, loads conversation history, and caches DOM elements
     */
    function initSearchAssistantWidget() {
        Logger.log('Initializing search assistant widget...');

        // Remove duplicate widget instances (theme + module injection can both render)
        var widgets = document.querySelectorAll('.search-assistant-widget');
        if (widgets.length > 1) {
            Logger.warn('Found', widgets.length, 'widget instances, removing duplicates');
            for (var i = 1; i < widgets.length; i++) {
                widgets[i].parentNode.removeChild(widgets[i]);
            }
        }

        // Cache DOM elements
        cacheElements();

        // Read welcome message from data attribute (set by server from DB setting)
        var widgetEl = document.querySelector('.search-assistant-widget');
        var welcomeMsg = (widgetEl && widgetEl.getAttribute('data-welcome-message')) || 'Bonjour ! Comment puis-je vous aider ?';

        // Apply the admin-configured primary color
        applyThemeColor(widgetEl);

        // Try to load conversation from URL first (takes precedence over sessionStorage)
        const loadedFromUrl = loadConversationFromUrl();

        // Load conversation history from sessionStorage only if not loaded from URL
        if (!loadedFromUrl) {
            const history = loadConversationHistory();
            // Show welcome message if no prior conversation exists
            if (!history || history.length === 0) {
                renderBotMessage(welcomeMsg);
            }
        }

        // Restore widget state
        restoreWidgetState();

        // Set up event listeners
        setupEventListeners();

        Logger.log('Search Assistant AI widget initialized successfully');
    }

    /**
     * Apply the admin-configured primary color (data-color attribute, set by
     * the server from the search_assistant_primary_color setting) by overriding the
     * CSS custom properties on the widget element. Hover/active/light shades
     * are derived from the base color.
     */
    function applyThemeColor(widgetEl) {
        var color = widgetEl && widgetEl.getAttribute('data-color');
        if (!color || !/^#[0-9A-Fa-f]{6}$/.test(color)) {
            return;
        }
        var r = parseInt(color.substr(1, 2), 16);
        var g = parseInt(color.substr(3, 2), 16);
        var b = parseInt(color.substr(5, 2), 16);
        var darken = function (factor) {
            return 'rgb(' + Math.round(r * factor) + ', ' + Math.round(g * factor) + ', ' + Math.round(b * factor) + ')';
        };
        widgetEl.style.setProperty('--search-assistant-primary', color);
        widgetEl.style.setProperty('--search-assistant-primary-hover', darken(0.82));
        widgetEl.style.setProperty('--search-assistant-primary-active', darken(0.66));
        widgetEl.style.setProperty('--search-assistant-primary-light', 'rgba(' + r + ', ' + g + ', ' + b + ', 0.08)');
        widgetEl.style.setProperty('--search-assistant-bg-header', color);
        widgetEl.style.setProperty('--search-assistant-message-user-bg', color);
    }

    /**
     * Cache DOM elements for performance
     * Logs warnings for missing optional elements
     */
    function cacheElements() {
        // Required elements
        elements.toggleButton = document.getElementById('search-assistant-toggle');
        elements.chatWindow = document.getElementById('search-assistant-window');
        elements.messagesContainer = document.getElementById('search-assistant-messages');
        elements.inputTextarea = document.getElementById('search-assistant-input-field');
        elements.sendButton = document.getElementById('search-assistant-send');
        elements.closeButton = document.getElementById('search-assistant-close');

        // Optional elements (may not exist yet)
        elements.clearButton = document.getElementById('search-assistant-clear-button');
        elements.exportButton = document.getElementById('search-assistant-export-button');
        elements.shareButton = document.getElementById('search-assistant-share-button');

        // Validate required elements
        const requiredElements = [
            { name: 'toggleButton', element: elements.toggleButton },
            { name: 'chatWindow', element: elements.chatWindow },
            { name: 'messagesContainer', element: elements.messagesContainer },
            { name: 'inputTextarea', element: elements.inputTextarea },
            { name: 'sendButton', element: elements.sendButton },
            { name: 'closeButton', element: elements.closeButton }
        ];

        let missingElements = [];
        requiredElements.forEach(function(item) {
            if (!item.element) {
                missingElements.push(item.name);
            }
        });

        if (missingElements.length > 0) {
            Logger.error('Missing required elements:', missingElements.join(', '));
        } else {
            Logger.log('All required DOM elements cached successfully');
        }
    }

    /**
     * Set up event listeners for widget interactions
     */
    function setupEventListeners() {
        // Widget toggle/close
        if (elements.toggleButton) {
            elements.toggleButton.addEventListener('click', toggleWidget);
        }

        if (elements.closeButton) {
            elements.closeButton.addEventListener('click', toggleWidget);
        }

        // Message sending
        if (elements.sendButton) {
            elements.sendButton.addEventListener('click', handleSendMessage);
        }

        if (elements.inputTextarea) {
            // Enter key handler (Shift+Enter for new line)
            elements.inputTextarea.addEventListener('keydown', handleTextareaKeydown);

            // Auto-resize handler
            elements.inputTextarea.addEventListener('input', autoResizeTextarea);
        }

        // Conversation management
        if (elements.clearButton) {
            elements.clearButton.addEventListener('click', clearConversationHistory);
        }

        if (elements.exportButton) {
            elements.exportButton.addEventListener('click', exportConversation);
        }

        if (elements.shareButton) {
            elements.shareButton.addEventListener('click', shareConversation);
        }

        // Event delegation for dynamically added elements (suggestions, feedback)
        if (elements.messagesContainer) {
            elements.messagesContainer.addEventListener('click', handleMessageContainerClick);
        }
    }

    /**
     * Restore widget open/closed state from localStorage
     */
    function restoreWidgetState() {
        try {
            const isOpen = localStorage.getItem(CONFIG.STORAGE_KEY_WIDGET_STATE);
            if (isOpen === 'true' && elements.chatWindow) {
                elements.chatWindow.hidden = false;
                if (elements.toggleButton) {
                    elements.toggleButton.setAttribute('aria-expanded', 'true');
                }
            }
        } catch (e) {
            Logger.warn('Error restoring widget state:', e);
        }
    }

    // ==================== UI Management & Interactions ====================

    /**
     * Toggle the search assistant widget open/closed state
     * Updates UI, ARIA attributes, and persists state to localStorage
     */
    function toggleWidget() {
        if (!elements.chatWindow || !elements.toggleButton) {
            Logger.error('Cannot toggle widget: required elements not found');
            return;
        }

        try {
            // Determine current state
            const isCurrentlyOpen = !elements.chatWindow.hidden;
            const newState = !isCurrentlyOpen;

            // Update chat window visibility
            elements.chatWindow.hidden = !newState;

            // Update toggle button ARIA attribute
            elements.toggleButton.setAttribute('aria-expanded', newState.toString());

            // Persist state to localStorage
            try {
                localStorage.setItem(CONFIG.STORAGE_KEY_WIDGET_STATE, newState.toString());
            } catch (storageError) {
                Logger.warn('Failed to save widget state to localStorage:', storageError);
            }

            // Focus input when opening for better UX
            if (newState && elements.inputTextarea) {
                // Small delay to ensure element is visible before focusing
                setTimeout(function() {
                    elements.inputTextarea.focus();
                }, 100);
            }

            Logger.log('Widget toggled:', newState ? 'opened' : 'closed');
        } catch (error) {
            Logger.error('Error toggling widget:', error);
        }
    }

    /**
     * Auto-scroll the message container to the bottom
     * Called after adding new messages to keep the latest message visible
     * Note: Smooth scrolling behavior is controlled via CSS scroll-behavior property
     */
    function autoScrollToBottom() {
        if (!elements.messagesContainer) {
            Logger.warn('Cannot auto-scroll: messagesContainer not found');
            return;
        }

        try {
            // Scroll to the bottom of the message container
            // scrollTop = scrollHeight positions the scroll at the very bottom
            elements.messagesContainer.scrollTop = elements.messagesContainer.scrollHeight;

            Logger.debug('Auto-scrolled to bottom of message container');
        } catch (error) {
            Logger.error('Error auto-scrolling to bottom:', error);
        }
    }

    /**
     * Auto-resize the textarea based on content
     * Uses debouncing to prevent performance issues during rapid typing
     * Respects CSS max-height property (120px default)
     */
    function autoResizeTextarea() {
        if (!elements.inputTextarea) {
            Logger.warn('Cannot auto-resize: inputTextarea not found');
            return;
        }

        try {
            // Clear existing debounce timer
            if (autoResizeDebounceTimer) {
                clearTimeout(autoResizeDebounceTimer);
            }

            // Set new debounce timer
            autoResizeDebounceTimer = setTimeout(function() {
                try {
                    // Reset height to 'auto' to get accurate scrollHeight
                    elements.inputTextarea.style.height = 'auto';

                    // Set height to match content (scrollHeight)
                    // CSS max-height property will cap this at 120px
                    elements.inputTextarea.style.height = elements.inputTextarea.scrollHeight + 'px';

                    Logger.debug('Textarea resized to', elements.inputTextarea.scrollHeight + 'px');
                } catch (resizeError) {
                    Logger.error('Error during textarea resize:', resizeError);
                }
            }, CONFIG.AUTO_RESIZE_DEBOUNCE);
        } catch (error) {
            Logger.error('Error setting up auto-resize debounce:', error);
        }
    }

    // ==================== Message Rendering ====================

    /**
     * Render a user message and append it to the messages container
     * Creates a message element with proper structure, timestamp, and ARIA attributes
     *
     * @param {string} messageText - The user's message text
     * @param {Date} [timestamp=new Date()] - Optional timestamp for the message
     * @returns {HTMLElement|null} The created message element, or null if error occurs
     */
    function renderUserMessage(messageText, timestamp) {
        if (!elements.messagesContainer) {
            Logger.error('Cannot render user message: messagesContainer not found');
            return null;
        }

        if (!messageText || typeof messageText !== 'string' || messageText.trim() === '') {
            Logger.warn('Cannot render user message: invalid or empty message text');
            return null;
        }

        try {
            // Use provided timestamp or create new one
            const messageTimestamp = timestamp instanceof Date ? timestamp : new Date();

            // Format timestamp (e.g., "10:45 AM")
            const formattedTime = formatTimestamp(messageTimestamp);

            // Create message wrapper with proper classes and attributes
            const messageWrapper = document.createElement('div');
            messageWrapper.className = 'search-assistant-message user';
            messageWrapper.setAttribute('role', 'article');
            messageWrapper.setAttribute('data-message-type', 'user');
            messageWrapper.setAttribute('data-timestamp', messageTimestamp.toISOString());

            // Create message bubble
            const messageBubble = document.createElement('div');
            messageBubble.className = 'search-assistant-message-bubble';
            messageBubble.textContent = messageText.trim();

            // Create timestamp element
            const timestampElement = document.createElement('div');
            timestampElement.className = 'search-assistant-message-timestamp';
            timestampElement.textContent = formattedTime;
            timestampElement.setAttribute('aria-label', 'Message sent at ' + formattedTime);

            // Assemble message structure
            messageWrapper.appendChild(messageBubble);
            messageWrapper.appendChild(timestampElement);

            // Append to messages container
            elements.messagesContainer.appendChild(messageWrapper);

            // Auto-scroll to show new message
            setTimeout(function() {
                autoScrollToBottom();
            }, CONFIG.MESSAGE_ANIMATION_DELAY);

            Logger.debug('User message rendered:', messageText.substring(0, 50) + '...');

            return messageWrapper;
        } catch (error) {
            Logger.error('Error rendering user message:', error);
            return null;
        }
    }

    /**
     * Render Markdown text into a message bubble with XSS sanitization
     * Used for complete bot messages and for in-place updates while a
     * response is streaming. Falls back to plain text when the marked or
     * DOMPurify libraries are unavailable or parsing fails.
     *
     * @param {HTMLElement} messageBubble - The bubble element to fill
     * @param {string} messageText - Raw (Markdown) message text
     */
    function renderMarkdownInto(messageBubble, messageText) {
        let htmlContent;
        try {
            // Check if marked library is available for Markdown parsing
            if (typeof marked !== 'undefined' && typeof marked.parse === 'function') {
                htmlContent = marked.parse(messageText.trim());
            } else {
                Logger.warn('marked library not available, rendering as plain text');
                htmlContent = messageText.trim();
            }

            // Check if DOMPurify is available for sanitization
            if (typeof DOMPurify !== 'undefined' && typeof DOMPurify.sanitize === 'function') {
                htmlContent = DOMPurify.sanitize(htmlContent, {
                    ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 'a', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
                    ALLOWED_ATTR: ['href', 'title', 'target', 'rel'],
                    ALLOW_DATA_ATTR: false
                });
            } else {
                Logger.warn('DOMPurify not available, using textContent for safety');
                // Fallback to safe text rendering if DOMPurify is not available
                messageBubble.textContent = messageText.trim();
                htmlContent = null;
            }

            // Set content (innerHTML only if sanitized, otherwise textContent is already set)
            if (htmlContent !== null) {
                messageBubble.innerHTML = htmlContent;
            }
        } catch (parseError) {
            Logger.error('Error parsing Markdown or sanitizing content:', parseError);
            // Fallback to plain text on error
            messageBubble.textContent = messageText.trim();
        }
    }

    /**
     * Render a bot message with Markdown parsing and XSS sanitization
     * Creates a message element with proper structure, parsed Markdown content, and ARIA attributes
     *
     * @param {string} messageText - The bot's message text (can contain Markdown)
     * @param {Date} [timestamp=new Date()] - Optional timestamp for the message
     * @returns {HTMLElement|null} The created message element, or null if error occurs
     */
    function renderBotMessage(messageText, timestamp) {
        if (!elements.messagesContainer) {
            Logger.error('Cannot render bot message: messagesContainer not found');
            return null;
        }

        if (!messageText || typeof messageText !== 'string' || messageText.trim() === '') {
            Logger.warn('Cannot render bot message: invalid or empty message text');
            return null;
        }

        try {
            // Use provided timestamp or create new one
            const messageTimestamp = timestamp instanceof Date ? timestamp : new Date();

            // Format timestamp (e.g., "10:45 AM")
            const formattedTime = formatTimestamp(messageTimestamp);

            // Generate unique message ID for feedback tracking
            const messageId = generateMessageId();

            // Create message wrapper with proper classes and attributes
            const messageWrapper = document.createElement('div');
            messageWrapper.className = 'search-assistant-message bot';
            messageWrapper.setAttribute('role', 'article');
            messageWrapper.setAttribute('data-message-type', 'bot');
            messageWrapper.setAttribute('data-timestamp', messageTimestamp.toISOString());
            messageWrapper.setAttribute('data-message-id', messageId);

            // Create message bubble
            const messageBubble = document.createElement('div');
            messageBubble.className = 'search-assistant-message-bubble';

            // Parse Markdown and sanitize for XSS protection
            renderMarkdownInto(messageBubble, messageText);

            // Create timestamp element
            const timestampElement = document.createElement('div');
            timestampElement.className = 'search-assistant-message-timestamp';
            timestampElement.textContent = formattedTime;
            timestampElement.setAttribute('aria-label', 'Message received at ' + formattedTime);

            // Assemble message structure
            messageWrapper.appendChild(messageBubble);
            messageWrapper.appendChild(timestampElement);

            // Add feedback buttons to bot messages
            renderFeedbackButtons(messageWrapper, messageId);

            // Apply any existing feedback state from localStorage
            applyFeedbackState(messageWrapper, messageId);

            // Append to messages container
            elements.messagesContainer.appendChild(messageWrapper);

            // Auto-scroll to show new message
            setTimeout(function() {
                autoScrollToBottom();
            }, CONFIG.MESSAGE_ANIMATION_DELAY);

            Logger.debug('Bot message rendered:', messageText.substring(0, 50) + '...');

            return messageWrapper;
        } catch (error) {
            Logger.error('Error rendering bot message:', error);
            return null;
        }
    }

    /**
     * Format a timestamp for display
     * Converts Date object to readable time format (e.g., "10:45 AM")
     *
     * @param {Date} date - The date to format
     * @returns {string} Formatted time string
     */
    function formatTimestamp(date) {
        if (!(date instanceof Date) || isNaN(date.getTime())) {
            Logger.warn('Invalid date provided to formatTimestamp, using current time');
            date = new Date();
        }

        try {
            // Format as "HH:MM AM/PM"
            let hours = date.getHours();
            const minutes = date.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';

            // Convert to 12-hour format
            hours = hours % 12;
            hours = hours ? hours : 12; // hour '0' should be '12'

            // Pad minutes with leading zero if needed
            const minutesStr = minutes < 10 ? '0' + minutes : minutes.toString();

            return hours + ':' + minutesStr + ' ' + ampm;
        } catch (error) {
            Logger.error('Error formatting timestamp:', error);
            return '';
        }
    }

    /**
     * Show an error message to the user in the chat interface
     * Displays user-friendly error messages as bot messages
     *
     * @param {string} message - Error message to display
     * @param {string} type - Message type (defaults to 'error')
     */
    function showErrorMessage(message, type) {
        if (!elements.messagesContainer) {
            Logger.error('Cannot show error message: messagesContainer not found');
            return;
        }

        if (!message || typeof message !== 'string') {
            Logger.warn('Invalid message provided to showErrorMessage');
            return;
        }

        try {
            type = type || 'error';

            // Create message wrapper with error styling
            const messageWrapper = document.createElement('div');
            messageWrapper.className = 'search-assistant-message bot error';
            messageWrapper.setAttribute('role', 'alert');
            messageWrapper.setAttribute('aria-live', 'assertive');
            messageWrapper.setAttribute('data-message-type', 'error');

            // Create message bubble
            const messageBubble = document.createElement('div');
            messageBubble.className = 'search-assistant-message-bubble';
            messageBubble.textContent = message;

            // Assemble message structure
            messageWrapper.appendChild(messageBubble);

            // Append to messages container
            elements.messagesContainer.appendChild(messageWrapper);

            // Auto-scroll to show error message
            setTimeout(function() {
                autoScrollToBottom();
            }, CONFIG.MESSAGE_ANIMATION_DELAY);

            Logger.debug('Error message displayed to user:', message);
        } catch (error) {
            Logger.error('Error displaying error message:', error);
        }
    }

    /**
     * Show the typing indicator to indicate bot is processing
     * Creates and displays an animated three-dot indicator
     * Used during API calls to provide visual feedback
     */
    function showTypingIndicator() {
        if (!elements.messagesContainer) {
            Logger.error('Cannot show typing indicator: messagesContainer not found');
            return;
        }

        // Don't create multiple indicators
        if (elements.typingIndicator) {
            Logger.debug('Typing indicator already visible');
            return;
        }

        try {
            // Create wrapper for typing indicator matching bot message alignment
            const indicatorWrapper = document.createElement('div');
            indicatorWrapper.className = 'search-assistant-message bot';
            indicatorWrapper.setAttribute('role', 'status');
            indicatorWrapper.setAttribute('aria-live', 'polite');
            indicatorWrapper.setAttribute('aria-label', 'Bot is typing');
            indicatorWrapper.setAttribute('data-typing-indicator', 'true');

            // Create typing indicator container
            const indicator = document.createElement('div');
            indicator.className = 'search-assistant-typing-indicator';

            // Create three animated dots
            for (let i = 0; i < 3; i++) {
                const dot = document.createElement('span');
                dot.className = 'search-assistant-typing-dot';
                dot.setAttribute('aria-hidden', 'true');
                indicator.appendChild(dot);
            }

            // Assemble structure
            indicatorWrapper.appendChild(indicator);

            // Store reference
            elements.typingIndicator = indicatorWrapper;

            // Append to messages container
            elements.messagesContainer.appendChild(indicatorWrapper);

            // Auto-scroll to show indicator
            setTimeout(function() {
                autoScrollToBottom();
            }, CONFIG.MESSAGE_ANIMATION_DELAY);

            Logger.debug('Typing indicator shown');
        } catch (error) {
            Logger.error('Error showing typing indicator:', error);
            elements.typingIndicator = null;
        }
    }

    /**
     * Hide the typing indicator
     * Removes the typing indicator from the DOM
     * Called when bot response is received or on error
     */
    function hideTypingIndicator() {
        if (!elements.typingIndicator) {
            Logger.debug('No typing indicator to hide');
            return;
        }

        try {
            // Remove from DOM
            if (elements.typingIndicator.parentNode) {
                elements.typingIndicator.parentNode.removeChild(elements.typingIndicator);
            }

            // Clear reference
            elements.typingIndicator = null;

            Logger.debug('Typing indicator hidden');
        } catch (error) {
            Logger.error('Error hiding typing indicator:', error);
            // Clear reference even on error to prevent stuck state
            elements.typingIndicator = null;
        }
    }

    // ==================== API Communication ====================

    /**
     * Handle API errors with user-friendly messages
     * Converts technical error messages to user-friendly display messages
     * Logs detailed error information to console for debugging
     *
     * @param {Error} error - The error object from the failed API request
     * @returns {string} User-friendly error message
     */
    function handleAPIError(error) {
        if (!error) {
            Logger.warn('handleAPIError called with no error object');
            return 'An unexpected error occurred. Please try again.';
        }

        // Log the full error details to console for debugging (never shown to user)
        Logger.error('API Error Details:', error);

        let userMessage = '';

        try {
            // Determine error type and create user-friendly message
            const errorMessage = error.message || '';

            if (errorMessage.includes('timeout') || errorMessage.includes('Request timeout')) {
                // Timeout error - request took too long
                userMessage = 'The request is taking longer than expected. Please try again.';
                Logger.error('API request timeout after', CONFIG.TIMEOUT, 'ms');
            } else if (errorMessage.includes('Network error') || errorMessage.includes('network') || error.name === 'NetworkError') {
                // Network error - connection failed
                userMessage = 'Unable to connect to the server. Please check your internet connection and try again.';
                Logger.error('Network error during API request');
            } else if (errorMessage.includes('aborted')) {
                // Request was aborted
                userMessage = 'The request was cancelled. Please try again.';
                Logger.error('API request was aborted');
            } else if (errorMessage.includes('JSON') || errorMessage.includes('parse')) {
                // JSON parsing error - invalid response format
                userMessage = 'Received an invalid response from the server. Please try again.';
                Logger.error('Failed to parse API response');
            } else if (errorMessage.match(/status\s+(\d+)/i)) {
                // HTTP error with status code
                const statusMatch = errorMessage.match(/status\s+(\d+)/i);
                const statusCode = statusMatch ? statusMatch[1] : '';

                if (statusCode === '404') {
                    userMessage = 'The search assistant service could not be found. Please contact support.';
                } else if (statusCode === '500' || statusCode === '503') {
                    userMessage = 'The server is experiencing issues. Please try again in a moment.';
                } else if (statusCode === '429') {
                    userMessage = 'Too many requests. Please wait a moment before trying again.';
                } else {
                    userMessage = 'An error occurred while processing your request. Please try again.';
                }
                Logger.error('API request failed with HTTP status:', statusCode);
            } else {
                // Generic error - unknown type
                userMessage = 'An unexpected error occurred. Please try again.';
                Logger.error('Unknown error type:', errorMessage);
            }

            // Display the user-friendly error message
            showErrorMessage(userMessage);

            return userMessage;
        } catch (handlingError) {
            // Error occurred while handling the error
            Logger.error('Error in handleAPIError:', handlingError);
            const fallbackMessage = 'An unexpected error occurred. Please try again.';
            showErrorMessage(fallbackMessage);
            return fallbackMessage;
        }
    }

    /**
     * Send a query to the search assistant API
     * Uses XMLHttpRequest with timeout and error handling
     *
     * @param {string} query - The user's query text
     * @param {Array} context - Array of previous message objects for conversation context
     * @returns {Promise<Object>} Promise that resolves with API response data or rejects with error
     */
    function sendQueryToAPI(query, context) {
        return new Promise(function(resolve, reject) {
            if (!query || typeof query !== 'string' || query.trim() === '') {
                Logger.error('sendQueryToAPI: Invalid query parameter');
                reject(new Error('Invalid query parameter'));
                return;
            }

            if (!Array.isArray(context)) {
                Logger.warn('sendQueryToAPI: context is not an array, using empty array');
                context = [];
            }

            Logger.debug('Sending query to API:', query.substring(0, 50) + '...');

            try {
                // Create XMLHttpRequest
                const xhr = new XMLHttpRequest();

                // Set timeout
                xhr.timeout = CONFIG.TIMEOUT;

                // Prepare request payload
                const payload = {
                    query: query.trim(),
                    context: context
                };

                // Open POST request to API endpoint
                xhr.open('POST', CONFIG.API_ENDPOINT, true);

                // Set request headers
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                // Handle state changes
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === XMLHttpRequest.DONE) {
                        if (xhr.status === 200) {
                            // Success - parse response
                            try {
                                const response = JSON.parse(xhr.responseText);
                                Logger.log('API response received successfully');
                                Logger.debug('Response data:', response);
                                resolve(response);
                            } catch (parseError) {
                                Logger.error('Failed to parse API response:', parseError);
                                reject(new Error('Invalid JSON response from API'));
                            }
                        } else if (xhr.status === 0) {
                            // Request was aborted or network error
                            Logger.error('API request failed: Network error or request aborted');
                            reject(new Error('Network error or request aborted'));
                        } else {
                            // HTTP error status
                            Logger.error('API request failed with status:', xhr.status);
                            let errorMessage = 'API request failed with status ' + xhr.status;

                            // Try to extract error message from response
                            try {
                                const errorResponse = JSON.parse(xhr.responseText);
                                if (errorResponse.error) {
                                    errorMessage = errorResponse.error;
                                }
                            } catch (e) {
                                // Could not parse error response, use default message
                            }

                            reject(new Error(errorMessage));
                        }
                    }
                };

                // Handle timeout
                xhr.ontimeout = function() {
                    Logger.error('API request timeout after', CONFIG.TIMEOUT, 'ms');
                    reject(new Error('Request timeout - please try again'));
                };

                // Handle network errors
                xhr.onerror = function() {
                    Logger.error('Network error during API request');
                    reject(new Error('Network error - please check your connection'));
                };

                // Send request with JSON payload
                xhr.send(JSON.stringify(payload));

                Logger.debug('API request sent to', CONFIG.API_ENDPOINT);
            } catch (error) {
                Logger.error('Error creating API request:', error);
                reject(error);
            }
        });
    }

    /**
     * Send a query to the search assistant API with retry logic and exponential backoff
     * Wraps sendQueryToAPI with automatic retry on failure
     *
     * @param {string} query - The user's query text
     * @param {Array} context - Array of previous message objects for conversation context
     * @returns {Promise<Object>} Promise that resolves with API response data or rejects with error
     */
    function sendQueryWithRetry(query, context) {
        return new Promise(function(resolve, reject) {
            // Reset retry counter at the start of a new request
            currentRetryCount = 0;

            /**
             * Attempt to send the query, with retry logic on failure
             * @param {number} attemptNumber - Current attempt number (0-indexed)
             */
            function attemptRequest(attemptNumber) {
                Logger.debug('API request attempt', attemptNumber + 1, 'of', CONFIG.MAX_RETRIES + 1);

                sendQueryToAPI(query, context)
                    .then(function(response) {
                        // Success - reset retry counter and resolve
                        currentRetryCount = 0;
                        Logger.log('API request succeeded on attempt', attemptNumber + 1);
                        resolve(response);
                    })
                    .catch(function(error) {
                        // Failure - check if we should retry
                        if (attemptNumber < CONFIG.MAX_RETRIES) {
                            // Calculate exponential backoff delay: 2^attemptNumber * RETRY_DELAY
                            const delay = Math.pow(2, attemptNumber) * CONFIG.RETRY_DELAY;
                            currentRetryCount = attemptNumber + 1;

                            Logger.warn('API request failed on attempt', attemptNumber + 1);
                            Logger.log('Retrying in', delay, 'ms... (attempt', currentRetryCount + 1, 'of', CONFIG.MAX_RETRIES + 1 + ')');

                            // Wait for backoff delay, then retry
                            setTimeout(function() {
                                attemptRequest(attemptNumber + 1);
                            }, delay);
                        } else {
                            // Max retries reached - reset counter, handle error, and reject
                            currentRetryCount = 0;
                            Logger.error('API request failed after', attemptNumber + 1, 'attempts');

                            // Display user-friendly error message
                            handleAPIError(error);

                            reject(error);
                        }
                    });
            }

            // Start the first attempt
            attemptRequest(0);
        });
    }

    // ==================== Streaming (SSE) API Communication ====================

    /**
     * Whether this browser can consume the streamed (SSE) API response
     *
     * @returns {boolean} True when fetch + ReadableStream + TextDecoder are available
     */
    function supportsStreaming() {
        return CONFIG.STREAMING_ENABLED &&
               typeof window.fetch === 'function' &&
               typeof window.ReadableStream !== 'undefined' &&
               typeof window.TextDecoder !== 'undefined';
    }

    /**
     * Send a query requesting a streamed (SSE) response
     * Emits intermediate events through onEvent as they arrive:
     *   - 'sources': {sources: [...]} once the search phase completes
     *   - 'delta':   {text: '...'} for each generated text fragment
     * Resolves with the final payload in the same shape as the JSON API
     * response. If the server answers with plain JSON (streaming disabled
     * or an error status), the promise resolves/rejects like the XHR path.
     *
     * @param {string} query - The user's query text
     * @param {Array} context - Previous messages for conversation context
     * @param {Function} onEvent - Callback invoked as onEvent(eventType, payload)
     * @returns {Promise<Object>} Promise resolving with the final response data
     */
    function streamQueryToAPI(query, context, onEvent) {
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeoutId = controller ? setTimeout(function () {
            Logger.error('Streaming request timeout after', CONFIG.TIMEOUT, 'ms');
            controller.abort();
        }, CONFIG.TIMEOUT) : null;

        Logger.debug('Sending streaming query to API:', query.substring(0, 50) + '...');

        return fetch(CONFIG.API_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream, application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                query: query.trim(),
                context: context,
                stream: true
            }),
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            const contentType = response.headers.get('Content-Type') || '';

            if (contentType.indexOf('text/event-stream') === -1) {
                // Plain JSON: an error response (rate limit, validation...) or
                // a server that ignored the stream flag
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error(data && data.error ? data.error : 'API request failed with status ' + response.status);
                    }
                    Logger.log('Server answered streaming request with plain JSON');
                    return data;
                });
            }

            return consumeEventStream(response, onEvent);
        }).finally(function () {
            if (timeoutId) {
                clearTimeout(timeoutId);
            }
        });
    }

    /**
     * Consume the SSE body of a streaming API response
     * Parses `event:`/`data:` frames, forwards intermediate events to
     * onEvent, resolves on the final `done` event and rejects on `error`.
     *
     * @param {Response} response - fetch Response with a text/event-stream body
     * @param {Function} onEvent - Callback invoked as onEvent(eventType, payload)
     * @returns {Promise<Object>} Promise resolving with the final response data
     */
    function consumeEventStream(response, onEvent) {
        return new Promise(function (resolve, reject) {
            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';
            let settled = false;

            function settle(fn, value) {
                if (settled) {
                    return;
                }
                settled = true;
                try {
                    reader.cancel();
                } catch (cancelError) {
                    // Stream already closed - nothing to cancel
                }
                fn(value);
            }

            function handleEvent(eventType, payload) {
                if (eventType === 'done') {
                    settle(resolve, {
                        success: true,
                        response: payload.response,
                        results: payload.results || [],
                        suggestions: payload.suggestions || [],
                        metadata: payload.metadata || {}
                    });
                } else if (eventType === 'error') {
                    settle(reject, new Error(payload.error || 'Streaming error'));
                } else {
                    try {
                        onEvent(eventType, payload);
                    } catch (callbackError) {
                        Logger.error('Error in stream event handler:', callbackError);
                    }
                }
            }

            function processBuffer() {
                let separatorIndex;
                // Complete SSE events are separated by a blank line
                while (!settled && (separatorIndex = buffer.indexOf('\n\n')) !== -1) {
                    const rawEvent = buffer.slice(0, separatorIndex);
                    buffer = buffer.slice(separatorIndex + 2);

                    let eventType = 'message';
                    let dataStr = '';
                    rawEvent.split('\n').forEach(function (line) {
                        if (line.indexOf('event:') === 0) {
                            eventType = line.slice(6).trim();
                        } else if (line.indexOf('data:') === 0) {
                            dataStr += (dataStr ? '\n' : '') + line.slice(5).trim();
                        }
                    });

                    if (!dataStr) {
                        continue;
                    }

                    let payload;
                    try {
                        payload = JSON.parse(dataStr);
                    } catch (parseError) {
                        Logger.warn('Skipping unparseable stream event:', dataStr.substring(0, 100));
                        continue;
                    }

                    handleEvent(eventType, payload);
                }
            }

            function pump() {
                reader.read().then(function (result) {
                    if (settled) {
                        return;
                    }
                    if (result.value) {
                        buffer += decoder.decode(result.value, { stream: true }).replace(/\r/g, '');
                        processBuffer();
                    }
                    if (settled) {
                        return;
                    }
                    if (result.done) {
                        settle(reject, new Error('Stream ended without completion'));
                        return;
                    }
                    pump();
                }).catch(function (error) {
                    settle(reject, error);
                });
            }

            pump();
        });
    }

    // ==================== Conversation Management ====================

    /**
     * Save conversation history to sessionStorage
     * Enforces maximum message limit by keeping only the most recent messages
     * Handles quota exceeded errors gracefully
     *
     * @param {Array} messages - Array of message objects to save
     * @returns {boolean} True if save successful, false otherwise
     */
    function saveConversationHistory(messages) {
        // Validate input
        if (!Array.isArray(messages)) {
            Logger.warn('saveConversationHistory: messages is not an array, skipping save');
            return false;
        }

        try {
            // Enforce message limit - keep only the most recent MAX_HISTORY_MESSAGES
            let messagesToSave = messages;
            if (messages.length > CONFIG.MAX_HISTORY_MESSAGES) {
                // Trim oldest messages, keeping only the most recent ones
                messagesToSave = messages.slice(-CONFIG.MAX_HISTORY_MESSAGES);
                Logger.log('Conversation history trimmed to', CONFIG.MAX_HISTORY_MESSAGES, 'messages');
            }

            // Convert to JSON string
            const jsonString = JSON.stringify(messagesToSave);

            // Save to sessionStorage
            try {
                sessionStorage.setItem(CONFIG.STORAGE_KEY_HISTORY, jsonString);
                Logger.debug('Conversation history saved to sessionStorage:', messagesToSave.length, 'messages');
                return true;
            } catch (storageError) {
                // Handle quota exceeded or other storage errors
                if (storageError.name === 'QuotaExceededError') {
                    Logger.error('sessionStorage quota exceeded. Unable to save conversation history.');
                    Logger.warn('Consider clearing old data or reducing conversation length.');
                } else {
                    Logger.error('Error saving to sessionStorage:', storageError);
                }
                return false;
            }
        } catch (error) {
            // Handle JSON stringify errors or other unexpected errors
            Logger.error('Error in saveConversationHistory:', error);
            return false;
        }
    }

    // ==================== Message Send/Receive Flow ====================

    /**
     * Handle sending a message to the search assistant
     * Validates input, sends to API, displays response, and saves to history
     * Implements debouncing to prevent rapid clicking and duplicate sends
     */
    function handleSendMessage() {
        // Get current timestamp
        const currentTime = Date.now();

        // Debounce rapid clicks - prevent multiple clicks within debounce window
        if (currentTime - lastSendClickTime < CONFIG.SEND_BUTTON_DEBOUNCE) {
            const timeSinceLastClick = currentTime - lastSendClickTime;
            Logger.log('Send button click debounced (' + timeSinceLastClick + 'ms since last click, minimum ' + CONFIG.SEND_BUTTON_DEBOUNCE + 'ms required)');
            return;
        }

        // Update last click timestamp
        lastSendClickTime = currentTime;

        // Prevent duplicate sends while API request is in progress
        if (isSending) {
            Logger.debug('Message send already in progress, skipping...');
            return;
        }

        if (!elements.inputTextarea || !elements.sendButton) {
            Logger.error('Cannot send message: required elements not found');
            return;
        }

        try {
            // Get and validate query text
            const query = elements.inputTextarea.value.trim();

            // Validate query is not empty or whitespace-only
            if (!query || query.length === 0) {
                Logger.debug('Cannot send empty message');
                // Optional: Show validation message to user
                // For now, just return silently - user will see send button is disabled
                return;
            }

            Logger.log('Sending message:', query.substring(0, 50) + '...');

            // Set sending flag and disable send button
            isSending = true;
            elements.sendButton.disabled = true;

            // Render user message immediately for better UX
            renderUserMessage(query);

            // Clear textarea and reset height
            elements.inputTextarea.value = '';
            elements.inputTextarea.style.height = 'auto'; // Reset to default height

            // Show typing indicator
            showTypingIndicator();

            // Build conversation context from history
            const context = buildConversationContext();

            // Streaming render state: the bot message element is created on
            // the first delta and its bubble updated in place as fragments arrive
            const streamState = { wrapper: null, bubble: null, text: '' };

            const handleStreamEvent = function (eventType, payload) {
                // 'sources' events are not rendered incrementally; links and
                // suggestions come from the final done payload
                if (eventType !== 'delta' || !payload || typeof payload.text !== 'string' || payload.text === '') {
                    return;
                }

                streamState.text += payload.text;

                if (!streamState.wrapper) {
                    hideTypingIndicator();
                    streamState.wrapper = renderBotMessage(streamState.text);
                    streamState.bubble = streamState.wrapper ?
                        streamState.wrapper.querySelector('.search-assistant-message-bubble') : null;
                } else if (streamState.bubble) {
                    renderMarkdownInto(streamState.bubble, streamState.text);
                    autoScrollToBottom();
                }
            };

            const finishSuccess = function (response, existingWrapper) {
                Logger.log('Received API response');

                // Hide typing indicator
                hideTypingIndicator();

                // Extract response text from API response
                const responseText = response.response || 'I received your message but have no response.';

                // Reuse the streamed message when present (re-rendering with the
                // final text), otherwise render the full bot response now
                let botMessageWrapper = existingWrapper || null;
                if (botMessageWrapper) {
                    const bubble = botMessageWrapper.querySelector('.search-assistant-message-bubble');
                    if (bubble) {
                        renderMarkdownInto(bubble, responseText);
                    }
                } else {
                    botMessageWrapper = renderBotMessage(responseText);
                }

                // Render suggestions if present in API response
                if (response.suggestions && Array.isArray(response.suggestions) && response.suggestions.length > 0) {
                    if (botMessageWrapper) {
                        renderSuggestions(response.suggestions, botMessageWrapper);
                    } else {
                        Logger.warn('Cannot render suggestions: bot message wrapper not available');
                    }
                }

                // Render collection item links if present in API response
                if (response.results && Array.isArray(response.results) && response.results.length > 0) {
                    if (botMessageWrapper) {
                        renderCollectionLinks(response.results, botMessageWrapper);
                    } else {
                        Logger.warn('Cannot render collection links: bot message wrapper not available');
                    }
                }

                // Save updated conversation to history
                // Add both user and bot messages to history
                const updatedHistory = context.concat([
                    {
                        role: 'user',
                        content: query,
                        timestamp: new Date().toISOString()
                    },
                    {
                        role: 'bot',
                        content: responseText,
                        timestamp: new Date().toISOString()
                    }
                ]);

                // Save to sessionStorage
                saveConversationHistory(updatedHistory);

                // Re-enable send button and reset flag
                isSending = false;
                elements.sendButton.disabled = false;

                // Focus textarea for next message
                if (elements.inputTextarea) {
                    setTimeout(function() {
                        elements.inputTextarea.focus();
                    }, 100);
                }

                Logger.log('Message send flow completed successfully');
            };

            const finishFailure = function (error) {
                Logger.error('Message send failed:', error);

                // Hide typing indicator
                hideTypingIndicator();

                // The user-facing error message has already been displayed
                // (handleAPIError) by the caller

                // Save user message to history even if API call failed
                // This prevents message loss
                const updatedHistory = context.concat([
                    {
                        role: 'user',
                        content: query,
                        timestamp: new Date().toISOString()
                    }
                ]);
                saveConversationHistory(updatedHistory);

                // Re-enable send button and reset flag
                isSending = false;
                elements.sendButton.disabled = false;

                // Focus textarea for retry
                if (elements.inputTextarea) {
                    setTimeout(function() {
                        elements.inputTextarea.focus();
                    }, 100);
                }
            };

            const startFallbackRequest = function () {
                // Classic JSON request with retry logic (handleAPIError shows
                // the user-facing message before the promise rejects)
                sendQueryWithRetry(query, context)
                    .then(function (response) {
                        finishSuccess(response, null);
                    })
                    .catch(finishFailure);
            };

            if (supportsStreaming()) {
                streamQueryToAPI(query, context, handleStreamEvent)
                    .then(function (response) {
                        finishSuccess(response, streamState.wrapper);
                    })
                    .catch(function (error) {
                        if (streamState.wrapper) {
                            // A partial answer is already rendered: keep it and
                            // surface the error below it
                            handleAPIError(error);
                            finishFailure(error);
                        } else {
                            // Nothing streamed yet: retry over the classic JSON path
                            Logger.warn('Streaming request failed before any output, falling back to standard request:', error);
                            startFallbackRequest();
                        }
                    });
            } else {
                startFallbackRequest();
            }
        } catch (error) {
            Logger.error('Error in handleSendMessage:', error);

            // Ensure cleanup happens even on unexpected errors
            hideTypingIndicator();
            isSending = false;
            if (elements.sendButton) {
                elements.sendButton.disabled = false;
            }

            // Show generic error message to user
            showErrorMessage('An unexpected error occurred while sending your message. Please try again.');
        }
    }

    /**
     * Build conversation context array from sessionStorage history
     * Extracts role and content from saved messages for API context
     *
     * @returns {Array} Array of context objects with role and content
     */
    function buildConversationContext() {
        try {
            // Load history from sessionStorage
            const storedData = sessionStorage.getItem(CONFIG.STORAGE_KEY_HISTORY);

            if (!storedData) {
                Logger.debug('No conversation history found, using empty context');
                return [];
            }

            // Parse history
            const messages = JSON.parse(storedData);

            if (!Array.isArray(messages)) {
                Logger.warn('Invalid history format, using empty context');
                return [];
            }

            // Extract role and content for API context
            // Filter to ensure only valid messages are included
            const context = messages
                .filter(function(message) {
                    return message &&
                           message.role &&
                           message.content &&
                           (message.role === 'user' || message.role === 'bot');
                })
                .map(function(message) {
                    return {
                        role: message.role,
                        content: message.content
                    };
                });

            Logger.debug('Built conversation context with', context.length, 'messages');

            return context;
        } catch (error) {
            Logger.error('Error building conversation context:', error);
            return [];
        }
    }

    /**
     * Handle keydown events in the textarea
     * Implements Enter to send (Shift+Enter for new line)
     *
     * @param {KeyboardEvent} event - The keyboard event
     */
    function handleTextareaKeydown(event) {
        if (!event) {
            Logger.warn('handleTextareaKeydown: no event object provided');
            return;
        }

        try {
            // Check if Enter key was pressed (key code 13 or key 'Enter')
            const isEnterKey = event.key === 'Enter' || event.keyCode === 13;

            if (isEnterKey) {
                // Check if Shift key is held down
                if (event.shiftKey) {
                    // Shift+Enter: Allow default behavior (new line)
                    Logger.debug('Shift+Enter detected: allowing new line');
                    return;
                } else {
                    // Enter without Shift: Send message
                    Logger.debug('Enter detected: sending message');

                    // Prevent default behavior (adding newline)
                    event.preventDefault();

                    // Trigger send message
                    handleSendMessage();
                }
            }
        } catch (error) {
            Logger.error('Error in handleTextareaKeydown:', error);
        }
    }

    /**
     * Load conversation history from sessionStorage
     * Validates data structure and renders messages to UI
     * Falls back to empty array on error or invalid data
     *
     * @returns {Array} Loaded messages array (empty if none or invalid)
     */
    function loadConversationHistory() {
        if (!elements.messagesContainer) {
            Logger.warn('Cannot load conversation history: messagesContainer not found');
            return [];
        }

        try {
            // Attempt to retrieve data from sessionStorage
            const storedData = sessionStorage.getItem(CONFIG.STORAGE_KEY_HISTORY);

            // No data stored - return empty array
            if (!storedData) {
                Logger.debug('No conversation history found in sessionStorage');
                return [];
            }

            // Parse JSON data
            let messages;
            try {
                messages = JSON.parse(storedData);
            } catch (parseError) {
                Logger.error('Failed to parse conversation history JSON:', parseError);
                Logger.warn('Clearing invalid conversation history from sessionStorage');
                sessionStorage.removeItem(CONFIG.STORAGE_KEY_HISTORY);
                return [];
            }

            // Validate data is an array
            if (!Array.isArray(messages)) {
                Logger.error('Conversation history is not an array:', typeof messages);
                Logger.warn('Clearing invalid conversation history from sessionStorage');
                sessionStorage.removeItem(CONFIG.STORAGE_KEY_HISTORY);
                return [];
            }

            // Validate and filter valid messages
            const validMessages = messages.filter(function(message, index) {
                // Check message structure
                if (!message || typeof message !== 'object') {
                    Logger.warn('Invalid message at index', index, '- not an object');
                    return false;
                }

                // Required fields: role and content
                if (!message.role || typeof message.role !== 'string') {
                    Logger.warn('Invalid message at index', index, '- missing or invalid role');
                    return false;
                }

                if (!message.content || typeof message.content !== 'string') {
                    Logger.warn('Invalid message at index', index, '- missing or invalid content');
                    return false;
                }

                // Validate role value
                if (message.role !== 'user' && message.role !== 'bot') {
                    Logger.warn('Invalid message at index', index, '- role must be "user" or "bot"');
                    return false;
                }

                return true;
            });

            // Log validation results
            if (validMessages.length < messages.length) {
                Logger.warn('Filtered out', messages.length - validMessages.length, 'invalid messages');
            }

            // Render each valid message
            validMessages.forEach(function(message) {
                try {
                    // Parse timestamp if provided
                    let timestamp = new Date();
                    if (message.timestamp) {
                        const parsedTimestamp = new Date(message.timestamp);
                        if (!isNaN(parsedTimestamp.getTime())) {
                            timestamp = parsedTimestamp;
                        }
                    }

                    // Render based on role
                    if (message.role === 'user') {
                        renderUserMessage(message.content, timestamp);
                    } else if (message.role === 'bot') {
                        renderBotMessage(message.content, timestamp);
                    }
                } catch (renderError) {
                    Logger.error('Error rendering message:', renderError);
                    // Continue rendering other messages despite error
                }
            });

            Logger.log('Loaded', validMessages.length, 'messages from conversation history');

            return validMessages;
        } catch (error) {
            // Catch-all error handler
            Logger.error('Error loading conversation history:', error);
            Logger.warn('Falling back to empty conversation history');
            return [];
        }
    }

    /**
     * Clear conversation history from DOM and sessionStorage
     * Resets the conversation to initial state with welcome message
     * Called when user clicks the clear/reset button
     */
    function clearConversationHistory() {
        if (!elements.messagesContainer) {
            Logger.error('Cannot clear conversation history: messagesContainer not found');
            return;
        }

        try {
            Logger.log('Clearing conversation history...');

            // Clear all messages from DOM
            // Remove all child elements from the messages container
            while (elements.messagesContainer.firstChild) {
                elements.messagesContainer.removeChild(elements.messagesContainer.firstChild);
            }

            Logger.debug('All messages removed from DOM');

            // Clear conversation history from sessionStorage
            try {
                sessionStorage.removeItem(CONFIG.STORAGE_KEY_HISTORY);
                Logger.debug('Conversation history cleared from sessionStorage');
            } catch (storageError) {
                Logger.warn('Error clearing sessionStorage:', storageError);
                // Continue even if storage clear fails
            }

            // Show welcome message to indicate fresh start (read from data attribute)
            var widgetEl = document.querySelector('.search-assistant-widget');
            var resetWelcome = (widgetEl && widgetEl.getAttribute('data-welcome-message')) || 'Bonjour ! Comment puis-je vous aider ?';
            renderBotMessage(resetWelcome);

            Logger.log('Conversation history cleared successfully');

            // Focus input for better UX
            if (elements.inputTextarea) {
                setTimeout(function() {
                    elements.inputTextarea.focus();
                }, 100);
            }
        } catch (error) {
            Logger.error('Error clearing conversation history:', error);
            showErrorMessage('Unable to clear conversation history. Please try again.');
        }
    }

    /**
     * Render suggestion buttons from API response
     * Creates clickable suggestion buttons below bot messages
     * When clicked, suggestions are sent as new queries
     *
     * @param {Array<string>} suggestions - Array of suggestion strings from API response
     * @param {HTMLElement} messageWrapper - The bot message wrapper to append suggestions to
     * @returns {HTMLElement|null} The created suggestions container, or null if error occurs
     */
    function renderSuggestions(suggestions, messageWrapper) {
        // Validate inputs
        if (!suggestions || !Array.isArray(suggestions) || suggestions.length === 0) {
            Logger.debug('No suggestions to render or invalid suggestions array');
            return null;
        }

        if (!messageWrapper || !(messageWrapper instanceof HTMLElement)) {
            Logger.error('Cannot render suggestions: invalid messageWrapper');
            return null;
        }

        try {
            Logger.log('Rendering', suggestions.length, 'suggestion(s)');

            // Create suggestions container
            const suggestionsContainer = document.createElement('div');
            suggestionsContainer.className = 'search-assistant-quick-replies';
            suggestionsContainer.setAttribute('role', 'group');
            suggestionsContainer.setAttribute('aria-label', 'Quick reply suggestions');

            // Create a button for each suggestion
            suggestions.forEach(function(suggestionText, index) {
                // Validate suggestion text
                if (!suggestionText || typeof suggestionText !== 'string' || suggestionText.trim() === '') {
                    Logger.warn('Skipping invalid suggestion at index', index);
                    return; // Skip this suggestion
                }

                // Create suggestion button
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'search-assistant-quick-reply-button';
                button.textContent = suggestionText.trim();
                button.setAttribute('aria-label', 'Send suggestion: ' + suggestionText.trim());
                button.setAttribute('data-suggestion', suggestionText.trim());

                // Add click handler to send suggestion as query
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    handleSuggestionClick(suggestionText.trim());
                });

                // Append button to container
                suggestionsContainer.appendChild(button);
            });

            // Only append container if we created at least one button
            if (suggestionsContainer.children.length > 0) {
                messageWrapper.appendChild(suggestionsContainer);
                Logger.log('Suggestions rendered successfully:', suggestionsContainer.children.length, 'button(s)');
                return suggestionsContainer;
            } else {
                Logger.warn('No valid suggestion buttons created');
                return null;
            }
        } catch (error) {
            Logger.error('Error rendering suggestions:', error);
            return null;
        }
    }

    /**
     * Handle suggestion button click
     * Populates the input textarea with suggestion text and sends the message
     *
     * @param {string} suggestionText - The suggestion text to send
     */
    function handleSuggestionClick(suggestionText) {
        if (!suggestionText || typeof suggestionText !== 'string' || suggestionText.trim() === '') {
            Logger.warn('Cannot handle suggestion click: invalid suggestion text');
            return;
        }

        if (!elements.inputTextarea) {
            Logger.error('Cannot handle suggestion click: input textarea not found');
            return;
        }

        try {
            Logger.log('Suggestion clicked:', suggestionText.substring(0, 50) + '...');

            // Populate textarea with suggestion text
            elements.inputTextarea.value = suggestionText.trim();

            // Trigger auto-resize to adjust textarea height
            autoResizeTextarea();

            // Send the message
            handleSendMessage();
        } catch (error) {
            Logger.error('Error handling suggestion click:', error);
        }
    }

    // ==================== Collection Item Links ====================

    /**
     * Render collection item links from API response
     * Creates clickable links to collection items below bot messages
     *
     * @param {Array<Object>} results - Array of result objects from API response
     *                                  Each result should have: {id, title, url}
     * @param {HTMLElement} messageWrapper - The bot message wrapper to append links to
     * @returns {HTMLElement|null} The created links container, or null if error occurs
     */
    function renderCollectionLinks(results, messageWrapper) {
        // Validate inputs
        if (!results || !Array.isArray(results) || results.length === 0) {
            Logger.debug('No collection links to render or invalid results array');
            return null;
        }

        if (!messageWrapper || !(messageWrapper instanceof HTMLElement)) {
            Logger.error('Cannot render collection links: invalid messageWrapper');
            return null;
        }

        try {
            Logger.log('Rendering', results.length, 'collection link(s)');

            // Create links container
            const linksContainer = document.createElement('div');
            linksContainer.className = 'search-assistant-collection-links';
            linksContainer.setAttribute('role', 'list');
            linksContainer.setAttribute('aria-label', 'Collection item results');

            // Create a link for each result
            results.forEach(function(result, index) {
                // Validate result object structure
                if (!result || typeof result !== 'object') {
                    Logger.warn('Skipping invalid result at index', index, '- not an object');
                    return; // Skip this result
                }

                // Validate required fields
                if (!result.title || typeof result.title !== 'string' || result.title.trim() === '') {
                    Logger.warn('Skipping result at index', index, '- missing or invalid title');
                    return; // Skip this result
                }

                if (!result.url || typeof result.url !== 'string' || result.url.trim() === '') {
                    Logger.warn('Skipping result at index', index, '- missing or invalid url');
                    return; // Skip this result
                }

                // Create link element with site prefix for proper routing
                const link = document.createElement('a');
                const rawUrl = result.url.trim();
                link.href = rawUrl.startsWith('/s/') ? rawUrl : SITE_PREFIX + rawUrl;
                link.className = 'search-assistant-collection-link';
                link.textContent = result.title.trim();
                link.setAttribute('role', 'listitem');
                link.setAttribute('aria-label', 'View collection item: ' + result.title.trim());
                link.setAttribute('target', '_blank'); // Open in new tab for better UX
                link.setAttribute('rel', 'noopener noreferrer'); // Security best practice

                // Optional: Add data attribute for result ID if provided
                if (result.id) {
                    link.setAttribute('data-result-id', result.id);
                }

                // Append link to container
                linksContainer.appendChild(link);
            });

            // Only append container if we created at least one link
            if (linksContainer.children.length > 0) {
                messageWrapper.appendChild(linksContainer);
                Logger.log('Collection links rendered successfully:', linksContainer.children.length, 'link(s)');
                return linksContainer;
            } else {
                Logger.warn('No valid collection links were created from results array');
                return null;
            }
        } catch (error) {
            Logger.error('Error rendering collection links:', error);
            return null;
        }
    }

    // ==================== Feedback System ====================

    /**
     * Generate a unique message ID for feedback tracking
     * Uses timestamp and random number for uniqueness
     *
     * @returns {string} Unique message ID
     */
    function generateMessageId() {
        try {
            // Create ID from timestamp + random number for uniqueness
            const timestamp = Date.now();
            const random = Math.floor(Math.random() * 10000);
            const messageId = 'msg_' + timestamp + '_' + random;

            Logger.debug('Generated message ID:', messageId);
            return messageId;
        } catch (error) {
            Logger.error('Error generating message ID:', error);
            // Fallback to timestamp only
            return 'msg_' + Date.now();
        }
    }

    /**
     * Render feedback buttons (👍👎) for a bot message
     * Creates thumbs up/down buttons and appends them to the message wrapper
     *
     * @param {HTMLElement} messageWrapper - The bot message wrapper element
     * @param {string} messageId - Unique ID for this message
     * @returns {HTMLElement|null} The created feedback container, or null if error occurs
     */
    function renderFeedbackButtons(messageWrapper, messageId) {
        // Validate inputs
        if (!messageWrapper || !(messageWrapper instanceof HTMLElement)) {
            Logger.error('Cannot render feedback buttons: invalid messageWrapper');
            return null;
        }

        if (!messageId || typeof messageId !== 'string') {
            Logger.error('Cannot render feedback buttons: invalid messageId');
            return null;
        }

        try {
            // Create feedback container
            const feedbackContainer = document.createElement('div');
            feedbackContainer.className = 'search-assistant-feedback-buttons';
            feedbackContainer.setAttribute('role', 'group');
            feedbackContainer.setAttribute('aria-label', 'Rate this response');
            feedbackContainer.setAttribute('data-message-id', messageId);

            // Create thumbs up button
            const thumbsUpButton = document.createElement('button');
            thumbsUpButton.type = 'button';
            thumbsUpButton.className = 'search-assistant-feedback-button search-assistant-feedback-thumbs-up';
            thumbsUpButton.textContent = '👍';
            thumbsUpButton.setAttribute('aria-label', 'Good response');
            thumbsUpButton.setAttribute('data-feedback-type', 'up');
            thumbsUpButton.setAttribute('data-message-id', messageId);
            thumbsUpButton.title = 'Good response';

            // Create thumbs down button
            const thumbsDownButton = document.createElement('button');
            thumbsDownButton.type = 'button';
            thumbsDownButton.className = 'search-assistant-feedback-button search-assistant-feedback-thumbs-down';
            thumbsDownButton.textContent = '👎';
            thumbsDownButton.setAttribute('aria-label', 'Poor response');
            thumbsDownButton.setAttribute('data-feedback-type', 'down');
            thumbsDownButton.setAttribute('data-message-id', messageId);
            thumbsDownButton.title = 'Poor response';

            // Append buttons to container
            feedbackContainer.appendChild(thumbsUpButton);
            feedbackContainer.appendChild(thumbsDownButton);

            // Append feedback container to message wrapper
            messageWrapper.appendChild(feedbackContainer);

            Logger.debug('Feedback buttons rendered for message:', messageId);
            return feedbackContainer;
        } catch (error) {
            Logger.error('Error rendering feedback buttons:', error);
            return null;
        }
    }

    /**
     * Handle feedback button click
     * Saves feedback to localStorage and updates visual state
     *
     * @param {string} messageId - The message ID
     * @param {string} feedbackType - 'up' or 'down'
     */
    function handleFeedbackClick(messageId, feedbackType) {
        // Validate inputs
        if (!messageId || typeof messageId !== 'string') {
            Logger.warn('Cannot handle feedback click: invalid messageId');
            return;
        }

        if (!feedbackType || (feedbackType !== 'up' && feedbackType !== 'down')) {
            Logger.warn('Cannot handle feedback click: invalid feedbackType');
            return;
        }

        try {
            Logger.log('Feedback received for message', messageId + ':', feedbackType);

            // Save feedback to localStorage
            const saved = saveFeedbackToLocalStorage(messageId, feedbackType);

            if (!saved) {
                Logger.warn('Failed to save feedback to localStorage');
                return;
            }

            // Find the message wrapper to update visual state
            const messageWrapper = document.querySelector('[data-message-id="' + messageId + '"]');

            if (!messageWrapper) {
                Logger.warn('Cannot update visual state: message wrapper not found for ID', messageId);
                return;
            }

            // Update visual state of feedback buttons
            applyFeedbackState(messageWrapper, messageId);

            Logger.log('Feedback processed successfully');
        } catch (error) {
            Logger.error('Error handling feedback click:', error);
        }
    }

    /**
     * Save feedback to localStorage
     * Stores feedback in a JSON object keyed by message ID
     *
     * @param {string} messageId - The message ID
     * @param {string} feedbackType - 'up' or 'down'
     * @returns {boolean} True if save successful, false otherwise
     */
    function saveFeedbackToLocalStorage(messageId, feedbackType) {
        // Validate inputs
        if (!messageId || typeof messageId !== 'string') {
            Logger.warn('Cannot save feedback: invalid messageId');
            return false;
        }

        if (!feedbackType || (feedbackType !== 'up' && feedbackType !== 'down')) {
            Logger.warn('Cannot save feedback: invalid feedbackType');
            return false;
        }

        try {
            // Load existing feedback data from localStorage
            let feedbackData = loadFeedbackFromLocalStorage();

            // Add or update feedback for this message
            feedbackData[messageId] = {
                type: feedbackType,
                timestamp: new Date().toISOString()
            };

            // Save to localStorage
            try {
                const jsonString = JSON.stringify(feedbackData);
                localStorage.setItem(CONFIG.STORAGE_KEY_FEEDBACK, jsonString);
                Logger.debug('Feedback saved to localStorage for message:', messageId);
                return true;
            } catch (storageError) {
                // Handle quota exceeded or other storage errors
                if (storageError.name === 'QuotaExceededError') {
                    Logger.error('localStorage quota exceeded. Unable to save feedback.');
                } else {
                    Logger.error('Error saving to localStorage:', storageError);
                }
                return false;
            }
        } catch (error) {
            Logger.error('Error in saveFeedbackToLocalStorage:', error);
            return false;
        }
    }

    /**
     * Load feedback data from localStorage
     * Returns an object with message IDs as keys and feedback objects as values
     *
     * @returns {Object} Feedback data object (empty object if none or error)
     */
    function loadFeedbackFromLocalStorage() {
        try {
            // Attempt to retrieve data from localStorage
            const storedData = localStorage.getItem(CONFIG.STORAGE_KEY_FEEDBACK);

            // No data stored - return empty object
            if (!storedData) {
                Logger.debug('No feedback data found in localStorage');
                return {};
            }

            // Parse JSON data
            let feedbackData;
            try {
                feedbackData = JSON.parse(storedData);
            } catch (parseError) {
                Logger.error('Failed to parse feedback data JSON:', parseError);
                Logger.warn('Clearing invalid feedback data from localStorage');
                localStorage.removeItem(CONFIG.STORAGE_KEY_FEEDBACK);
                return {};
            }

            // Validate data is an object
            if (!feedbackData || typeof feedbackData !== 'object' || Array.isArray(feedbackData)) {
                Logger.error('Feedback data is not an object:', typeof feedbackData);
                Logger.warn('Clearing invalid feedback data from localStorage');
                localStorage.removeItem(CONFIG.STORAGE_KEY_FEEDBACK);
                return {};
            }

            Logger.debug('Loaded feedback data from localStorage:', Object.keys(feedbackData).length, 'entries');
            return feedbackData;
        } catch (error) {
            Logger.error('Error loading feedback from localStorage:', error);
            return {};
        }
    }

    /**
     * Apply feedback visual state to a message
     * Highlights the selected feedback button based on saved feedback
     *
     * @param {HTMLElement} messageWrapper - The message wrapper element
     * @param {string} messageId - The message ID
     */
    function applyFeedbackState(messageWrapper, messageId) {
        // Validate inputs
        if (!messageWrapper || !(messageWrapper instanceof HTMLElement)) {
            Logger.warn('Cannot apply feedback state: invalid messageWrapper');
            return;
        }

        if (!messageId || typeof messageId !== 'string') {
            Logger.warn('Cannot apply feedback state: invalid messageId');
            return;
        }

        try {
            // Load feedback data
            const feedbackData = loadFeedbackFromLocalStorage();

            // Check if feedback exists for this message
            const feedback = feedbackData[messageId];

            if (!feedback || !feedback.type) {
                Logger.debug('No feedback found for message:', messageId);
                return;
            }

            // Find feedback buttons in the message wrapper
            const feedbackButtons = messageWrapper.querySelectorAll('.search-assistant-feedback-button');

            if (!feedbackButtons || feedbackButtons.length === 0) {
                Logger.warn('No feedback buttons found in message wrapper');
                return;
            }

            // Apply active state to the selected button
            feedbackButtons.forEach(function(button) {
                const buttonType = button.getAttribute('data-feedback-type');

                if (buttonType === feedback.type) {
                    // This button was clicked - add active class
                    button.classList.add('active');
                    button.setAttribute('aria-pressed', 'true');
                } else {
                    // Other button - remove active class if present
                    button.classList.remove('active');
                    button.setAttribute('aria-pressed', 'false');
                }
            });

            Logger.debug('Applied feedback state for message:', messageId, '- type:', feedback.type);
        } catch (error) {
            Logger.error('Error applying feedback state:', error);
        }
    }

    /**
     * Handle clicks on dynamically added elements in messages container
     * Delegates to appropriate handlers for suggestions and feedback buttons
     *
     * @param {MouseEvent} event - The click event
     */
    function handleMessageContainerClick(event) {
        if (!event || !event.target) {
            Logger.warn('handleMessageContainerClick: invalid event object');
            return;
        }

        try {
            const target = event.target;

            // Check if click was on a feedback button
            if (target.classList && target.classList.contains('search-assistant-feedback-button')) {
                event.preventDefault();

                const messageId = target.getAttribute('data-message-id');
                const feedbackType = target.getAttribute('data-feedback-type');

                if (messageId && feedbackType) {
                    handleFeedbackClick(messageId, feedbackType);
                } else {
                    Logger.warn('Feedback button missing required data attributes');
                }
                return;
            }

            // Check if click was on a suggestion button (to be implemented in future subtask)
            if (target.classList && target.classList.contains('search-assistant-quick-reply-button')) {
                // Already handled by individual button event listeners in renderSuggestions()
                Logger.debug('Suggestion button clicked (handled by individual listener)');
                return;
            }

            // Other clicks in messages container - log for debugging
            Logger.debug('Click in messages container on:', target.className || target.tagName);
        } catch (error) {
            Logger.error('Error in handleMessageContainerClick:', error);
        }
    }

    /**
     * Export conversation history as a downloadable .txt file
     * Formats messages with timestamps and role labels for readability
     * Creates a text file download with the conversation content
     */
    function exportConversation() {
        try {
            Logger.log('Exporting conversation...');

            // Load conversation history from sessionStorage
            const storedData = sessionStorage.getItem(CONFIG.STORAGE_KEY_HISTORY);

            if (!storedData) {
                Logger.warn('No conversation to export');
                showErrorMessage('No conversation history to export.');
                return;
            }

            // Parse conversation history
            let messages;
            try {
                messages = JSON.parse(storedData);
            } catch (parseError) {
                Logger.error('Failed to parse conversation history for export:', parseError);
                showErrorMessage('Unable to export conversation. Invalid data format.');
                return;
            }

            // Validate messages array
            if (!Array.isArray(messages) || messages.length === 0) {
                Logger.warn('No messages to export');
                showErrorMessage('No conversation history to export.');
                return;
            }

            // Format conversation as plain text
            let textContent = 'Search Assistant AI Conversation Export\n';
            textContent += '='.repeat(50) + '\n';
            textContent += 'Exported: ' + new Date().toLocaleString() + '\n';
            textContent += '='.repeat(50) + '\n\n';

            // Add each message to the text content
            messages.forEach(function(message, index) {
                // Validate message structure
                if (!message || !message.role || !message.content) {
                    Logger.warn('Skipping invalid message at index', index);
                    return; // Skip this message
                }

                // Format timestamp
                let timestamp = '';
                if (message.timestamp) {
                    const date = new Date(message.timestamp);
                    if (!isNaN(date.getTime())) {
                        timestamp = ' (' + date.toLocaleString() + ')';
                    }
                }

                // Format role label
                const roleLabel = message.role === 'user' ? 'You' : 'Bot';

                // Add message to text content
                textContent += roleLabel + timestamp + ':\n';
                textContent += message.content.trim() + '\n\n';
            });

            textContent += '='.repeat(50) + '\n';
            textContent += 'End of conversation\n';

            // Create Blob with text content
            const blob = new Blob([textContent], { type: 'text/plain;charset=utf-8' });

            // Generate filename with timestamp
            const now = new Date();
            const dateStr = now.getFullYear() +
                           '-' + String(now.getMonth() + 1).padStart(2, '0') +
                           '-' + String(now.getDate()).padStart(2, '0');
            const timeStr = String(now.getHours()).padStart(2, '0') +
                           String(now.getMinutes()).padStart(2, '0');
            const filename = 'search-assistant-conversation-' + dateStr + '-' + timeStr + '.txt';

            // Create download link
            const downloadLink = document.createElement('a');
            downloadLink.href = URL.createObjectURL(blob);
            downloadLink.download = filename;
            downloadLink.style.display = 'none';

            // Append to body, click, and remove
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);

            // Clean up blob URL
            setTimeout(function() {
                URL.revokeObjectURL(downloadLink.href);
            }, 100);

            Logger.log('Conversation exported successfully as', filename);
        } catch (error) {
            Logger.error('Error exporting conversation:', error);
            showErrorMessage('Unable to export conversation. Please try again.');
        }
    }

    /**
     * Share conversation by encoding it in a URL
     * Creates a shareable URL with the conversation encoded as a query parameter
     * Copies the URL to the clipboard for easy sharing
     */
    function shareConversation() {
        try {
            Logger.log('Sharing conversation...');

            // Load conversation history from sessionStorage
            const storedData = sessionStorage.getItem(CONFIG.STORAGE_KEY_HISTORY);

            if (!storedData) {
                Logger.warn('No conversation to share');
                showErrorMessage('No conversation history to share.');
                return;
            }

            // Parse conversation history
            let messages;
            try {
                messages = JSON.parse(storedData);
            } catch (parseError) {
                Logger.error('Failed to parse conversation history for sharing:', parseError);
                showErrorMessage('Unable to share conversation. Invalid data format.');
                return;
            }

            // Validate messages array
            if (!Array.isArray(messages) || messages.length === 0) {
                Logger.warn('No messages to share');
                showErrorMessage('No conversation history to share.');
                return;
            }

            // Encode conversation data for URL
            // Use base64 encoding for URL safety
            let encodedData;
            try {
                // Convert to base64 (URL-safe)
                encodedData = btoa(encodeURIComponent(storedData));
            } catch (encodingError) {
                Logger.error('Failed to encode conversation data:', encodingError);
                showErrorMessage('Unable to encode conversation for sharing.');
                return;
            }

            // Create shareable URL with encoded conversation
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('conversation', encodedData);
            const shareableUrl = currentUrl.toString();

            // Copy URL to clipboard
            copyToClipboard(shareableUrl)
                .then(function() {
                    Logger.log('Conversation URL copied to clipboard');
                    // Show success feedback as a bot message
                    renderBotMessage('✓ Conversation link copied to clipboard! You can now share it with others.');
                })
                .catch(function(error) {
                    Logger.error('Failed to copy to clipboard:', error);
                    // Fallback: Show URL in a message for manual copying
                    showShareUrlFallback(shareableUrl);
                });
        } catch (error) {
            Logger.error('Error sharing conversation:', error);
            showErrorMessage('Unable to share conversation. Please try again.');
        }
    }

    /**
     * Copy text to clipboard using modern Clipboard API with fallback
     * Returns a promise that resolves when copy succeeds
     *
     * @param {string} text - Text to copy to clipboard
     * @returns {Promise} Promise that resolves on success, rejects on failure
     */
    function copyToClipboard(text) {
        return new Promise(function(resolve, reject) {
            if (!text || typeof text !== 'string') {
                reject(new Error('Invalid text to copy'));
                return;
            }

            try {
                // Modern Clipboard API (preferred)
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text)
                        .then(function() {
                            Logger.debug('Copied to clipboard using Clipboard API');
                            resolve();
                        })
                        .catch(function(error) {
                            Logger.warn('Clipboard API failed, trying fallback:', error);
                            // Try fallback method
                            fallbackCopyToClipboard(text)
                                .then(resolve)
                                .catch(reject);
                        });
                } else {
                    // Fallback for older browsers
                    Logger.debug('Clipboard API not available, using fallback');
                    fallbackCopyToClipboard(text)
                        .then(resolve)
                        .catch(reject);
                }
            } catch (error) {
                Logger.error('Error in copyToClipboard:', error);
                reject(error);
            }
        });
    }

    /**
     * Fallback method to copy text to clipboard using temporary textarea
     * Used when modern Clipboard API is not available
     *
     * @param {string} text - Text to copy to clipboard
     * @returns {Promise} Promise that resolves on success, rejects on failure
     */
    function fallbackCopyToClipboard(text) {
        return new Promise(function(resolve, reject) {
            try {
                // Create temporary textarea
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.top = '0';
                textarea.style.left = '0';
                textarea.style.opacity = '0';
                textarea.setAttribute('readonly', '');

                // Append to body
                document.body.appendChild(textarea);

                // Select and copy
                textarea.select();
                textarea.setSelectionRange(0, text.length);

                const successful = document.execCommand('copy');

                // Remove temporary element
                document.body.removeChild(textarea);

                if (successful) {
                    Logger.debug('Copied to clipboard using fallback method');
                    resolve();
                } else {
                    Logger.error('execCommand copy failed');
                    reject(new Error('Copy command failed'));
                }
            } catch (error) {
                Logger.error('Error in fallbackCopyToClipboard:', error);
                reject(error);
            }
        });
    }

    /**
     * Show share URL as a fallback when clipboard copy fails
     * Displays the URL in a bot message for manual copying
     *
     * @param {string} url - The shareable URL
     */
    function showShareUrlFallback(url) {
        if (!url || typeof url !== 'string') {
            Logger.error('Invalid URL for fallback share');
            showErrorMessage('Unable to generate shareable link.');
            return;
        }

        try {
            // Create a message with the URL for manual copying
            const message = 'Unable to copy automatically. Please copy this link manually:\n\n`' + url + '`';
            renderBotMessage(message);
            Logger.log('Share URL displayed as fallback');
        } catch (error) {
            Logger.error('Error showing share URL fallback:', error);
            showErrorMessage('Unable to display shareable link.');
        }
    }

    /**
     * Load conversation from URL parameter if present
     * Checks for 'conversation' query parameter and restores the conversation
     * Called during widget initialization
     */
    function loadConversationFromUrl() {
        try {
            // Check for conversation parameter in URL
            const urlParams = new URLSearchParams(window.location.search);
            const encodedConversation = urlParams.get('conversation');

            if (!encodedConversation) {
                Logger.debug('No conversation parameter in URL');
                return false;
            }

            Logger.log('Loading conversation from URL...');

            // Decode conversation data
            let conversationData;
            try {
                conversationData = decodeURIComponent(atob(encodedConversation));
            } catch (decodingError) {
                Logger.error('Failed to decode conversation from URL:', decodingError);
                showErrorMessage('Unable to load shared conversation. Invalid link format.');
                return false;
            }

            // Parse conversation data
            let messages;
            try {
                messages = JSON.parse(conversationData);
            } catch (parseError) {
                Logger.error('Failed to parse conversation data from URL:', parseError);
                showErrorMessage('Unable to load shared conversation. Invalid data format.');
                return false;
            }

            // Validate messages array
            if (!Array.isArray(messages) || messages.length === 0) {
                Logger.error('Invalid conversation data from URL');
                showErrorMessage('Unable to load shared conversation. No messages found.');
                return false;
            }

            // Save to sessionStorage (this will replace any existing conversation)
            const saved = saveConversationHistory(messages);

            if (!saved) {
                Logger.error('Failed to save conversation from URL to sessionStorage');
                showErrorMessage('Unable to load shared conversation. Storage error.');
                return false;
            }

            // Render messages to UI (they will be loaded by loadConversationHistory)
            // Clear existing messages first if any
            if (elements.messagesContainer) {
                while (elements.messagesContainer.firstChild) {
                    elements.messagesContainer.removeChild(elements.messagesContainer.firstChild);
                }
            }

            // Render each message
            messages.forEach(function(message) {
                try {
                    // Parse timestamp if provided
                    let timestamp = new Date();
                    if (message.timestamp) {
                        const parsedTimestamp = new Date(message.timestamp);
                        if (!isNaN(parsedTimestamp.getTime())) {
                            timestamp = parsedTimestamp;
                        }
                    }

                    // Render based on role
                    if (message.role === 'user') {
                        renderUserMessage(message.content, timestamp);
                    } else if (message.role === 'bot') {
                        renderBotMessage(message.content, timestamp);
                    }
                } catch (renderError) {
                    Logger.error('Error rendering message from URL:', renderError);
                }
            });

            Logger.log('Loaded', messages.length, 'messages from URL');

            // Remove conversation parameter from URL (clean up browser history)
            // This prevents the conversation from being reloaded on refresh
            try {
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('conversation');
                window.history.replaceState({}, document.title, cleanUrl.toString());
                Logger.debug('Cleaned conversation parameter from URL');
            } catch (urlError) {
                Logger.warn('Failed to clean URL parameter:', urlError);
                // Not critical, continue anyway
            }

            // Show notification that conversation was loaded
            setTimeout(function() {
                renderBotMessage('ℹ️ Shared conversation loaded successfully.');
            }, 500);

            return true;
        } catch (error) {
            Logger.error('Error loading conversation from URL:', error);
            showErrorMessage('Unable to load shared conversation. Please try again.');
            return false;
        }
    }

    // ==================== Document Ready ====================

    /**
     * Initialize widget when DOM is fully ready
     * Always wait for DOMContentLoaded to ensure dynamically injected widget HTML
     * (from Module::injectWidget) is present in the DOM before initialization
     */
    if (document.readyState === 'complete') {
        // Page fully loaded (including async resources), safe to init immediately
        initSearchAssistantWidget();
    } else {
        // Wait for DOMContentLoaded (covers both 'loading' and 'interactive' states)
        // This ensures the widget HTML injection script runs first
        document.addEventListener('DOMContentLoaded', initSearchAssistantWidget);
    }

})();
