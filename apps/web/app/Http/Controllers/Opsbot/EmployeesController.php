<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Billing\PlanLimits;
use App\Domain\Opsbot\EmployeeLinkService;
use App\Domain\Opsbot\EmployeeService;
use App\Domain\Opsbot\OpsbotException;
use App\Domain\Opsbot\Presenters\BotPresenter;
use App\Domain\Opsbot\Presenters\EmployeePresenter;
use App\Domain\Opsbot\TelegramBotService;
use App\Enums\MemberStatus;
use App\Http\Requests\Opsbot\BulkLinksRequest;
use App\Http\Requests\Opsbot\StoreEmployeeRequest;
use App\Http\Requests\Opsbot\UpdateEmployeeRequest;
use App\Models\EmployeeExport;
use App\Models\TelegramBot;
use App\Models\User;
use App\Support\Flash;
use App\Support\Paginated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EmployeesController extends OpsController
{
    public function index(Request $request, PlanLimits $limits, TelegramBotService $bots): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'employees.manage');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(MemberStatus::values())],
            'telegram' => ['nullable', 'string', Rule::in(['not_linked', 'linked', 'blocked'])],
            'needs_relink' => ['nullable', 'boolean'],
        ]);

        $bot = TelegramBot::query()->where('workspace_id', $actor->workspaceId())->first();
        $delivery = $bots->deliveryBot($actor->workspace);
        $botKey = $delivery['key'] ?? null;

        $query = User::query()
            ->select('users.*')
            ->join('workspace_user', 'workspace_user.user_id', '=', 'users.id')
            ->where('workspace_user.workspace_id', $actor->workspaceId())
            ->when(! $actor->privileged(), fn (Builder $query) => $query->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('department_user')
                ->whereColumn('department_user.user_id', 'users.id')
                ->where('department_user.workspace_id', $actor->workspaceId())
                ->whereIn('department_user.department_id', $actor->departmentIds === [] ? [0] : $actor->departmentIds)))
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->where('users.name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int|string $departmentId) => $query->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('department_user')
                ->whereColumn('department_user.user_id', 'users.id')
                ->where('department_user.department_id', (int) $departmentId)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('workspace_user.status', $status))
            ->when(($filters['telegram'] ?? null) === 'not_linked', fn (Builder $query) => $query->whereNotExists(fn ($sub) => $this->accountSubquery($sub, $actor->workspaceId(), $botKey)))
            ->when(($filters['telegram'] ?? null) === 'linked', fn (Builder $query) => $query->whereExists(fn ($sub) => $this->accountSubquery($sub, $actor->workspaceId(), $botKey)->whereNull('telegram_accounts.blocked_at')))
            ->when(($filters['telegram'] ?? null) === 'blocked', fn (Builder $query) => $query->whereExists(fn ($sub) => $this->accountSubquery($sub, $actor->workspaceId(), $botKey)->whereNotNull('telegram_accounts.blocked_at')))
            ->when((bool) ($filters['needs_relink'] ?? false), fn (Builder $query) => $query
                ->where('workspace_user.kind', 'telegram_only')
                ->where('workspace_user.status', MemberStatus::Active->value)
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('telegram_accounts')->whereColumn('telegram_accounts.user_id', 'users.id')->where('telegram_accounts.bot_key', $bot !== null && $bot->isActive() ? $bot->key() : '')))
            ->orderByRaw("CASE workspace_user.status WHEN 'offboarded' THEN 1 ELSE 0 END")
            ->orderBy('users.name')
            ->orderBy('users.id');

        $page = $query->paginate(25)->withQueryString();
        $context = EmployeePresenter::context($actor->workspaceId(), array_values(array_map(fn (User $user): int => $user->id, $page->items())), $botKey);

        return Inertia::render('employees/Index', [
            'employees' => Paginated::from($page, fn (User $user): array => EmployeePresenter::present($user, $context, $actor->id())),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'departments' => $this->departmentRefs($actor->workspace),
            'seats' => ['used' => $limits->used($actor->workspace, 'seats'), 'limit' => $limits->limit($actor->workspace, 'seats')],
            'bot' => $bot !== null ? ['status' => $bot->status->value, 'username' => $bot->username] : null,
            'pendingRelink' => BotPresenter::pendingRelink($actor->workspaceId(), $bot !== null && $bot->isActive() ? $bot->key() : null),
        ]);
    }

    public function store(StoreEmployeeRequest $request, EmployeeService $employees): RedirectResponse
    {
        $employee = $employees->create($this->actor(), $request->payload());
        Flash::success('flash.employees.created', ['name' => $employee->name]);

        return back();
    }

    public function update(UpdateEmployeeRequest $request, User $user, EmployeeService $employees): RedirectResponse
    {
        $actor = $this->actor();
        $this->workspaceUser($actor->workspace, $user);

        $employees->update($actor, $user, $request->payload());
        Flash::success('flash.employees.updated', ['name' => $user->displayName()]);

        return back();
    }

    public function link(User $user, EmployeeLinkService $links): JsonResponse
    {
        $actor = $this->actor();
        $this->workspaceUser($actor->workspace, $user);

        return response()->json($links->issue($actor, $user));
    }

    public function bulkLinks(BulkLinksRequest $request, EmployeeLinkService $links): JsonResponse
    {
        return response()->json(['links' => $links->issueBulk($this->actor(), $request->userIds())]);
    }

    public function destroy(User $user, EmployeeService $employees): RedirectResponse
    {
        $actor = $this->actor();
        $this->workspaceUser($actor->workspace, $user);

        $employees->offboard($actor, $user);
        Flash::success('flash.employees.offboarded', ['name' => $user->displayName()]);

        return back();
    }

    public function reactivate(User $user, EmployeeService $employees): JsonResponse
    {
        $actor = $this->actor();
        $this->workspaceUser($actor->workspace, $user);

        return response()->json($employees->reactivate($actor, $user));
    }

    public function export(User $user, EmployeeService $employees): RedirectResponse
    {
        $actor = $this->actor();
        $this->workspaceUser($actor->workspace, $user);

        $employees->requestExport($actor, $user);
        Flash::success('flash.employees.export_queued');

        return back();
    }

    public function erase(User $user, EmployeeService $employees): RedirectResponse
    {
        $actor = $this->actor();
        $this->workspaceUser($actor->workspace, $user);

        $employees->erase($actor, $user);
        Flash::success('flash.employees.erased');

        return back();
    }

    public function download(string $export): StreamedResponse
    {
        $model = EmployeeExport::query()->with('subject')->find($export);
        $actor = $this->actor();

        if ($model === null || $model->workspace_id !== $actor->workspaceId() || $model->status !== 'ready' || $model->path === null || $model->disk === null || $model->expires_at->isPast()) {
            throw OpsbotException::notFound();
        }

        if ($model->requested_by !== $actor->id() && ! $actor->privileged()) {
            throw OpsbotException::forbidden();
        }

        return Storage::disk($model->disk)->download($model->path, 'employee-'.$model->subject_user_id.'-export.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function accountSubquery(QueryBuilder $sub, int $workspaceId, ?string $botKey): QueryBuilder
    {
        return $sub->selectRaw('1')
            ->from('telegram_accounts')
            ->whereColumn('telegram_accounts.user_id', 'users.id')
            ->where(function ($query) use ($workspaceId, $botKey): void {
                $query->where('telegram_accounts.workspace_id', $workspaceId);

                if ($botKey !== null) {
                    $query->orWhere('telegram_accounts.bot_key', $botKey);
                }
            });
    }
}
