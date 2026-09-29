<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voting_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['event_id', 'status']);
            $table->unique(['event_id', 'id']);
        });

        Schema::create('voting_contestants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voting_subject_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['voting_subject_id', 'display_order']);
            $table->unique(['voting_subject_id', 'id']);
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->unique(['event_id', 'id'], 'registrations_event_id_id_unique');
        });

        Schema::create('voting_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id');
            $table->foreignId('voting_subject_id');
            $table->foreignId('voting_contestant_id');
            $table->foreignId('registration_id');
            $table->timestamps();
            $table->index(['event_id', 'voting_subject_id']);
            $table->index(['voting_subject_id', 'voting_contestant_id']);
            $table->index(['event_id', 'registration_id']);
            $table->unique(['voting_subject_id', 'registration_id']);
            $table->foreign(['event_id', 'voting_subject_id'])
                ->references(['event_id', 'id'])->on('voting_subjects')->cascadeOnDelete();
            $table->foreign(['voting_subject_id', 'voting_contestant_id'])
                ->references(['voting_subject_id', 'id'])->on('voting_contestants')->restrictOnDelete();
            $table->foreign(['event_id', 'registration_id'])
                ->references(['event_id', 'id'])->on('registrations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voting_votes');
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropUnique('registrations_event_id_id_unique');
        });
        Schema::dropIfExists('voting_contestants');
        Schema::dropIfExists('voting_subjects');
    }
};
