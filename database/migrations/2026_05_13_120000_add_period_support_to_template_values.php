<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPeriodSupportToTemplateValues extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add assessment_period to common_groupingparameter table with index
        Schema::table('common_groupingparameter', function (Blueprint $table) {
            $table->string('assessment_period', 20)->nullable()->after('benchmark_template_id');
            $table->index(['benchmark_template_id', 'language_id', 'assessment_period'], 'grouping_parameter_template_period_idx');
        });

        // Drop unique constraint to allow new column
        Schema::table('common_accuracy', function (Blueprint $table) {
            $table->dropUnique('common_accuracy_full_unique');
            $table->string('assessment_period', 20)->nullable()->after('benchmark_template_id');
        });

        // Recreate unique constraint with new column
        Schema::table('common_accuracy', function (Blueprint $table) {
            $table->unique(
                ['grade_id', 'level_id', 'language_id', 'benchmark_template_id', 'assessment_period'],
                'common_accuracy_template_period_unique'
            );
            $table->index(['benchmark_template_id', 'language_id', 'assessment_period'], 'accuracy_template_period_idx');
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
            $table->dropIndex('accuracy_template_period_idx');
            $table->dropUnique('common_accuracy_template_period_unique');
            $table->dropColumn('assessment_period');
        });

        Schema::table('common_accuracy', function (Blueprint $table) {
            $table->unique(['grade_id', 'level_id', 'language_id', 'benchmark_template_id'], 'common_accuracy_full_unique');
        });

        Schema::table('common_groupingparameter', function (Blueprint $table) {
            $table->dropIndex('grouping_parameter_template_period_idx');
            $table->dropColumn('assessment_period');
        });
    }
}
