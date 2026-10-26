<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('attendees', 'gender')) {
            Schema::table('attendees', function (Blueprint $table): void {
                $table->dropColumn('gender');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('attendees', 'gender')) {
            Schema::table('attendees', function (Blueprint $table): void {
                $table->string('gender', 20)->nullable()->after('last_name');
            });
        }
    }
};
