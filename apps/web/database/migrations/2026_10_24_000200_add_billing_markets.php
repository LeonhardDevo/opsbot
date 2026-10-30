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
        if (! Schema::hasColumn('workspaces', 'country')) {
            Schema::table('workspaces', function (Blueprint $table): void {
                $table->char('country', 2)->nullable();
            });
        }

        DB::statement("UPDATE workspaces SET country = 'RU' WHERE country IS NULL AND currency = 'RUB'");

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('gateway', 16)->default('stripe')->after('workspace_id');
            $table->char('currency', 3)->nullable()->after('interval');
            $table->timestampTz('payment_failed_at')->nullable()->after('canceled_at');
        });

        DB::statement(<<<'SQL'
            UPDATE subscriptions s
            SET currency = w.currency,
                gateway = CASE WHEN w.currency = 'RUB' THEN 'yookassa' ELSE 'stripe' END
            FROM workspaces w
            WHERE w.id = s.workspace_id AND s.stripe_subscription_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['gateway', 'currency', 'payment_failed_at']);
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('country');
        });
    }
};
