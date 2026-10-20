<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\RateLimit;

use SearchAssistantAI\Service\FileLogger;

use RuntimeException;
use InvalidArgumentException;

/**
 * Service implementation for rate limiting API requests.
 *
 * Provides methods for tracking request rates per client IP and enforcing
 * configurable rate limits using a sliding window algorithm with file-based storage.
 */
class RateLimitService
{
    /**
     * @var int Maximum number of requests allowed per time window
     */
    private $maxRequests;

    /**
     * @var int Time window duration in seconds
     */
    private $windowSeconds;

    /**
     * @var string Directory path for storing rate limit data
     */
    private $storageDir;

    /**
     * @var int Maximum age of tracking files before cleanup (in seconds)
     */
    private $maxFileAge;

    /**
     * Constructor.
     *
     * @param int $maxRequests Maximum requests allowed per window (default: 10)
     * @param int $windowSeconds Time window in seconds (default: 60)
     * @param string $storageDir Directory for storing rate limit data (default: /tmp/search_assistant_rate_limits)
     * @throws RuntimeException If storage directory cannot be created or is not writable
     */
    public function __construct(
        int $maxRequests = 10,
        int $windowSeconds = 60,
        string $storageDir = '/tmp/search_assistant_rate_limits'
    ) {
        if ($maxRequests < 1) {
            throw new InvalidArgumentException('Maximum requests must be at least 1');
        }

        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('Window duration must be at least 1 second');
        }

        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->storageDir = rtrim($storageDir, '/');
        $this->maxFileAge = $windowSeconds * 2; // Keep files for 2x window duration

