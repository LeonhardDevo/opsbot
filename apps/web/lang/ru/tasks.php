<?php

declare(strict_types=1);

return [
    'number' => 'T-:number',
    'status' => [
        'todo' => 'К выполнению',
        'in_progress' => 'В работе',
        'done' => 'Выполнена',
        'cancelled' => 'Отменена',
    ],
    'source' => [
        'web' => 'Веб',
        'bot' => 'Telegram',
        'incident' => 'Инцидент',
        'ai_proposal' => 'Предложение ассистента',
    ],
    'reminder' => [
        'pre_due' => 'Перед сроком',
        'due' => 'В срок',
        'overdue_1' => 'Просрочка, 1-й день',
        'overdue_2' => 'Просрочка, 2-й день',
        'overdue_3' => 'Просрочка, 3-й день',
        'escalation' => 'Эскалация менеджеру',
    ],
    'reminder_status' => [
        'planned' => 'Запланировано',
        'sent' => 'Отправлено',
        'skipped' => 'Пропущено',
        'cancelled' => 'Отменено',
    ],
    'count' => ':count задача|:count задачи|:count задач',
];
