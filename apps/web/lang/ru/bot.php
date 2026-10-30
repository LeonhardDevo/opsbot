<?php

declare(strict_types=1);

return [
    'status' => [
        'connecting' => 'Подключается',
        'active' => 'Активен',
        'invalid_token' => 'Неверный токен',
        'revoked' => 'Токен отозван',
        'disconnecting' => 'Отключается',
        'disconnected' => 'Отключён',
    ],
    'errors' => [
        'invalid_token' => 'Telegram не принял этот токен. Скопируйте его заново из BotFather.',
        'token_mismatch' => 'Этот токен принадлежит другому боту.',
        'already_connected' => 'Этот бот уже подключён к другому пространству OpsBot.',
        'network' => 'Не удалось связаться с Telegram. Повторите через минуту.',
        'webhook_failed' => 'Не удалось установить вебхук. Воркеру нужен публичный HTTPS-адрес.',
        'revoked' => 'Токен отозван в BotFather. Подключите новый токен.',
    ],
    'commands' => [
        'start' => 'Начать и привязать аккаунт',
        'help' => 'Что умеет бот',
        'lang' => 'Сменить язык',
        'whoami' => 'Ваше имя, компания, роль и отделы',
        'history' => 'Ваши последние 10 вопросов',
        'tasks' => 'Ваши открытые задачи',
        'incident' => 'Сообщить об инциденте',
        'ask_manager' => 'Передать последний вопрос менеджеру',
        'queue' => 'Вопросы, ожидающие вашего ответа',
        'privacy' => 'Что хранится и кто это видит',
        'unlink' => 'Отвязать аккаунт от бота',
    ],
    'desk' => [
        'revoked' => 'Бот @:username: токен отозван',
        'invalid_token' => 'Бот @:username: неверный токен',
        'active' => 'Бот @:username активен',
        'connecting' => 'Бот @:username подключается',
        'disconnecting' => 'Бот @:username отключается',
        'disconnected' => 'Бот @:username отключён',
    ],
    'test_message_html' => '<b>Тестовое сообщение</b>
Бот компании <b>:workspace</b> подключён и может присылать вам уведомления.',
    'access_revoked_html' => 'Ваш доступ к ассистенту <b>:workspace</b> завершён. Спасибо за работу.',
];
