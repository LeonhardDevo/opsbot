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
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.f_unaccent(text) RETURNS text
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT;
        SQL);

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('department');
        });

        DB::statement("ALTER TABLE documents ADD COLUMN department_ids integer[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX documents_department_ids_gin ON documents USING gin (department_ids)');

        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedInteger('upload_rev')->default(1);
            $table->string('approval_status', 16)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->date('review_due_at')->nullable();
            $table->date('review_notified_on')->nullable();
            $table->foreignId('supersedes_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 16)->nullable();
            $table->string('source', 16)->default('upload');
            $table->string('source_ref', 64)->nullable();
            $table->string('language', 5)->nullable();
            $table->unsignedInteger('pages_done')->nullable();
            $table->unsignedInteger('pages_total')->nullable();
            $table->string('error_code', 32)->nullable();

            $table->index(['workspace_id', 'approval_status']);
            $table->index(['workspace_id', 'review_due_at']);
            $table->index(['workspace_id', 'source_ref']);
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_approval_status_check CHECK (approval_status IN ('draft', 'approved', 'archived'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_archive_reason_check CHECK (archive_reason IS NULL OR archive_reason IN ('manual', 'superseded'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_source_check CHECK (source IN ('upload', 'starter_pack', 'faq'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_approved_effective_check CHECK (approval_status <> 'approved' OR effective_date IS NOT NULL)");

        DB::statement('ALTER TABLE ai_queries ALTER COLUMN question DROP NOT NULL');

        Schema::table('ai_queries', function (Blueprint $table): void {
            $table->string('answer_kind', 12)->default('document');
            $table->string('bot_key', 20)->nullable();
            $table->string('conversation_id', 120)->nullable();
            $table->string('role_at_time', 16)->nullable();
            $table->jsonb('cited_document_versions')->default('[]');
            $table->string('refusal_reason', 24)->nullable();
            $table->unsignedBigInteger('escalated_question_id')->nullable();
            $table->jsonb('disclaimers')->default('[]');

            $table->index(['workspace_id', 'user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE ai_queries ADD COLUMN department_ids_at_time integer[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX ai_queries_department_ids_gin ON ai_queries USING gin (department_ids_at_time)');
        DB::statement("ALTER TABLE ai_queries ADD CONSTRAINT ai_queries_answer_kind_check CHECK (answer_kind IN ('document', 'tool_data'))");
        DB::statement("ALTER TABLE ai_queries ADD CONSTRAINT ai_queries_refusal_reason_check CHECK (refusal_reason IS NULL OR refusal_reason IN ('low_confidence', 'not_found', 'model_refusal', 'unavailable', 'limit_reached', 'paused'))");
        DB::statement(<<<'SQL'
            ALTER TABLE ai_queries ADD COLUMN question_normalized text
            GENERATED ALWAYS AS (regexp_replace(lower(public.f_unaccent(question)), '\s+', ' ', 'g')) STORED
        SQL);
        DB::statement('CREATE INDEX ai_queries_question_normalized_trgm ON ai_queries USING gin (question_normalized gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_queries_question_normalized_trgm');
        DB::statement('ALTER TABLE ai_queries DROP COLUMN IF EXISTS question_normalized');
        DB::statement('ALTER TABLE ai_queries DROP CONSTRAINT IF EXISTS ai_queries_answer_kind_check');
        DB::statement('ALTER TABLE ai_queries DROP CONSTRAINT IF EXISTS ai_queries_refusal_reason_check');
        DB::statement('ALTER TABLE ai_queries DROP COLUMN IF EXISTS department_ids_at_time');

        Schema::table('ai_queries', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'user_id', 'created_at']);
            $table->dropColumn([
                'answer_kind', 'bot_key', 'conversation_id', 'role_at_time', 'cited_document_versions',
                'refusal_reason', 'escalated_question_id', 'disclaimers',
            ]);
        });

        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_approval_status_check');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_archive_reason_check');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_source_check');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_approved_effective_check');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'approval_status']);
            $table->dropIndex(['workspace_id', 'review_due_at']);
            $table->dropIndex(['workspace_id', 'source_ref']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('supersedes_document_id');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn([
                'upload_rev', 'approval_status', 'approved_at', 'review_due_at', 'review_notified_on', 'archived_at',
                'archive_reason', 'source', 'source_ref', 'language', 'pages_done', 'pages_total', 'error_code',
            ]);
        });

        DB::statement('ALTER TABLE documents DROP COLUMN IF EXISTS department_ids');

        Schema::table('documents', function (Blueprint $table): void {
            $table->string('department', 120)->nullable();
        });
    }
};
