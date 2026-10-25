<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Product descriptions come from the rich text editor (and imports) as HTML. Only simple
 * formatting survives: paragraphs, headings, bold/italic/underline/strike, lists, quotes and
 * http(s)/mailto links (opened with rel="noopener nofollow"). Scripts, styles, images, iframes
 * and event attributes are removed. Always store the sanitised result.
 */
final class RichText
{
    public const MAX_LENGTH = 20000;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->forceAttribute('a', 'rel', 'noopener nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(self::MAX_LENGTH * 2);

        $clean = trim((new HtmlSanitizer($config))->sanitize($html));

        // An editor left empty still sends "<p></p>".
        return trim(strip_tags($clean)) === '' ? null : $clean;
    }

    /** Plain text for search, labels and imports. */
    public static function toText(?string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', (string) $html)))));
    }
}
