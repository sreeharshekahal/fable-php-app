<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBenchmarkTemplateToCommonAccuracyTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('common_accuracy', function (Blueprint $table) {
            // 1. Add relationship columns
            $table->uuid('benchmark_template_id')->nullable();
            $table->uuid('language_id')->nullable();
            $table->uuid('level_id')->nullable();

            // 2. Setup Foreign Keys
            $table->foreign('benchmark_template_id')->references('id')->on('benchmark_templates')->onDelete('cascade');
            $table->foreign('language_id')->references('id')->on('common_language')->onDelete('cascade');
            $table->foreign('level_id')->references('id')->on('common_level')->onDelete('cascade');

            // 3. Standardize Unique Constraint
            // Drop the original global grade-only constraint to allow template-specific accuracies
            $table->dropUnique('common_accuracy_grade_id_key');

            // Add the composite unique key matching benchmark parameter granularity
            $table->unique(['grade_id', 'level_id', 'language_id', 'benchmark_template_id'], 'common_accuracy_full_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('common_accuracy', function (Blueprint $table) {
            $table->dropUnique('common_accuracy_full_unique');
            
            $table->dropForeign(['benchmark_template_id']);
            $table->dropForeign(['language_id']);
            $table->dropForeign(['level_id']);

            $table->dropColumn(['benchmark_template_id', 'language_id', 'level_id']);

            // Restore the original global constraint
            $table->unique('grade_id', 'common_accuracy_grade_id_key');
        });
    }
}
