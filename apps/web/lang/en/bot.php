<?php

declare(strict_types=1);

return [
    'status' => [
        'connecting' => 'Connecting',
        'active' => 'Active',
        'invalid_token' => 'Invalid token',
        'revoked' => 'Token revoked',
        'disconnecting' => 'Disconnecting',
        'disconnected' => 'Disconnected',
    ],
    'errors' => [
        'invalid_token' => 'Telegram rejected this token. Copy it again from BotFather.',
        'token_mismatch' => 'This token belongs to a different bot.',
        'already_connected' => 'This bot is already connected to another OpsBot workspace.',
        'network' => 'Telegram could not be reached. Try again in a minute.',
        'webhook_failed' => 'The webhook could not be set. The worker needs a public HTTPS address.',
        'revoked' => 'The token was revoked in BotFather. Connect a new token.',
    ],
    'commands' => [
        'start' => 'Start and link your account',
        'help' => 'What the bot can do',
        'lang' => 'Change the language',
        'whoami' => 'Your name, company, role and departments',
        'history' => 'Your last 10 questions',
        'tasks' => 'Your open tasks',
        'incident' => 'Report an incident',
        'ask_manager' => 'Pass your last question to a manager',
        'queue' => 'Questions waiting for your answer',
        'privacy' => 'What is stored and who sees it',
        'unlink' => 'Disconnect your account from the bot',
    ],
    'desk' => [
        'revoked' => 'Bot @:username: token revoked',
        'invalid_token' => 'Bot @:username: invalid token',
        'active' => 'Bot @:username is active',
        'connecting' => 'Bot @:username is connecting',
        'disconnecting' => 'Bot @:username is disconnecting',
        'disconnected' => 'Bot @:username is disconnected',
    ],
    'test_message_html' => '<b>Test message</b>
The company bot for <b>:workspace</b> is connected and can send you notifications.',
    'access_revoked_html' => 'Your access to the <b>:workspace</b> assistant has ended. Thank you for your work.',
];
