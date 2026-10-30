<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use App\Audit\Auditor;
use App\Domain\Opsbot\Access\Actor;
use App\Enums\MemberStatus;
use App\Enums\RoutingCategory;
use App\Enums\WorkspaceRole;
use App\Models\Department;
use App\Models\RoutingRule;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DepartmentService
{
    public function __construct(private Auditor $auditor) {}

    /**
     * @param  array{name: string, code?: string|null, manager_user_id?: int|null}  $data
     *
     * @throws OpsbotException
     */
    public function create(Actor $actor, array $data): Department
    {
        $this->authorize($actor);
        $name = trim($data['name']);
        $code = $this->code($actor, $data['code'] ?? null, $name);
        $this->assertNameFree($actor, $name, null);
        $managerId = $this->manager($actor, $data['manager_user_id'] ?? null);

        return DB::transaction(function () use ($actor, $name, $code, $managerId): Department {
            $department = Department::query()->create([
                'workspace_id' => $actor->workspaceId(),
                'name' => $name,
                'code' => $code,
                'manager_user_id' => $managerId,
            ]);

            $this->auditor->record('department.created', $department, metadata: ['code' => $code, 'manager_user_id' => $managerId], actor: $actor->user, workspace: $actor->workspace, subjectLabel: $name);

            return $department;
        });
    }

    /**
     * @param  array{name?: string, code?: string|null, manager_user_id?: int|null}  $data
     *
     * @throws OpsbotException
     */
    public function update(Actor $actor, Department $department, array $data): Department
    {
        $this->authorize($actor);
        $this->assertWorkspace($actor, $department);

        return DB::transaction(function () use ($actor, $department, $data): Department {
            $before = $department->only(['name', 'code', 'manager_user_id']);

            if (isset($data['name'])) {
                $name = trim($data['name']);
                $this->assertNameFree($actor, $name, $department->id);
                $department->name = $name;
            }

            if (isset($data['code']) && $data['code'] !== $department->code) {
                $department->code = $this->code($actor, $data['code'], $department->name, $department->id);
            }

            if (array_key_exists('manager_user_id', $data)) {
                $department->manager_user_id = $this->manager($actor, $data['manager_user_id']);
            }

            $department->save();
            $changes = Auditor::diff($before, $department->only(['name', 'code', 'manager_user_id']));

            if ($changes !== []) {
                $this->auditor->record('department.updated', $department, $changes, actor: $actor->user, workspace: $actor->workspace, subjectLabel: $department->name);
            }

            return $department;
        });
    }

    /** @throws OpsbotException */
    public function archive(Actor $actor, Department $department): Department
    {
        $this->authorize($actor);
        $this->assertWorkspace($actor, $department);

        if ($department->archived_at !== null) {
            throw new OpsbotException('departments.already_archived');
        }

        return DB::transaction(function () use ($actor, $department): Department {
            $department->archived_at = now();
            $department->save();

            RoutingRule::query()->forWorkspace($actor->workspace)->where('department_id', $department->id)->delete();

            $this->auditor->record('department.archived', $department, ['archived_at' => [null, $department->archived_at->toIso8601String()]], actor: $actor->user, workspace: $actor->workspace, subjectLabel: $department->name);

            return $department;
        });
    }

    /**
     * @param  list<array{department_id: int|null, category: string, manager_user_id: int}>  $rules
     *
     * @throws OpsbotException
     */
    public function replaceRules(Actor $actor, array $rules): void
    {
        $this->authorize($actor);
        $seen = [];

        foreach ($rules as $rule) {
            $key = ($rule['department_id'] ?? 'null').':'.$rule['category'];

            if (isset($seen[$key])) {
                throw new OpsbotException('routing.duplicate');
            }

            $seen[$key] = true;

            if ($rule['department_id'] !== null && ! Department::query()->forWorkspace($actor->workspace)->live()->whereKey($rule['department_id'])->exists()) {
                throw new OpsbotException('departments.invalid');
            }

            RoutingCategory::from($rule['category']);
            $this->manager($actor, $rule['manager_user_id']);
        }

        DB::transaction(function () use ($actor, $rules): void {
            $before = $this->snapshot($actor);
            RoutingRule::query()->forWorkspace($actor->workspace)->delete();

            foreach ($rules as $rule) {
                RoutingRule::query()->create([
                    'workspace_id' => $actor->workspaceId(),
                    'department_id' => $rule['department_id'],
                    'category' => $rule['category'],
                    'manager_user_id' => $rule['manager_user_id'],
                ]);
            }

            $after = $this->snapshot($actor);

            $this->auditor->record('routing_rules.updated', $actor->workspace, ['rules' => [$before, $after]], actor: $actor->user, workspace: $actor->workspace, subjectLabel: $actor->workspace->name);
        });
    }

    /** @return list<string> */
    private function snapshot(Actor $actor): array
    {
        return array_values(RoutingRule::query()
            ->forWorkspace($actor->workspace)
            ->orderBy('category')
            ->orderBy('department_id')
            ->get()
            ->map(fn (RoutingRule $rule): string => ($rule->department_id ?? '*').':'.$rule->category->value.'>'.$rule->manager_user_id)
            ->all());
    }

    /** @throws OpsbotException */
    private function authorize(Actor $actor): void
    {
        if (! $actor->can('departments.manage')) {
            throw OpsbotException::forbidden();
        }
    }

    /** @throws OpsbotException */
    private function assertWorkspace(Actor $actor, Department $department): void
    {
        if ($department->workspace_id !== $actor->workspaceId()) {
            throw OpsbotException::notFound();
        }
    }

    /** @throws OpsbotException */
    private function assertNameFree(Actor $actor, string $name, ?int $ignore): void
    {
        $exists = Department::query()
            ->forWorkspace($actor->workspace)
            ->live()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))
            ->exists();

        if ($exists) {
            throw new OpsbotException('departments.name_taken');
        }
    }

    /** @throws OpsbotException */
    private function code(Actor $actor, ?string $code, string $name, ?int $ignore = null): string
    {
        $explicit = $code !== null && trim($code) !== '';
        $base = Str::limit(Str::slug($explicit ? (string) $code : Str::ascii($name)), 32, '') ?: 'department';
        $candidate = $base;
        $suffix = 2;

        while (Department::query()->forWorkspace($actor->workspace)->where('code', $candidate)->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))->exists()) {
            if ($explicit) {
                throw new OpsbotException('departments.code_taken');
            }

            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    /** @throws OpsbotException */
    private function manager(Actor $actor, ?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $actor->workspaceId())
            ->where('user_id', $userId)
            ->first();

        if ($membership === null || $membership->status !== MemberStatus::Active || ! $membership->role->atLeast(WorkspaceRole::Manager)) {
            throw new OpsbotException('departments.manager_invalid');
        }

        return $userId;
    }
}
