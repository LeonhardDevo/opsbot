<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Legal\LegalAcceptances;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(LegalAcceptances $acceptances): void
    {
        $this->call(OpsbotDemoSeeder::class);

        User::query()
            ->whereNotNull('email')
            ->whereNotNull('password')
            ->orderBy('id')
            ->each(function (User $user) use ($acceptances): void {
                $acceptances->acceptPending($user, null, $user->locale, null);

                $owned = WorkspaceMembership::query()
                    ->where('user_id', $user->id)
                    ->where('role', WorkspaceRole::Owner->value)
                    ->pluck('workspace_id');

                foreach (Workspace::query()->whereIn('id', $owned)->get() as $workspace) {
                    $acceptances->acceptPending($user, $workspace, $user->locale, null);
                }
            });
    }
}
