<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Domain\Opsbot\DepartmentScope;
use App\Domain\Opsbot\EscalationService;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Presenters\QuestionPresenter;
use App\Domain\Opsbot\QuestionGrouper;
use App\Domain\Opsbot\Support\DisplayNumber;
use App\Enums\DismissReason;
use App\Enums\QuestionStatus;
use App\Models\UnansweredQuestion;
use App\Support\Flash;
use App\Support\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class UnansweredController extends OpsController
{
    public function index(Request $request, DepartmentScope $scope, QuestionGrouper $grouper): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'questions.answer');

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in([...QuestionStatus::values(), 'all'])],
            'department_id' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'regex:/^(me|[0-9]+)$/'],
            'focus' => ['nullable', 'integer'],
        ]);

        $status = $filters['status'] ?? QuestionStatus::Open->value;
        $now = CarbonImmutable::now();

        $base = fn (): Builder => $scope->questions(UnansweredQuestion::query()->forWorkspace($actor->workspace), $actor)
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $department) => $query->where('department_id', (int) $department))
            ->when($filters['assignee'] ?? null, fn (Builder $query, string $assignee) => $query->where('assignee_user_id', $assignee === 'me' ? $actor->id() : (int) $assignee));

        $openRows = DB::table('unanswered_questions')
            ->whereIn('id', $base()->where('status', QuestionStatus::Open->value)->select('id'))
            ->selectRaw("id, question, regexp_replace(lower(public.f_unaccent(question)), '\\s+', ' ', 'g') AS normalized, created_at")
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get();

        $groups = $grouper->group(array_values($openRows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'normalized' => (string) $row->normalized,
            'text' => (string) $row->question,
            'at' => CarbonImmutable::parse((string) $row->created_at)->utc()->toIso8601String(),
        ])->all()));

        $similar = [];

        foreach ($groups as $group) {
            foreach ($group['ids'] as $id) {
                $similar[$id] = $group['count'] - 1;
            }
        }

        $page = $base()
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->with(QuestionPresenter::relations())
            ->orderBy('created_at', $status === QuestionStatus::Open->value ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $counts = [];

        foreach (QuestionStatus::cases() as $case) {
            $counts[$case->value] = $base()->where('status', $case->value)->count();
        }

        $counts['overdue_24h'] = $base()
            ->where('status', QuestionStatus::Open->value)
            ->where('created_at', '<', $now->subHours((int) config('opsbot.question_sla_hours', 24)))
            ->count();

        return Inertia::render('unanswered/Index', [
            'questions' => Paginated::from($page, fn (UnansweredQuestion $question): array => QuestionPresenter::present($question, $similar[$question->id] ?? 0, $now)),
            'groups' => array_values(array_map(fn (array $group): array => [
                'representative' => $group['representative'],
                'count' => $group['count'],
                'question_ids' => $group['ids'],
            ], array_filter($groups, fn (array $group): bool => $group['count'] > 1))),
            'filters' => array_filter([...$filters, 'status' => $status], fn (mixed $value): bool => $value !== null && $value !== ''),
            'counts' => $counts,
            'departments' => $this->departmentRefs($actor->workspace),
            'assignees' => $this->assignableUsers($actor->workspace, 'questions.answer'),
        ]);
    }

    public function answer(Request $request, UnansweredQuestion $question, EscalationService $escalations): RedirectResponse
    {
        $data = $request->validate(['answer_text' => ['required', 'string', 'min:1', 'max:2000']]);

        $escalations->answer($this->actor(), $question, (string) $data['answer_text']);
        Flash::success('flash.questions.answered', ['number' => DisplayNumber::question($question->number)]);

        return back();
    }

    public function dismiss(Request $request, UnansweredQuestion $question, EscalationService $escalations): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', Rule::enum(DismissReason::class)]]);

        $escalations->dismiss($this->actor(), $question, DismissReason::from((string) $data['reason']));
        Flash::success('flash.questions.dismissed', ['number' => DisplayNumber::question($question->number)]);

        return back();
    }

    public function convert(Request $request, UnansweredQuestion $question, EscalationService $escalations): RedirectResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:250']]);

        $question = $escalations->convert($this->actor(), $question, $data['title'] ?? null);

        if ($question->document_id === null) {
            throw new OpsbotException('questions.not_answered');
        }

        Flash::success('flash.questions.converted');

        return redirect()->route('knowledge.show', ['document' => $question->document_id]);
    }

    public function update(Request $request, UnansweredQuestion $question, EscalationService $escalations): RedirectResponse
    {
        $data = $request->validate(['assignee_user_id' => ['required', 'integer']]);

        $escalations->reassign($this->actor(), $question, (int) $data['assignee_user_id']);
        Flash::success('flash.questions.reassigned');

        return back();
    }
}
