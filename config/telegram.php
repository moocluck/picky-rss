<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Entity\Feed;
use App\Entity\User;
use App\Telegram\Command\AddCommand;
use App\Telegram\Command\ListCommand;
use App\Telegram\Command\StartCommand;
use App\Telegram\Conversations\AddFeedConversation;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;

// Fetch EntityManager via Nutgram's delegate container (which is Symfony's container)
/** @var EntityManagerInterface $em */
$em = $bot->getContainer()->get(EntityManagerInterface::class);

// Register command classes from Symfony DI container
$container = $bot->getContainer();
$bot->registerCommand($container->get(StartCommand::class));
$bot->registerCommand($container->get(ListCommand::class));
$bot->registerCommand($container->get(AddCommand::class));

// Text button listeners (reusing command handlers/conversations to avoid duplication)
$bot->onText('➕ Добавить ленту', function (Nutgram $bot) {
    AddFeedConversation::begin($bot);
});

$bot->onText('📋 Мои подписки', function (Nutgram $bot) use ($em) {
    ListCommand::showSubscriptions($bot, $em);
});

// Handle unsubscribe callback
$bot->onCallbackQueryData('unsubscribe:{id}', function (Nutgram $bot, int $id) use ($em) {
    $userId = $bot->userId();
    $user = $em->getRepository(User::class)->find($userId);
    $feed = $em->getRepository(Feed::class)->find($id);

    if ($user && $feed) {
        $user->removeFeed($feed);
        $em->flush();
        try {
            $bot->answerCallbackQuery('Вы успешно отписались от ленты!');
        } catch (\Throwable) {
            // Ignore if callback query is too old
        }
    } else {
        try {
            $bot->answerCallbackQuery('Лента не найдена.');
        } catch (\Throwable) {
        }
    }

    // Refresh subscriptions view
    ListCommand::showSubscriptions($bot, $em, true);
});
