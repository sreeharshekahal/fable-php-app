<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class IncreaseNameLengthInAuthUserTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement('ALTER TABLE auth_user ALTER COLUMN first_name TYPE TEXT');
        DB::statement('ALTER TABLE auth_user ALTER COLUMN last_name TYPE TEXT');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement('ALTER TABLE auth_user ALTER COLUMN first_name TYPE VARCHAR(30)');
        DB::statement('ALTER TABLE auth_user ALTER COLUMN last_name TYPE VARCHAR(30)');
    }
}
