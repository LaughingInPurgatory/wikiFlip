<?php

declare(strict_types=1);

namespace WikiApp\Core;

require_once dirname(__DIR__) . '/lib/Parsedown.php';

/**
 * Markdown helpers: relative content.md parsing and HTML rendering.
 */
final class Markdown
{
    /**
     * Split content.md into title + body (format: first line is # Title).
     *
     * @return array{title: string, body: string}
     */
    public static function parseDocument(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $title = '';
        $body = $markdown;

        if (preg_match('/\A\s*#\s+(.+?)\s*\n(.*)\z/s', $markdown, $m)) {
            $title = trim($m[1]);
            $body = ltrim($m[2], "\n");
        } elseif (preg_match('/\A\s*#\s+(.+?)\s*\z/', trim($markdown), $m)) {
            $title = trim($m[1]);
            $body = '';
        }

        return ['title' => $title, 'body' => $body];
    }

    /**
     * Build content.md from title + markdown body.
     */
    public static function buildDocument(string $title, string $body): string
    {
        $title = trim(str_replace(["\r", "\n"], ' ', $title));
        if ($title === '') {
            $title = 'Untitled';
        }
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = ltrim($body, "\n");
        // Avoid duplicating leading H1 that matches title
        if (preg_match('/\A#\s+(.+?)\s*\n/s', $body, $m) && trim($m[1]) === $title) {
            $body = (string) preg_replace('/\A#\s+.+?\s*\n+/s', '', $body, 1);
        }
        if ($body === '') {
            return '# ' . $title . "\n";
        }
        return '# ' . $title . "\n\n" . rtrim($body) . "\n";
    }

    /**
     * Render Markdown body to HTML (allows limited HTML for PDF embeds).
     */
    public static function toHtml(string $markdown): string
    {
        $pd = new \Parsedown();
        $pd->setBreaksEnabled(true);
        $pd->setSafeMode(true);
        $pd->setMarkupEscaped(true);
        return $pd->text($markdown);
    }

    /**
     * Full pipeline: MD → HTML, rewrite wiki links + page-relative media.
     */
    public static function renderPageBody(string $markdown, string $pageSlug): string
    {
        $embeds = [];
        $markdown = self::protectPdfEmbeds($markdown, $pageSlug, $embeds);
        $html = self::toHtml($markdown);
        foreach ($embeds as $token => $embed) {
            $pattern = '~<p>\\s*' . preg_quote($token, '~') . '\\s*</p>~';
            $html = preg_replace($pattern, $embed, $html, 1) ?? $html;
        }
        $html = self::rewriteWikiLinks($html);
        $html = self::rewriteRelativeMedia($html, $pageSlug);
        return $html;
    }

