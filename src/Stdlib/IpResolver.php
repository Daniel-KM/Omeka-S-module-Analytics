<?php declare(strict_types=1);

namespace Analytics\Stdlib;

/**
 * Resolve the real client IP from server variables, honoring proxy headers only
 * when the direct peer is a trusted reverse proxy.
 */
class IpResolver
{
    /** @var string[] Trusted IPs (exact). */
    private array $trustedIps = [];

    /** @var array<int, array{0: string, 1: int, 2: int}> Trusted CIDR ranges: [packed, prefix, family]. */
    private array $trustedRanges = [];

    /**
     * @param string $trustedProxies IPs or CIDR (space, comma or semicolon separated).
     */
    public function __construct(string $trustedProxies = '')
    {
        $tokens = array_filter(array_map('trim', preg_split('/[\s,;]+/', $trustedProxies)));
        foreach ($tokens as $tok) {
            if (strpos($tok, '/') !== false) {
                [$net, $prefix] = explode('/', $tok, 2);
                $packed = @inet_pton($net);
                if ($packed === false) {
                    continue;
                }
                $family = strlen($packed) === 4 ? 4 : 6;
                $prefix = (int) $prefix;
                $maxPrefix = $family === 4 ? 32 : 128;
                if ($prefix < 0 || $prefix > $maxPrefix) {
                    continue;
                }
                $this->trustedRanges[] = [$packed, $prefix, $family];
            } elseif (filter_var($tok, FILTER_VALIDATE_IP)) {
                $this->trustedIps[] = $tok;
            }
        }
    }

    /**
     * Resolve the client IP from a $_SERVER-like array.
     *
     * Returns '::' if no valid IP can be resolved.
     */
    public function resolve(array $server): string
    {
        $remote = $server['REMOTE_ADDR'] ?? '';
        if (!$this->isValidIp($remote)) {
            return '::';
        }

        if ($this->isTrusted($remote)) {
            $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                // The leftmost IP is the original client; subsequent IPs are
                // proxies. Walk left-to-right and skip trusted hops.
                $chain = array_map('trim', explode(',', $forwarded));
                foreach ($chain as $candidate) {
                    if ($this->isValidIp($candidate) && !$this->isTrusted($candidate)) {
                        return $candidate;
                    }
                }
                // Whole chain is trusted: take the leftmost valid IP.
                foreach ($chain as $candidate) {
                    if ($this->isValidIp($candidate)) {
                        return $candidate;
                    }
                }
            }
            $real = $server['HTTP_X_REAL_IP'] ?? '';
            if ($real !== '' && $this->isValidIp($real)) {
                return $real;
            }
        }

        return $remote;
    }

    public function isTrusted(string $ip): bool
    {
        if (in_array($ip, $this->trustedIps, true)) {
            return true;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $family = strlen($packed) === 4 ? 4 : 6;
        foreach ($this->trustedRanges as [$net, $prefix, $rangeFamily]) {
            if ($rangeFamily !== $family) {
                continue;
            }
            if ($this->cidrMatch($packed, $net, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function isValidIp(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function cidrMatch(string $a, string $b, int $prefix): bool
    {
        if ($prefix === 0) {
            return true;
        }
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $mask = chr(0xFF << (8 - $bits) & 0xFF);
        return (($a[$bytes] & $mask) === ($b[$bytes] & $mask));
    }
}
