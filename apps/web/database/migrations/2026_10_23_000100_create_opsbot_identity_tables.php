<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('erased_at')->nullable();
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->jsonb('onboarding')->default('{}');
        });

        DB::statement("UPDATE workspaces SET currency = 'USD' WHERE currency NOT IN ('USD', 'EUR', 'RUB')");
        DB::statement("ALTER TABLE workspaces ADD CONSTRAINT workspaces_currency_check CHECK (currency IN ('USD', 'EUR', 'RUB'))");

        DB::statement("UPDATE workspace_user SET role = 'employee' WHERE role = 'member'");

        Schema::table('workspace_user', function (Blueprint $table): void {
            $table->string('kind', 16)->default('web');
            $table->string('status', 16)->default('active');
            $table->text('phone')->nullable();
            $table->string('position', 120)->nullable();
            $table->timestampTz('offboarded_at')->nullable();
            $table->foreignId('offboarded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['workspace_id', 'status']);
        });

        DB::statement("ALTER TABLE workspace_user ADD CONSTRAINT workspace_user_role_check CHECK (role IN ('owner', 'admin', 'manager', 'employee'))");
        DB::statement("ALTER TABLE workspace_user ADD CONSTRAINT workspace_user_kind_check CHECK (kind IN ('web', 'telegram_only'))");
        DB::statement("ALTER TABLE workspace_user ADD CONSTRAINT workspace_user_status_check CHECK (status IN ('invited', 'active', 'offboarded'))");

        DB::statement("UPDATE workspace_invitations SET role = 'employee' WHERE role = 'member'");
        DB::statement("ALTER TABLE workspace_invitations ADD COLUMN department_ids integer[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE workspace_invitations ADD CONSTRAINT workspace_invitations_role_check CHECK (role IN ('owner', 'admin', 'manager', 'employee'))");

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'code']);
        });

        DB::statement('CREATE UNIQUE INDEX departments_workspace_name_live ON departments (workspace_id, lower(name)) WHERE archived_at IS NULL');

        Schema::create('department_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['department_id', 'user_id']);
            $table->index(['workspace_id', 'user_id']);
        });

        DB::statement('CREATE UNIQUE INDEX department_user_primary_unique ON department_user (workspace_id, user_id) WHERE is_primary');

        Schema::create('employee_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('bot_key', 20);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'used_at']);
        });

        Schema::create('telegram_bots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('tg_bot_id');
            $table->string('username', 64)->nullable();
            $table->string('first_name', 128)->nullable();
            $table->text('token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('status', 16);
            $table->string('mode', 10)->nullable();
            $table->boolean('can_join_groups')->nullable();
            $table->string('last_error', 32)->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('disconnected_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE telegram_bots ADD CONSTRAINT telegram_bots_status_check CHECK (status IN ('connecting', 'active', 'invalid_token', 'revoked', 'disconnecting', 'disconnected'))");
        DB::statement("ALTER TABLE telegram_bots ADD CONSTRAINT telegram_bots_mode_check CHECK (mode IS NULL OR mode IN ('webhook', 'polling', 'fake'))");
        DB::statement("CREATE UNIQUE INDEX telegram_bots_tg_bot_id_live ON telegram_bots (tg_bot_id) WHERE status <> 'disconnected'");

        Schema::table('telegram_link_tokens', function (Blueprint $table): void {
            $table->string('bot_key', 20)->default('platform');
        });
        DB::statement('ALTER TABLE telegram_link_tokens ALTER COLUMN bot_key DROP DEFAULT');

        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropUnique(['telegram_user_id']);
        });

        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->string('bot_key', 20)->default('platform');
            $table->foreignId('telegram_bot_id')->nullable()->constrained('telegram_bots')->cascadeOnDelete();
            $table->timestampTz('blocked_at')->nullable();
            $table->string('notice_version', 20)->nullable();
            $table->timestampTz('notice_acked_at')->nullable();

            $table->unique(['bot_key', 'telegram_user_id']);
            $table->unique(['bot_key', 'user_id']);
            $table->index('user_id');
        });
        DB::statement('ALTER TABLE telegram_accounts ALTER COLUMN bot_key DROP DEFAULT');

    }

    public function down(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->dropUnique(['bot_key', 'telegram_user_id']);
            $table->dropUnique(['bot_key', 'user_id']);
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('telegram_bot_id');
            $table->dropColumn(['bot_key', 'blocked_at', 'notice_version', 'notice_acked_at']);
            $table->unique('user_id');
            $table->unique('telegram_user_id');
        });

        Schema::table('telegram_link_tokens', function (Blueprint $table): void {
            $table->dropColumn('bot_key');
        });

        Schema::dropIfExists('telegram_bots');
        Schema::dropIfExists('employee_links');
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');

        DB::statement('ALTER TABLE workspace_invitations DROP CONSTRAINT IF EXISTS workspace_invitations_role_check');
        DB::statement('ALTER TABLE workspace_invitations DROP COLUMN IF EXISTS department_ids');

        DB::statement('ALTER TABLE workspace_user DROP CONSTRAINT IF EXISTS workspace_user_role_check');
        DB::statement('ALTER TABLE workspace_user DROP CONSTRAINT IF EXISTS workspace_user_kind_check');
        DB::statement('ALTER TABLE workspace_user DROP CONSTRAINT IF EXISTS workspace_user_status_check');

        Schema::table('workspace_user', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropConstrainedForeignId('offboarded_by');
            $table->dropColumn(['kind', 'status', 'phone', 'position', 'offboarded_at']);
        });

        DB::statement('ALTER TABLE workspaces DROP CONSTRAINT IF EXISTS workspaces_currency_check');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('onboarding');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('erased_at');
        });
    }
};
