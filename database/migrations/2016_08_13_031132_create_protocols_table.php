<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateProtocolsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('protocols', function (Blueprint $table) {
            $table->increments('id');
            $table->string('environment');
            $table->string('mdfe')->nullable();
            $table->string('receipt')->nullable();
            $table->string('protocol')->nullable();
            $table->string('digval')->nullable();
            $table->string('status_code')->nullable();
            $table->string('status_msg')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('protocols');
    }
}
