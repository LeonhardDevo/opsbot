<?php

declare(strict_types=1);

return [
    'number' => 'Q-:number',
    'status' => [
        'open' => 'Открыт',
        'answered' => 'Отвечен',
        'converted_to_doc' => 'Перенесён в FAQ',
        'dismissed' => 'Отклонён',
    ],
    'reason' => [
        'low_confidence' => 'Низкая уверенность',
        'not_found' => 'Нет в документах',
        'conflict' => 'Противоречивые документы',
        'user_request' => 'Запрос сотрудника',
        'limit_reached' => 'Исчерпан лимит вопросов за месяц',
    ],
    'dismiss_reason' => [
        'duplicate' => 'Дубликат',
        'out_of_scope' => 'Не по теме',
        'spam' => 'Спам',
        'other' => 'Другое',
    ],
    'faq' => [
        'title' => 'FAQ: :question',
        'question' => 'Вопрос',
        'answer' => 'Ответ',
        'source' => 'Источник: ответ менеджера, :name, :date.',
    ],
    'count' => ':count вопрос|:count вопроса|:count вопросов',
];
