<?php

declare(strict_types=1);

function clientAddress(): ?string
{
    // Vercel overwrites X-Forwarded-For at its edge. The marker is a runtime
    // environment variable, never a request header. Other hosts use the peer
    // address already resolved by the web server's trusted-proxy configuration.
    if (getenv('VERCEL') === '1') {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if (is_string($forwarded) && filter_var(trim($forwarded), FILTER_VALIDATE_IP) !== false) {
            return trim($forwarded);
        }
    }
    $peer = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($peer) && filter_var($peer, FILTER_VALIDATE_IP) !== false ? $peer : null;
}
