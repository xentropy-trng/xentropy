# xentropy
A true random number generator written in PHP that uses entropy extracted from the timestamps of X posts

Entropy Collection: Queries the xAI Live Search API (API key required) for posts in the last 60 seconds (e.g., 2025-07-10T04:08:09Z to 2025-07-10T04:09:09Z for 10:09 PM CDT). Extracts created_at timestamps, converts to nanoseconds, and takes the lower 32 bits (~4–8 bits of entropy each). Aggregates ~50 timestamps, hashes with SHA-256, and truncates to a 128-bit seed.<br/><br/>
Randomization: Seeds a custom LCG with the first 8 bytes of the hashed entropy. The LCG (xn+1=(1664525⋅xn+1013904223)mod  232x_{n+1} = (1664525 \cdot x_n + 1013904223) \mod 2^{32}x_{n+1} = (1664525 \cdot x_n + 1013904223) \mod 2^{32}
) generates a 32-bit number, mapped to $min–$max via modulo.<br/><br/>
Fallback: Uses microtime (time-based, not random) if API fails, mixed with prior entropy to maintain randomness.<br/><br/>
Logging: Writes to /var/log/xentropy.log for debugging (e.g., API responses, generated numbers).<br/><br/>
No Built-in Random Functions: Uses only hash(), pack(), and LCG for randomness, deriving all entropy from X timestamps.<br/><br/>
Global Function: gen_rnd_from_x($min, $max) lazily instantiates XEntropy and returns a random integer.

Find more of my work on X (@h45hb4ng) or morallyrelative.com


