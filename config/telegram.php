<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Feed;
use App\Telegram\Conversations\AddFeedConversation;

// Fetch EntityManager via Nutgram's delegate container (which is Symfony's container)
/** @var EntityManagerInterface $em */
$em = $bot->getContainer()->get(EntityManagerInterface::class);


// Helper to show main menu keyboard
function getMainMenuKeyboard(): ReplyKeyboardMarkup
{
    return ReplyKeyboardMarkup::make(
        resize_keyboard: true,
        one_time_keyboard: false
    )->addRow(
        KeyboardButton::make('➕ Добавить ленту'),
        KeyboardButton::make('📋 Мои подписки')
    );
}

// /start command
$bot->onCommand('start', function (Nutgram $bot) use ($em) {
    $userId = $bot->userId();
    $user = $em->getRepository(User::class)->find($userId);

    if (!$user) {
        $user = new User();
        $user->setId($userId);
        $user->setUsername($bot->user()?->username);
        $user->setFirstName($bot->user()?->first_name);
        $user->setLastName($bot->user()?->last_name);
        $em->persist($user);
        $em->flush();
    }

    $bot->sendMessage(
        "Привет, <b>" . htmlspecialchars($bot->user()?->first_name ?? 'друг') . "</b>!\n\n" .
        "Я бот-агрегатор RSS. Я буду проверять ваши любимые RSS-ленты и присылать новые публикации сюда.",
        ...[
            'parse_mode' => 'html',
            'reply_markup' => getMainMenuKeyboard()
        ]
    );
})->description('Запустить бота и показать меню');

// Trigger conversational flow for adding a feed
$bot->onCommand('add', function (Nutgram $bot) {
    AddFeedConversation::begin($bot);
})->description('Добавить новую RSS-ленту');

$bot->onText('➕ Добавить ленту', function (Nutgram $bot) {
    AddFeedConversation::begin($bot);
});

// Show subscriptions list
function showSubscriptions(Nutgram $bot, EntityManagerInterface $em, bool $isCallback = false): void
{
    $userId = $bot->userId();
    $user = $em->getRepository(User::class)->find($userId);

    if (!$user || $user->getFeeds()->isEmpty()) {
        $msg = "У вас пока нет активных подписок.\nНажмите кнопку <b>➕ Добавить ленту</b>, чтобы подписаться.";
        if ($isCallback) {
            $bot->editMessageText($msg, ...['parse_mode' => 'html']);
        } else {
            $bot->sendMessage($msg, ...[
                'parse_mode' => 'html',
                'reply_markup' => getMainMenuKeyboard()
            ]);
        }
        return;
    }

    $keyboard = InlineKeyboardMarkup::make();
    foreach ($user->getFeeds() as $feed) {
        $title = $feed->getTitle() ?: $feed->getUrl();
        if (mb_strlen($title) > 30) {
            $title = mb_substr($title, 0, 27) . '...';
        }
        
        $keyboard->addRow(
            InlineKeyboardButton::make($title, url: $feed->getUrl()),
            InlineKeyboardButton::make('❌ Удалить', callback_data: "unsubscribe:{$feed->getId()}")
        );
    }

    $msg = "📋 <b>Ваши активные подписки:</b>\nВы можете кликнуть на название, чтобы открыть ленту, или нажать ❌, чтобы отписаться.";
    
    if ($isCallback) {
        $bot->editMessageText($msg, ...[
            'parse_mode' => 'html',
            'reply_markup' => $keyboard
        ]);
    } else {
        $bot->sendMessage($msg, ...[
            'parse_mode' => 'html',
            'reply_markup' => $keyboard
        ]);
    }
}

$bot->onCommand('list', function (Nutgram $bot) use ($em) {
    showSubscriptions($bot, $em);
})->description('Показать мои подписки');

$bot->onText('📋 Мои подписки', function (Nutgram $bot) use ($em) {
    showSubscriptions($bot, $em);
});

// Handle unsubscribe callback
$bot->onCallbackQuery('unsubscribe:{id}', function (Nutgram $bot, int $id) use ($em) {
    $userId = $bot->userId();
    $user = $em->getRepository(User::class)->find($userId);
    $feed = $em->getRepository(Feed::class)->find($id);

    if ($user && $feed) {
        $user->removeFeed($feed);
        $em->flush();
        $bot->answerCallbackQuery('Вы успешно отписались от ленты!');
    } else {
        $bot->answerCallbackQuery('Лента не найдена.');
    }

    // Refresh subscriptions view
    showSubscriptions($bot, $em, true);
});