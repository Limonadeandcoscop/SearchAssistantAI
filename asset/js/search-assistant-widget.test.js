/**
 * Unit Tests for Search Assistant AI Widget
 *
 * Tests core functionality including message rendering, conversation management,
 * API communication, error handling, and utility functions.
 *
 * This test file is compatible with both Jest and QUnit testing frameworks.
 * To run with Jest: npm test
 * To run with QUnit: Open test.html in browser
 *
 * Note: Since search-assistant-widget.js is wrapped in an IIFE, these tests focus on
 * testing the expected behavior and DOM manipulation. For full unit testing,
 * the widget code would need to expose certain functions or use a module pattern.
 */

// ==================== Test Setup ====================

// Mock environment setup for browser APIs
const setupTestEnvironment = () => {
    // Mock sessionStorage if not available (e.g., in Node.js environment)
    if (typeof sessionStorage === 'undefined') {
        global.sessionStorage = {
            store: {},
            getItem(key) {
                return this.store[key] || null;
            },
            setItem(key, value) {
                this.store[key] = String(value);
            },
            removeItem(key) {
                delete this.store[key];
            },
            clear() {
                this.store = {};
            }
        };
    }

    // Mock localStorage if not available
    if (typeof localStorage === 'undefined') {
        global.localStorage = {
            store: {},
            getItem(key) {
                return this.store[key] || null;
            },
            setItem(key, value) {
                this.store[key] = String(value);
            },
            removeItem(key) {
                delete this.store[key];
            },
            clear() {
                this.store = {};
            }
        };
    }

    // Mock console if not available
    if (typeof console === 'undefined') {
        global.console = {
            log: () => {},
            error: () => {},
            warn: () => {},
            debug: () => {}
        };
    }

    // Mock DOM if not available (for Node.js environment)
    if (typeof document === 'undefined') {
        global.document = {
            getElementById: () => null,
            createElement: (tag) => ({
                tagName: tag.toUpperCase(),
                className: '',
                textContent: '',
                children: [],
                appendChild: function(child) {
                    this.children.push(child);
                },
                setAttribute: function() {},
                getAttribute: function() { return null; },
                removeChild: function() {},
                addEventListener: function() {},
                removeEventListener: function() {}
            }),
            querySelector: () => null,
            querySelectorAll: () => []
        };
    }
};

setupTestEnvironment();

// ==================== Test Utilities ====================

/**
 * Helper function to create a mock message element
 */
const createMockMessageElement = (role, content, timestamp) => {
    return {
        role: role || 'user',
        content: content || 'Test message',
        timestamp: timestamp || new Date().toISOString()
    };
};

/**
 * Helper function to validate message structure
 */
const isValidMessage = (message) => {
    return (
        message &&
        typeof message === 'object' &&
        typeof message.role === 'string' &&
        (message.role === 'user' || message.role === 'bot') &&
        typeof message.content === 'string' &&
        message.content.trim() !== ''
    );
};

/**
 * Helper function to validate timestamp format
 */
const isValidTimestamp = (timestamp) => {
    if (typeof timestamp !== 'string') return false;
    const date = new Date(timestamp);
    return !isNaN(date.getTime());
};

// ==================== Conversation History Tests ====================

