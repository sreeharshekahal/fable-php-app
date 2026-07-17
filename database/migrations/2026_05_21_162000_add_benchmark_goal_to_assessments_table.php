<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBenchmarkGoalToAssessmentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('assessment_assessment', function (Blueprint $table) {
            $table->integer('benchmark_goal')->nullable()->after('assessment_period');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('assessment_assessment', function (Blueprint $table) {
            $table->dropColumn('benchmark_goal');
        });
    }
}
