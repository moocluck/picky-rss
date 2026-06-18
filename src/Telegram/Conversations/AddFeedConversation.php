<?php

namespace App\Telegram\Conversations;

use App\Entity\Feed;
use App\Entity\FeedItem;
use App\Entity\User;
use App\Service\NewsNotifier;
use App\Service\RssParser;
use App\Service\TelegramChannelParser;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class AddFeedConversation extends Conversation
{
    public function start(Nutgram $bot)
    {
        $bot->sendMessage('Пожалуйста, отправьте ссылку на RSS-ленту или @юзернейм Telegram-канала:');
        $this->next('askUrl');
    }

    public function askUrl(Nutgram $bot)
    {
        // Fetch services from Symfony container via Nutgram's delegate container
        /** @var RssParser $parser */
        $parser = $bot->getContainer()->get(RssParser::class);
        /** @var EntityManagerInterface $em */
        $em = $bot->getContainer()->get(EntityManagerInterface::class);
        /** @var NewsNotifier $notifier */
        $notifier = $bot->getContainer()->get(NewsNotifier::class);

        $url = trim($bot->message()->text);

        if ($url === '/cancel' || $url === 'Отмена') {
            $bot->sendMessage('Добавление отменено.');
            $this->end();
            return;
        }

        $isTelegram = false;
        $tgUsername = null;

        // Detect Telegram channel links or username
        if (str_starts_with($url, '@')) {
            $isTelegram = true;
            $tgUsername = ltrim($url, '@');
            $url = "https://t.me/" . $tgUsername;
        } elseif (preg_match('/(?:t\.me|telegram\.me)\/([a-zA-Z0-9_]{5,})/i', $url, $matches)) {
            $isTelegram = true;
            $tgUsername = $matches[1];
            $url = "https://t.me/" . $tgUsername;
        }

        if (!$isTelegram && !filter_var($url, FILTER_VALIDATE_URL)) {
            $bot->sendMessage("Это не похоже на корректную ссылку или @юзернейм.\nПожалуйста, отправьте правильный URL-адрес или @юзернейм канала (или напишите /cancel для отмены):");
            $this->next('askUrl');
            return;
        }

        $bot->sendMessage($isTelegram ? 'Проверяю Telegram-канал...' : 'Проверяю RSS-ленту...');

        // Parse feed
        if ($isTelegram) {
            /** @var TelegramChannelParser $tgParser */
            $tgParser = $bot->getContainer()->get(TelegramChannelParser::class);
            $feedDto = $tgParser->parse($tgUsername);
        } else {
            $feedDto = $parser->parse($url);
        }

        if (!$feedDto) {
            $bot->sendMessage($isTelegram ?
                "Не удалось прочитать этот Telegram-канал.\nУбедитесь, что канал публичный и имя указано верно, затем отправьте его снова (или напишите /cancel):" :
                "Не удалось прочитать RSS-ленту по этой ссылке.\nУбедитесь, что ссылка ведет на валидный RSS/Atom фид, и отправьте её снова (или напишите /cancel для отмены):"
            );
            $this->next('askUrl');
            return;
        }

        // Add or retrieve user from DB
        $user = $em->getRepository(User::class)->find($bot->userId());
        if (!$user) {
            $user = new User();
            $user->setId($bot->userId());
            $user->setUsername($bot->user()?->username);
            $user->setFirstName($bot->user()?->first_name);
            $user->setLastName($bot->user()?->last_name);
            $em->persist($user);
        }

        // Add or retrieve Feed from DB
        $feed = $em->getRepository(Feed::class)->findOneBy(['url' => $url]);
        if (!$feed) {
            $feed = new Feed();
            $feed->setUrl($url);
            $feed->setTitle($feedDto->title);
            $feed->setType($isTelegram ? 'telegram' : 'rss');
            $em->persist($feed);
        }

        // Link User to Feed
        if (!$user->getFeeds()->contains($feed)) {
            $user->addFeed($feed);
        }

        // Process current items to populate database
        // and find the latest one to show the user as a preview
        $latestFeedItem = null;
        foreach ($feedDto->items as $itemDto) {
            $existingItem = $em->getRepository(FeedItem::class)->findOneBy([
                'feed' => $feed,
                'guid' => $itemDto->guid
            ]);

            if (!$existingItem) {
                $feedItem = new FeedItem();
                $feedItem->setFeed($feed);
                $feedItem->setGuid($itemDto->guid);
                $feedItem->setTitle($itemDto->title);
                $feedItem->setLink($itemDto->link);
                $feedItem->setDescription($itemDto->description);
                $feedItem->setImageUrl($itemDto->imageUrl);
                $feedItem->setPublishedAt($itemDto->publishedAt);
                $em->persist($feedItem);

                $feed->addFeedItem($feedItem);

                if ($latestFeedItem === null) {
                    $latestFeedItem = $feedItem;
                }
            } else {
                if ($latestFeedItem === null) {
                    $latestFeedItem = $existingItem;
                }
            }
        }

        $em->flush();

        $bot->sendMessage("✅ Успешно! Вы подписались на ленту: <b>" . htmlspecialchars($feedDto->title) . "</b>", ...[
            'parse_mode' => 'html'
        ]);

        // Send preview of the latest item if available
        if ($latestFeedItem) {
            $bot->sendMessage("Последняя новость из этой ленты:");
            $notifier->notifyUser($user, $latestFeedItem);
        }

        $this->end();
    }
}
