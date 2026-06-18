<?php

namespace App\Telegram\Command;

use App\Entity\User;
use App\Telegram\Keyboard\Keyboards;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ListCommand extends Command
{
    protected string $command = 'list';
    protected ?string $description = 'Показать мои подписки';

    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    public function handle(Nutgram $bot): void
    {
        self::showSubscriptions($bot, $this->em);
    }

    public static function showSubscriptions(Nutgram $bot, EntityManagerInterface $em, bool $isCallback = false): void
    {
        $userId = $bot->userId();
        $user = $em->getRepository(User::class)->find($userId);

        $feedsCount = $user ? $user->getFeeds()->count() : 0;
        error_log("showSubscriptions: isCallback=" . ($isCallback ? "true" : "false") . ", userId={$userId}, feedsCount={$feedsCount}");

        if (!$user || $user->getFeeds()->isEmpty()) {
            $msg = "У вас пока нет активных подписок.\nНажмите кнопку <b>➕ Добавить ленту</b>, чтобы подписаться.";
            if ($isCallback) {
                try {
                    $chatId = $bot->callbackQuery()?->message?->chat?->id ?? $bot->chatId();
                    $messageId = $bot->callbackQuery()?->message?->message_id ?? $bot->messageId();
                    error_log("Attempting to delete message: chatId={$chatId}, messageId={$messageId}");
                    if ($chatId && $messageId) {
                        $bot->deleteMessage($chatId, $messageId);
                    }
                } catch (\Throwable $e) {
                    error_log("Failed to delete message: " . $e->getMessage());
                }
            } else {
                $bot->sendMessage($msg, ...[
                    'parse_mode' => 'html',
                    'reply_markup' => Keyboards::getMainMenu()
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
}
