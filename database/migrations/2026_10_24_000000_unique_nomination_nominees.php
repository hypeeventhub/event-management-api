<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voting_contestants', function (Blueprint $table) {
            $table->unique(['voting_subject_id', 'attendee_id'], 'voting_contestants_subject_attendee_unique');
        });
    }

    public function down(): void
    {
        Schema::table('voting_contestants', function (Blueprint $table) {
            $table->dropUnique('voting_contestants_subject_attendee_unique');
        });
    }
};
