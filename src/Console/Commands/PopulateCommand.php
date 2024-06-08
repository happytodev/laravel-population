<?php

namespace CapsulesCodes\Population\Console\Commands;

use CapsulesCodes\Population\Dumper;
use CapsulesCodes\Population\Populator;
use CapsulesCodes\Population\Replicator;
use Exception;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Console\View\Components\BulletList;
use Illuminate\Console\View\Components\Error;
use Illuminate\Console\View\Components\Info;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Console\Migrations\BaseCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;


class PopulateCommand extends BaseCommand implements Isolatable
{
    use ConfirmableTrait;


    protected $name = 'populate';
    protected $description = 'Update migration changes and convert current records';

    protected Dumper $dumper;
    protected Replicator $replicator;
    protected Populator $populator;

    protected string $uuid;
    protected int $status;


    public function __construct( Dumper $dumper, Replicator $replicator, Populator $populator )
    {
        parent::__construct();

        $this->dumper = $dumper;
        $this->migrator = $replicator;
        $this->populator = $populator;
    }

    public function handle() : int
    {
        $this->uuid = Str::orderedUuid()->getHex()->serialize();

        $this->status = 0;

        $this->registerShutdownHandler();

        $databases = Collection::make( empty( $this->input->getOption( 'database' ) ) ? [ Config::get( 'database.default' ) ] : $this->input->getOption( 'database' ) );

        $databases->each( function( $database ) use ( $databases ) : void
        {
            if( $this->status ) return;

            if( $databases->count() > 1 ) $this->write( Info::class, "Populating database : {$database}..." );

            $this->migrator->usingConnection( $database, function() use ( $database ) : void
            {
                $this->migrator->setOutput( $this->output );

                try
                {
                    $this->migrator->databaseExists( $database );

                    $this->dumper->copy( $database );

                    $this->migrator->replicate( $database, $this->uuid, $this->migrator->getMigrationFiles( $this->getMigrationPaths() ) );

                    $this->migrator->inspect( $database, $this->uuid );

                    $this->populate( $database );
                }
                catch( Exception $exception )
                {
                    $this->write( Error::class, $exception->getMessage() );

                    $this->revert();

                    $this->status = 1;
                }
            } );
        } );

        return $this->status;
    }

    protected function registerShutdownHandler() : void
    {
        register_shutdown_function( function() : void { $this->revert(); } );
    }

    protected function populate( $database ) : void
    {
        foreach( $this->migrator->getDirties() as $table => $changes )
        {
            $this->write( Info::class, "Table '{$table}' has changes" );

            $this->write( BulletList::class, $changes->map( fn( $change, $column ) => match ( true )
            {
                ( $change[ 'old' ] && $change[ 'new' ] ) => "update column : '{$column}' => type : {$change[ 'old' ]} > {$change[ 'new' ]}",
                ( $change[ 'old' ] && ! $change[ 'new' ] ) => "delete column : '{$column}' => type : {$change[ 'old' ]}",
                ( ! $change[ 'old' ] && $change[ 'new' ] ) => "create column : '{$column}' => type : {$change[ 'new' ]}",
            } ) );

            $confirmed = confirm( "Do you want to proceed on populating the '{$table}' table?", false );

            if( $confirmed )
            {
                $this->request( $database, $table, $changes );
            }
            else
            {
                $this->migrator->clean( $this->uuid, Collection::make( [ $table => $this->migrator->getTables()->filter( fn( $value, $key ) => $key === $table )->first() ] ), true );
            }
        }

        if( $this->populator->isDirty() )
        {
            $this->write( Info::class, 'Population succeeded.' );
        }
    }

    protected function request( $database, $table, $changes ) : void
    {
        $formulas = Collection::make();

        $records = $this->load( $table );

        if( $records->isEmpty() )
        {
            $this->write( Info::class, "The '{$table}' table columns have been updated but it seems the table has no records. Skipping record conversion." );
        }
        else
        {
            foreach( $changes as $column => $change )
            {
                if( $change[ 'new' ] )
                {
                    $input = text( "How would you like to convert the records for the column '{$column}' of type '{$change[ 'new' ]}'?  'fn( \$attribute, \$model ) => \$attribute'", 'fn( $attribute, $model ) => $attribute' );

                    preg_match( '/^\s*fn\s*\(\s*(\$[\w\d]*\s*(?:,\s*\$[\w\d]*)?)?\s*\)\s*=>\s*(.+)\s*/', $input, $matches );

                    if( Collection::make( $matches )->isEmpty() ) throw new Exception( 'The function did not respect the required format.' );

                    $formulas[ $column ] = $matches;
                }
                else
                {
                    $formulas[ $column ] = null;
                }
            }
        }

        $this->populator->process( $table, $database, $this->uuid, $formulas, $records );
    }

    protected function load( $table = null, $input = null ) : Collection
    {
        $class = $input ?? 'App\\Models\\' . Str::studly( Str::singular( $table ) );

        if( class_exists( $class ) ) return $class::all();

        if( ! $input )
        {
            return $this->load( null, text( "The '{$class}' model path does not exist, please provide the correct path.", 'App\\Models\\' ) );
        }
        else
        {
            throw new Exception( 'The model file was not found.' );
        }
    }

    protected function revert() : void
    {
        if( ! $this->populator->isDirty() )
        {
            $this->migrator->clean( $this->uuid );

            $this->dumper->remove();
        }
    }

    protected function write( $component, ...$arguments ) : void
    {
        ( new $component( $this->output ) )->render( ...$arguments );
    }

    protected function getOptions() : array
    {
        return [
            [ 'database', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The database connection(s) to be inspected' ],
            [ 'path', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The path(s) to the migrations files to be executed' ],
            [ 'realpath', null, InputOption::VALUE_NONE, 'Indicate any provided migration file paths are pre-resolved absolute paths' ]
        ];
    }
}
