<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

/**
 * An engine error as an operator may see it (search block spec §3.8): credentials and hosts in a URL
 * or DSN become `[redacted]`, and the text is cut to a readable length.
 */
final class ErrorText
{
    public static function sanitize(string $message): string
    {
        $clean = (string) preg_replace('#\b[a-z][a-z0-9+.-]*://[^\s]+#i', '[redacted]', $message);
        $clean = (string) preg_replace(
            '#\b(?:password|passwd|pwd|api[_-]?key|token|secret)\s*[=:]\s*\S+#i',
            '[redacted]',
            $clean,
        );
        $clean = (string) preg_replace('#\bBearer\s+\S+#i', 'Bearer [redacted]', $clean);
        return mb_substr(trim($clean), 0, 500);
    }
}
