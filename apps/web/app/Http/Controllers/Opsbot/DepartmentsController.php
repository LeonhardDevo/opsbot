<?php

declare(strict_types=1);

namespace App\Http\Controllers\Opsbot;

use App\Domain\Opsbot\DepartmentService;
use App\Domain\Opsbot\Presenters\Refs;
use App\Domain\Opsbot\RoutingResolver;
use App\Enums\RoutingCategory;
use App\Models\Department;
use App\Models\DepartmentMember;
use App\Models\Document;
use App\Models\RoutingRule;
use App\Support\Flash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class DepartmentsController extends OpsController
{
    public function index(): Response
    {
        $actor = $this->actor();
        $this->require($actor, 'departments.manage');
        $workspace = $actor->workspace;

        $departments = Department::query()->forWorkspace($workspace)->with('manager')->orderByRaw('archived_at IS NOT NULL')->orderBy('name')->get();
        $members = DepartmentMember::query()->where('department_user.workspace_id', $workspace->id)
            ->join('workspace_user', fn ($join) => $join->on('workspace_user.user_id', '=', 'department_user.user_id')->where('workspace_user.workspace_id', $workspace->id))
            ->where('workspace_user.status', '!=', 'offboarded')
            ->selectRaw('department_user.department_id, count(*) AS total')
            ->groupBy('department_user.department_id')
            ->pluck('total', 'department_user.department_id');
        $documents = Document::query()->forWorkspace($workspace)->notArchived()->get(['id', 'department_ids']);

        return Inertia::render('departments/Index', [
            'departments' => $departments->map(fn (Department $department): array => [
                ...$department->toRef(),
                'manager' => Refs::user($department->manager),
                'members_count' => (int) ($members[$department->id] ?? 0),
                'documents_count' => $documents->filter(fn (Document $document): bool => in_array($department->id, $document->department_ids, true))->count(),
                'archived_at' => $department->archived_at?->toIso8601String(),
            ])->values()->all(),
            'rules' => RoutingRule::query()->forWorkspace($workspace)->with('manager')->orderBy('category')->orderBy('department_id')->get()
                ->map(fn (RoutingRule $rule): array => [
                    'id' => $rule->id,
                    'department_id' => $rule->department_id,
                    'category' => $rule->category->value,
                    'manager' => $rule->manager->toRef(),
                ])->values()->all(),
            'categories' => RoutingCategory::options(),
            'managers' => $this->assignableUsers($workspace, 'questions.answer'),
            'fallbackOwner' => Refs::user($workspace->oldestActiveOwner()),
        ]);
    }

    public function store(Request $request, DepartmentService $departments): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'manager_user_id' => ['nullable', 'integer'],
        ]);

        $department = $departments->create($this->actor(), [
            'name' => (string) $data['name'],
            'code' => $data['code'] ?? null,
            'manager_user_id' => isset($data['manager_user_id']) ? (int) $data['manager_user_id'] : null,
        ]);

        Flash::success('flash.departments.created', ['name' => $department->name]);

        return back();
    }

    public function update(Request $request, Department $department, DepartmentService $departments): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = (string) $data['name'];
        }

        if (array_key_exists('code', $data)) {
            $payload['code'] = $data['code'] !== null ? (string) $data['code'] : null;
        }

        if (array_key_exists('manager_user_id', $data)) {
            $payload['manager_user_id'] = $data['manager_user_id'] !== null ? (int) $data['manager_user_id'] : null;
        }

        $departments->update($this->actor(), $department, $payload);
        Flash::success('flash.departments.updated', ['name' => $department->name]);

        return back();
    }

    public function destroy(Department $department, DepartmentService $departments): RedirectResponse
    {
        $departments->archive($this->actor(), $department);
        Flash::success('flash.departments.archived', ['name' => $department->name]);

        return back();
    }

    public function routing(Request $request, DepartmentService $departments): RedirectResponse
    {
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:200'],
            'rules.*.department_id' => ['nullable', 'integer'],
            'rules.*.category' => ['required', 'string', Rule::enum(RoutingCategory::class)],
            'rules.*.manager_user_id' => ['required', 'integer'],
        ]);

        $departments->replaceRules($this->actor(), array_values(array_map(fn (array $rule): array => [
            'department_id' => isset($rule['department_id']) ? (int) $rule['department_id'] : null,
            'category' => (string) $rule['category'],
            'manager_user_id' => (int) $rule['manager_user_id'],
        ], (array) $data['rules'])));

        Flash::success('flash.routing.updated');

        return back();
    }

    public function preview(Request $request, RoutingResolver $routing): JsonResponse
    {
        $actor = $this->actor();
        $this->require($actor, 'incidents.create');

        $data = $request->validate([
            'category' => ['required', 'string', Rule::enum(RoutingCategory::class)],
            'department_id' => ['nullable', 'integer'],
        ]);

        $result = $routing->resolve($actor->workspace, RoutingCategory::from((string) $data['category']), isset($data['department_id']) ? (int) $data['department_id'] : null);

        return response()->json([
            'user' => Refs::user($result['user']),
            'source' => $result['source'],
        ]);
    }
}
