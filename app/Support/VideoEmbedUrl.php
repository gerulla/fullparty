<?php

namespace App\Support;

final class VideoEmbedUrl
{
    public static function normalize(string $input): ?string
    {
        $parts = parse_url($input);
        if (! $parts || strlen($input) > 2048 || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || preg_match('/[\x00-\x20\x7f\\\\]/', $input)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = trim($parts['path'] ?? '', '/');
        parse_str($parts['query'] ?? '', $query);
        $time = $query['start'] ?? $query['t'] ?? '0';
        if (! is_scalar($time) || ! preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/D', (string) $time, $match)) {
            return null;
        }
        $seconds = (int) ($match[1] ?? 0) * 3600 + (int) ($match[2] ?? 0) * 60 + (int) ($match[3] ?? 0);
        if ($seconds > 864000) {
            return null;
        }
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be'], true)) {
            $id = $host === 'youtu.be' ? $path : ($path === 'watch' ? ($query['v'] ?? '') : (preg_match('~^(?:embed|shorts|live)/([^/]+)$~D', $path, $found) ? $found[1] : ''));

            return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? 'https://www.youtube.com/watch?v='.$id.($seconds ? '&start='.$seconds : '') : null;
        }
        if ($host === 'clips.twitch.tv' && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $path)) {
            return 'https://clips.twitch.tv/'.$path;
        }
        if (in_array($host, ['twitch.tv', 'www.twitch.tv', 'm.twitch.tv'], true)) {
            if (preg_match('~^videos/([0-9]{1,20})$~D', $path, $found)) {
                return 'https://www.twitch.tv/videos/'.$found[1].($seconds ? '?t='.$seconds.'s' : '');
            }
            if (preg_match('~^[A-Za-z0-9_]+/clip/([A-Za-z0-9_-]{1,100})$~D', $path, $found)) {
                return 'https://clips.twitch.tv/'.$found[1];
            }
            if (preg_match('/^[A-Za-z0-9_]{1,25}$/D', $path) && ! in_array(strtolower($path), ['directory', 'downloads', 'settings', 'subscriptions', 'inventory', 'wallet', 'search'], true)) {
                return 'https://www.twitch.tv/'.strtolower($path);
            }
        }

        return null;
    }
}
