<?php

namespace App\Telegram\Keyboard;

use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

class Keyboards
{
    public static function getMainMenu(): ReplyKeyboardMarkup
    {
        return ReplyKeyboardMarkup::make(
            resize_keyboard: true,
            one_time_keyboard: false
        )->addRow(
            KeyboardButton::make('➕ Добавить ленту'),
            KeyboardButton::make('📋 Мои подписки')
        );
    }
}
