<?php

namespace App\Support;

/** Strict YouTube URL parsing: only official hosts are accepted and only the 11-char video id is kept. */
final class YouTube
{
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be', 'www.youtu.be'];

    public static function extractId(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
            return $input;
        }
        $parts = parse_url($input);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }
        $path = $parts['path'] ?? '';
        $id = null;
        if (str_ends_with($host, 'youtu.be')) {
            $id = trim($path, '/');
        } elseif ($path === '/watch') {
            parse_str($parts['query'] ?? '', $q);
            $id = is_string($q['v'] ?? null) ? $q['v'] : null;
        } elseif (preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
            $id = $m[2];
        }

        return $id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }
}
