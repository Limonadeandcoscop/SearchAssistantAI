/**
 * Admin Dashboard JavaScript for Search Assistant AI Module
 *
 * Handles real-time statistics updates via AJAX polling, Charts.js visualization,
 * and quick action button handlers for administrative tasks.
 *
 * Features:
 * - AJAX polling every 30 seconds to fetch updated statistics
 * - Charts.js initialization for pie chart (distribution) and line chart (usage)
 * - Quick action handlers (Reindex All, Force Sync, Clear Cache, Test Configuration)
 * - Loading states and user feedback messages
 * - Graceful error handling
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
        POLL_INTERVAL: 30000, // 30 seconds
        STATS_ENDPOINT: '/admin/search-assistant/dashboard/stats',
        AJAX_TIMEOUT: 10000, // 10 seconds
        MESSAGE_DISPLAY_TIME: 5000 // 5 seconds
    };

    // ==================== Global State ====================

    let usageChart = null;
    let pollInterval = null;
    let isPolling = false;

    // ==================== Utility Functions ====================

    /**
     * Format a number with thousands separators
     * @param {number} num - Number to format
     * @returns {string} Formatted number
     */
    function formatNumber(num) {
        if (num === null || num === undefined) return '-';
        return num.toLocaleString('en-US');
    }

    /**
     * Format a timestamp to a human-readable date/time
     * @param {number|string|null} timestamp - Unix timestamp or ISO date string
     * @returns {string} Formatted date/time or '-'
     */
    function formatTimestamp(timestamp) {
        if (!timestamp) return '-';

        try {
            const date = typeof timestamp === 'number'
                ? new Date(timestamp * 1000)
                : new Date(timestamp);

            if (isNaN(date.getTime())) return '-';

            return date.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            Logger.error('Error formatting timestamp:', e);
            return '-';
        }
    }

    /**
     * Format response time in milliseconds to human-readable format
     * @param {number} ms - Response time in milliseconds
     * @returns {string} Formatted response time
     */
    function formatResponseTime(ms) {
        if (ms === null || ms === undefined || ms === 0) return '-';

        if (ms < 1000) {
            return ms + ' ms';
        } else {
            return (ms / 1000).toFixed(2) + ' s';
        }
    }

    /**
     * Format satisfaction rate as percentage
     * @param {number} rate - Satisfaction rate (0-1 or 0-100)
     * @returns {string} Formatted percentage
     */
    function formatSatisfactionRate(rate) {
        if (rate === null || rate === undefined) return '-';

        // Normalize to percentage if needed
        const percentage = rate <= 1 ? rate * 100 : rate;
        return percentage.toFixed(1) + '%';
    }

    /**
     * Show a status message to the user
     * @param {string} message - Message text
     * @param {string} type - Message type (success, error, warning, info)
     */
    function showMessage(message, type = 'info') {
        const container = document.getElementById('status-messages');
        if (!container) return;

        const messageDiv = document.createElement('div');
        messageDiv.className = 'message ' + type;
        messageDiv.textContent = message;

        container.appendChild(messageDiv);

        // Auto-remove after configured time
        setTimeout(function() {
            messageDiv.style.opacity = '0';
            setTimeout(function() {
                if (messageDiv.parentNode) {
                    messageDiv.parentNode.removeChild(messageDiv);
                }
            }, 300);
        }, CONFIG.MESSAGE_DISPLAY_TIME);
    }

    /**
     * Show loading overlay
     */
    function showLoading() {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.display = 'flex';
        }
    }

    /**
     * Hide loading overlay
     */
    function hideLoading() {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    /**
     * Update a stat element with new value
     * @param {string} statId - Element ID
     * @param {string|number} value - New value
     */
    function updateStat(statId, value) {
        const element = document.getElementById(statId);
        if (element) {
            element.textContent = value;
        }
    }

    /**
     * Get the configured vector store display name from DOM data attributes
     * @returns {string} The vector store display name (e.g., "Qdrant", "Elasticsearch 8.x")
     */
    function getVectorStoreDisplayName() {
        const container = document.getElementById('page-actions');
        return container && container.dataset.vectorStoreDisplayName
            ? container.dataset.vectorStoreDisplayName
            : 'Vector Store';
    }

    /**
     * Get the configured LLM provider display name from DOM data attributes
     * @returns {string} The provider label (e.g., "Self-hosted (Ollama or OpenAI-compatible)")
     */
    function getLlmDisplayName() {
        const container = document.getElementById('page-actions');
        return container && container.dataset.llmDisplayName
            ? container.dataset.llmDisplayName
            : 'LLM';
    }

    /**
     * Update a status indicator element
     * @param {string} statId - Element ID
     * @param {string} status - Status value
     * @param {string} displayText - Text to display
     */
    function updateStatusIndicator(statId, status, displayText) {
        const element = document.getElementById(statId);
        if (!element) return;

        // Remove existing status classes
        element.className = element.className.replace(/status-\w+/g, '').trim();

        // Add status indicator class if not present
        if (!element.classList.contains('status-indicator')) {
            element.classList.add('status-indicator');
        }

        // Add new status class
        element.classList.add('status-' + status);
        element.textContent = displayText;
    }

    // ==================== Charts Initialization ====================

    /**
     * Initialize the usage line chart
     */
    function initUsageChart() {
        Logger.log('Initializing usage chart...');
        const canvas = document.getElementById('usageChart');
        if (!canvas) {
            Logger.error('Usage chart canvas not found');
            return;
        }

        const ctx = canvas.getContext('2d');

        // Generate last 7 days for x-axis
        const labels = [];
        const now = new Date();
        for (let i = 6; i >= 0; i--) {
            const date = new Date(now);
            date.setDate(date.getDate() - i);
            labels.push(date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }));
        }

        usageChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Questions',
                    data: [0, 0, 0, 0, 0, 0, 0],
                    borderColor: '#2196F3',
                    backgroundColor: 'rgba(33, 150, 243, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#2196F3',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return 'Questions: ' + formatNumber(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            callback: function(value) {
                                return formatNumber(value);
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        Logger.log('Usage chart initialized');
    }

    /**
     * Update the usage chart with new data
     * @param {array} data - Array of question counts for last 7 days
     */
    function updateUsageChart(data) {
        if (!usageChart) return;

        if (Array.isArray(data) && data.length === 7) {
            Logger.debug('Updating usage chart:', data);
            usageChart.data.datasets[0].data = data;
            usageChart.update('none');
        }
    }

    // ==================== Pending Warning Management ====================

    /**
     * Update the pending warning visibility based on stale pending items
     * @param {object} stalePendingData - Data about stale pending items
     */
    function updatePendingWarning(stalePendingData) {
        const warningElement = document.getElementById('pending-warning');
        if (!warningElement) {
            Logger.warn('Pending warning element not found');
            return;
        }

        // Check if there are stale pending items
        if (stalePendingData && stalePendingData.count > 0) {
            Logger.log('Showing pending warning:', stalePendingData.count, 'stale items');

            // Update the message with count and age information
            const messageElement = document.getElementById('stale-pending-message');
            if (messageElement && stalePendingData.oldest_age_minutes) {
                const ageText = stalePendingData.oldest_age_minutes >= 60
                    ? Math.floor(stalePendingData.oldest_age_minutes / 60) + ' hours'
                    : stalePendingData.oldest_age_minutes + ' minutes';

                messageElement.textContent = stalePendingData.count + ' item' +
                    (stalePendingData.count > 1 ? 's have' : ' has') +
                    ' been pending indexation for over ' + ageText + '.';
            }

            // Show the warning
            warningElement.style.display = 'block';
        } else {
            Logger.debug('Hiding pending warning - no stale items');
            warningElement.style.display = 'none';
        }
    }

    /**
     * Handle Force Sync button in the warning
     */
    function handleWarningForceSync() {
        Logger.log('Warning Force Sync button clicked');

        // Hide the warning immediately for better UX
        const warningElement = document.getElementById('pending-warning');
        if (warningElement) {
            warningElement.style.display = 'none';
        }

        // Trigger the main Force Sync handler
        handleForceSync();
    }

    // ==================== Stats Fetching & Updating ====================

    /**
     * Fetch statistics from the server via AJAX
     * @returns {Promise} Promise that resolves with stats data
     */
    function fetchStats() {
        return new Promise(function(resolve, reject) {
            Logger.log('Fetching stats from:', CONFIG.STATS_ENDPOINT);
            const xhr = new XMLHttpRequest();

            xhr.timeout = CONFIG.AJAX_TIMEOUT;

            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        Logger.log('Stats response received:', response.success ? 'success' : 'failed');
                        if (response.success) {
                            Logger.debug('Stats data:', response.data);
                            resolve(response.data);
                        } else {
                            Logger.error('Stats request failed:', response.message);
                            reject(new Error(response.message || 'Failed to fetch statistics'));
                        }
                    } catch (e) {
                        Logger.error('Failed to parse stats response:', e);
                        reject(new Error('Invalid JSON response'));
                    }
                } else {
                    Logger.error('Stats request HTTP error:', xhr.status, xhr.statusText);
                    reject(new Error('HTTP ' + xhr.status + ': ' + xhr.statusText));
                }
            };

            xhr.onerror = function() {
                Logger.error('Stats request network error');
                reject(new Error('Network error'));
            };

            xhr.ontimeout = function() {
                Logger.error('Stats request timeout after', CONFIG.AJAX_TIMEOUT, 'ms');
                reject(new Error('Request timeout'));
            };

            xhr.open('GET', CONFIG.STATS_ENDPOINT, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send();
        });
    }

    /**
     * Update all dashboard stats with fetched data
     * @param {object} stats - Statistics data from server
     */
    function updateDashboardStats(stats) {
        Logger.log('Updating dashboard stats...');
        // Section 1: Indexation State
        if (stats.indexation) {
            updateStat('stat-total-vectors', formatNumber(stats.indexation.total_vectors || 0));
            updateStat('stat-indexed-items', formatNumber(stats.indexation.indexed_items || 0));
            updateStat('stat-pending-items', formatNumber(stats.indexation.pending_items || 0));
            updateStat('stat-last-indexation', formatTimestamp(stats.indexation.last_indexed));

        }

        // Section 2: Synchronization Status
        if (stats.synchronization) {
            const syncStatus = stats.synchronization.status || 'idle';
            const syncStatusText = syncStatus.charAt(0).toUpperCase() + syncStatus.slice(1);
            updateStatusIndicator('stat-sync-status', syncStatus, syncStatusText);
            updateStat('stat-last-sync', formatTimestamp(stats.synchronization.last_sync));
            updateStat('stat-today-operations', formatNumber(stats.synchronization.today_operations || 0));
            updateStat('stat-queue-count', formatNumber(stats.synchronization.queue_count || 0));

            // Update pending warning based on stale pending items
            if (stats.synchronization.stale_pending !== undefined && stats.synchronization.stale_pending === true) {
                // Construct the warning data object from synchronization stats
                const warningData = {
                    count: stats.synchronization.queue_count || 0,
                    oldest_age_minutes: stats.synchronization.oldest_pending_age
                        ? Math.floor(stats.synchronization.oldest_pending_age / 60)
                        : null
                };
                updatePendingWarning(warningData);
            } else {
                // Hide warning if no stale items
                updatePendingWarning(null);
            }
        }

        // Section 3: Usage Metrics
        if (stats.usage) {
            updateStat('stat-total-questions', formatNumber(stats.usage.total_questions || 0));
            updateStat('stat-today-questions', formatNumber(stats.usage.today_questions || 0));
            updateStat('stat-week-questions', formatNumber(stats.usage.week_questions || 0));
            updateStat('stat-month-questions', formatNumber(stats.usage.month_questions || 0));
            updateStat('stat-avg-response-time', formatResponseTime(stats.usage.avg_response_time || 0));
            updateStat('stat-satisfaction-rate', formatSatisfactionRate(stats.usage.satisfaction_rate));

            // Update usage chart
            if (stats.usage.daily_counts && Array.isArray(stats.usage.daily_counts)) {
                updateUsageChart(stats.usage.daily_counts);
            }
        }

        // Section 4: Technical Health
        if (stats.health) {
            // Qdrant health
            if (stats.health.qdrant) {
                const qdrantStatus = stats.health.qdrant.status || 'disconnected';
                const qdrantText = qdrantStatus === 'connected' ? 'Connected' : 'Disconnected';
                updateStatusIndicator('stat-qdrant-health', qdrantStatus, qdrantText);

                const qdrantDetail = document.getElementById('stat-qdrant-detail');
                if (qdrantDetail) {
                    if (stats.health.qdrant.error) {
                        qdrantDetail.textContent = stats.health.qdrant.error;
                    } else {
                        qdrantDetail.textContent = '';
                    }
                }
            }

            // Embeddings health
            if (stats.health.embeddings) {
                const embeddingsStatus = stats.health.embeddings.status || 'disconnected';
                const embeddingsText = embeddingsStatus === 'connected' ? 'Connected' : 'Disconnected';
                updateStatusIndicator('stat-embeddings-health', embeddingsStatus, embeddingsText);

                const embeddingsDetail = document.getElementById('stat-embeddings-detail');
                if (embeddingsDetail) {
                    if (stats.health.embeddings.error) {
                        embeddingsDetail.textContent = stats.health.embeddings.error;
                    } else {
                        embeddingsDetail.textContent = '';
                    }
                }
            }

            // LLM health (configured provider)
            if (stats.health.llm) {
                const llmStatus = stats.health.llm.status || 'disconnected';
                let llmText = 'Disconnected';
                if (llmStatus === 'connected') {
                    llmText = 'Connected';
                } else if (llmStatus === 'not_configured') {
                    llmText = 'Not Configured';
                }
                updateStatusIndicator('stat-llm-health', llmStatus, llmText);

                const llmDetail = document.getElementById('stat-llm-detail');
                if (llmDetail) {
                    if (stats.health.llm.error) {
                        llmDetail.textContent = stats.health.llm.error;
                    } else if (stats.health.llm.data) {
                        // Self-hosted providers report the model and the models they serve
                        let modelInfo = '';
                        const message = stats.health.llm.data.message || '';
                        const modelMatch = message.match(/Model '([^']+)'/);

                        if (modelMatch) {
                            modelInfo = 'Model: ' + modelMatch[1];
                            if (stats.health.llm.data.model_available === false) {
                                modelInfo += ' (not available - run: ollama pull ' + modelMatch[1] + ')';
                            }
                        } else if (stats.health.llm.data.models && stats.health.llm.data.models.length > 0) {
                            modelInfo = 'Available models: ' + stats.health.llm.data.models.length;
                        }

                        llmDetail.textContent = modelInfo;
                    } else {
                        llmDetail.textContent = '';
                    }
                }
            }
        }
        Logger.log('Dashboard stats update complete');
    }

    /**
     * Refresh dashboard statistics
     */
    function refreshStats() {
        if (isPolling) {
            Logger.debug('Stats refresh already in progress, skipping...');
            return;
        }

        isPolling = true;
        Logger.log('Starting stats refresh...');

        fetchStats()
            .then(function(stats) {
                updateDashboardStats(stats);
                isPolling = false;
                Logger.log('Stats refresh completed successfully');
            })
            .catch(function(error) {
                Logger.error('Failed to fetch stats:', error.message);
                isPolling = false;
                // Don't show error message for background polling to avoid spam
            });
    }

    /**
     * Start automatic polling for stats updates
     */
    function startPolling() {
        Logger.log('Starting dashboard polling...');
        if (pollInterval) {
            clearInterval(pollInterval);
        }

        // Initial fetch
        refreshStats();

        // Set up polling interval
        pollInterval = setInterval(function() {
            refreshStats();
        }, CONFIG.POLL_INTERVAL);

        Logger.log('Dashboard polling started (interval: ' + CONFIG.POLL_INTERVAL + 'ms)');
    }

    /**
     * Stop automatic polling
     */
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
            Logger.log('Dashboard polling stopped');
        }
    }

    // ==================== Quick Action Handlers ====================

    /**
     * Get CSRF token and field name from the page
     * @returns {{name: string, value: string}|null} CSRF data or null if not found
     */
    function getCsrfData() {
        const csrfInput = document.getElementById('csrf-token');
        if (!csrfInput) return null;
        return {
            name: csrfInput.getAttribute('data-csrf-name') || csrfInput.name,
            value: csrfInput.value
        };
    }

    /**
     * Get CSRF token value (backward-compatible helper)
     * @returns {string|null} CSRF token or null if not found
     */
    function getCsrfToken() {
        const data = getCsrfData();
        return data ? data.value : null;
    }

    /**
     * Build CSRF POST body string
     * @returns {string} URL-encoded CSRF parameter
     */
    function getCsrfPostBody() {
        const data = getCsrfData();
        if (!data) return '';
        return encodeURIComponent(data.name) + '=' + encodeURIComponent(data.value);
    }

    /**
     * Handle "Reindex All" button click
     */
    function handleReindexAll() {
        Logger.log('Reindex All button clicked');
        if (!confirm('This will reindex all items, media, and collections. This may take some time. Continue?')) {
            Logger.log('Reindex All cancelled by user');
            return;
        }

        const button = document.getElementById('action-reindex');
        if (button) {
            button.disabled = true;
            button.textContent = 'Reindexing...';
        }

        // Get CSRF token
        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            if (button) {
                button.disabled = false;
                button.textContent = 'Reindex All';
            }
            showMessage('Security token not found. Please refresh the page.', 'error');
            return;
        }

        showLoading();
        Logger.log('Sending reindex request...');

        // Make AJAX POST request to reindex endpoint
        const xhr = new XMLHttpRequest();
        xhr.timeout = CONFIG.AJAX_TIMEOUT;

        xhr.onload = function() {
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Reindex All';
            }

            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    Logger.log('Reindex response:', response.success ? 'success' : 'failed');
                    if (response.success) {
                        showMessage(response.message || 'Reindexation started successfully', 'success');
                        // Refresh stats after a short delay to show updated data
                        setTimeout(refreshStats, 2000);
                    } else {
                        Logger.error('Reindex failed:', response.message);
                        showMessage(response.message || 'Failed to start reindexation', 'error');
                    }
                } catch (e) {
                    Logger.error('Reindex response parse error:', e);
                    showMessage('Invalid response from server', 'error');
                }
            } else {
                Logger.error('Reindex HTTP error:', xhr.status);
                showMessage('Failed to start reindexation: HTTP ' + xhr.status, 'error');
            }
        };

        xhr.onerror = function() {
            Logger.error('Reindex network error');
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Reindex All';
            }
            showMessage('Network error occurred', 'error');
        };

        xhr.ontimeout = function() {
            Logger.error('Reindex request timeout');
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Reindex All';
            }
            showMessage('Request timeout', 'error');
        };

        xhr.open('POST', '/admin/search-assistant/dashboard/reindex', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        // Send CSRF token with request
        xhr.send(getCsrfPostBody());
    }

    /**
     * Handle "Force Sync" button click
     */
    function handleForceSync() {
        Logger.log('Force Sync button clicked');
        const button = document.getElementById('action-sync');
        if (button) {
            button.disabled = true;
            button.textContent = 'Syncing...';
        }

        // Get CSRF token
        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            if (button) {
                button.disabled = false;
                button.textContent = 'Force Sync';
            }
            showMessage('Security token not found. Please refresh the page.', 'error');
            return;
        }

        showLoading();
        Logger.log('Sending sync request...');

        // Make AJAX POST request to sync endpoint
        const xhr = new XMLHttpRequest();
        xhr.timeout = CONFIG.AJAX_TIMEOUT;

        xhr.onload = function() {
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Force Sync';
            }

            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    Logger.log('Sync response:', response.success ? 'success' : 'failed');
                    if (response.success) {
                        showMessage(response.message || 'Synchronization completed successfully', 'success');
                        // Refresh stats after a short delay to show updated data
                        setTimeout(refreshStats, 2000);
                    } else {
                        Logger.error('Sync failed:', response.message);
                        showMessage(response.message || 'Failed to synchronize', 'error');
                    }
                } catch (e) {
                    Logger.error('Sync response parse error:', e);
                    showMessage('Invalid response from server', 'error');
                }
            } else {
                Logger.error('Sync HTTP error:', xhr.status);
                showMessage('Failed to synchronize: HTTP ' + xhr.status, 'error');
            }
        };

        xhr.onerror = function() {
            Logger.error('Sync network error');
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Force Sync';
            }
            showMessage('Network error occurred', 'error');
        };

        xhr.ontimeout = function() {
            Logger.error('Sync request timeout');
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Force Sync';
            }
            showMessage('Request timeout', 'error');
        };

        xhr.open('POST', '/admin/search-assistant/dashboard/sync', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        // Send CSRF token with request
        xhr.send(getCsrfPostBody());
    }

    /**
     * Handle "Clear Cache" button click
     */
    function handleClearCache() {
        Logger.log('Clear Cache button clicked');
        const button = document.getElementById('action-clear-cache');
        if (button) {
            button.disabled = true;
            button.textContent = 'Clearing...';
        }

        // Get CSRF token
        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            if (button) {
                button.disabled = false;
                button.textContent = 'Clear Cache';
            }
            showMessage('Security token not found. Please refresh the page.', 'error');
            return;
        }

        showLoading();
        Logger.log('Sending clear cache request...');

        // Make AJAX POST request to clear-cache endpoint
        const xhr = new XMLHttpRequest();
        xhr.timeout = CONFIG.AJAX_TIMEOUT;

        xhr.onload = function() {
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Clear Cache';
            }

            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    Logger.log('Clear cache response:', response.success ? 'success' : 'failed');
                    if (response.success) {
                        showMessage(response.message || 'Cache cleared successfully', 'success');
                    } else {
                        Logger.error('Clear cache failed:', response.message);
                        showMessage(response.message || 'Failed to clear cache', 'error');
                    }
                } catch (e) {
                    Logger.error('Clear cache response parse error:', e);
                    showMessage('Invalid response from server', 'error');
                }
            } else {
                Logger.error('Clear cache HTTP error:', xhr.status);
                showMessage('Failed to clear cache: HTTP ' + xhr.status, 'error');
            }
        };

        xhr.onerror = function() {
            Logger.error('Clear cache network error');
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Clear Cache';
            }
            showMessage('Network error occurred', 'error');
        };

        xhr.ontimeout = function() {
            Logger.error('Clear cache request timeout');
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = 'Clear Cache';
            }
            showMessage('Request timeout', 'error');
        };

        xhr.open('POST', '/admin/search-assistant/dashboard/clear-cache', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        // Send CSRF token with request
        xhr.send(getCsrfPostBody());
    }

    /**
     * Handle "Test Configuration" button click
     */
    function handleTestConfiguration() {
        Logger.log('Test Configuration button clicked');
        const button = document.getElementById('action-test-config');
        if (button) {
            button.disabled = true;
            button.textContent = 'Testing...';
        }

        showLoading();
        Logger.log('Testing service configuration...');

        // Fetch stats to test all service connections
        fetchStats()
            .then(function(stats) {
                hideLoading();
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Test Configuration';
                }

                // Check health status
                const health = stats.health || {};
                const vectorStoreDisplayName = getVectorStoreDisplayName();
                const vectorStoreOk = health.qdrant && health.qdrant.status === 'connected';
                const embeddingsOk = health.embeddings && health.embeddings.status === 'connected';
                const llmLabel = getLlmDisplayName();
                const llmOk = health.llm && (health.llm.status === 'connected' || health.llm.status === 'not_configured');

                Logger.log('Configuration test results:', {
                    vectorStore: vectorStoreOk ? 'OK' : 'FAIL',
                    embeddings: embeddingsOk ? 'OK' : 'FAIL',
                    llm: llmOk ? 'OK' : 'FAIL'
                });

                if (vectorStoreOk && embeddingsOk && llmOk) {
                    showMessage('All services are operational', 'success');
                } else {
                    let failedServices = [];
                    if (!vectorStoreOk) failedServices.push(vectorStoreDisplayName);
                    if (!embeddingsOk) failedServices.push('Embeddings');
                    if (!llmOk) failedServices.push(llmLabel);
                    Logger.error('Services with connection issues:', failedServices);
                    showMessage('Connection issues: ' + failedServices.join(', '), 'error');
                }

                // Force refresh stats display
                updateDashboardStats(stats);
            })
            .catch(function(error) {
                Logger.error('Configuration test failed:', error.message);
                hideLoading();
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Test Configuration';
                }
                showMessage('Configuration test failed: ' + error.message, 'error');
            });
    }

    /**
     * Handle "Clear Vector Store" button click
     */
    function handleClearVectorStore() {
        const vectorStoreDisplayName = getVectorStoreDisplayName();
        Logger.log('Clear Vector Store button clicked (backend: ' + vectorStoreDisplayName + ')');

        // Important: Confirmation dialog for destructive action
        if (!confirm('WARNING: This will delete ALL vector data from ' + vectorStoreDisplayName + '. You will need to reindex all content. This action cannot be undone. Continue?')) {
            Logger.log('Clear Vector Store cancelled by user');
            return;
        }

        const button = document.getElementById('action-clear-vector-store');
        const buttonOriginalText = button ? button.textContent : 'Clear Vector Store';
        if (button) {
            button.disabled = true;
            button.textContent = 'Clearing...';
        }

        // Get CSRF token
        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            if (button) {
                button.disabled = false;
                button.textContent = buttonOriginalText;
            }
            showMessage('Security token not found. Please refresh the page.', 'error');
            return;
        }

        showLoading();
        Logger.log('Sending clear vector store request...');

        // Make AJAX POST request to clear-vector-store endpoint
        const xhr = new XMLHttpRequest();
        xhr.timeout = CONFIG.AJAX_TIMEOUT;

        xhr.onload = function() {
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = buttonOriginalText;
            }

            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    Logger.log('Clear vector store response:', response.success ? 'success' : 'failed');
                    if (response.success) {
                        showMessage(response.message || vectorStoreDisplayName + ' data cleared successfully', 'success');
                        // Refresh stats after a short delay to show updated data
                        setTimeout(refreshStats, 2000);
                    } else {
                        Logger.error('Clear vector store failed:', response.message);
                        showMessage(response.message || 'Failed to clear ' + vectorStoreDisplayName + ' data', 'error');
                    }
                } catch (e) {
                    Logger.error('Clear vector store response parse error:', e);
                    showMessage('Invalid response from server', 'error');
                }
            } else {
                Logger.error('Clear vector store HTTP error:', xhr.status);
                showMessage('Failed to clear ' + vectorStoreDisplayName + ' data: HTTP ' + xhr.status, 'error');
            }
        };

        xhr.onerror = function() {
            Logger.error('Clear vector store network error');
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = buttonOriginalText;
            }
            showMessage('Network error occurred', 'error');
        };

        xhr.ontimeout = function() {
            Logger.error('Clear vector store request timeout');
            hideLoading();
            if (button) {
                button.disabled = false;
                button.textContent = buttonOriginalText;
            }
            showMessage('Request timeout', 'error');
        };

        xhr.open('POST', '/admin/search-assistant/dashboard/clear-vector-store', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        // Send CSRF token with request
        xhr.send(getCsrfPostBody());
    }


    // ==================== Initialization ====================

    /**
     * Initialize the dashboard when DOM is ready
     */
    function init() {
        Logger.log('Initializing Search Assistant AI Dashboard...');

        // Usage chart, when a dashboard panel provides it (canvas + Chart.js)
        if (typeof Chart !== 'undefined' && document.getElementById('usageChart')) {
            Logger.log('Chart.js detected, initializing charts...');
            initUsageChart();
        }

        // Attach quick action button handlers
        Logger.log('Attaching button event handlers...');
        const reindexButton = document.getElementById('action-reindex');
        if (reindexButton) {
            reindexButton.addEventListener('click', handleReindexAll);
            Logger.debug('Reindex button handler attached');
        }

        const syncButton = document.getElementById('action-sync');
        if (syncButton) {
            syncButton.addEventListener('click', handleForceSync);
            Logger.debug('Sync button handler attached');
        }

        const cacheButton = document.getElementById('action-clear-cache');
        if (cacheButton) {
            cacheButton.addEventListener('click', handleClearCache);
            Logger.debug('Clear cache button handler attached');
        }

        const testButton = document.getElementById('action-test-config');
        if (testButton) {
            testButton.addEventListener('click', handleTestConfiguration);
            Logger.debug('Test config button handler attached');
        }

        const clearVectorStoreButton = document.getElementById('action-clear-vector-store');
        if (clearVectorStoreButton) {
            clearVectorStoreButton.addEventListener('click', handleClearVectorStore);
            Logger.debug('Clear vector store button handler attached');
        }

        // Attach warning Force Sync button handler
        const warningForceSyncButton = document.getElementById('action-force-sync');
        if (warningForceSyncButton) {
            warningForceSyncButton.addEventListener('click', handleWarningForceSync);
            Logger.debug('Warning Force Sync button handler attached');
        }

        // Start polling for stats
        startPolling();

        // Stop polling when page is hidden/unloaded
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                Logger.log('Page hidden, pausing polling');
                stopPolling();
            } else {
                Logger.log('Page visible, resuming polling');
                startPolling();
            }
        });

        window.addEventListener('beforeunload', function() {
            Logger.log('Page unloading, stopping polling');
            stopPolling();
        });

        Logger.log('Dashboard initialized successfully');
    }

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
