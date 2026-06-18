<?php

namespace App\Service;

use SergiX44\Nutgram\Nutgram;
use App\Entity\FeedItem;
use App\Entity\User;

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

        $text = "<b>" . htmlspecialchars($title) . "</b>\n\n";
        if ($description) {
            $text .= TelegramFormatter::truncate($description, 800) . "\n\n";
        }
        $text .= "<a href=\"" . htmlspecialchars($link) . "\">Читать полностью</a>";

        if ($imageUrl) {
            try {
                $caption = TelegramFormatter::truncate($text, 1000);
                $this->bot->sendPhoto($imageUrl, ...[
                    'chat_id' => $user->getId(),
                    'caption' => $caption,
                    'parse_mode' => 'html'
                ]);
            } catch (\Exception) {
                // Fallback to text if sending photo fails
                $this->bot->sendMessage($text, ...[
                    'chat_id' => $user->getId(),
                    'parse_mode' => 'html',
                    'disable_web_page_preview' => false
                ]);
            }
        } else {
            $this->bot->sendMessage($text, ...[
                'chat_id' => $user->getId(),
                'parse_mode' => 'html',
                'disable_web_page_preview' => false
            ]);
        }
    }
}
