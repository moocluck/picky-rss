<?php

namespace App\Telegram\Command;

use App\Telegram\Conversations\AddFeedConversation;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class AddCommand extends Command
{
    protected string $command = 'add';
    protected ?string $description = 'Добавить новую RSS-ленту';

    public function handle(Nutgram $bot): void
    {
        AddFeedConversation::begin($bot);
    }
}
