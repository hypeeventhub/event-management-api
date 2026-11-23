<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomination_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position');
            $table->unsignedInteger('female_top_n');
            $table->unsignedInteger('male_top_n');
            $table->string('stage')->default('draft');
            $table->timestamps();
            $table->unique(['event_id', 'position']);
            $table->index(['event_id', 'stage']);
        });

        Schema::table('voting_subjects', function (Blueprint $table) {
            $table->foreignId('nomination_topic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 6)->nullable();
            $table->unique(['nomination_topic_id', 'mode', 'category'], 'voting_subjects_topic_round_unique');
        });
    }

    public function down(): void
    {
        Schema::table('voting_subjects', function (Blueprint $table) {
            $table->dropForeign(['nomination_topic_id']);
            $table->dropUnique('voting_subjects_topic_round_unique');
            $table->dropColumn(['nomination_topic_id', 'category']);
        });

        Schema::dropIfExists('nomination_topics');
    }
};
