<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Actions;

final readonly class ActionOutcome
{
    /**
     * @param  array{title: string, fields: list<array{label: string, value: string}>}|null  $card
     * @param  array{type: string, id: int, display_number: string, url: string}|null  $result
     * @param  list<array{text: string, callback?: string, url?: string}>  $buttons
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public ?array $card = null,
        public ?array $result = null,
        public array $buttons = [],
        public bool $alert = false,
        public ?string $messageHtml = null,
    ) {}

    public static function failure(string $message): self
    {
        return new self(false, $message, alert: true);
    }

    /** @return array<string, mixed> */
    public function toWeb(): array
    {
        return array_filter([
            'ok' => $this->ok,
            'message' => $this->message,
            'card' => $this->card,
            'result' => $this->result,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public function toTelegram(): array
    {
        $html = $this->messageHtml ?? $this->cardHtml() ?? e($this->message);

        return array_filter([
            'ok' => $this->ok,
            'message' => mb_substr($this->message, 0, 190),
            'message_html' => $html,
            'alert' => $this->alert ?: null,
            'buttons' => $this->buttons !== [] ? $this->buttons : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function cardHtml(): ?string
    {
        if ($this->card === null) {
            return null;
        }

        $lines = ['<b>'.e($this->card['title']).'</b>'];

        foreach ($this->card['fields'] as $field) {
            $lines[] = '<b>'.e($field['label']).':</b> '.e($field['value']);
        }

        return implode("\n", $lines);
    }
}
