<?php

namespace App\Service;

use App\Entity\FeedItem;
use App\Entity\User;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaVideo;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;

class NewsNotifier
{
    public function __construct(
        private Nutgram $bot
    ) {}

    public function notifyUser(User $user, FeedItem $item): void
    {
        $title = $item->getTitle();
        $link = $item->getLink();
        $description = $item->getDescription() ? TelegramFormatter::formatHtml($item->getDescription()) : '';
        $imageUrl = $item->getImageUrl();

        // Format headers and footers based on feed type
        if ($item->getFeed()?->getType() === 'telegram') {
            $header = "<a href=\"" . htmlspecialchars($item->getFeed()->getUrl()) . "\">📢 " . htmlspecialchars($item->getFeed()->getTitle() ?? 'Telegram-канал') . "</a>\n\n";
            $footer = "\n\n<a href=\"" . htmlspecialchars($link) . "\">Ссылка на пост</a>";
        } else {
            $header = "<b>" . htmlspecialchars($title) . "</b>\n\n";
            $footer = "\n\n<a href=\"" . htmlspecialchars($link) . "\">Читать полностью</a>";
        }

        $mediaItems = [];
        if ($imageUrl) {
            $decoded = json_decode($imageUrl, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item) && isset($item['url'])) {
                        $mediaItems[] = [
                            'type' => $item['type'] ?? 'photo',
                            'url' => $item['url']
                        ];
                    } else {
                        $mediaItems[] = [
                            'type' => 'photo',
                            'url' => (string)$item
                        ];
                    }
                }
            } else {
                $mediaItems[] = [
                    'type' => 'photo',
                    'url' => $imageUrl
                ];
            }
        }

        if (!empty($mediaItems)) {
            // Caption limit is 1024 characters.
            // Available space for description = 1024 - length of header - length of footer - safety margin
            $headerLen = mb_strlen(strip_tags($header));
            $footerLen = mb_strlen(strip_tags($footer));
            $captionLimit = 1024 - $headerLen - $footerLen - 30;
            if ($captionLimit < 200) {
                $captionLimit = 200;
            }

            $captionDescription = TelegramFormatter::truncate($description, $captionLimit);
            $captionText = $header . $captionDescription . $footer;

            $tempFiles = [];
            $openedResources = [];
            $mediaArray = [];

            try {
                foreach ($mediaItems as $index => $item) {
                    if (count($mediaArray) >= 10) {
                        break;
                    }

                    $type = $item['type'];
                    $url = $item['url'];
                    $ext = ($type === 'video') ? '.mp4' : '.jpg';

                    $tempFile = tempnam(sys_get_temp_dir(), 'tg_med_');
                    if (!$tempFile) {
                        continue;
                    }

                    $tempFileWithExt = $tempFile . $ext;
                    if (rename($tempFile, $tempFileWithExt)) {
                        $tempFile = $tempFileWithExt;
                    }

                    // Download media using curl
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
                    
                    $isHttps = str_starts_with(strtolower($url), 'https://');
                    $proxy = getenv('PARSER_PROXY') ?: getenv($isHttps ? 'HTTPS_PROXY' : 'HTTP_PROXY') ?: getenv($isHttps ? 'https_proxy' : 'http_proxy');
                    if ($proxy) {
                        curl_setopt($ch, CURLOPT_PROXY, $proxy);
                    }

                    $data = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode === 200 && $data) {
                        file_put_contents($tempFile, $data);
                        $tempFiles[] = $tempFile;

                        $resource = fopen($tempFile, 'r');
                        if ($resource) {
                            $openedResources[] = $resource;
                            $filename = "media_" . count($tempFiles) . $ext;

                            if ($type === 'video') {
                                $mediaArray[] = new InputMediaVideo(
                                    media: InputFile::make($resource, $filename),
                                    caption: (count($mediaArray) === 0) ? $captionText : null,
                                    parse_mode: (count($mediaArray) === 0) ? 'html' : null
                                );
                            } else {
                                $mediaArray[] = new InputMediaPhoto(
                                    media: InputFile::make($resource, $filename),
                                    caption: (count($mediaArray) === 0) ? $captionText : null,
                                    parse_mode: (count($mediaArray) === 0) ? 'html' : null
                                );
                            }
                        }
                    } else {
                        error_log("Failed to download media from $url. HTTP code: $httpCode");
                        @unlink($tempFile);
                    }
                }

                if (count($mediaArray) === 1) {
                    $mediaItem = $mediaArray[0];
                    if ($mediaItem instanceof InputMediaVideo) {
                        $this->bot->sendVideo($mediaItem->media, ...[
                            'chat_id' => $user->getId(),
                            'caption' => $captionText,
                            'parse_mode' => 'html'
                        ]);
                    } else {
                        $this->bot->sendPhoto($mediaItem->media, ...[
                            'chat_id' => $user->getId(),
                            'caption' => $captionText,
                            'parse_mode' => 'html'
                        ]);
                    }
                    return;
                } elseif (count($mediaArray) > 1) {
                    $this->bot->sendMediaGroup($mediaArray, ...[
                        'chat_id' => $user->getId()
                    ]);
                    return;
                }
            } catch (\Exception $e) {
                error_log("Failed to send media. Error: " . $e->getMessage());
                // Fallback to text message if media sending fails
            } finally {
                foreach ($openedResources as $res) {
                    if (is_resource($res)) {
                        fclose($res);
                    }
                }
                foreach ($tempFiles as $file) {
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }
            }
        }

        // Text message limit is 4096. We truncate description to 3800 to fit safely with header/footer.
        $textDescription = TelegramFormatter::truncate($description, 3800);
        $text = $header . $textDescription . $footer;

        $this->bot->sendMessage($text, ...[
            'chat_id' => $user->getId(),
            'parse_mode' => 'html',
            'disable_web_page_preview' => false
        ]);
    }
}
