<?php

/**
 * XEntropy class to collect entropy from X platform posts and generate true random numbers.
 * xAI API key is hardcoded for simplicity; store securely to prevent exposure.
 */
class XEntropy {
    private $apiKey = 'your-xai-api-key'; // Replace with your actual xAI API key
    private $baseUrl = 'https://api.x.ai/v1/search';
    private $query = '*'; // Broad query to capture all posts
    private $maxResults = 50; // API limit per request
    private $rateLimitDelay = 1.2; // Seconds between requests (50 RPM)
    private $entropyBits = 128; // Target entropy
    private $logFile = '/var/log/xentropy.log'; // Log file path
    private $lcgState; // LCG state for randomization

    /**
     * Constructor to initialize with hardcoded API key.
     */
    public function __construct() {
        if (empty($this->apiKey) || $this->apiKey === 'your-xai-api-key') {
            throw new Exception('xAI API key is not set or invalid');
        }
        $this->setupLogging();
    }

    /**
     * Set up logging to file.
     */
    private function setupLogging() {
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
            chmod($this->logFile, 0664);
        }
        $this->log('XEntropy initialized');
    }

    /**
     * Log a message to file.
     * @param string $message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    /**
     * Make API request to xAI Live Search.
     * @param int $startTime Unix timestamp (seconds) for query start
     * @param int $endTime Unix timestamp (seconds) for query end
     * @return array Timestamps (lower 32 bits)
     */
    private function getPostTimestamps($startTime, $endTime) {
        $payload = [
            'query' => $this->query,
            'search_parameters' => [
                'domain_list' => ['x.com'],
                'date_range' => [
                    'from' => $startTime,
                    'to' => $endTime
                ],
                'max_results' => $this->maxResults
            ]
        ];

        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->apiKey}",
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->log("API request failed with code $httpCode: $response");
            throw new Exception("API request failed");
        }

        $data = json_decode($response, true);
        $posts = $data['results'] ?? [];

        $timestamps = [];
        foreach ($posts as $post) {
            if (isset($post['created_at'])) {
                // Convert ISO 8601 to nanoseconds
                $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $post['created_at']);
                if ($dt) {
                    $ns = (int)($dt->getTimestamp() * 1_000_000_000 + $dt->format('u') * 1000);
                    // Use lower 32 bits for entropy
                    $timestamps[] = $ns & 0xFFFFFFFF;
                }
            }
        }

        $this->log("Retrieved " . count($timestamps) . " timestamps");
        return $timestamps;
    }

    /**
     * Collect entropy from X post timestamps.
     * @return string 128-bit (16-byte) entropy seed
     */
    private function collectEntropy() {
        $entropy = '';
        $bytesNeeded = $this->entropyBits / 8; // 16 bytes for 128 bits

        while (strlen($entropy) < $bytesNeeded) {
            try {
                // Query last 60 seconds (UTC)
                $endTime = time();
                $startTime = $endTime - 60;
                $timestamps = $this->getPostTimestamps($startTime, $endTime);

                if (empty($timestamps)) {
                    $this->log("No timestamps received, retrying");
                    usleep((int)($this->rateLimitDelay * 1_000_000));
                    continue;
                }

                foreach ($timestamps as $ts) {
                    $entropy .= pack('N', $ts); // 4 bytes per timestamp
                }

                // Respect rate limit
                usleep((int)($this->rateLimitDelay * 1_000_000));
            } catch (Exception $e) {
                $this->log("Error collecting entropy: " . $e->getMessage());
                // Fallback: use current microtime (not random, but time-based)
                $mt = (int)(microtime(true) * 1_000_000);
                $entropy .= pack('N', $mt & 0xFFFFFFFF);
                usleep((int)($this->rateLimitDelay * 1_000_000));
            }
        }

        // Hash to ensure uniform distribution and truncate to 128 bits
        $seed = hash('sha256', $entropy, true);
        $seed = substr($seed, 0, 16); // 16 bytes = 128 bits

        $this->log("Generated 128-bit entropy seed");
        return $seed;
    }

    /**
     * Initialize LCG with entropy seed.
     * @param string $seed 16-byte seed
     */
    private function initLCG($seed) {
        // Convert seed to 64-bit integer (first 8 bytes)
        $state = 0;
        for ($i = 0; $i < 8; $i++) {
            $state = ($state << 8) | ord($seed[$i]);
        }
        $this->lcgState = $state & 0x7FFFFFFFFFFFFFFF; // Ensure positive 64-bit
        $this->log("LCG initialized with state: $this->lcgState");
    }

    /**
     * Generate next LCG random number.
     * @return int Random number (32-bit)
     */
    private function nextLCG() {
        // LCG parameters: a = 1664525, c = 1013904223, m = 2^32
        $a = 1664525;
        $c = 1013904223;
        $m = 1 << 32; // 2^32
        $this->lcgState = ($a * $this->lcgState + $c) % $m;
        return $this->lcgState & 0xFFFFFFFF; // 32-bit output
    }

    /**
     * Generate a random number between $min and $max (inclusive) using X entropy.
     * @param int $min Minimum value
     * @param int $max Maximum value
     * @return int Random number
     * @throws Exception If min > max or range too large
     */
    public function genRndFromX($min, $max) {
        if ($min > $max) {
            throw new Exception("Minimum value cannot be greater than maximum");
        }

        // Get entropy seed and initialize LCG
        $seed = $this->collectEntropy();
        $this->initLCG($seed);

        // Generate random number and map to range [$min, $max]
        $range = $max - $min + 1;
        if ($range <= 0) {
            throw new Exception("Range too large for random number generation");
        }

        $random = $this->nextLCG();
        $result = $min + ($random % $range);

        $this->log("Generated random number: $result (range: $min-$max)");
        return $result;
    }
}

// Global function for external use
/**
 * Generate a random number using X entropy.
 * @param int $min Minimum value
 * @param int $max Maximum value
 * @return int Random number
 * @throws Exception If API key invalid or range error
 */
function gen_rnd_from_x($min, $max) {
    static $xEntropy = null;
    if ($xEntropy === null) {
        $xEntropy = new XEntropy();
    }
    return $xEntropy->genRndFromX($min, $max);
}

?>
