<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function connection(): ?string
    {
        return config('vigilance.storage.connection') ?: config('database.default');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->table('vigilance_failure_groups', function (Blueprint $table) {
            // The release an issue was first seen in, and the release it
            // regressed in — correlate errors with the deploy that introduced
            // (or resurrected) them.
            if (! Schema::connection($this->connection())->hasColumn('vigilance_failure_groups', 'first_release')) {
                $table->string('first_release')->nullable()->after('source');
            }

            if (! Schema::connection($this->connection())->hasColumn('vigilance_failure_groups', 'regressed_release')) {
                $table->string('regressed_release')->nullable()->after('first_release');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->table('vigilance_failure_groups', function (Blueprint $table) {
            foreach (['first_release', 'regressed_release'] as $column) {
                if (Schema::connection($this->connection())->hasColumn('vigilance_failure_groups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
