<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Jobs;

use App\Knowledge\DocumentService;
use App\Models\AiQuery;
use App\Models\DepartmentMember;
use App\Models\EmployeeExport;
use App\Models\Incident;
use App\Models\LegalAcceptance;
use App\Models\Task;
use App\Models\TelegramAccount;
use App\Models\UnansweredQuestion;
use App\Models\WorkspaceMembership;
use App\Notifications\EmployeeExportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

final class BuildEmployeeExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $exportId) {}

    public function handle(): void
    {
        $export = EmployeeExport::query()->find($this->exportId);

        if ($export === null || $export->status === 'ready') {
            return;
        }

        $files = self::collect($export->workspace_id, $export->subject_user_id);
        $tmp = tempnam(sys_get_temp_dir(), 'emp-export-');

        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary export file.');
        }

        try {
            $zip = new ZipArchive;
            $zip->open($tmp, ZipArchive::OVERWRITE);

            foreach ($files as $name => $data) {
                $zip->addFromString($name.'.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $zip->close();

            $disk = DocumentService::disk();
            $path = 'exports/'.$export->workspace_id.'/'.$export->id.'.zip';
            Storage::disk($disk)->put($path, (string) file_get_contents($tmp));
        } finally {
            @unlink($tmp);
        }

        $export->forceFill(['status' => 'ready', 'disk' => $disk, 'path' => $path, 'completed_at' => now()])->save();

        $requester = $export->requester;
        $requester?->notify(new EmployeeExportReady($export));
    }

    /** @return array<string, mixed> */
    public static function collect(int $workspaceId, int $userId): array
    {
        $membership = WorkspaceMembership::query()->where('workspace_id', $workspaceId)->where('user_id', $userId)->first();
        $user = $membership?->user;

        return [
            'profile' => [
                'id' => $userId,
                'name' => $user?->displayName(),
                'email' => $user?->email,
                'locale' => $user?->locale,
                'phone' => $membership?->phone,
                'position' => $membership?->position,
                'role' => $membership?->role->value,
                'kind' => $membership?->kind->value,
                'status' => $membership?->status->value,
                'departments' => DepartmentMember::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('user_id', $userId)
                    ->with('department')
                    ->get()
                    ->map(fn (DepartmentMember $row): array => ['name' => $row->department->name, 'primary' => $row->is_primary])
                    ->values()
                    ->all(),
            ],
            'telegram' => TelegramAccount::query()->where('user_id', $userId)->where('workspace_id', $workspaceId)->get()
                ->map(fn (TelegramAccount $account): array => [
                    'username' => $account->username,
                    'linked_at' => $account->linked_at->toIso8601String(),
                ])->values()->all(),
            'ai_queries' => AiQuery::query()->forWorkspace($workspaceId)->where('user_id', $userId)->orderBy('id')->get()
                ->map(fn (AiQuery $query): array => [
                    'question' => $query->question,
                    'answer' => $query->answer,
                    'refused' => $query->refused,
                    'citations' => array_map(fn (array $citation): array => ['document_id' => $citation['document_id'] ?? null, 'title' => $citation['title'] ?? null, 'page' => $citation['page'] ?? null], $query->citations),
                    'created_at' => $query->created_at->toIso8601String(),
                ])->values()->all(),
            'incidents_reported' => Incident::query()->forWorkspace($workspaceId)->where('reporter_id', $userId)->orderBy('id')->get()
                ->map(fn (Incident $incident): array => [
                    'number' => $incident->number,
                    'category' => $incident->category->value,
                    'status' => $incident->status->value,
                    'description' => $incident->description,
                    'created_at' => $incident->created_at->toIso8601String(),
                ])->values()->all(),
            'tasks_assigned' => Task::query()->forWorkspace($workspaceId)->where('assignee_id', $userId)->orderBy('id')->get()
                ->map(fn (Task $task): array => [
                    'number' => $task->number,
                    'title' => $task->title,
                    'status' => $task->status->value,
                    'due_at' => $task->due_at?->toIso8601String(),
                ])->values()->all(),
            'unanswered_questions' => UnansweredQuestion::query()->forWorkspace($workspaceId)->where('employee_user_id', $userId)->orderBy('id')->get()
                ->map(fn (UnansweredQuestion $question): array => [
                    'number' => $question->number,
                    'question' => $question->question,
                    'status' => $question->status->value,
                    'answer' => $question->answer_text,
                    'created_at' => $question->created_at->toIso8601String(),
                ])->values()->all(),
            'legal_acceptances' => LegalAcceptance::query()->where('user_id', $userId)->where('workspace_id', $workspaceId)->orderBy('id')->get()
                ->map(fn (LegalAcceptance $acceptance): array => [
                    'document' => $acceptance->document,
                    'version' => $acceptance->version,
                    'accepted_at' => $acceptance->accepted_at->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
