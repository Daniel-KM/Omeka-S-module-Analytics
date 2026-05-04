<?php declare(strict_types=1);

namespace Analytics\Stdlib;

/**
 * Pure-string helpers to inspect and update the Apache .htaccess for the
 * download tracking rule of the module Analytics.
 *
 * No Omeka services involved: takes .htaccess content as a string and returns
 * data or a new string. This makes the rewrite logic unit-testable.
 */
class HtaccessManager
{
    public const MARKER = '# Module Analytics: count downloads.';
    public const MARKER_END = '# /Module Analytics: count downloads.';
    public const ACCESS_MARKER = '# Module Access: protect files.';
    public const MANAGED_COMMENT = '# This rule is automatically managed by the module.';
    public const STANDARD_TYPES = ['original', 'large', 'medium', 'square'];

    /**
     * Extract the file types covered by the Access module rewrite rule.
     */
    public function parseAccessTypes(string $htaccess): array
    {
        // Match any active (non-commented) RewriteRule that sends ^files/(...)
        // to /access/files/. Marker is not required: deployments may use a
        // custom marker comment.
        $types = [];
        if (preg_match_all(
            '/^[ \t]*RewriteRule\s+"?\^files\/\(([^)]+)\)\/[^"\s]*"?\s+"?\/?access\/files\//m',
            $htaccess,
            $matches
        )) {
            foreach ($matches[1] as $group) {
                $types = array_merge($types, explode('|', $group));
            }
        }
        // Per-type form (without alternation).
        if (preg_match_all(
            '/^[ \t]*RewriteRule\s+"?\^files\/(' . implode('|', self::STANDARD_TYPES) . ')\/[^"\s]*"?\s+"?\/?access\/files\//m',
            $htaccess,
            $matches
        )) {
            $types = array_merge($types, $matches[1]);
        }
        return array_values(array_unique(array_filter($types)));
    }

    /**
     * Extract the file types from the managed Analytics rule (with marker).
     */
    public function parseManagedAnalyticsTypes(string $htaccess): array
    {
        if (strpos($htaccess, self::MARKER) === false) {
            return [];
        }
        $regex = '/' . preg_quote(self::MARKER, '/')
            . '\s*\n(?:\s*#[^\n]*\n)*\s*RewriteRule\s+"\^files\/\(([^)]+)\)\//';
        if (preg_match($regex, $htaccess, $m)) {
            return array_values(array_filter(explode('|', $m[1])));
        }
        return [];
    }

    /**
     * Extract types from legacy (unmanaged) rules redirecting to
     * /download/files/.
     *
     * @return array{types: string[], found: bool}
     */
    public function parseLegacyAnalyticsTypes(string $htaccess): array
    {
        if (strpos($htaccess, self::MARKER) !== false) {
            return ['types' => [], 'found' => false];
        }
        $types = [];
        $found = false;
        if (preg_match_all('/^\s*RewriteRule\s+.*files\/\(([^)]+)\).*\/download\/files\//m', $htaccess, $matches)) {
            foreach ($matches[1] as $group) {
                $types = array_merge($types, explode('|', $group));
            }
            $found = true;
        }
        $standard = self::STANDARD_TYPES;
        if (preg_match_all('/^\s*RewriteRule\s+["\^]*files\/(' . implode('|', $standard) . ')\/.*\/download\/files\//m', $htaccess, $matches)) {
            $types = array_merge($types, $matches[1]);
            $found = true;
        }
        $types = array_values(array_unique(array_intersect($types, $standard)));
        return ['types' => $types, 'found' => $found];
    }

    /**
     * Compute the analytics rule types after removing those handled by Access.
     *
     * @param string[] $requested
     * @param string[] $accessTypes
     * @return string[]
     */
    public function computeAnalyticsTypes(array $requested, array $accessTypes): array
    {
        return array_values(array_diff(array_values(array_unique($requested)), $accessTypes));
    }

    /**
     * Compute the types absorbed by the Access rule (intersection).
     *
     * @param string[] $requested
     * @param string[] $accessTypes
     * @return string[]
     */
    public function computeAbsorbedTypes(array $requested, array $accessTypes): array
    {
        return array_values(array_intersect(array_values(array_unique($requested)), $accessTypes));
    }

    /**
     * Build the managed Analytics rule block for the given types.
     *
     * Returns an empty string if no types are provided.
     */
    public function buildBlock(array $types): string
    {
        if (empty($types)) {
            return '';
        }
        return self::MARKER . "\n"
            . self::MANAGED_COMMENT . "\n"
            . 'RewriteRule "^files/(' . implode('|', $types) . ')/(.*)$" "download/files/$1/$2" [NC,L]' . "\n"
            . self::MARKER_END;
    }

    /**
     * Apply the new Analytics block to the htaccess content.
     *
     * Removes any existing managed block (preferring bounded removal between
     * start and end markers when both are present, falling back to the legacy
     * "first RewriteRule after marker" heuristic for blocks written before the
     * end marker existed) and any legacy unmanaged rules redirecting to
     * /download/files/, then inserts the new block (if any) right after the
     * "RewriteEngine On" directive.
     *
     * @param string $htaccess Original .htaccess content.
     * @param string[] $analyticsTypes Types for which to write the rule.
     * @param bool $hasLegacyRule Whether legacy rules were detected.
     */
    public function apply(string $htaccess, array $analyticsTypes, bool $hasLegacyRule = false): string
    {
        $hasStart = strpos($htaccess, self::MARKER) !== false;
        $hasEnd = strpos($htaccess, self::MARKER_END) !== false;
        if ($hasStart && $hasEnd) {
            // Bounded removal between explicit markers: cannot accidentally
            // swallow neighbouring rules.
            $htaccess = preg_replace(
                '/'
                . preg_quote(self::MARKER, '/')
                . '.*?'
                . preg_quote(self::MARKER_END, '/')
                . '\s*\n?/s',
                '',
                $htaccess
            );
        } elseif ($hasStart) {
            // Legacy block (no end marker): remove the start marker plus the
            // first RewriteRule that follows.
            $htaccess = preg_replace(
                '/' . preg_quote(self::MARKER, '/')
                . '\s*\n(?:\s*#[^\n]*\n)*\s*RewriteRule\s+"[^"]*"\s+"[^"]*"\s+\[[^\]]*\]\s*\n?/',
                '',
                $htaccess
            );
        }

        // Remove legacy rules (no marker at all).
        if ($hasLegacyRule) {
            $htaccess = preg_replace(
                '/^\s*RewriteRule\s+.*files\/\([^)]+\).*\/download\/files\/.*\n?/m',
                '',
                $htaccess
            );
            $htaccess = preg_replace(
                '/^\s*RewriteRule\s+.*files\/(' . implode('|', self::STANDARD_TYPES) . ')\/.*\/download\/files\/.*\n?/m',
                '',
                $htaccess
            );
        }

        // Insert new block after "RewriteEngine On".
        $newBlock = $this->buildBlock($analyticsTypes);
        if ($newBlock !== '' && preg_match('/RewriteEngine\s+On\s*\n/', $htaccess, $m, PREG_OFFSET_CAPTURE)) {
            $insertPos = $m[0][1] + strlen($m[0][0]);
            $htaccess = substr_replace($htaccess, "\n" . $newBlock . "\n\n", $insertPos, 0);
        }

        return $htaccess;
    }
}