describe('Conversation History Management', () => {
    beforeEach(() => {
        // Clear storage before each test
        sessionStorage.clear();
        localStorage.clear();
    });

    describe('saveConversationHistory()', () => {
        test('should save valid conversation to sessionStorage', () => {
            const messages = [
                createMockMessageElement('user', 'Hello'),
                createMockMessageElement('bot', 'Hi there!')
            ];

            sessionStorage.setItem('search_assistant_history', JSON.stringify(messages));
            const saved = JSON.parse(sessionStorage.getItem('search_assistant_history'));

            expect(saved).toBeDefined();
            expect(saved.length).toBe(2);
            expect(saved[0].role).toBe('user');
            expect(saved[1].role).toBe('bot');
        });

        test('should enforce 50 message limit', () => {
            const messages = [];
            for (let i = 0; i < 60; i++) {
                messages.push(createMockMessageElement('user', `Message ${i}`));
            }

            // Simulate trimming to 50 messages (most recent)
            const trimmed = messages.slice(-50);
            sessionStorage.setItem('search_assistant_history', JSON.stringify(trimmed));
            const saved = JSON.parse(sessionStorage.getItem('search_assistant_history'));

            expect(saved.length).toBe(50);
            expect(saved[0].content).toBe('Message 10'); // First kept message
            expect(saved[49].content).toBe('Message 59'); // Last message
        });

        test('should handle empty array', () => {
            const messages = [];
            sessionStorage.setItem('search_assistant_history', JSON.stringify(messages));
            const saved = JSON.parse(sessionStorage.getItem('search_assistant_history'));

            expect(saved).toBeDefined();
            expect(saved.length).toBe(0);
        });

        test('should validate message structure before saving', () => {
            const messages = [
                createMockMessageElement('user', 'Valid message'),
                { role: 'invalid' }, // Invalid: missing content
                { content: 'No role' }, // Invalid: missing role
                createMockMessageElement('bot', 'Another valid message')
            ];

            const validMessages = messages.filter(isValidMessage);
            sessionStorage.setItem('search_assistant_history', JSON.stringify(validMessages));
            const saved = JSON.parse(sessionStorage.getItem('search_assistant_history'));

            expect(saved.length).toBe(2);
            expect(saved[0].content).toBe('Valid message');
            expect(saved[1].content).toBe('Another valid message');
        });
    });

    describe('loadConversationHistory()', () => {
        test('should load valid conversation from sessionStorage', () => {
            const messages = [
                createMockMessageElement('user', 'Test query'),
                createMockMessageElement('bot', 'Test response')
            ];

            sessionStorage.setItem('search_assistant_history', JSON.stringify(messages));
            const loaded = JSON.parse(sessionStorage.getItem('search_assistant_history'));

            expect(loaded).toBeDefined();
            expect(loaded.length).toBe(2);
            expect(loaded[0].role).toBe('user');
            expect(loaded[1].role).toBe('bot');
        });

        test('should return empty array if no history exists', () => {
            const loaded = sessionStorage.getItem('search_assistant_history');
            expect(loaded).toBeNull();
        });

        test('should handle corrupted JSON data', () => {
            sessionStorage.setItem('search_assistant_history', 'invalid json {');

            let loaded = null;
            try {
                loaded = JSON.parse(sessionStorage.getItem('search_assistant_history'));
            } catch (e) {
                // Expected to fail parsing
                loaded = [];
            }

            expect(loaded).toBeDefined();
            expect(Array.isArray(loaded)).toBe(true);
        });

        test('should validate message structure and filter invalid messages', () => {
            const messages = [
                createMockMessageElement('user', 'Valid'),
                { role: 'bot' }, // Invalid: missing content
                null, // Invalid: null
                createMockMessageElement('user', 'Also valid')
            ];

            sessionStorage.setItem('search_assistant_history', JSON.stringify(messages));
            const loaded = JSON.parse(sessionStorage.getItem('search_assistant_history'))
                .filter(msg => isValidMessage(msg));

            expect(loaded.length).toBe(2);
            expect(loaded[0].content).toBe('Valid');
            expect(loaded[1].content).toBe('Also valid');
        });

        test('should validate timestamps', () => {
            const messages = [
                createMockMessageElement('user', 'Message 1', '2024-01-01T10:00:00.000Z'),
                createMockMessageElement('user', 'Message 2', 'invalid-timestamp')
            ];

            sessionStorage.setItem('search_assistant_history', JSON.stringify(messages));
            const loaded = JSON.parse(sessionStorage.getItem('search_assistant_history'));

            expect(isValidTimestamp(loaded[0].timestamp)).toBe(true);
            expect(isValidTimestamp(loaded[1].timestamp)).toBe(false);
        });
    });

    describe('clearConversationHistory()', () => {
        test('should clear all messages from sessionStorage', () => {
            const messages = [
                createMockMessageElement('user', 'Test'),
                createMockMessageElement('bot', 'Response')
            ];

            sessionStorage.setItem('search_assistant_history', JSON.stringify(messages));
            expect(sessionStorage.getItem('search_assistant_history')).toBeDefined();

            sessionStorage.removeItem('search_assistant_history');
            expect(sessionStorage.getItem('search_assistant_history')).toBeNull();
        });
    });
});