    /**
     * Preserve only editor-generated, page-local PDF embeds before safe parsing.
     * All other raw HTML remains escaped by Parsedown safe mode.
     *
     * @param array<string, string> $embeds
     */
    private static function protectPdfEmbeds(string $markdown, string $pageSlug, array &$embeds): string
    {
        return preg_replace_callback(
            "~<div\\b[^>]*\\bclass\\s*=\\s*([\"'])[^\"']*\\bpdf-embed\\b[^\"']*\\1[^>]*>.*?</div>~is",
            static function (array $match) use ($pageSlug, &$embeds): string {
                if (!preg_match("~<iframe\\b[^>]*\\bsrc\\s*=\\s*([\"'])(.*?)\\1~is", $match[0], $srcMatch)) {
                    return $match[0];
                }

                $file = self::pdfFileForPage($srcMatch[2], $pageSlug);
                if ($file === null) {
                    return $match[0];
                }

                $token = 'WIKIFLIP_PDF_' . bin2hex(random_bytes(12));
                $mediaUrl = url(
                    'media.php?slug=' . rawurlencode(DatabaseManager::sanitizeSlug($pageSlug))
                    . '&file=' . rawurlencode($file)
                );
                $safeUrl = htmlspecialchars($mediaUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safeTitle = htmlspecialchars(basename($file), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $embeds[$token] = '<div class="pdf-embed">'
                    . '<iframe class="pdf-frame" src="' . $safeUrl . '#view=FitH" title="' . $safeTitle . '"></iframe>'
                    . '<p class="pdf-embed-actions"><a href="' . $safeUrl . '" target="_blank" rel="noopener">Open PDF</a> · '
                    . '<a href="' . $safeUrl . '" download>Download</a></p></div>';
                return "\n\n{$token}\n\n";
            },
            $markdown
        ) ?? $markdown;
    }

    private static function pdfFileForPage(string $url, string $pageSlug): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $file = self::mediaUrlToRelativeFile($url);
        if ($file === null) {
            $file = explode('?', explode('#', $url, 2)[0], 2)[0];
            if (!preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\\.pdf$#i', $file)) {
                return null;
            }
        }
        if (!str_ends_with(strtolower($file), '.pdf')) {
            return null;
        }
        return DatabaseManager::resolveMediaFile($pageSlug, $file) === null ? null : $file;
    }

    /**
     * Rewrite internal wiki hrefs to app-rooted URLs (respects base path).
     * Handles: ?slug=x | /?slug=x | index.php?slug=x
     */
    public static function rewriteWikiLinks(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        return preg_replace_callback(
            '/\bhref\s*=\s*(["\'])([^"\']+)\1/i',
            static function (array $m): string {
                $q = $m[1];
                $href = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // ?slug=guides  or  /?slug=guides  or  index.php?slug=guides
                if (preg_match('#^(?:/)?(?:index\.php)?\?slug=([a-z0-9\-]+)$#i', $href, $sm)) {
                    return 'href=' . $q . page_url($sm[1]) . $q;
                }

                // Bare app paths that are only a slug (optional clean URL form)
                if (preg_match('#^/([a-z0-9]+(?:-[a-z0-9]+)*)$#i', $href, $sm)) {
                    $slug = DatabaseManager::sanitizeSlug($sm[1]);
                    // Don't rewrite real asset/admin paths
                    if (!in_array($slug, ['admin', 'assets', 'media', 'src'], true)) {
                        return 'href=' . $q . page_url($slug) . $q;
                    }
                }

                return $m[0];
            },
            $html
        ) ?? $html;
    }

    /**
     * Rewrite relative *media* paths in rendered HTML to public media URLs.
     * Only rewrites file-like paths (images/PDFs), never wiki links like ?slug=…
     */
    public static function rewriteRelativeMedia(string $html, string $pageSlug): string
    {
        $pageSlug = DatabaseManager::sanitizeSlug($pageSlug);
        if ($pageSlug === '' || $html === '') {
            return $html;
        }

        $rewrite = static function (string $url) use ($pageSlug): string {
            $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url === '') {
                return $url;
            }

            $fragment = '';
            if (str_contains($url, '#')) {
                [$url, $frag] = explode('#', $url, 2);
                $fragment = '#' . $frag;
            }

            // Already a media.php URL — rebuild cleanly (and keep fragment for PDF viewers)
            $fromMedia = self::mediaUrlToRelativeFile($url . $fragment);
            if ($fromMedia !== null && preg_match('~media\.php~i', $url)) {
                // Prefer current page slug when URL slug matches or is missing
                $slug = $pageSlug;
                if (preg_match('~[?&]slug=([a-z0-9\-]+)~i', $url, $sm)) {
                    $slug = DatabaseManager::sanitizeSlug($sm[1]) ?: $pageSlug;
                }
                $out = url('media.php?slug=' . rawurlencode($slug) . '&file=' . rawurlencode($fromMedia));
                if (str_ends_with(strtolower($fromMedia), '.pdf') && $fragment === '') {
                    $fragment = '#view=FitH';
                }
                return $out . $fragment;
            }

            // Leave other absolute / scheme / wiki links alone
            if (str_starts_with($url, '#')
                || str_starts_with($url, '?')
                || str_starts_with($url, 'data:')
                || str_starts_with($url, 'blob:')
                || str_starts_with($url, 'mailto:')
                || str_starts_with($url, 'tel:')
                || str_starts_with($url, 'http://')
                || str_starts_with($url, 'https://')
                || str_starts_with($url, '//')
                || str_starts_with($url, '/')) {
                return $url . $fragment;
            }

            // strip ./
            $url = preg_replace('#^\./#', '', $url) ?? $url;
            if (str_contains($url, '..')) {
                return $url . $fragment;
            }

            // Only treat as media when it has a media file extension
            $pathOnly = explode('?', $url, 2)[0];
            if (!preg_match('/\.(png|jpe?g|gif|webp|svg|pdf|bmp|ico)$/i', $pathOnly)) {
                return $url . $fragment;
            }

            $parts = explode('/', $pathOnly);
            foreach ($parts as $p) {
                if ($p === '' || $p === '.' || $p === '..') {
                    return $url . $fragment;
                }
            }

            if (str_ends_with(strtolower($pathOnly), '.pdf') && $fragment === '') {
                $fragment = '#view=FitH';
            }

            return url('media.php?slug=' . rawurlencode($pageSlug) . '&file=' . rawurlencode($pathOnly)) . $fragment;
        };

        $html = preg_replace_callback(
            '/\b(src|href)\s*=\s*(["\'])([^"\']+)\2/i',
            static function (array $m) use ($rewrite): string {
                $new = $rewrite($m[3]);
                return $m[1] . '=' . $m[2] . $new . $m[2];
            },
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Best-effort HTML → Markdown for migrating old page.json HTML content.
     */
    public static function htmlToMarkdown(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        // If it doesn't look like HTML, treat as already markdown
        if (!preg_match('/<[a-z][\s\S]*>/i', $html)) {
            return $html;
        }

        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        // PDF embeds → keep as HTML block in markdown
        $html = preg_replace_callback(
            '/<div[^>]*class=["\'][^"\']*pdf-embed[^"\']*["\'][^>]*>.*?<\/div>/is',
            static function (array $m): string {
                return "\n\n" . $m[0] . "\n\n";
            },
            $html
        ) ?? $html;

        // Images
        $html = preg_replace_callback(
            '/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
            static function (array $m): string {
                $src = $m[1];
                $alt = '';
                if (preg_match('/\balt=["\']([^"\']*)["\']/', $m[0], $a)) {
                    $alt = $a[1];
                }
                // Prefer basename for relative style when under uploads
                if (preg_match('#/assets/uploads/([^/?#]+)#', $src, $u)) {
                    $src = $u[1];
                }
                return '![' . $alt . '](' . $src . ')';
            },
            $html
        ) ?? $html;

        // Links
        $html = preg_replace_callback(
            '/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            static function (array $m): string {
                $text = trim(strip_tags($m[2]));
                return '[' . $text . '](' . $m[1] . ')';
            },
            $html
        ) ?? $html;

        $replacements = [
            '/<h1[^>]*>(.*?)<\/h1>/is' => "# $1\n\n",
            '/<h2[^>]*>(.*?)<\/h2>/is' => "## $1\n\n",
            '/<h3[^>]*>(.*?)<\/h3>/is' => "### $1\n\n",
            '/<h4[^>]*>(.*?)<\/h4>/is' => "#### $1\n\n",
            '/<blockquote[^>]*>(.*?)<\/blockquote>/is' => "> $1\n\n",
            '/<strong[^>]*>(.*?)<\/strong>/is' => '**$1**',
            '/<b[^>]*>(.*?)<\/b>/is' => '**$1**',
            '/<em[^>]*>(.*?)<\/em>/is' => '*$1*',
            '/<i[^>]*>(.*?)<\/i>/is' => '*$1*',
            '/<code[^>]*>(.*?)<\/code>/is' => '`$1`',
            '/<li[^>]*>(.*?)<\/li>/is' => "- $1\n",
            '/<\/?ul[^>]*>/i' => "\n",
            '/<\/?ol[^>]*>/i' => "\n",
            '/<br\s*\/?>/i' => "\n",
            '/<\/p>/i' => "\n\n",
            '/<p[^>]*>/i' => '',
            '/<\/div>/i' => "\n",
            '/<div[^>]*>/i' => '',
            '/<\/?span[^>]*>/i' => '',
            '/&nbsp;/i' => ' ',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html) ?? $html;
        }

        $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;
        return trim($html);
    }

