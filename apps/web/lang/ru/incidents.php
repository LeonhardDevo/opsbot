<?php

declare(strict_types=1);

return [
    'number' => '№ :number',
    'category' => [
        'damage' => 'Повреждение',
        'complaint' => 'Жалоба',
        'no_access' => 'Нет доступа',
        'injury' => 'Травма',
        'lost_item' => 'Пропажа',
        'other' => 'Другое',
    ],
    'severity' => [
        'low' => 'Низкая',
        'medium' => 'Средняя',
        'high' => 'Высокая',
    ],
    'status' => [
        'open' => 'Открыт',
        'in_progress' => 'В работе',
        'resolved' => 'Решён',
        'closed' => 'Закрыт',
    ],
    'source' => [
        'bot' => 'Telegram',
        'web' => 'Веб',
        'ai_proposal' => 'Предложение ассистента',
    ],
    'event' => [
        'created' => 'Создан',
        'status_changed' => 'Статус изменён',
        'assigned' => 'Ответственный изменён',
        'severity_changed' => 'Важность изменена',
        'category_changed' => 'Категория изменена',
        'comment' => 'Комментарий',
        'task_linked' => 'Создана задача',
    ],
    'count' => ':count инцидент|:count инцидента|:count инцидентов',
];
