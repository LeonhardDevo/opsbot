<?php

declare(strict_types=1);

return [
    'action_type' => [
        'create_incident' => 'Оформить инцидент',
        'create_task' => 'Создать задачу',
        'escalate' => 'Передать менеджеру',
        'open_source' => 'Открыть источник',
        'ask' => 'Спросить',
    ],
    'action_status' => [
        'proposed' => 'Предложено',
        'executed' => 'Выполнено',
        'cancelled' => 'Отменено',
        'expired' => 'Истекло',
        'failed' => 'Ошибка',
    ],
    'refusal_reason' => [
        'low_confidence' => 'Низкая уверенность',
        'not_found' => 'Не найдено',
        'model_refusal' => 'Модель отказалась отвечать',
        'unavailable' => 'Ассистент недоступен',
        'limit_reached' => 'Исчерпан месячный лимит',
        'paused' => 'Ответы приостановлены',
    ],
    'answer_kind' => [
        'document' => 'По документам',
        'tool_data' => 'По данным компании',
    ],
    'actions' => [
        'create_incident' => 'Оформить инцидент',
        'create_task' => 'Создать задачу',
        'escalate' => 'Передать менеджеру',
        'open_source' => 'Открыть источник',
        'ask' => 'Спросить',
    ],
    'actions_result' => [
        'not_found' => 'Это действие больше недоступно.',
        'foreign' => 'Эта кнопка была отправлена другому человеку.',
        'unavailable' => 'Это действие недоступно.',
        'cancelled' => 'Отменено.',
        'expired' => 'Срок действия предложения истёк. Спросите ещё раз.',
        'rate_limited' => 'Слишком много запросов за последний час. Повторите позже.',
        'confirm_prompt' => 'Проверьте данные и подтвердите.',
        'source_ready' => 'Вот документ-источник.',
        'done' => 'Готово.',
        'incident_created' => 'Инцидент :number создан. Ответственный: :assignee.',
        'incident_exists' => 'Инцидент :number уже создан. Ответственный: :assignee.',
        'task_created' => 'Задача :number создана. Исполнитель: :assignee.',
        'task_exists' => 'Задача :number уже создана. Исполнитель: :assignee.',
        'escalated' => 'Вопрос :number передан: :assignee.',
    ],
    'card' => [
        'incident_title' => 'Новый инцидент',
        'task_title' => 'Новая задача',
        'category' => 'Категория',
        'severity' => 'Важность',
        'description' => 'Описание',
        'client_ref' => 'Клиент',
        'title' => 'Название',
        'due' => 'Срок',
        'due_in_hours' => 'через :count час|через :count часа|через :count часов',
    ],
    'buttons' => [
        'confirm' => 'Подтвердить',
        'cancel' => 'Отмена',
        'open_source' => 'Открыть источник',
        'convert_faq' => 'Сохранить как черновик FAQ',
    ],
    'subscription' => [
        'paused' => 'Ответы ассистента для вашей компании приостановлены — обратитесь к администратору. Сообщить об инциденте по-прежнему можно.',
    ],
    'bot_actions' => [
        'incident_already' => 'Инцидент :number уже в этом статусе.',
        'incident_in_progress' => 'Инцидент :number взят в работу.',
        'incident_resolved' => 'Инцидент :number решён.',
        'task_already' => 'Задача :number уже в этом статусе.',
        'task_in_progress' => 'Задача :number взята в работу.',
        'task_done' => 'Задача :number выполнена.',
        'dismiss_choose' => 'Выберите причину отклонения вопроса.',
        'dismissed' => 'Вопрос :number отклонён.',
        'converted' => 'Из вопроса :number создан черновик FAQ. Бот начнёт его использовать после утверждения.',
        'incident_created_html' => 'Инцидент <b>:number</b> создан. Ответственный: <b>:assignee</b>.',
        'nothing_to_escalate' => 'Вы ещё ничего не спрашивали.',
        'escalated_html' => 'Ваш вопрос <b>:number</b> передан: <b>:assignee</b>.',
        'answered_html' => 'Ваш ответ на <b>:number</b> отправлен сотруднику.',
    ],
    'counts' => [
        'hours' => ':count час|:count часа|:count часов',
        'days' => ':count день|:count дня|:count дней',
    ],
    'mail' => [
        'open' => 'Открыть в OpsBot',
        'fields' => [
            'category' => 'Категория: :value',
            'severity' => 'Важность: :value',
            'department' => 'Отдел: :value',
            'due' => 'Срок: :value',
        ],
        'incident_created' => [
            'subject' => 'Новый инцидент :number',
            'intro' => 'Вам назначен новый инцидент. Сообщил: :reporter.',
        ],
        'incident_status' => [
            'subject' => 'Инцидент :number: :status',
            'intro' => 'Статус вашего инцидента изменён на «:status».',
        ],
        'task_assigned' => [
            'subject' => 'Новая задача :number',
            'intro' => 'У вас новая задача «:title» от: :by.',
        ],
        'task_reminder' => [
            'subject' => 'Напоминание: задача :number',
            'pre_due' => 'Скоро срок задачи «:title» (:due).',
            'due' => 'Наступил срок задачи «:title» (:due).',
            'overdue_1' => 'Задача «:title» просрочена (срок :due).',
            'overdue_2' => 'Задача «:title» всё ещё просрочена (срок :due).',
            'overdue_3' => 'Задача «:title» просрочена третий день (срок :due).',
            'escalation' => 'Задача «:title» просрочена (срок :due).',
        ],
        'task_escalated' => [
            'subject' => 'Просроченная задача :number',
            'intro' => 'Задача «:title» исполнителя :assignee просрочена (срок :due).',
        ],
        'question_escalated' => [
            'subject' => 'Вопрос :number ждёт вашего ответа',
            'intro' => ':employee задал вопрос, на который ассистент не нашёл ответа в утверждённых документах.',
        ],
        'question_answered' => [
            'subject' => 'Ответ менеджера (не из регламента)',
            'intro' => ':manager ответил на ваш вопрос:',
        ],
        'review_due' => [
            'subject' => 'Пора пересмотреть: :title',
            'intro' => 'Срок пересмотра документа «:title» наступил :date.',
        ],
        'limits' => [
            'subject' => 'Достигнут лимит тарифа',
            'intro' => 'Пространство достигло лимита «:limit» (:used из :max). Перейдите на другой тариф, чтобы добавлять дальше.',
        ],
        'bot_revoked' => [
            'subject' => 'Токен бота компании отозван',
            'intro' => 'Telegram больше не принимает токен бота :username. Сотрудники не смогут писать боту, пока вы не подключите новый токен.',
        ],
    ],
];
