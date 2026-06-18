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
        
        // Strip all tags except those supported by Telegram HTML parse mode
        $html = strip_tags($html, '<b><i><u><ins><s><strike><del><a><code><pre>');
        
        // Unescape HTML entities that might remain
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Re-encode essential XML characters that must be escaped in Telegram HTML
        // (but only <, >, and & that are not part of HTML tags)
        // Actually, we can do a simple replacement of &, <, > if they are not part of tags,
        // but since strip_tags kept valid tags, we must escape & that are not part of entities.
        // A simpler way: since we stripped all unsupported tags, we just make sure
        // we encode special chars, but wait: if we do htmlspecialchars, it will escape <b> etc.
        // So we can do a placeholder replace or just use a helper to escape raw text.
        // Let's implement a clean escaping or just keep it simple:
        // Telegram requires:
        // & must be replaced with &amp;
        // < must be replaced with &lt;
        // > must be replaced with &gt;
        // except when they are part of tags.
        // Since we only have a few tags, we can replace them with placeholders, escape, and restore:
        
        $placeholders = [];
        $i = 0;
        
        $html = preg_replace_callback('/<\/?(b|i|u|ins|s|strike|del|code|pre|a\s+href="[^"]*")>/i', function($matches) use (&$placeholders, &$i) {
            $placeholder = "___TAG_PLACEHOLDER_{$i}___";
            $placeholders[$placeholder] = $matches[0];
            $i++;
            return $placeholder;
        }, $html);
        
        // Now escape characters for Telegram
        $html = htmlspecialchars($html, ENT_NOQUOTES, 'UTF-8');
        
        // Restore tags
        foreach ($placeholders as $placeholder => $tag) {
            $html = str_replace($placeholder, $tag, $html);
        }
        
        // Replace multiple consecutive newlines with maximum two
        $html = preg_replace("/\n{3,}/", "\n\n", $html);
        
        return trim($html);
    }
    
    public static function truncate(string $text, int $limit = 1000): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        
        return mb_substr($text, 0, $limit - 3) . '...';
    }
}
