<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropUniqueValidationFromCommonAccuracyTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('common_accuracy', function (Blueprint $table) {
            $table->dropUnique('common_accuracy_template_period_unique');
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
            $table->unique(
                ['grade_id', 'level_id', 'language_id', 'benchmark_template_id', 'assessment_period'],
                'common_accuracy_template_period_unique'
            );
        });
    }
}