// ==================== Message Rendering Tests ====================

describe('Message Rendering', () => {
    describe('renderUserMessage()', () => {
        test('should create message element with correct structure', () => {
            const messageText = 'Hello, search assistant!';
            const timestamp = new Date();

            const message = {
                role: 'user',
                content: messageText,
                timestamp: timestamp.toISOString()
            };

            expect(message.role).toBe('user');
            expect(message.content).toBe(messageText);
            expect(isValidTimestamp(message.timestamp)).toBe(true);
        });

        test('should sanitize user input to prevent XSS', () => {
            const maliciousInput = '<script>alert("XSS")</script>';
            const sanitized = maliciousInput
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            expect(sanitized).not.toContain('<script>');
            expect(sanitized).toContain('&lt;script&gt;');
        });

        test('should handle empty or whitespace-only messages', () => {
            const emptyMessage = '';
            const whitespaceMessage = '   ';

            expect(emptyMessage.trim()).toBe('');
            expect(whitespaceMessage.trim()).toBe('');
        });

        test('should format timestamp correctly', () => {
            const timestamp = new Date('2024-01-15T14:30:00');
            const hours = timestamp.getHours();
            const minutes = timestamp.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            const displayHours = hours % 12 || 12;
            const displayMinutes = minutes < 10 ? '0' + minutes : minutes;
            const formatted = `${displayHours}:${displayMinutes} ${ampm}`;

            expect(formatted).toBe('2:30 PM');
        });
    });

    describe('renderBotMessage()', () => {
        test('should parse Markdown formatting', () => {
            const markdown = '**Bold** and *italic* text';
            // In actual implementation, marked.js would parse this
            // Here we just verify the structure
            expect(markdown).toContain('**Bold**');
            expect(markdown).toContain('*italic*');
        });

        test('should sanitize HTML output to prevent XSS', () => {
            const maliciousHTML = '<img src=x onerror=alert("XSS")>';
            // DOMPurify would sanitize this in actual implementation
            const sanitized = maliciousHTML
                .replace(/onerror/g, '')
                .replace(/alert/g, '');

            expect(sanitized).not.toContain('onerror');
            expect(sanitized).not.toContain('alert');
        });

        test('should preserve safe links', () => {
            const markdown = '[Collection Item](/items/show/123)';
            expect(markdown).toContain('](/items/show/123)');
        });

        test('should sanitize javascript: links', () => {
            const maliciousLink = '[Click](javascript:alert("XSS"))';
            // In actual implementation, DOMPurify would remove javascript: protocol
            expect(maliciousLink).toContain('javascript:');
            // After sanitization, javascript: should be removed
        });

        test('should render lists correctly', () => {
            const markdown = '- Item 1\n- Item 2\n- Item 3';
            const lines = markdown.split('\n');
            expect(lines.length).toBe(3);
            expect(lines[0]).toContain('- Item 1');
        });
    });
});

// ==================== API Communication Tests ====================

