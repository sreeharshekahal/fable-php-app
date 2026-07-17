<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAssessmentPeriodToBenchmarkTemplatesAndAssessments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('benchmark_templates', function (Blueprint $table) {
            $table->string('assessment_period', 20)->nullable();
        });

        Schema::table('assessment_assessment', function (Blueprint $table) {
            $table->string('assessment_period', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('benchmark_templates', function (Blueprint $table) {
            $table->dropColumn('assessment_period');
        });

        Schema::table('assessment_assessment', function (Blueprint $table) {
            $table->dropColumn('assessment_period');
        });
    }
}
