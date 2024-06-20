<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up() : void
    {
        Schema::create( 'baz', function( Blueprint $table ) : void
        {
            $table->id();
            $table->boolean( 'baz' );
            $table->timestamps();
        } );

        Schema::create( 'qux', function( Blueprint $table ) : void
         {
            $table->id();
            $table->boolean( 'qux' );
            $table->timestamps();
        } );
    }

    public function down() : void
    {
        Schema::dropIfExists( 'baz' );

        Schema::dropIfExists( 'qux' );
    }
};
