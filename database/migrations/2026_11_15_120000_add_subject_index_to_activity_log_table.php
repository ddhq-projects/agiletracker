<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The item timeline and the administrative journal both read one subject's
     * history newest first, which is what this composite index serves.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(
                ['subject_type', 'subject_id', 'created_at'],
                'activity_log_subject_created_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_subject_created_at_index');
        });
    }
};
