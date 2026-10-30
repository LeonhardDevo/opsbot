<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\DocumentProcessed;
use App\Notifications\InAppMessage;
use Illuminate\Notifications\DatabaseNotification;

it('shares the in-app feed of the current workspace, translated for the reader', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Admin);
    $user->forceFill(['locale' => 'ru'])->save();
    $other = Workspace::factory()->create();
    $mine = Document::factory()->indexed(chunks: 7)->create(['workspace_id' => $workspace->id, 'title' => 'Регламент']);
    $foreign = Document::factory()->indexed()->create(['workspace_id' => $other->id, 'title' => 'Other workspace']);

    $user->notify(new DocumentProcessed($mine));
    $user->notify(new DocumentProcessed($foreign));

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('notifications.unread', 1)
            ->has('notifications.items', 1)
            ->where('notifications.items.0.kind', 'success')
            ->where('notifications.items.0.read', false)
            ->where('notifications.items.0.href', '/knowledge/'.$mine->id)
            ->where('notifications.items.0.title', __('notifications.in_app.document_indexed.title', ['title' => 'Регламент'], 'ru'))
            ->where('notifications.items.0.body', __('notifications.in_app.document_indexed.body', ['chunks' => 7], 'ru')));
});

it('shares null notifications for guests', function (): void {
    $this->get(route('home'))->assertInertia(fn ($page) => $page->where('notifications', null));
});

it('marks one or all notifications of the current workspace as read', function (): void {
    [$user, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    $document = Document::factory()->indexed()->create(['workspace_id' => $workspace->id, 'uploaded_by' => $user->id]);
    $user->notify(new DocumentProcessed($document));
    $user->notify(new DocumentProcessed($document));
    $foreign = User::factory()->create();
    $foreign->notify(new DocumentProcessed($document));

    $first = DatabaseNotification::query()->where('notifiable_id', $user->id)->firstOrFail();

    $this->postJson(route('notifications.read'), ['id' => $first->id])->assertOk()->assertExactJson(['marked' => 1, 'unread' => 1]);
    $this->postJson(route('notifications.read'))->assertOk()->assertExactJson(['marked' => 1, 'unread' => 0]);
    $this->postJson(route('notifications.read'), ['id' => 'not-a-uuid'])->assertUnprocessable();

    $this->from(route('dashboard'))->post(route('notifications.read'))->assertRedirect(route('dashboard'));
    expect(DatabaseNotification::query()->where('notifiable_id', $foreign->id)->whereNull('read_at')->count())->toBe(1);
});

it('notifies the uploader once when the ai service finishes a document', function (): void {
    [$user, $workspace] = $this->member(WorkspaceRole::Owner);
    $document = Document::factory()->create(['workspace_id' => $workspace->id, 'uploaded_by' => $user->id, 'title' => 'Refund policy']);

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'processing', 'upload_rev' => 1])->assertOk();
    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'indexed', 'chunks' => 3, 'upload_rev' => 1])->assertOk();
    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'indexed', 'chunks' => 3, 'upload_rev' => 1])->assertOk();

    $notification = DatabaseNotification::query()->where('notifiable_id', $user->id)->sole();
    expect($notification->type)->toBe(DocumentProcessed::class)
        ->and($notification->data['kind'])->toBe('success')
        ->and($notification->data['href'])->toBe('/knowledge/'.$document->id)
        ->and($notification->data['title'])->toBe(['key' => 'notifications.in_app.document_indexed.title', 'params' => ['title' => 'Refund policy']]);

    $this->internal('POST', "/internal/v1/documents/{$document->id}/status", ['status' => 'failed', 'error' => 'the PDF is password-protected', 'upload_rev' => 1], 'worker')->assertOk();
    $failed = DatabaseNotification::query()->where('notifiable_id', $user->id)->where('data->kind', 'danger')->sole();
    expect($failed->data['title']['key'])->toBe('notifications.in_app.document_failed.title')
        ->and($failed->data['body']['params']['error'])->toBe('the PDF is password-protected');
});

it('rejects unknown kinds and absolute links in in-app messages', function (string $kind, ?string $href): void {
    new InAppMessage($kind, 'x', href: $href);
})->with([
    ['party', null],
    ['info', 'https://evil.example/'],
    ['info', '//evil.example/'],
])->throws(InvalidArgumentException::class);
