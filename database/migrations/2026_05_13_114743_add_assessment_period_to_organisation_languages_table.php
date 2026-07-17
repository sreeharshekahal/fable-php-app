<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAssessmentPeriodToOrganisationLanguagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('organisation_organisation_languages', function (Blueprint $table) {
            $table->string('assessment_period', 20)->nullable()->after('language_id');
            $table->index(['organisation_id', 'language_id', 'assessment_period'], 'org_lang_period_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('organisation_organisation_languages', function (Blueprint $table) {
            $table->dropIndex('org_lang_period_idx');
            $table->dropColumn('assessment_period');
        });
    }
}
