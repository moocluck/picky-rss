<?php

namespace App\Service;

class TelegramFormatter
{
    public static function formatHtml(string $html): string
    {
        // Replace break, paragraph, header, and list item tags with newlines
        $html = preg_replace('/<(br|p|div|li|h1|h2|h3|h4|h5|h6)[^>]*>/i', "\n", $html);
        
        // Replace <strong> with <b>, <em> with <i> for consistency
        $html = preg_replace('/<strong[^>]*>/i', '<b>', $html);
        $html = preg_replace('/<\/strong>/i', '</b>', $html);
        $html = preg_replace('/<em[^>]*>/i', '<i>', $html);
        $html = preg_replace('/<\/em>/i', '</i>', $html);

        // Load HTML fragment using DOMDocument
        $dom = new \DOMDocument();
        // Wrap in XML encoding declaration and root div to handle UTF-8 properly
        $wrappedHtml = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
        @$dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root) {
            self::sanitizeNode($root);
            // Get inner HTML of root
            $html = '';
            foreach ($root->childNodes as $child) {
                $html .= $dom->saveHTML($child);
            }
        }

        // Replace multiple consecutive newlines with maximum two
        $html = preg_replace("/\n{3,}/", "\n\n", $html);
        
        return trim($html);
    }

    private static function sanitizeNode(\DOMNode $node): void
    {
        $allowedTags = ['b', 'i', 'u', 'ins', 's', 'strike', 'del', 'code', 'pre', 'a'];
        
        // Loop backwards because we are modifying the child list
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->nodeName);
                
                if (in_array($tagName, $allowedTags)) {
                    // Sanitize children first
                    self::sanitizeNode($child);
                    
                    // Clean attributes
                    if ($tagName === 'a') {
                        $href = $child->getAttribute('href');
                        // Remove all attributes
                        while ($child->attributes->length > 0) {
                            $child->removeAttributeNode($child->attributes->item(0));
                        }
                        // Keep only href if present
                        if ($href) {
                            $child->setAttribute('href', $href);
                        } else {
                            self::unwrapNode($child);
                        }
                    } else {
                        // Remove all attributes for other elements
                        while ($child->attributes->length > 0) {
                            $child->removeAttributeNode($child->attributes->item(0));
                        }
                    }
                } else {
                    // Tag not allowed, unwrap it (keep text/children, remove tag)
                    self::sanitizeNode($child);
                    self::unwrapNode($child);
                }
            }
        }
    }

    private static function unwrapNode(\DOMElement $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) {
            return;
        }
        
        while ($node->childNodes->length > 0) {
            $child = $node->childNodes->item(0);
            $parent->insertBefore($child, $node);
        }
        
        $parent->removeChild($node);
    }
    
    public static function truncate(string $html, int $limit = 800): string
    {
        if (mb_strlen(strip_tags($html)) <= $limit) {
            return $html;
        }

        $printedLength = 0;
        $position = 0;
        $tags = [];
        $result = '';
        $htmlLength = mb_strlen($html);

        while ($printedLength < $limit && $position < $htmlLength) {
            $char = mb_substr($html, $position, 1);
            if ($char === '<') {
                $tag = '';
                while ($position < $htmlLength && mb_substr($html, $position, 1) !== '>') {
                    $tag .= mb_substr($html, $position, 1);
                    $position++;
                }
                $tag .= '>';
                $position++;

                if (preg_match('/<\s*\/([a-z0-9]+)/i', $tag, $matches)) {
                    $tagName = strtolower($matches[1]);
                    $key = array_search($tagName, $tags);
                    if ($key !== false) {
                        unset($tags[$key]);
                    }
                } elseif (preg_match('/<\s*([a-z0-9]+)/i', $tag, $matches)) {
                    $tagName = strtolower($matches[1]);
                    if (!str_ends_with($tag, '/>')) {
                        $tags[] = $tagName;
                    }
                }
                $result .= $tag;
            } else {
                $result .= $char;
                $printedLength++;
                $position++;
            }
        }

        if ($printedLength >= $limit) {
            $result .= '...';
        }

        // Close any unclosed tags in reverse order
        foreach (array_reverse($tags) as $tagName) {
            $result .= "</$tagName>";
        }

        return $result;
    }
}
