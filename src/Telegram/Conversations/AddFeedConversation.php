<?php

namespace App\Telegram\Conversations;

use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\RssParser;
use App\Service\NewsNotifier;
use App\Entity\Feed;
use App\Entity\FeedItem;
use App\Entity\User;

class AddFeedConversation extends Conversation
{
    public function start(Nutgram $bot)
    {
        $bot->sendMessage('Пожалуйста, отправьте ссылку на RSS-ленту:');
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
            $bot->sendMessage('Добавление ленты отменено.');
            $this->end();
            return;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $bot->sendMessage("Это не похоже на корректную ссылку.\nПожалуйста, отправьте правильный URL-адрес (или напишите /cancel для отмены):");
            $this->next('askUrl');
            return;
        }

        $bot->sendMessage('Проверяю RSS-ленту...');

        // Parse feed
        $feedDto = $parser->parse($url);
        if (!$feedDto) {
            $bot->sendMessage("Не удалось прочитать RSS-ленту по этой ссылке.\nУбедитесь, что ссылка ведет на валидный RSS/Atom фид, и отправьте её снова (или напишите /cancel для отмены):");
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
