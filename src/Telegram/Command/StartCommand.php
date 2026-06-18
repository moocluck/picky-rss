<?php

namespace App\Telegram\Command;

use App\Entity\User;
use App\Telegram\Keyboard\Keyboards;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

class StartCommand extends Command
{
    protected string $command = 'start';
    protected ?string $description = 'Запустить бота и показать меню';

    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    public function handle(Nutgram $bot): void
    {
        $userId = $bot->userId();
        $user = $this->em->getRepository(User::class)->find($userId);

        if (!$user) {
            $user = new User();
            $user->setId($userId);
            $user->setUsername($bot->user()?->username);
            $user->setFirstName($bot->user()?->first_name);
            $user->setLastName($bot->user()?->last_name);
            $this->em->persist($user);
            $this->em->flush();
        }

        $bot->sendMessage(
            "Привет, <b>" . htmlspecialchars($bot->user()?->first_name ?? 'друг') . "</b>!\n\n" .
            "Я бот-агрегатор RSS. Я буду проверять ваши любимые RSS-ленты и присылать новые публикации сюда.",
            ...[
                'parse_mode' => 'html',
                'reply_markup' => Keyboards::getMainMenu()
            ]
        );
    }
}
