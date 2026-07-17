<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAssessmentAudioFields extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('assessment_assessment', function (Blueprint $table) {
            $table->string('audio')->nullable();
            $table->string('retell_audio')->nullable();
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
            $table->dropColumn(['audio']);
            $table->dropColumn(['retell_audio']);
        });
    }
}
