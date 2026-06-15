<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends failure groups into a unified "issues" inbox that also captures HTTP
 * request and manually-reported exceptions (not just queue/command failures),
 * each enriched with a stack-trace sample and request context, and mutable.
 */
return new class extends Migration
{
    protected function connection(): ?string
    {
        return config('vigilance.storage.connection') ?: config('database.default');
    }

    public function up(): void
    {
        Schema::connection($this->connection())->table('vigilance_failure_groups', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('type');
            $table->timestamp('muted_until')->nullable()->after('resolved_at');
            $table->longText('sample')->nullable();
            $table->json('context')->nullable();

            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table('vigilance_failure_groups', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'muted_until', 'sample', 'context']);
        });
    }
};
