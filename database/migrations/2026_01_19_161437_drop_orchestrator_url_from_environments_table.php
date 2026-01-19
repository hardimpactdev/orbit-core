<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('environments', 'orchestrator_url')) {
            Schema::table('environments', function (Blueprint $table) {
                $table->dropColumn('orchestrator_url');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('environments', 'orchestrator_url')) {
            Schema::table('environments', function (Blueprint $table) {
                $table->string('orchestrator_url')->nullable()->after('is_default');
            });
        }
    }
};