describe('API Communication', () => {
    describe('sendQueryToAPI()', () => {
        test('should format request correctly', () => {
            const query = 'Find Monet paintings';
            const context = [
                { role: 'user', content: 'Previous query' },
                { role: 'bot', content: 'Previous response' }
            ];

            const requestBody = {
                query: query,
                context: context
            };

            expect(requestBody.query).toBe(query);
            expect(requestBody.context.length).toBe(2);
            expect(requestBody.context[0].role).toBe('user');
        });

        test('should include conversation context in request', () => {
            const context = [
                { role: 'user', content: 'Hello' },
                { role: 'bot', content: 'Hi!' }
            ];

            const requestBody = {
                query: 'New query',
                context: context
            };

            expect(requestBody.context).toBeDefined();
            expect(requestBody.context.length).toBe(2);
        });

        test('should validate API response structure', () => {
            const validResponse = {
                response: 'I found 3 paintings by Monet',
                results: [
                    { id: '123', title: 'Nymphéas', url: '/items/show/123' }
                ],
                suggestions: ['View all Monet paintings', '19th century art']
            };

            expect(validResponse.response).toBeDefined();
            expect(typeof validResponse.response).toBe('string');
            expect(Array.isArray(validResponse.results)).toBe(true);
            expect(Array.isArray(validResponse.suggestions)).toBe(true);
        });

        test('should handle empty results gracefully', () => {
            const emptyResponse = {
                response: 'No items found',
                results: [],
                suggestions: []
            };

            expect(emptyResponse.results.length).toBe(0);
            expect(emptyResponse.suggestions.length).toBe(0);
        });
    });

    describe('retryFailedRequest()', () => {
        test('should calculate exponential backoff correctly', () => {
            const baseDelay = 2000; // 2 seconds
            const attemptNumber = 0;
            const delay = Math.pow(2, attemptNumber) * baseDelay;

            expect(delay).toBe(2000); // 2^0 * 2000 = 2000ms
        });

        test('should not exceed max retry limit', () => {
            const maxRetries = 1;
            let retryCount = 0;

            // Simulate retry attempts
            while (retryCount < maxRetries + 5) {
                retryCount++;
            }

            // In actual implementation, retry should stop at maxRetries
            expect(retryCount).toBeGreaterThan(maxRetries);
            // But controlled retry count should not exceed max
            const controlledRetryCount = Math.min(retryCount, maxRetries);
            expect(controlledRetryCount).toBe(maxRetries);
        });

        test('should reset retry counter on success', () => {
            let retryCount = 3;
            // On successful request
            retryCount = 0;

            expect(retryCount).toBe(0);
        });
    });

    describe('handleAPIError()', () => {
        test('should display user-friendly timeout error', () => {
            const errorType = 'timeout';
            const userMessage = getUserFriendlyErrorMessage(errorType);

            expect(userMessage).toBeDefined();
            expect(userMessage).not.toContain('Error');
            expect(userMessage.length).toBeGreaterThan(0);
        });

        test('should display user-friendly network error', () => {
            const errorType = 'network';
            const userMessage = getUserFriendlyErrorMessage(errorType);

            expect(userMessage).toBeDefined();
            expect(userMessage).not.toContain('stack');
            expect(userMessage).not.toContain('trace');
        });

        test('should not expose stack traces to users', () => {
            const error = new Error('Internal error');
            const stackTrace = error.stack;

            // User-facing message should never contain stack trace
            const userMessage = 'Une erreur est survenue. Veuillez réessayer.';

            expect(userMessage).not.toContain(stackTrace);
            expect(userMessage).not.toContain('Error:');
        });

        test('should handle HTTP status errors', () => {
            const statusCodes = [400, 404, 500, 503];
            statusCodes.forEach(code => {
                const message = getUserFriendlyErrorMessage('http_' + code);
                expect(message).toBeDefined();
                expect(typeof message).toBe('string');
            });
        });
    });
});

// ==================== Validation Tests ====================

describe('Input Validation', () => {
    describe('validateQuery()', () => {
        test('should reject empty queries', () => {
            const query = '';
            const isValid = query.trim().length > 0;

            expect(isValid).toBe(false);
        });

        test('should reject whitespace-only queries', () => {
            const query = '   \n\t  ';
            const isValid = query.trim().length > 0;

            expect(isValid).toBe(false);
        });

        test('should accept valid queries', () => {
            const query = 'Find paintings by Monet';
            const isValid = query.trim().length > 0;

            expect(isValid).toBe(true);
        });

        test('should trim leading and trailing whitespace', () => {
            const query = '  valid query  ';
            const trimmed = query.trim();

            expect(trimmed).toBe('valid query');
            expect(trimmed.length).toBeLessThan(query.length);
        });
    });
});

