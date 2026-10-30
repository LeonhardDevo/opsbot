<?php

declare(strict_types=1);

return [

    'actions' => [
        'auth' => [
            'login' => 'Вход в систему',
            'logout' => 'Выход из системы',
            'login_failed' => 'Неудачная попытка входа',
            'lockout' => 'Вход заблокирован после множества попыток',
            'registered' => 'Создан аккаунт',
            'email_verified' => 'Почта подтверждена',
            'password_reset' => 'Пароль сброшен',
            'two_factor_enabled' => 'Включена двухфакторная аутентификация',
            'account_deleted' => 'Аккаунт удалён',
            'two_factor_disabled' => 'Отключена двухфакторная аутентификация',
        ],
        'workspace' => [
            'created' => 'Пространство создано',
            'updated' => 'Настройки пространства изменены',
            'deleted' => 'Пространство удалено',
            'onboarding_step_skipped' => 'Шаг настройки пропущен',
            'onboarding_finished' => 'Настройка завершена',
            'deletion_scheduled' => 'Запланировано удаление пространства',
            'deletion_cancelled' => 'Удаление пространства отменено',
        ],
        'member' => [
            'role_changed' => 'Изменена роль участника',
            'removed' => 'Участник удалён',
            'left' => 'Участник покинул пространство',
        ],
        'invitation' => [
            'created' => 'Отправлено приглашение',
            'revoked' => 'Приглашение отозвано',
            'accepted' => 'Приглашение принято',
        ],
        'billing' => [
            'subscription_changed' => 'Изменена подписка',
        ],
        'ai' => [
            'settings_updated' => 'Изменены настройки ИИ',
            'key_updated' => 'Сохранён ключ провайдера ИИ',
            'key_cleared' => 'Удалён ключ провайдера ИИ',
        ],
        'document' => [
            'uploaded' => 'Документ загружен',
            'updated' => 'Данные документа изменены',
            'deleted' => 'Документ удалён',
            'approved' => 'Документ утверждён',
            'archived' => 'Документ перенесён в архив',
            'metadata_updated' => 'Данные документа изменены',
            'imported_starter_pack' => 'Импортирован стартовый набор',
            'viewed_via_bot' => 'Документ открыт из Telegram',
        ],
        'telegram' => [
            'linked' => 'Telegram подключён',
            'unlinked' => 'Telegram отключён',
        ],
        'assistant' => [
            'escalated' => 'Вопрос передан администраторам',
        ],
        'telegram_bot' => [
            'connected' => 'Бот компании подключён',
            'token_rotated' => 'Токен бота заменён',
            'disconnect_requested' => 'Запрошено отключение бота',
            'disconnected' => 'Бот отключён',
            'revoked' => 'Токен бота отозван в Telegram',
        ],
        'employee' => [
            'created' => 'Сотрудник добавлен',
            'updated' => 'Данные сотрудника изменены',
            'link_issued' => 'Выдана ссылка-приглашение',
            'linked' => 'Сотрудник подключил Telegram',
            'offboarded' => 'Сотрудник отключён',
            'reactivated' => 'Сотрудник восстановлен',
            'data_exported' => 'Выгружены данные сотрудника',
            'erased' => 'Данные сотрудника удалены',
        ],
        'department' => [
            'created' => 'Отдел создан',
            'updated' => 'Отдел изменён',
            'archived' => 'Отдел перенесён в архив',
        ],
        'routing_rules' => [
            'updated' => 'Правила маршрутизации изменены',
        ],
        'incident' => [
            'created' => 'Инцидент создан',
            'status_changed' => 'Статус инцидента изменён',
            'assigned' => 'Ответственный по инциденту изменён',
            'updated' => 'Инцидент изменён',
            'commented' => 'Добавлен комментарий к инциденту',
        ],
        'task' => [
            'created' => 'Задача создана',
            'status_changed' => 'Статус задачи изменён',
            'assigned' => 'Исполнитель задачи изменён',
            'updated' => 'Задача изменена',
        ],
        'question' => [
            'escalated' => 'Вопрос передан менеджеру',
            'answered' => 'На вопрос ответили',
            'dismissed' => 'Вопрос отклонён',
            'converted_to_doc' => 'Вопрос перенесён в черновик FAQ',
        ],
        'ai_query' => [
            'viewed' => 'Просмотрен журнал вопросов',
        ],
        'ops_settings' => [
            'updated' => 'Настройки ассистента изменены',
        ],
        'legal' => [
            'employee_notice_acknowledged' => 'Сотрудник подтвердил уведомление',
            'dpa_accepted' => 'Принято соглашение об обработке данных',
        ],
        'retention' => [
            'purged' => 'Удалены записи с истёкшим сроком хранения',
        ],
        'legal' => [
            'accepted' => 'Принят юридический документ',
        ],
        'privacy' => [
            'export_requested' => 'Запрошена выгрузка персональных данных',
            'workspace_export_requested' => 'Запрошена выгрузка рабочего пространства',
            'export_ready' => 'Выгрузка данных готова',
            'export_failed' => 'Ошибка выгрузки данных',
            'export_downloaded' => 'Выгрузка данных скачана',
            'deletion_scheduled' => 'Запланировано удаление аккаунта',
            'deletion_cancelled' => 'Удаление аккаунта отменено',
            'deletion_completed' => 'Аккаунт удалён безвозвратно',
            'workspace_purged' => 'Данные рабочего пространства удалены',
            'settings_updated' => 'Изменены настройки конфиденциальности',
            'retention_purged' => 'Выполнена очистка по срокам хранения',
        ],
        'email' => [
            'opted_out' => 'Получатель отписался от автоматических писем',
        ],
    ],

    'system' => 'Система',

];
