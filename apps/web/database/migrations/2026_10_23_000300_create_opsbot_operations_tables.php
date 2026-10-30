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
        Schema::create('ops_settings', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained()->cascadeOnDelete();
            $table->decimal('min_confidence', 3, 2)->default(0.40);
            $table->time('quiet_hours_start')->default('21:00');
            $table->time('quiet_hours_end')->default('08:00');
            $table->boolean('reminders_enabled')->default(true);
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement('ALTER TABLE ops_settings ADD CONSTRAINT ops_settings_min_confidence_check CHECK (min_confidence BETWEEN 0.20 AND 0.80)');

        Schema::create('workspace_counters', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key', 16);
            $table->bigInteger('value')->default(0);

            $table->primary(['workspace_id', 'key']);
        });

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->integer('number');
            $table->string('category', 16);
            $table->string('severity', 8);
            $table->string('status', 16);
            $table->string('title', 160);
            $table->text('description');
            $table->string('client_ref', 120)->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16);
            $table->foreignId('ai_query_id')->nullable()->constrained('ai_queries')->nullOnDelete();
            $table->uuid('ai_query_action_id')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'department_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_category_check CHECK (category IN ('damage', 'complaint', 'no_access', 'injury', 'lost_item', 'other'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_severity_check CHECK (severity IN ('low', 'medium', 'high'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_status_check CHECK (status IN ('open', 'in_progress', 'resolved', 'closed'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_source_check CHECK (source IN ('bot', 'web', 'ai_proposal'))");

        Schema::create('incident_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('from_value', 32)->nullable();
            $table->string('to_value', 32)->nullable();
            $table->text('body')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['incident_id', 'id']);
        });
        DB::statement("ALTER TABLE incident_events ADD CONSTRAINT incident_events_type_check CHECK (type IN ('created', 'status_changed', 'assigned', 'severity_changed', 'category_changed', 'comment', 'task_linked'))");

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->integer('number');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('status', 16);
            $table->timestampTz('due_at')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_query_id')->nullable()->constrained('ai_queries')->nullOnDelete();
            $table->string('source', 16);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status', 'due_at']);
            $table->index(['assignee_id', 'status']);
        });
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('todo', 'in_progress', 'done', 'cancelled'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_check CHECK (source IN ('web', 'bot', 'incident', 'ai_proposal'))");

        Schema::create('task_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->timestampTz('planned_at');
            $table->string('status', 12);
            $table->string('skip_reason', 32)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->uuid('outbox_id')->nullable();

            $table->unique(['task_id', 'kind']);
            $table->index(['status', 'planned_at']);
        });
        DB::statement("ALTER TABLE task_reminders ADD CONSTRAINT task_reminders_kind_check CHECK (kind IN ('pre_due', 'due', 'overdue_1', 'overdue_2', 'overdue_3', 'escalation'))");
        DB::statement("ALTER TABLE task_reminders ADD CONSTRAINT task_reminders_status_check CHECK (status IN ('planned', 'sent', 'skipped', 'cancelled'))");

        Schema::create('routing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 16);
            $table->foreignId('manager_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX routing_rules_scope_unique ON routing_rules (workspace_id, department_id, category) NULLS NOT DISTINCT');
        DB::statement("ALTER TABLE routing_rules ADD CONSTRAINT routing_rules_category_check CHECK (category IN ('damage', 'complaint', 'no_access', 'injury', 'lost_item', 'other', 'question', 'task'))");

        Schema::create('unanswered_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->integer('number');
            $table->foreignId('ai_query_id')->nullable()->unique()->constrained('ai_queries')->nullOnDelete();
            $table->text('question');
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 16);
            $table->string('status', 20);
            $table->text('answer_text')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('answered_at')->nullable();
            $table->string('dismissed_reason', 16)->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('dismissed_at')->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['assignee_user_id', 'status']);
        });
        DB::statement("ALTER TABLE unanswered_questions ADD CONSTRAINT unanswered_questions_reason_check CHECK (reason IN ('low_confidence', 'not_found', 'conflict', 'user_request', 'limit_reached'))");
        DB::statement("ALTER TABLE unanswered_questions ADD CONSTRAINT unanswered_questions_status_check CHECK (status IN ('open', 'answered', 'converted_to_doc', 'dismissed'))");
        DB::statement("ALTER TABLE unanswered_questions ADD CONSTRAINT unanswered_questions_dismissed_reason_check CHECK (dismissed_reason IS NULL OR dismissed_reason IN ('duplicate', 'out_of_scope', 'spam', 'other'))");

        Schema::table('ai_queries', function (Blueprint $table): void {
            $table->foreign('escalated_question_id')->references('id')->on('unanswered_questions')->nullOnDelete();
        });

        Schema::create('ai_query_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_query_id')->constrained('ai_queries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            $table->string('label', 64);
            $table->jsonb('payload');
            $table->string('status', 12);
            $table->string('result_type', 24)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['ai_query_id']);
            $table->index(['user_id', 'status', 'executed_at']);
        });
        DB::statement("ALTER TABLE ai_query_actions ADD CONSTRAINT ai_query_actions_type_check CHECK (type IN ('create_incident', 'create_task', 'escalate', 'open_source', 'ask'))");
        DB::statement("ALTER TABLE ai_query_actions ADD CONSTRAINT ai_query_actions_status_check CHECK (status IN ('proposed', 'executed', 'cancelled', 'expired', 'failed'))");

        Schema::create('limit_notices', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('limit_key', 32);
            $table->date('notified_on');

            $table->primary(['workspace_id', 'limit_key', 'notified_on']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestampTz('past_due_since')->nullable();
        });

        Schema::create('employee_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('queued');
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['workspace_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_exports');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('past_due_since');
        });

        Schema::dropIfExists('limit_notices');
        Schema::dropIfExists('ai_query_actions');

        Schema::table('ai_queries', function (Blueprint $table): void {
            $table->dropForeign(['escalated_question_id']);
        });

        Schema::dropIfExists('unanswered_questions');
        Schema::dropIfExists('routing_rules');
        Schema::dropIfExists('task_reminders');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('workspace_counters');
        Schema::dropIfExists('ops_settings');
    }
};
