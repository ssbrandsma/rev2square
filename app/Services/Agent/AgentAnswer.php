<?php

namespace App\Services\Agent;

/**
 * Safe renderer for Revival Agent answers.
 *
 * The agent writes GitHub-flavored Markdown (headings, lists, emphasis,
 * code); rendering only part of that format leaves raw markers visible,
 * so this renderer covers the whole answer subset: ATX headings,
 * bullet/numbered lists, bold/italic, fenced and inline code, quotes,
 * horizontal rules, and bare-URL autolinks. Everything is escaped first —
 * there is no raw HTML passthrough and only http(s) URLs are linked.
 * Citation markers ([S123]) pass through untouched for post-linking.
 */
final class AgentAnswer
{
    public static function render(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        // Extract fenced code blocks first so their contents are never
        // interpreted as Markdown.
        $blocks = [];
        $text = preg_replace_callback(
            '/```(\w*)\n?(.*?)```/s',
            function (array $matches) use (&$blocks): string {
                $index = count($blocks);
                $language = trim($matches[1]) !== '' ? ' language-'.preg_replace('/[^a-z0-9-]/i', '', $matches[1]) : '';
                $blocks[$index] = '<pre class="overflow-x-auto rounded-lg bg-zinc-950 p-3 text-[13px] leading-relaxed text-zinc-100"><code class="font-mono'.$language.'">'
                    .e(rtrim($matches[2], "\n")).'</code></pre>';

                return "ZZAGENTCODE{$index}ZZ";
            },
            $text
        );

        $lines = explode("\n", (string) $text);
        $html = [];
        $paragraph = [];

        $flush = function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>'.self::inline(implode("\n", $paragraph)).'</p>';
                $paragraph = [];
            }
        };

        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flush();
                $i++;

                continue;
            }

            if (preg_match('/^ZZAGENTCODE\d+ZZ$/', $trimmed)) {
                $flush();
                $html[] = $trimmed;
                $i++;

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
                $flush();
                $level = strlen($m[1]) >= 3 ? 'h4' : 'h3';
                $class = $level === 'h3' ? 'text-base font-semibold text-zinc-900' : 'text-sm font-semibold text-zinc-900';
                $html[] = "<{$level} class=\"{$class}\">".self::inline($m[2])."</{$level}>";
                $i++;

                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $flush();
                $html[] = '<hr class="border-zinc-200">';
                $i++;

                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
                $flush();
                $quote = [$m[1]];
                $i++;

                while ($i < $count && preg_match('/^>\s?(.*)$/', trim($lines[$i]), $qm)) {
                    $quote[] = $qm[1];
                    $i++;
                }

                $html[] = '<blockquote class="border-l-2 border-zinc-200 pl-3 text-zinc-700">'.self::inline(implode("\n", $quote)).'</blockquote>';

                continue;
            }

            if (preg_match('/^([*-])\s+(.+)$/', $trimmed, $m)) {
                $flush();
                $items = [$m[2]];
                $i++;

                while ($i < $count && preg_match('/^[*-]\s+(.+)$/', trim($lines[$i]), $lm)) {
                    $items[] = $lm[1];
                    $i++;
                }

                $html[] = '<ul class="list-disc space-y-1 pl-5">'
                    .implode('', array_map(fn (string $item): string => '<li>'.self::inline($item).'</li>', $items))
                    .'</ul>';

                continue;
            }

            if (preg_match('/^\d{1,9}[.)]\s+(.+)$/', $trimmed, $m)) {
                $flush();
                $items = [$m[1]];
                $i++;

                while ($i < $count && preg_match('/^\d{1,9}[.)]\s+(.+)$/', trim($lines[$i]), $lm)) {
                    $items[] = $lm[1];
                    $i++;
                }

                $html[] = '<ol class="list-decimal space-y-1 pl-5">'
                    .implode('', array_map(fn (string $item): string => '<li>'.self::inline($item).'</li>', $items))
                    .'</ol>';

                continue;
            }

            $paragraph[] = $line;
            $i++;
        }

        $flush();

        $rendered = implode("\n", $html);

        return str_replace(
            array_map(fn (int $index): string => "ZZAGENTCODE{$index}ZZ", array_keys($blocks)),
            array_values($blocks),
            $rendered
        );
    }

    private static function inline(string $text): string
    {
        $escaped = e($text);

        // Inline code before emphasis so backticked markers stay literal.
        $escaped = preg_replace_callback(
            '/`([^`\n]+)`/',
            fn (array $matches): string => '<code class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[13px] text-zinc-800">'.$matches[1].'</code>',
            $escaped
        );

        // Bold before italic; markers must wrap non-empty text.
        $escaped = preg_replace_callback(
            '/\*\*([^*\n]+)\*\*/',
            fn (array $matches): string => '<strong>'.$matches[1].'</strong>',
            $escaped
        );
        $escaped = preg_replace_callback(
            '/__([^_\n]+)__/',
            fn (array $matches): string => '<strong>'.$matches[1].'</strong>',
            $escaped
        );
        $escaped = preg_replace_callback(
            '/\*([^*\n]+)\*/',
            fn (array $matches): string => '<em>'.$matches[1].'</em>',
            $escaped
        );
        $escaped = preg_replace_callback(
            '/(?<!\w)_([^_\n]+)_(?!\w)/',
            fn (array $matches): string => '<em>'.$matches[1].'</em>',
            $escaped
        );

        // Auto-link bare http(s) URLs; trailing punctuation stays outside.
        $escaped = preg_replace_callback(
            '/https?:\/\/[^\s<>"\']+/',
            function (array $matches): string {
                $url = rtrim($matches[0], '.,;:!?"\'');
                $trail = substr($matches[0], strlen($url));

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
