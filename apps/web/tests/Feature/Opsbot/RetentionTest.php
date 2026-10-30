<?php

declare(strict_types=1);

use App\Enums\QuestionStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiQuery;
use App\Models\AuditLog;
use App\Models\EmployeeLink;
use App\Models\Incident;
use App\Models\UnansweredQuestion;

it('purges records past their retention and keeps business records', function (): void {
    [$employee, $workspace] = $this->member(WorkspaceRole::Employee);
    $old = $this->aiQuery($workspace, $employee, ['created_at' => now()->subDays(181)]);
    $recent = $this->aiQuery($workspace, $employee, ['created_at' => now()->subDays(10)]);
    $incident = $this->incident($workspace, ['ai_query_id' => $old->id]);
    $closed = $this->question($workspace, $employee, ['status' => QuestionStatus::Answered, 'answered_at' => now()->subDays(366), 'ai_query_id' => $old->id]);
    $openOld = $this->question($workspace, $employee, ['created_at' => now()->subDays(400)]);
    EmployeeLink::query()->create(['workspace_id' => $workspace->id, 'user_id' => $employee->id, 'bot_key' => 'platform', 'token_hash' => str_repeat('e', 64), 'expires_at' => now()->subDays(31)]);
    EmployeeLink::query()->create(['workspace_id' => $workspace->id, 'user_id' => $employee->id, 'bot_key' => 'platform', 'token_hash' => str_repeat('f', 64), 'expires_at' => now()->addDay()]);

    $this->artisan('privacy:purge')->assertSuccessful();

    expect(AiQuery::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(Incident::query()->sole()->ai_query_id)->toBeNull()
        ->and($incident->exists)->toBeTrue()
        ->and(UnansweredQuestion::query()->pluck('id')->all())->toBe([$openOld->id])
        ->and(UnansweredQuestion::query()->whereKey($closed->id)->exists())->toBeFalse()
        ->and(EmployeeLink::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'privacy.retention_purged')->sole()->metadata['retention'])->toMatchArray(['ai_queries' => 1, 'unanswered_questions' => 1, 'employee_links' => 1]);
});
