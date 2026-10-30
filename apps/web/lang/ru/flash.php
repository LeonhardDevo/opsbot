<?php

declare(strict_types=1);

return [
    'bot' => [
        'connected' => 'Бот подключён и активен.',
        'disconnect_requested' => 'Бот отключается.',
        'test_message_sent' => 'Тестовое сообщение отправлено в ваш Telegram.',
    ],
    'employees' => [
        'created' => 'Сотрудник :name добавлен. Выдайте ссылку-приглашение, чтобы подключить Telegram.',
        'updated' => 'Данные сотрудника :name обновлены.',
        'offboarded' => 'Сотрудник :name отключён.',
        'export_queued' => 'Выгрузка готовится. Ссылка на скачивание скоро придёт.',
        'erased' => 'Персональные данные сотрудника удалены.',
    ],
    'departments' => [
        'created' => 'Отдел «:name» создан.',
        'updated' => 'Отдел «:name» обновлён.',
        'archived' => 'Отдел «:name» перенесён в архив.',
    ],
    'routing' => [
        'updated' => 'Правила маршрутизации сохранены.',
    ],
    'incidents' => [
        'created' => 'Инцидент :number создан.',
        'updated' => 'Инцидент обновлён.',
        'commented' => 'Комментарий добавлен.',
    ],
    'tasks' => [
        'created' => 'Задача :number создана.',
        'updated' => 'Задача обновлена.',
    ],
    'questions' => [
        'answered' => 'Ответ на :number отправлен сотруднику.',
        'dismissed' => 'Вопрос :number отклонён.',
        'converted' => 'Черновик FAQ создан. Утвердите его, чтобы бот начал по нему отвечать.',
        'reassigned' => 'Вопрос переназначен.',
    ],
    'documents' => [
        'uploaded' => '«:title» загружен — обработка началась.',
        'starter_pack_imported' => 'Импортирован :count черновик.|Импортировано :count черновика.|Импортировано :count черновиков.',
        'updated' => '«:title» обновлён.',
        'approved' => '«:title» утверждён.',
        'approved_bulk' => 'Утверждён :count документ.|Утверждено :count документа.|Утверждено :count документов.',
        'archived' => '«:title» перенесён в архив.',
        'retry' => 'Обработка «:title» перезапущена.',
        'deleted' => '«:title» удалён.',
    ],
    'settings' => [
        'assistant_saved' => 'Настройки ассистента сохранены.',
    ],
    'onboarding' => [
        'finished' => 'Настройка завершена. Добро пожаловать в OpsBot.',
    ],
];
