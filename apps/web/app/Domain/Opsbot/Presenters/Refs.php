<?php

declare(strict_types=1);

namespace App\Domain\Opsbot\Presenters;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class Refs
{
    /** @return array{id: int, name: string}|null */
    public static function user(?User $user): ?array
    {
        return $user?->toRef();
    }

    /** @return array{id: int, name: string, code: string}|null */
    public static function department(?Department $department): ?array
    {
        return $department?->toRef();
    }

    /**
     * @param  Collection<int, User>|iterable<User>  $users
     * @return list<array{id: int, name: string}>
     */
    public static function users(iterable $users): array
    {
        $refs = [];

        foreach ($users as $user) {
            $refs[] = $user->toRef();
        }

        return $refs;
    }

    /**
     * @param  iterable<Department>  $departments
     * @return list<array{id: int, name: string, code: string}>
     */
    public static function departments(iterable $departments): array
    {
        $refs = [];

        foreach ($departments as $department) {
            $refs[] = $department->toRef();
        }

        return $refs;
    }
}
