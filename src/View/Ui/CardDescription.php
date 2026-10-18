<?php
declare(strict_types=1);
namespace Berserk\View\Ui;

/** Description HTML is presentation data, never executable markup. */
final class CardDescription
{
    public static function render(string $html, bool $spoiler = true): string
    {
        if (trim($html) === '') return '';
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
            $body = $dom->getElementsByTagName('body')->item(0);
            $output = $body ? self::children($body) : '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$spoiler) return trim($output) === '' ? '' : '<div class="panel-card-description">' . $output . '</div>';
        return trim($output) === '' ? '' : '<details class="panel-card-description">'
            . '<summary>Описание<span class="description-chevron" aria-hidden="true"></span></summary>'
            . '<div class="panel-card-description-body">' . $output . '</div></details>';
    }

    private static function children(\DOMNode $parent): string
    {
        $html = '';
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMText) {
                $html .= htmlspecialchars($node->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!$node instanceof \DOMElement) continue;
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template'], true)) continue;
            if ($tag === 'img') {
                $src = $node->getAttribute('src');
                // Existing descriptions reference local PNG icons only. Never load
                // external resources or preserve event/style attributes from HTML.
                if (preg_match('~\A/(?:assets/)?images/icons/([a-zA-Z0-9_-]+\.png)\z~D', $src, $matches)) {
                    $src = '/assets/images/icons/' . $matches[1];
                    $html .= '<img class="card-description-icon" src="' . htmlspecialchars($src, ENT_QUOTES)
                        . '" alt="' . htmlspecialchars($node->getAttribute('alt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . '" loading="lazy" draggable="false">';
                }
            } elseif ($tag === 'br') {
                $html .= '<br>';
            } elseif (in_array($tag, ['b', 'strong', 'i', 'em', 'u', 'small', 'p', 'ul', 'ol', 'li'], true)) {
                $html .= '<' . $tag . '>' . self::children($node) . '</' . $tag . '>';
            } else {
                $html .= self::children($node);
            }
        }
        return $html;
    }
}
