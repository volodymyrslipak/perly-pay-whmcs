<?php

declare(strict_types=1);

namespace PerlyPay\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The module's own words, in English and Ukrainian (lang/english.php,
 * lang/ukrainian.php). Gateway modules have no language loader of their
 * own in WHMCS, so this one picks the file by WHMCS's language name:
 * the client's, else the session's, else the system default. WHMCS
 * calls its Ukrainian translation "ukranian"; both spellings are read.
 * Anything else falls back to English, word by word.
 *
 * Only what clients and admins read is translated. The Gateway Log and
 * refund replies stay in English, like WHMCS's own entries there.
 */
final class Lang
{
    /** @var array<string, array<string, string>> */
    private static array $files = [];

    /**
     * @param array<string, string> $strings
     * @param array<string, string> $fallback
     */
    private function __construct(public readonly string $name, private readonly array $strings, private readonly array $fallback)
    {
    }

    public static function named(?string $language): self
    {
        $name = match (strtolower(trim((string) $language))) {
            'ukrainian', 'ukranian', 'uk', 'uk_ua' => 'ukrainian',
            default => 'english',
        };

        return new self($name, self::file($name), self::file('english'));
    }

    /**
     * The client's language on a client-area page.
     *
     * @param array<string, mixed> $params
     */
    public static function client(array $params = []): self
    {
        $details = is_array($params['clientdetails'] ?? null) ? $params['clientdetails'] : [];
        foreach ([$details['language'] ?? null, $_SESSION['Language'] ?? null, $GLOBALS['CONFIG']['Language'] ?? null] as $choice) {
            if (is_string($choice) && $choice !== '') {
                return self::named($choice);
            }
        }

        return self::named(null);
    }

    /** The signed-in admin's language on an admin page. */
    public static function admin(): self
    {
        foreach ([$_SESSION['adminlang'] ?? null, $GLOBALS['CONFIG']['Language'] ?? null] as $choice) {
            if (is_string($choice) && $choice !== '') {
                return self::named($choice);
            }
        }

        return self::named(null);
    }

    /** The text for a key, with sprintf placeholders filled; the key itself if neither file has it. */
    public function t(string $key, string ...$values): string
    {
        $text = $this->strings[$key] ?? $this->fallback[$key] ?? $key;

        return $values === [] ? $text : vsprintf($text, $values);
    }

    /** @return array<string, string> */
    private static function file(string $name): array
    {
        if (!isset(self::$files[$name])) {
            $path = dirname(__DIR__) . '/lang/' . $name . '.php';
            $strings = is_readable($path) ? require $path : [];
            self::$files[$name] = is_array($strings) ? $strings : [];
        }

        return self::$files[$name];
    }
}
