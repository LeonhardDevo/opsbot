<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\PlanCatalog;
use App\Models\AuditLog;
use App\Support\Paginated;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditController extends Controller
{
    private const array SOURCES = ['web', 'bot', 'ai_proposal', 'system'];

    public function index(Request $request, CurrentWorkspace $current, PlanCatalog $catalog): Response
    {
        $this->authorize('audit.view');
        $workspace = $current->get();
        abort_if($workspace === null, 404);

        $filters = $this->filters($request);

        $logs = $this->query($workspace->id, $workspace->timezone, $filters)
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $actions = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();

        return Inertia::render('audit/Index', [
            'logs' => Paginated::from($logs, fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => self::actionLabel($log->action),
                'actor' => $log->actor_id !== null ? ['id' => $log->actor_id, 'name' => (string) $log->actor_name] : null,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_label' => $log->subject_label,
                'changes' => $log->changes,
                'source' => is_string($log->metadata['source'] ?? null) ? $log->metadata['source'] : null,
                'ip' => $log->ip,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
            'actions' => $actions,
            'actionLabels' => array_combine($actions, array_map(self::actionLabel(...), $actions)) ?: (object) [],
            'actors' => AuditLog::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('actor_id')
                ->select(['actor_id', 'actor_name'])
                ->distinct()
                ->orderBy('actor_name')
                ->get()
                ->map(fn (AuditLog $log): array => ['id' => (int) $log->actor_id, 'name' => (string) $log->actor_name])
                ->unique('id')
                ->values()
                ->all(),
            'can' => ['export' => $catalog->hasFeature($workspace->planKey(), 'audit_export')],
        ]);
    }

    public function export(Request $request, CurrentWorkspace $current, PlanCatalog $catalog): StreamedResponse
    {
        $this->authorize('audit.view');
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        abort_unless($catalog->hasFeature($workspace->planKey(), 'audit_export'), 403);

        $query = $this->query($workspace->id, $workspace->timezone, $this->filters($request))->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['id', 'created_at', 'action', 'actor_id', 'actor_name', 'subject_type', 'subject_id', 'subject_label', 'source', 'ip', 'changes'], escape: '');

            foreach ($query->lazyById(500) as $log) {
                fputcsv($out, [
                    $log->id,
                    $log->created_at->toIso8601String(),
                    $log->action,
                    $log->actor_id,
                    $log->actor_name,
                    $log->subject_type,
                    $log->subject_id,
                    self::safeCell($log->subject_label),
                    is_string($log->metadata['source'] ?? null) ? $log->metadata['source'] : '',
                    $log->ip,
                    $log->changes !== null ? json_encode($log->changes, JSON_UNESCAPED_UNICODE) : '',
                ], escape: '');
            }

            fclose($out);
        }, 'audit-'.$workspace->slug.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'actor' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['nullable', 'string', Rule::in(self::SOURCES)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function query(int $workspaceId, string $timezone, array $filters): Builder
    {
        return AuditLog::query()
            ->where('workspace_id', $workspaceId)
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => str_ends_with($action, '.*')
                ? $query->where('action', 'like', addcslashes(substr($action, 0, -1), '%_\\').'%')
                : $query->where('action', $action))
            ->when($filters['actor'] ?? null, fn (Builder $query, int|string $actor) => $query->where('actor_id', (int) $actor))
            ->when($filters['source'] ?? null, fn (Builder $query, string $source) => $query->where('metadata->source', $source))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where(
                'created_at',
                '>=',
                Carbon::createFromFormat('Y-m-d', $from, $timezone)?->startOfDay()->utc(),
            ))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where(
                'created_at',
                '<=',
                Carbon::createFromFormat('Y-m-d', $to, $timezone)?->endOfDay()->utc(),
            ));
    }

    private static function safeCell(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }

    private static function actionLabel(string $action): string
    {
        $key = 'audit.actions.'.$action;

        return Lang::has($key) ? (string) __($key) : $action;
    }
}