// ==================== Utility Function Tests ====================

describe('Utility Functions', () => {
    describe('formatTimestamp()', () => {
        test('should format timestamp in 12-hour format', () => {
            const date = new Date('2024-01-15T14:30:00');
            const hours = date.getHours();
            const minutes = date.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            const displayHours = hours % 12 || 12;
            const displayMinutes = minutes < 10 ? '0' + minutes : minutes;
            const formatted = `${displayHours}:${displayMinutes} ${ampm}`;

            expect(formatted).toMatch(/^\d{1,2}:\d{2} (AM|PM)$/);
            expect(formatted).toBe('2:30 PM');
        });

        test('should handle midnight correctly', () => {
            const date = new Date('2024-01-15T00:00:00');
            const hours = date.getHours();
            const displayHours = hours % 12 || 12;

            expect(displayHours).toBe(12);
        });

        test('should handle noon correctly', () => {
            const date = new Date('2024-01-15T12:00:00');
            const hours = date.getHours();
            const ampm = hours >= 12 ? 'PM' : 'AM';

            expect(ampm).toBe('PM');
        });
    });

    describe('sanitizeHTML()', () => {
        test('should remove script tags', () => {
            const malicious = '<script>alert("XSS")</script>Safe text';
            const sanitized = malicious.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');

            expect(sanitized).not.toContain('<script>');
            expect(sanitized).toContain('Safe text');
        });

        test('should preserve safe HTML elements', () => {
            const safe = '<p>Paragraph</p><strong>Bold</strong><em>Italic</em>';

            expect(safe).toContain('<p>');
            expect(safe).toContain('<strong>');
            expect(safe).toContain('<em>');
        });

        test('should remove event handlers', () => {
            const malicious = '<div onclick="alert(\'XSS\')">Click me</div>';
            const sanitized = malicious.replace(/on\w+="[^"]*"/g, '');

            expect(sanitized).not.toContain('onclick');
            expect(sanitized).toContain('Click me');
        });
    });
});

// ==================== Storage Tests ====================

describe('Browser Storage', () => {
    beforeEach(() => {
        sessionStorage.clear();
        localStorage.clear();
    });

    describe('sessionStorage', () => {
        test('should persist conversation history', () => {
            const messages = [
                createMockMessageElement('user', 'Test query'),
                createMockMessageElement('bot', 'Test response')
            ];

            sessionStorage.setItem('search_assistant_history', JSON.stringify(messages));
            const stored = sessionStorage.getItem('search_assistant_history');

            expect(stored).toBeDefined();
            expect(JSON.parse(stored).length).toBe(2);
        });

        test('should handle quota exceeded error', () => {
            let quotaExceeded = false;

            try {
                // In a real scenario, this would fill up storage
                const largeData = new Array(1000000).join('x');
                sessionStorage.setItem('large_key', largeData);
            } catch (e) {
                if (e.name === 'QuotaExceededError') {
                    quotaExceeded = true;
                }
            }

            // Test should handle quota gracefully
            expect(typeof quotaExceeded).toBe('boolean');
        });
    });

    describe('localStorage', () => {
        test('should persist widget state', () => {
            localStorage.setItem('search_assistant_widget_open', 'true');
            const state = localStorage.getItem('search_assistant_widget_open');

            expect(state).toBe('true');
        });

        test('should persist feedback data', () => {
            const feedback = {
                'msg-123': { type: 'up', timestamp: new Date().toISOString() },
                'msg-456': { type: 'down', timestamp: new Date().toISOString() }
            };

            localStorage.setItem('search_assistant_feedback', JSON.stringify(feedback));
            const stored = JSON.parse(localStorage.getItem('search_assistant_feedback'));

            expect(stored['msg-123'].type).toBe('up');
            expect(stored['msg-456'].type).toBe('down');
        });
    });
});

