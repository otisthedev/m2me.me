<?php
declare(strict_types=1);

namespace MatchMe\Quiz;

/**
 * Generates secure, unguessable share tokens.
 */
final class ShareTokenGenerator
{
    private const TOKEN_LENGTH = 32;
    private const BASE62_CHARS = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Generate a new share token.
     */
    public function generate(): string
    {
        // Generate base62 with unbiased selection via rejection sampling.
        // We accept bytes < 248 (62 * 4) so that (byte % 62) is uniform.
        $token = '';

        while (strlen($token) < self::TOKEN_LENGTH) {
            $bytes = random_bytes(64);
            $len = strlen($bytes);
            for ($i = 0; $i < $len && strlen($token) < self::TOKEN_LENGTH; $i++) {
                $b = ord($bytes[$i]);
                if ($b >= 248) {
                    continue;
                }
                $token .= self::BASE62_CHARS[$b % 62];
            }
        }

        return $token;
    }
}