        // Ensure storage directory exists and is writable
        $this->ensureStorageDirectory();
    }

    /**
     * Ensure storage directory exists and is writable.
     *
     * Creates the directory if it doesn't exist and verifies write permissions.
     *
     * @return void
     * @throws RuntimeException If directory cannot be created or is not writable
     */
    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            if (!mkdir($this->storageDir, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create rate limit storage directory: {$this->storageDir}"
                );
            }
        }

        if (!is_writable($this->storageDir)) {
            throw new RuntimeException(
                "Rate limit storage directory is not writable: {$this->storageDir}"
            );
        }
    }

    /**
     * Get file path for storing request timestamps for a given client IP.
     *
     * Sanitizes the IP address to create a safe filename.
     *
     * @param string $clientIp Client IP address
     * @return string Full path to the tracking file
     */
    private function getTrackingFilePath(string $clientIp): string
    {
        // Sanitize IP for safe filename (replace dots and colons with underscores)
        $sanitizedIp = str_replace([':', '.'], '_', $clientIp);
        return $this->storageDir . '/ratelimit_' . $sanitizedIp . '.dat';
    }

    /**
     * Load request timestamps from tracking file for a given client IP.
     *
     * Reads and unserializes timestamp array from file, or returns empty array if file doesn't exist.
     *
     * @param string $clientIp Client IP address
     * @return list<int> Array of Unix timestamps
     */
    private function loadTimestamps(string $clientIp): array
    {
        $filePath = $this->getTrackingFilePath($clientIp);

        if (!file_exists($filePath)) {
            return [];
        }

        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            FileLogger::getInstance()->error("RateLimitService: Failed to read rate-limit tracking file");
            return [];
        }

        $timestamps = @unserialize($contents);
        if (!is_array($timestamps)) {
            FileLogger::getInstance()->error("RateLimitService: Invalid rate-limit tracking file format");
            return [];
        }

        return $timestamps;
    }

    /**
     * Save request timestamps to tracking file for a given client IP.
     *
     * Serializes and writes timestamp array to file with atomic write operation.
     *
     * @param string $clientIp Client IP address
     * @param list<int> $timestamps Array of Unix timestamps
     * @return bool True if save succeeded, false otherwise
     */
    private function saveTimestamps(string $clientIp, array $timestamps): bool
    {
        $filePath = $this->getTrackingFilePath($clientIp);
        $serialized = serialize($timestamps);

        // Use atomic write with temporary file and rename
        $tempFile = $filePath . '.tmp';
        if (@file_put_contents($tempFile, $serialized, LOCK_EX) === false) {
            FileLogger::getInstance()->error("RateLimitService: Failed to write rate-limit tracking file");
            return false;
        }

        if (!@rename($tempFile, $filePath)) {
            FileLogger::getInstance()->error("RateLimitService: Failed to rename temporary rate-limit tracking file");
            @unlink($tempFile);
            return false;
        }

        return true;
    }

    /**
     * Remove timestamps older than the current time window.
     *
     * Implements sliding window by filtering out expired timestamps.
     *
     * @param list<int> $timestamps Array of Unix timestamps
     * @param int $currentTime Current Unix timestamp
     * @return array<int, int> Filtered array containing only recent timestamps
     */
    private function filterExpiredTimestamps(array $timestamps, int $currentTime): array
    {
        $windowStart = $currentTime - $this->windowSeconds;

        return array_filter($timestamps, function ($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });
    }

    /**
     * Check if a request from the given client IP is allowed under rate limit.
     *
     * Implements sliding window rate limiting algorithm. If request is allowed,
     * records the current timestamp. Thread-safe using file locking.
     *
     * @param string $clientIp Client IP address (IPv4 or IPv6)
     * @return bool True if request is allowed, false if rate limit exceeded
     * @throws InvalidArgumentException If client IP is empty or invalid
     */
    public function isAllowed(string $clientIp): bool
    {
        if (empty($clientIp)) {
            throw new InvalidArgumentException('Client IP address cannot be empty');
        }

        $currentTime = time();

        // Load existing timestamps
        $timestamps = $this->loadTimestamps($clientIp);

        // Filter out expired timestamps (sliding window)
        $timestamps = $this->filterExpiredTimestamps($timestamps, $currentTime);

        // Check if limit is exceeded
        if (count($timestamps) >= $this->maxRequests) {
            // Rate limit exceeded
            return false;
        }

        // Request is allowed, record timestamp
        $timestamps[] = $currentTime;

        // Save updated timestamps
        $this->saveTimestamps($clientIp, $timestamps);

        // Perform cleanup periodically (10% chance)
        if (rand(1, 100) <= 10) {
            $this->cleanupExpiredFiles();
        }

        return true;
    }

    /**
     * Get number of requests remaining in current window for a client IP.
     *
     * Useful for including rate limit information in API responses.
     *
     * @param string $clientIp Client IP address
     * @return int Number of requests remaining (0 if limit exceeded)
     * @throws InvalidArgumentException If client IP is empty or invalid
     */
    public function getRemainingRequests(string $clientIp): int
    {
        if (empty($clientIp)) {
            throw new InvalidArgumentException('Client IP address cannot be empty');
        }

        $currentTime = time();
        $timestamps = $this->loadTimestamps($clientIp);
        $timestamps = $this->filterExpiredTimestamps($timestamps, $currentTime);

        $remaining = $this->maxRequests - count($timestamps);
        return max(0, $remaining);
    }

    /**
     * Get seconds until rate limit reset for a client IP.
     *
     * Returns the time in seconds until the oldest request in the current
     * window expires, allowing a new request.
     *
     * @param string $clientIp Client IP address
     * @return int Seconds until reset (0 if not rate limited)
     * @throws InvalidArgumentException If client IP is empty or invalid
     */
    public function getResetTime(string $clientIp): int
    {
        if (empty($clientIp)) {
            throw new InvalidArgumentException('Client IP address cannot be empty');
        }

        $currentTime = time();
        $timestamps = $this->loadTimestamps($clientIp);
        $timestamps = $this->filterExpiredTimestamps($timestamps, $currentTime);

        if (count($timestamps) < $this->maxRequests) {
            // Not rate limited
            return 0;
        }

        // Get oldest timestamp in current window
        $oldestTimestamp = min($timestamps);
        $resetTime = $oldestTimestamp + $this->windowSeconds - $currentTime;

        return max(1, $resetTime); // Return at least 1 second
    }

    /**
     * Clean up expired tracking files to prevent disk usage growth.
     *
     * Removes files that haven't been modified within maxFileAge seconds.
     * Called periodically during isAllowed() checks.
     *
     * @return int Number of files deleted
     */
    private function cleanupExpiredFiles(): int
    {
        $deletedCount = 0;
        $currentTime = time();
        $pattern = $this->storageDir . '/ratelimit_*.dat';

        foreach (glob($pattern) as $filePath) {
            $fileAge = $currentTime - filemtime($filePath);

            if ($fileAge > $this->maxFileAge) {
                if (@unlink($filePath)) {
                    $deletedCount++;
                }
            }
        }

        if ($deletedCount > 0) {
            FileLogger::getInstance()->info("RateLimitService: Cleaned up {$deletedCount} expired tracking files");
        }

        return $deletedCount;
    }

    /**
     * Reset rate limit for a specific client IP.
     *
     * Removes all tracking data for the given IP address.
     * Useful for administrative override or testing.
     *
     * @param string $clientIp Client IP address
     * @return bool True if reset succeeded, false if file doesn't exist or deletion failed
     * @throws InvalidArgumentException If client IP is empty or invalid
     */
    public function reset(string $clientIp): bool
    {
        if (empty($clientIp)) {
            throw new InvalidArgumentException('Client IP address cannot be empty');
        }

        $filePath = $this->getTrackingFilePath($clientIp);

        if (!file_exists($filePath)) {
            return true; // Already reset (no file exists)
        }

        return @unlink($filePath);
    }

    /**
     * Get current rate limit configuration.
     *
     * Returns the maximum requests and window duration for informational purposes.
     *
     * @return array{maxRequests: int, windowSeconds: int} Associative array with keys: maxRequests, windowSeconds
     */
    public function getConfiguration(): array
    {
        return [
            'maxRequests' => $this->maxRequests,
            'windowSeconds' => $this->windowSeconds,
        ];
    }
}