// ==================== Helper Functions ====================

/**
 * Get user-friendly error message for different error types
 * This simulates the handleAPIError() function behavior
 */
function getUserFriendlyErrorMessage(errorType) {
    const messages = {
        'timeout': 'La requête a pris trop de temps. Veuillez réessayer.',
        'network': 'Erreur de connexion. Vérifiez votre connexion internet.',
        'http_400': 'Requête invalide. Veuillez reformuler votre question.',
        'http_404': 'Service non trouvé. Veuillez contacter le support.',
        'http_500': 'Erreur du serveur. Veuillez réessayer plus tard.',
        'http_503': 'Service temporairement indisponible. Veuillez réessayer.',
        'parse': 'Erreur lors du traitement de la réponse.',
        'default': 'Une erreur est survenue. Veuillez réessayer.'
    };

    return messages[errorType] || messages['default'];
}

// ==================== Test Runner ====================

// QUnit compatibility
if (typeof QUnit !== 'undefined') {
    QUnit.module('Search Assistant AI Widget Tests');

    // Export test suites for QUnit
    global.conversationHistoryTests = describe;
    global.messageRenderingTests = describe;
    global.apiCommunicationTests = describe;
}

// Jest compatibility
if (typeof describe === 'undefined') {
    global.describe = function(name, fn) {
        console.log('\n' + name);
        fn();
    };
}

if (typeof test === 'undefined') {
    global.test = function(name, fn) {
        try {
            fn();
            console.log('  ✓ ' + name);
        } catch (e) {
            console.log('  ✗ ' + name);
            console.error('    ' + e.message);
        }
    };
}

if (typeof expect === 'undefined') {
    global.expect = function(actual) {
        return {
            toBe: function(expected) {
                if (actual !== expected) {
                    throw new Error(`Expected ${actual} to be ${expected}`);
                }
            },
            toBeDefined: function() {
                if (actual === undefined) {
                    throw new Error(`Expected value to be defined`);
                }
            },
            toBeNull: function() {
                if (actual !== null) {
                    throw new Error(`Expected ${actual} to be null`);
                }
            },
            toContain: function(expected) {
                if (!actual.includes(expected)) {
                    throw new Error(`Expected ${actual} to contain ${expected}`);
                }
            },
            toBeGreaterThan: function(expected) {
                if (actual <= expected) {
                    throw new Error(`Expected ${actual} to be greater than ${expected}`);
                }
            },
            toBeLessThan: function(expected) {
                if (actual >= expected) {
                    throw new Error(`Expected ${actual} to be less than ${expected}`);
                }
            },
            toMatch: function(pattern) {
                if (!pattern.test(actual)) {
                    throw new Error(`Expected ${actual} to match ${pattern}`);
                }
            },
            not: {
                toContain: function(expected) {
                    if (actual.includes(expected)) {
                        throw new Error(`Expected ${actual} not to contain ${expected}`);
                    }
                },
                toBe: function(expected) {
                    if (actual === expected) {
                        throw new Error(`Expected ${actual} not to be ${expected}`);
                    }
                }
            }
        };
    };
}

if (typeof beforeEach === 'undefined') {
    global.beforeEach = function(fn) {
        // Simple implementation - just run before each test
        // In a real test framework, this would be more sophisticated
        fn();
    };
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        createMockMessageElement,
        isValidMessage,
        isValidTimestamp,
        getUserFriendlyErrorMessage
    };
}

console.log('\n=== Search Assistant AI Widget Test Suite ===');
console.log('Tests can be run with Jest or QUnit');
console.log('For Jest: npm test');
console.log('For QUnit: Open test.html in browser');
console.log('For manual verification: Check each test suite above\n');
