<?php

namespace App\Services\Discussion;

/**
 * Minimal safe markup for discussion posts and chat messages: paragraphs,
 * auto-linked URLs, fenced and inline code. Everything else is escaped;
 * there is no raw HTML passthrough.
 */
class Markup
{
    public static function render(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));

        // Extract fenced code blocks first so their contents are never
        // linkified or split into paragraphs.
        $blocks = [];
        $body = preg_replace_callback(
            '/```(\w*)\n?(.*?)```/s',
            function (array $matches) use (&$blocks): string {
                $index = count($blocks);
                $language = trim($matches[1]) !== '' ? ' language-'.preg_replace('/[^a-z0-9-]/i', '', $matches[1]) : '';
                $blocks[$index] = '<pre class="overflow-x-auto rounded-lg bg-zinc-950 p-3 text-[13px] leading-relaxed text-zinc-100"><code class="font-mono'.$language.'">'
                    .e(rtrim($matches[2], "\n")).'</code></pre>';

                return "ZZCODEBLOCK{$index}ZZ";
            },
            $body
        );

        $paragraphs = preg_split('/\n\s*\n/', $body) ?: [];

        $html = collect($paragraphs)
            ->map(fn (string $paragraph): string => trim($paragraph))
            ->filter()
            ->map(function (string $paragraph): string {
                // A paragraph that is only a code placeholder stays block-level.
                if (preg_match('/^ZZCODEBLOCK\d+ZZ$/', $paragraph)) {
                    return $paragraph;
                }

                return '<p>'.self::inline($paragraph).'</p>';
            })
            ->implode("\n");

        return str_replace(
            array_map(fn (int $index): string => "ZZCODEBLOCK{$index}ZZ", array_keys($blocks)),
            array_values($blocks),
            $html
        );
    }

    public static function excerpt(string $body, int $length = 140): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }

    private static function inline(string $text): string
    {
        $escaped = e($text);

        // Inline code before links so backticked URLs stay literal.
        $escaped = preg_replace_callback(
            '/`([^`\n]+)`/',
            fn (array $matches): string => '<code class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[13px] text-zinc-800">'.$matches[1].'</code>',
            $escaped
        );

        // Auto-link bare http(s) URLs; trailing punctuation stays outside.
        $escaped = preg_replace_callback(
            '/https?:\/\/[^\s<>"\']+/',
            function (array $matches): string {
                $url = rtrim($matches[0], '.,;:!?"\'');
                $trail = substr($matches[0], strlen($url));

                // Keep balanced parentheses inside the URL (wiki links);
                // otherwise a closing paren ends the surrounding sentence.
                while (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
                    $url = substr($url, 0, -1);
                    $trail = ')'.$trail;
                }

                $label = mb_strlen($url) > 60 ? mb_substr($url, 0, 57).'…' : $url;

                return '<a href="'.e($url).'" target="_blank" rel="noopener nofollow" class="font-medium text-emerald-700 hover:text-emerald-600">'.e($label).'</a>'.e($trail);
            },
            $escaped
        );

        return nl2br($escaped, false);
    }
}
