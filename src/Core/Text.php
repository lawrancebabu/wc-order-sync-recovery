<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

/**
 * Small text helpers (no WordPress dependency).
 */
final class Text
{
    /**
     * Make untrusted text (ERP response bodies, exception messages) safe to store and display:
     * valid UTF-8, no control characters, bounded length. An invalid byte sequence would make
     * $wpdb->update() fail *after* the ERP already accepted an order, so it is repaired here.
     */
    public static function clean(string $text, int $max = 1000): string
    {
        if (! preg_match('//u', $text)) {
            $text = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($text, 'UTF-8', 'UTF-8')
                : (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
        }

        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max);
        }

        return substr($text, 0, $max);
    }
}
