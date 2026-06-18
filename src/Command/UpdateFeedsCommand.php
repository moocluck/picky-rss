<?php

namespace App\Command;

use App\Entity\Feed;
use App\Entity\FeedItem;
use App\Service\RssParser;
use App\Service\TelegramChannelParser;
use App\Service\NewsNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-feeds',
    description: 'Check all active RSS and Telegram feeds for new items and notify subscribers.',
)]
class UpdateFeedsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private RssParser $parser,
        private TelegramChannelParser $tgParser,
        private NewsNotifier $notifier
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Starting RSS feed check...');

        $feeds = $this->em->getRepository(Feed::class)->findAll();
        
        if (empty($feeds)) {
            $io->info('No feeds found in database.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d feed(s) to check.', count($feeds)));

        foreach ($feeds as $feed) {
            $io->section(sprintf('Checking: %s (%s)', $feed->getTitle() ?: 'Untitled', $feed->getUrl()));

            // Skip if no users are subscribed to this feed
            if ($feed->getUsers()->isEmpty()) {
                $io->text('No users subscribed. Skipping.');
                continue;
            }

            if ($feed->getType() === 'telegram') {
                $username = str_replace('https://t.me/', '', $feed->getUrl());
                $feedDto = $this->tgParser->parse($username);
            } else {
                $feedDto = $this->parser->parse($feed->getUrl());
            }

            if (!$feedDto) {
                $io->warning(sprintf('Failed to parse feed: %s', $feed->getUrl()));
                continue;
            }

            // Update feed title if it was null/empty before
            if (!$feed->getTitle() && $feedDto->title) {
                $feed->setTitle($feedDto->title);
            }

            $newItemsCount = 0;
            $newFeedItems = [];

            // Read items in reverse order (oldest first) so that when we send them,
            // they are delivered in chronological order (latest is sent last)
            $parsedItems = array_reverse($feedDto->items);

            foreach ($parsedItems as $itemDto) {
                $existingItem = $this->em->getRepository(FeedItem::class)->findOneBy([
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
                    
                    $this->em->persist($feedItem);
                    $feed->addFeedItem($feedItem);
                    
                    $newFeedItems[] = $feedItem;
                    $newItemsCount++;
                }
            }

            if ($newItemsCount > 0) {
                $io->success(sprintf('Found %d new items. Sending notifications...', $newItemsCount));
                
                // Notify all subscribed users
                foreach ($newFeedItems as $item) {
                    foreach ($feed->getUsers() as $user) {
                        try {
                            $this->notifier->notifyUser($user, $item);
                            $io->text(sprintf('- Sent item "%s" to user %d', $item->getTitle(), $user->getId()));
                        } catch (\Exception $e) {
                            $io->error(sprintf('Error notifying user %d: %s', $user->getId(), $e->getMessage()));
                        }
                    }
                }
            } else {
                $io->text('No new items.');
            }

            $feed->setLastCheckedAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        $io->success('RSS feed check completed.');

        return Command::SUCCESS;
    }
}
