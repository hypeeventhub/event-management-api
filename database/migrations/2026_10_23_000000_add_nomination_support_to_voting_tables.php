<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voting_subjects', function (Blueprint $table) {
            $table->string('mode')->default('manual');
            $table->unsignedInteger('top_n')->nullable();
            $table->foreignId('generated_from_subject_id')
                ->nullable()
                ->unique()
                ->constrained('voting_subjects')
                ->nullOnDelete();
        });

        Schema::table('voting_contestants', function (Blueprint $table) {
            $table->foreignId('attendee_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('voting_contestants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendee_id');
        });

        Schema::table('voting_subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generated_from_subject_id');
            $table->dropColumn(['mode', 'top_n']);
        });
    }
};
