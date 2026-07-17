<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRetellAudioField extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('organisation_studentgrouphistory', function (Blueprint $table) {
            $table->string('retell_audio')->nullable()->after('audio');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('organisation_studentgrouphistory', function (Blueprint $table) {
            $table->dropColumn(['retell_audio']);
        });
    }
}
