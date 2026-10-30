<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Enums\QuestionStatus;
use App\Models\EmployeeExport;
use App\Models\EmployeeLink;
use App\Models\UnansweredQuestion;
use App\Privacy\RetentionExtension;
use App\Privacy\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class RetentionPurge implements RetentionExtension
{
    public function __construct(private RetentionPolicy $policy) {}

    /** @return array{unanswered_questions: int, employee_links: int, employee_exports: int, limit_notices: int} */
    public function purge(CarbonImmutable $now): array
    {
        $counts = DB::transaction(function () use ($now): array {
            $questionCutoff = $now->subDays($this->policy->default('unanswered_questions'));
            $linkCutoff = $now->subDays($this->policy->default('employee_links'));

            return [
                'unanswered_questions' => UnansweredQuestion::query()->withoutGlobalScopes()
                    ->where(fn ($query) => $query
                        ->where(fn ($inner) => $inner->whereIn('status', [QuestionStatus::Answered->value, QuestionStatus::ConvertedToDoc->value])->where('answered_at', '<', $questionCutoff))
                        ->orWhere(fn ($inner) => $inner->where('status', QuestionStatus::Dismissed->value)->where('dismissed_at', '<', $questionCutoff)))
                    ->delete(),
                'employee_links' => EmployeeLink::query()
                    ->where(fn ($query) => $query
                        ->where('expires_at', '<', $linkCutoff)
                        ->orWhere('used_at', '<', $linkCutoff)
                        ->orWhere('revoked_at', '<', $linkCutoff))
                    ->delete(),
                'employee_exports' => 0,
                'limit_notices' => DB::table('limit_notices')
                    ->where('notified_on', '<', $now->subDays($this->policy->default('limit_notices'))->toDateString())
                    ->delete(),
            ];
        });

        $exports = EmployeeExport::query()->where('expires_at', '<', $now)->get();

        foreach ($exports as $export) {
            if ($export->disk !== null && $export->path !== null) {
                Storage::disk($export->disk)->delete($export->path);
            }

            $export->delete();
        }

        $counts['employee_exports'] = $exports->count();

        return $counts;
    }
}