    /**
     * Extract filename from a media.php URL or path (strips query/fragment).
     */
    public static function mediaUrlToRelativeFile(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // media.php?slug=x&file=y.pdf  (optional #fragment)
        if (preg_match('~media\.php\?([^#\s"\']+)~i', $url, $m)) {
            parse_str($m[1], $q);
            $file = (string) ($q['file'] ?? '');
            $file = rawurldecode($file);
            $file = explode('#', $file, 2)[0];
            return $file !== '' ? $file : null;
        }
        // /assets/uploads/file.ext
        if (preg_match('~/assets/uploads/([^/?#]+)~i', $url, $m)) {
            return rawurldecode($m[1]);
        }
        return null;
    }

    /**
     * Normalize markdown before save: absolute media URLs → relative filenames for this page.
     */
    public static function relativizeMediaPaths(string $markdown, string $pageSlug): string
    {
        $pageSlug = DatabaseManager::sanitizeSlug($pageSlug);

        // Markdown links/images: ](…media.php?…file=Y…) → ](Y)
        $markdown = preg_replace_callback(
            '~\]\(([^)]*media\.php\?[^)]+)\)~i',
            static function (array $m) use ($pageSlug): string {
                $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                $parts = parse_url(str_replace(['&amp;'], ['&'], $url));
                if (!isset($parts['query'])) {
                    return $m[0];
                }
                parse_str($parts['query'], $q);
                $file = rawurldecode((string) ($q['file'] ?? ''));
                $file = explode('#', $file, 2)[0];
                $slug = DatabaseManager::sanitizeSlug((string) ($q['slug'] ?? ''));
                if ($file === '') {
                    return $m[0];
                }
                if ($slug !== '' && $slug !== $pageSlug) {
                    return $m[0];
                }
                return '](' . $file . ')';
            },
            $markdown
        ) ?? $markdown;

        // /assets/uploads/file.ext → file.ext (legacy)
        $markdown = preg_replace(
            '~\]\((?:https?://[^/]+)?/assets/uploads/([^)/?#]+)\)~i',
            ']($1)',
            $markdown
        ) ?? $markdown;

        // HTML src/href (PDF iframes, anchors) — use ~ delimiter so # fragments are safe
        $markdown = preg_replace_callback(
            '~\b(src|href)\s*=\s*(["\'])([^"\']+)\2~i',
            static function (array $m) use ($pageSlug): string {
                $url = html_entity_decode(str_replace('&amp;', '&', $m[3]), ENT_QUOTES, 'UTF-8');
                $fragment = '';
                if (str_contains($url, '#')) {
                    [$url, $frag] = explode('#', $url, 2);
                    $fragment = '#' . $frag;
                }

                $rel = self::mediaUrlToRelativeFile($url);
                if ($rel !== null) {
                    // Only drop to relative when same page (or no slug in URL)
                    if (preg_match('~[?&]slug=([a-z0-9\-]+)~i', $url, $sm)) {
                        $slug = DatabaseManager::sanitizeSlug($sm[1]);
                        if ($slug !== '' && $slug !== $pageSlug) {
                            return $m[0];
                        }
                    }
                    // Keep PDF viewer fragment on relative src for nicer embeds after re-expand
                    if (str_ends_with(strtolower($rel), '.pdf') && $fragment === '') {
                        $fragment = '#view=FitH';
                    }
                    return $m[1] . '=' . $m[2] . $rel . $fragment . $m[2];
                }

                return $m[0];
            },
            $markdown
        ) ?? $markdown;

        return $markdown;
    }
}
