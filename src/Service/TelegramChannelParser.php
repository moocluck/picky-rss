<?php

namespace App\Service;

use App\Dto\RssFeedDto;
use App\Dto\RssItemDto;

class TelegramChannelParser
{
    public function parse(string $username): ?RssFeedDto
    {
        $username = ltrim($username, '@');
        // If it is a full link like t.me/channel or telegram.me/channel, extract username
        if (preg_match('/(?:t\.me|telegram\.me)\/([a-zA-Z0-9_]{5,})/i', $username, $matches)) {
            $username = $matches[1];
        }
        
        $url = "https://t.me/s/" . $username;
        $content = $this->fetchUrl($url);
        if (!$content) {
            return null;
        }

        // Use DOMDocument and DOMXPath to parse HTML
        $dom = new \DOMDocument();
        // Suppress HTML warnings
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $content);
        $xpath = new \DOMXPath($dom);

        // Extract channel title
        $titleNode = $xpath->query('//div[contains(@class, "tgme_channel_info_header_title")]//span');
        $channelTitle = $titleNode->length > 0 ? trim($titleNode->item(0)->nodeValue) : "@" . $username;

        $items = [];
        $processedGuids = [];
        // Each message is inside div.tgme_widget_message
        $messageNodes = $xpath->query('//div[contains(@class, "tgme_widget_message") and not(contains(@class, "tgme_widget_message_service"))]');

        foreach ($messageNodes as $node) {
            // Find link and GUID
            $linkNode = $xpath->query('.//a[contains(@class, "tgme_widget_message_date")]', $node);
            if ($linkNode->length === 0) {
                continue;
            }
            $link = $linkNode->item(0)->getAttribute('href');
            $guid = $link; // Use link as unique guid (e.g., https://t.me/username/123)

            if (isset($processedGuids[$guid])) {
                continue;
            }
            $processedGuids[$guid] = true;

            // Extract text/html
            $textNode = $xpath->query('.//div[contains(@class, "tgme_widget_message_text")]', $node);
            $description = '';
            if ($textNode->length > 0) {
                $description = $this->getInnerHtml($textNode->item(0));
            }

            // Extract media (photos and videos)
            $mediaItems = [];
            // Parse photos
            $photoNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " tgme_widget_message_photo_wrap ")]', $node);
            foreach ($photoNodes as $photoNode) {
                $style = $photoNode->getAttribute('style');
                if ($style && preg_match("/background-image:\s*url\s*\(\s*['\"]?([^'\"]+?)['\"]?\s*\)/i", $style, $matches)) {
                    $mediaItems[] = [
                        'type' => 'photo',
                        'url' => $matches[1]
                    ];
                }
            }
            // Parse videos
            $videoNodes = $xpath->query('.//video', $node);
            foreach ($videoNodes as $videoNode) {
                $src = $videoNode->getAttribute('src');
                if ($src) {
                    $mediaItems[] = [
                        'type' => 'video',
                        'url' => $src
                    ];
                }
            }

            // Deduplicate media items by URL
            $uniqueMedia = [];
            $seenUrls = [];
            foreach ($mediaItems as $item) {
                if (!in_array($item['url'], $seenUrls)) {
                    $seenUrls[] = $item['url'];
                    $uniqueMedia[] = $item;
                }
            }

            $imageUrl = !empty($uniqueMedia) ? json_encode($uniqueMedia) : null;

            // Extract date
            $dateNode = $xpath->query('.//a[contains(@class, "tgme_widget_message_date")]//time', $node);
            $publishedAt = null;
            if ($dateNode->length > 0) {
                $datetime = $dateNode->item(0)->getAttribute('datetime');
                if ($datetime) {
                    try {
                        $publishedAt = new \DateTimeImmutable($datetime);
                    } catch (\Exception) {
                    }
                }
            }

            // Telegram posts don't have titles, so we use a truncated snippet or channelTitle
            $title = $channelTitle;

            // Create item DTO
            $items[] = new RssItemDto(
                $guid,
                $title,
                $link,
                $description,
                $imageUrl,
                $publishedAt
            );
        }

        return new RssFeedDto($channelTitle, array_reverse($items));
    }

    private function fetchUrl(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        
        $isHttps = str_starts_with(strtolower($url), 'https://');
        $proxy = getenv('PARSER_PROXY') ?: getenv($isHttps ? 'HTTPS_PROXY' : 'HTTP_PROXY') ?: getenv($isHttps ? 'https_proxy' : 'http_proxy');
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        
        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        return $output;
    }

    private function getInnerHtml(\DOMNode $node): string
    {
        $innerHTML = "";
        $children = $node->childNodes;
        foreach ($children as $child) {
            $innerHTML .= $node->ownerDocument->saveHTML($child);
        }
        return $innerHTML;
    }
}
