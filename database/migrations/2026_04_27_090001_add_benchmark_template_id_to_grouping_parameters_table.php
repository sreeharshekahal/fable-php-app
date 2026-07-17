<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBenchmarkTemplateIdToGroupingParametersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('common_groupingparameter', function (Blueprint $table) {
            $table->uuid('benchmark_template_id')->nullable();
            $table->foreign('benchmark_template_id')->references('id')->on('benchmark_templates')->onDelete('cascade');

            $table->index('benchmark_template_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('common_groupingparameter', function (Blueprint $table) {
            $table->dropForeign(['benchmark_template_id']);
            $table->dropColumn('benchmark_template_id');
        });
    }
}
