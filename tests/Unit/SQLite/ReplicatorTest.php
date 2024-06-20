<?php

use CapsulesCodes\Population\Parser;
use CapsulesCodes\Population\Replicator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;


beforeEach( function() : void
{
    $this->uuid = Str::orderedUuid()->getHex()->serialize();

    $this->replicator = new Replicator( App::make( 'migrator' ), App::make( Parser::class ) );
} );

afterEach( function() : void
{
    $this->replicator->clean( $this->uuid );
} );




it( 'can replicate existing migrations on a specific SQLite database', function() : void
{
    [ $base, $new ] = replicateMigrationsOnSQLiteDatabase( 'two' );

    expect( $new )->toContain( "foo-{$this->uuid}", ...$base );
} );


it( 'can replicate existing migrations on multiple specific SQLite databases', function() : void
{
    [ $base, $new ] = replicateMigrationsOnSQLiteDatabase( 'one' );

    expect( $new )->toContain( "foo-{$this->uuid}", ...$base );

    [ $base, $new ] = replicateMigrationsOnSQLiteDatabase( 'two' );

    expect( $new )->toContain( "foo-{$this->uuid}", ...$base );
} );


function replicateMigrationsOnSQLiteDatabase( string $database ) : array
{
    Config::set( 'database.default', $database );

    test()->loadMigrationsFrom( 'tests/App/Database/Migrations/Databases/one/base' );

    $base = Arr::pluck( Schema::getTables(), 'name' );

    test()->replicator->path( 'tests/App/Database/Migrations/Databases/one/new/foo_table.php' );

    test()->replicator->replicate( $database, test()->uuid, test()->replicator->getMigrationFiles( test()->replicator->paths() ) );

    $new = Arr::pluck( Schema::getTables(), 'name' );

    test()->artisan( 'migrate:fresh' );

    return [ $base, $new ];
}
