<?php

namespace App\Service;

use App\Dto\RssFeedDto;
use App\Dto\RssItemDto;

class RssParser
{
    public function parse(string $url): ?RssFeedDto
    {
        $content = $this->fetchUrl($url);
        if (!$content) {
            return null;
        }

        $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) {
            return null;
        }

        // Detect Atom vs RSS 2.0
        if ($xml->getName() === 'feed') {
            return $this->parseAtom($xml);
        } elseif ($xml->getName() === 'rss' || isset($xml->channel)) {
            return $this->parseRss($xml);
        }

        return null;
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
        
        // Use proxy if set in environment
        $isHttps = str_starts_with(strtolower($url), 'https://');
        $proxy = getenv($isHttps ? 'HTTPS_PROXY' : 'HTTP_PROXY') ?: getenv($isHttps ? 'https_proxy' : 'http_proxy');
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

    private function parseRss(\SimpleXMLElement $xml): RssFeedDto
    {
        $channel = $xml->channel;
        $feedTitle = (string)($channel->title ?? 'Без названия');
        
        $items = [];
        foreach ($channel->item as $item) {
            $guid = (string)($item->guid ?? $item->link ?? '');
            if (!$guid) {
                continue;
            }
            
            $title = (string)($item->title ?? 'Без названия');
            $link = (string)($item->link ?? '');
            $description = (string)($item->description ?? '');
            
            // Extract image
            $imageUrl = null;
            if (isset($item->enclosure) && str_starts_with((string)$item->enclosure['type'], 'image/')) {
                $imageUrl = (string)$item->enclosure['url'];
            }
            
            // Try media namespace
            if (!$imageUrl) {
                $media = $item->children('http://search.yahoo.com/mrss/');
                if (isset($media->content)) {
                    $imageUrl = (string)$media->content->attributes()->url;
                } elseif (isset($media->thumbnail)) {
                    $imageUrl = (string)$media->thumbnail->attributes()->url;
                }
            }
            
            // Try parsing from description HTML
            if (!$imageUrl && $description) {
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $matches)) {
                    $imageUrl = $matches[1];
                }
            }
            
            $publishedAt = null;
            if (isset($item->pubDate)) {
                try {
                    $publishedAt = new \DateTimeImmutable((string)$item->pubDate);
                } catch (\Exception) {
                }
            }
            
            $items[] = new RssItemDto($guid, $title, $link, $description, $imageUrl, $publishedAt);
        }

        return new RssFeedDto($feedTitle, $items);
    }

    private function parseAtom(\SimpleXMLElement $xml): RssFeedDto
    {
        $feedTitle = (string)($xml->title ?? 'Без названия');
        
        $items = [];
        foreach ($xml->entry as $entry) {
            $guid = (string)($entry->id ?? '');
            if (!$guid) {
                continue;
            }
            
            $title = (string)($entry->title ?? 'Без названия');
            
            $link = '';
            foreach ($entry->link as $l) {
                $attributes = $l->attributes();
                if (isset($attributes['href'])) {
                    $rel = (string)($attributes['rel'] ?? '');
                    if ($rel === 'alternate' || $rel === '') {
                        $link = (string)$attributes['href'];
                        break;
                    }
                }
            }
            if (!$link) {
                $link = (string)$entry->link['href'];
            }
            
            $description = (string)($entry->summary ?? $entry->content ?? '');
            
            $imageUrl = null;
            // Try media namespace
            $media = $entry->children('http://search.yahoo.com/mrss/');
            if (isset($media->content)) {
                $imageUrl = (string)$media->content->attributes()->url;
            } elseif (isset($media->thumbnail)) {
                $imageUrl = (string)$media->thumbnail->attributes()->url;
            }
            
            if (!$imageUrl && $description) {
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $description, $matches)) {
                    $imageUrl = $matches[1];
                }
            }
            
            $publishedAt = null;
            $dateStr = (string)($entry->updated ?? $entry->published ?? '');
            if ($dateStr) {
                try {
                    $publishedAt = new \DateTimeImmutable($dateStr);
                } catch (\Exception) {
                }
            }
            
            $items[] = new RssItemDto($guid, $title, $link, $description, $imageUrl, $publishedAt);
        }

        return new RssFeedDto($feedTitle, $items);
    }
}
