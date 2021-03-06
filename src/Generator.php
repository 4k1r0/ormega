<?php

namespace Ormega;

/**
 * Class Ormega\Generator
 *
 * @package  Ormega
 * @version  20160921
 *
 */
class Generator
{
    public $sBasePath = '';
    public $sTableFilter = '.*';

    public $sqlQuote = '"';
    public $sqlEscQuote = '\'';

    public $verbose = true;

    protected $aDb;
    protected $db;

    protected $aTables;
    protected $aCols;
    protected $aKeys;
    protected $aPrimaryKeys;
    protected $aForeignKeys;
    protected $aFiles = array();

    protected $sDatabase = 'database';

    protected $sDirBase = 'Ormega';
    protected $sDirEntity = 'Entity';
    protected $sDirPrivate = 'Base';
    protected $sDirEnum = 'Enum';
    protected $sDirQuery = 'Query';

    /**
     * Generator constructor.
     *
     * @param array $config array containing all configs needed
     *                      'db' => database driver link
     *                      'table_filter' => regular expression to filter tables for which we want generate models
     *                      'path' => relative path where to generate models (relative to app root)
     *                      'namespace' => relative path where to generate models (relative to app root)
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    public function __construct( array $config )
    {
        if( empty( $config['databases'] ) || !is_array($config['databases']) ){
            throw new \InvalidArgumentException('Array expected for config["tables"]');
        }

        foreach ( $config['databases'] as $aDbConf ) {
            if ( !is_a($aDbConf['db'], 'Evolution\CodeIgniterDB\CI_DB_driver') ) {
                throw new \InvalidArgumentException('Instance of Evolution\CodeIgniterDB\CI_DB_driver needed to start 
                the model generator');
            }
        }

        $this->aDb = $config['databases'];

        if( isset($config['path']) ) {}
            $this->sBasePath = $config['path'];

        if( isset($config['namespace']) ) {}
            $this->sDirBase = $config['namespace'];

    }

    /**
     * Start method to start the php files generation
     *
     * @throws \Exception
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    public function run()
    {
        $this->output('Start @ ' . date('Y-m-d H:i:s'));

        $this->aFiles[] = array(
            'file'    => $this->sDirBase . '/Orm.php',
            'content' => $this->genOrm(),
            'erase'   => true,
        );

        $this->aFiles[] = array(
            'file'    => $this->sDirBase . '/EntitiesCollection.php',
            'content' => $this->genEntitiesCollection(),
            'erase'   => true,
        );

        $this->aFiles[] = array(
            'file'    => $this->sDirBase . '/EntityInterface.php',
            'content' => $this->genEntityInterface(),
            'erase'   => true,
        );

        $this->aFiles[] = array(
            'file'    => $this->sDirBase . '/QueryInterface.php',
            'content' => $this->genQueryInterface(),
            'erase'   => true,
        );

        $this->aFiles[] = array(
            'file'    => $this->sDirBase . '/EnumInterface.php',
            'content' => $this->genEnumInterface(),
            'erase'   => true,
        );

        $this->aFiles[] = array(
            'file'    => $this->sDirBase . '/CacheInterface.php',
            'content' => $this->genCacheInterface(),
            'erase'   => true,
        );

        $this->aFiles[] = array(
            'file'    => $this->sDirBase . '/Simucache.php',
            'content' => $this->genSimucache(),
            'erase'   => true,
        );

        foreach ( $this->aDb as $sDatabase => $aDb ) {

            $this->db = $aDb['db'];
            $this->sDatabase = ucfirst($sDatabase);
            $this->sTableFilter = $aDb['filter'];

            $this->output('Config : Filter tables with regular expression "'. $this->sTableFilter .'"');
            $this->output('Config : Generate models in "'. $this->sBasePath . $this->sDirBase .'/'. $this->sDatabase .'"');

            $this->getTables();

            foreach ( $this->aTables as $sTable ) {

                $this->getFields($sTable);
                $this->getKeys($sTable);

                if ( strpos($sTable, 'enum') === 0 ) {

                    $this->aFiles[] = array(
                        'file'    => $this->sDirBase . '/' . $this->sDatabase . '/' . $this->sDirEnum . '/' . $this->formatPhpClassName($sTable) . '.php',
                        'content' => $this->genFileEnum($sTable),
                        'erase'   => true,
                    );
                }
                else {

                    $this->aFiles[] = array(
                        'file'    => $this->sDirBase . '/' . $this->sDatabase . '/' . $this->sDirEntity . '/' . $this->formatPhpClassName($sTable) . '.php',
                        'content' => $this->genFileEntity($sTable),
                        'erase'   => false,
                    );

                    $this->aFiles[] = array(
                        'file'    => $this->sDirBase . '/' . $this->sDatabase . '/' . $this->sDirEntity . '/' . $this->sDirPrivate . '/' . $this->formatPhpClassName($sTable) . '.php',
                        'content' => $this->genFileEntityPrivate($sTable),
                        'erase'   => true,
                    );

                    $this->aFiles[] = array(
                        'file'    => $this->sDirBase . '/' . $this->sDatabase . '/' . $this->sDirQuery . '/' . $this->formatPhpClassName($sTable) . '.php',
                        'content' => $this->genFileQuery($sTable),
                        'erase'   => false,
                    );

                    $this->aFiles[] = array(
                        'file'    => $this->sDirBase . '/' . $this->sDatabase . '/' . $this->sDirQuery . '/' . $this->sDirPrivate . '/' . $this->formatPhpClassName($sTable) . '.php',
                        'content' => $this->genFileQueryPrivate($sTable),
                        'erase'   => true,
                    );
                }
            }

            $this->genFiles();
        }

        $this->output('End @ ' . date('Y-m-d H:i:s'));
    }

    protected function output( $print )
    {
        if ( $this->verbose ) {
            echo '<div style="border:1px solid black; margin: 2px; padding: 5px">' . $print . '</div>';
        }
    }

    /**
     * Get table list
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function getTables()
    {
        $this->aTables = array();
        $this->aCols = array();
        $this->aKeys = array();
        $this->aPrimaryKeys = array();
        $this->aForeignKeys = array();

        $query = $this->db->query('SHOW TABLES');
        foreach( $query->result_array() as $aTable ) {
            $aTable = array_values($aTable);
            if ( empty($this->sTableFilter) || preg_match('/' . $this->sTableFilter . '/', $aTable[0]) )
                $this->aTables[] = $aTable[0];
        }

        $this->output('Table list : ' . implode(' ; ', $this->aTables));
    }



    /**
     * Get field list for a table
     *
     * @param string $sTable Table name
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function getFields( $sTable )
    {
        $this->aCols[ $sTable ] = array();
        $query = $this->db->query('SHOW COLUMNS FROM `' . $sTable . '`');
        foreach ( $query->result_array() as $aCol ) {
            $this->aCols[ $sTable ][ $aCol['Field'] ] = $aCol;
        }
    }

    /**
     * Get indexes for a table
     *
     * @param string $sTable Table name
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function getKeys( $sTable )
    {
        $this->aKeys[ $sTable ] = array();
        $this->aPrimaryKeys[ $sTable ] = array();
        $this->aForeignKeys[ $sTable ] = array();

        $query = $this->db->query(
            "SELECT COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_COLUMN_NAME, REFERENCED_TABLE_NAME
                  FROM information_schema.KEY_COLUMN_USAGE
                  WHERE (CONSTRAINT_NAME = 'PRIMARY' 
                      OR ( 
                        REFERENCED_COLUMN_NAME IS NOT NULL
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                      )
                    )
                    AND TABLE_SCHEMA = '".$this->db->database."'
                    AND TABLE_NAME = '$sTable'"
        );
        
        foreach ( $query->result_array() as $aKey ) {
            if( isset($this->aCols[ $sTable ][ $aKey['COLUMN_NAME'] ]) ) {
                $this->aKeys[ $sTable ][ $aKey['COLUMN_NAME'] ] = $aKey;
                if ( $aKey['CONSTRAINT_NAME'] == 'PRIMARY' )
                    $this->aPrimaryKeys[ $sTable ][ $aKey['COLUMN_NAME'] ] = $aKey;
                if( $this->isGenerableForeignKey($sTable, $aKey) )
                    $this->aForeignKeys[ $sTable ][ $aKey['COLUMN_NAME'] ] = $aKey;
            }
        }
    }

    /**
     * Check if a Column is a foreign key
     * and if we can map it with an Entity object of the referenced table
     *
     * @param string $sTable Table name
     * @param array $aKey Indexes : COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_COLUMN_NAME, REFERENCED_TABLE_NAME
     *
     * @return bool
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function isGenerableForeignKey( $sTable, array $aKey )
    {
        return
            !empty($aKey['REFERENCED_COLUMN_NAME'] )
            && !empty($aKey['REFERENCED_TABLE_NAME'])
            // Not a primary key
            && !isset($this->aPrimaryKeys[ $sTable ][ $aKey['COLUMN_NAME'] ])
            // Not a already foreign key
            && !isset($this->aForeignKeys[ $sTable ][ $aKey['COLUMN_NAME'] ])
            // Fk name not already exists as normal column name
            && !isset($this->aCols[ $sTable ][ $this->formatPhpForeignAttrName($aKey['COLUMN_NAME']) ])
            && strpos($aKey['COLUMN_NAME'], 'enum') === false // Dont refer to an enum table
            ;
    }

    /**
     * Generate the code for the bridge between the app and the generated
     * classes
     *
     * @return string
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function genOrm()
    {
        return '<?php
            
namespace ' . $this->sDirBase . ';
        
class Orm {

    const OPERATOR_GREATER_THAN = ">";
    const OPERATOR_LOWER_THAN = "<";
    const OPERATOR_GREATEREQUALS_THAN = ">=";
    const OPERATOR_LOWEREQUALS_THAN = "<=";
    const OPERATOR_EQUALS = "=";
    const OPERATOR_DIFF = "<>";
    const OPERATOR_IN = "IN";
    const OPERATOR_NOTIN = "NOT IN";
    const OPERATOR_PC_LIKE_PC = "%LIKE%";
    const OPERATOR_PC_LIKE = "%LIKE";
    const OPERATOR_LIKE_PC = "LIKE%";
    const OPERATOR_ISNULL = "IS NULL";
    const OPERATOR_ISNOTNULL = "IS NOT NULL";
    
    const ORDER_ASC = "ASC";
    const ORDER_DESC = "DESC";
    
    /**
     * @var array $aDb array of CI_DB_driver
     */
    protected static $aDb;
    
    /**
     * @var \Ormega\CacheInterface Cache driver
     */
    protected static $oCache;

    /**
     * Get the database driver set in the init() method
     *
     * @param string $sClassName Classname of the calling class, used to determine which connection use in case of multiple database connections
     *
     * @return CI_DB_driver
     *
     * @author ' . __CLASS__ . '
     */
    public static function driver( $sClassName )
    {
        $aClass = explode("\\\", $sClassName);

        if( isset($aClass[1]) && isset( self::$aDb[ $aClass[1] ] ) )
            return self::$aDb[ $aClass[1] ];
        else
            return reset(self::$aDb);
    }
    
    /**
     * Get the cache driver set in the init() method
     * 
     * @return \Ormega\CacheInterface
     */
    public static function cache()
    {
        return self::$oCache;
    }
    
    /**
     * Initiate the orm with a database connection (can be adapted to any driver
     *      as long as it implement the \Ormega\DbInterface interface).
     * Define an autoload for all Ormega generated classes
     *
     * @param array $aDb Array of CI_DB_driver objects
     * @param \Ormega\CacheInterface|null $oCache
     
     * @return void
     *
     * @author ' . __CLASS__ . '
     */
    public static function init(array $aDb, \Ormega\CacheInterface $oCache = null)
    {

        /* --------------------------------------------------------
         * DATABASE
         * --------------------------------------------------------
         */
        $aDb = self::setDatabase($aDb);

        /* --------------------------------------------------------
         * AUTOLOADER
         * --------------------------------------------------------
         */
        spl_autoload_register(function($class) use ($aDb){
            $aPaths = explode("\\\", $class);

            if( isset($aPaths[0]) && $aPaths[0] == __NAMESPACE__ ) {

                $basepath = __DIR__."/";
                if( isset($aPaths[1]) && isset( $aDb[ $aPaths[1] ] ) ) {
                    $basepath = $basepath.$aPaths[1]."/";
    
                    if( isset($aPaths[2]) && is_dir($basepath.$aPaths[2]) ){
                        $basepath = $basepath.$aPaths[2]."/";
    
                        if( isset($aPaths[3]) && is_dir($basepath.$aPaths[3]) ){
                            $basepath = $basepath.$aPaths[3]."/";
                        }
                    }
                }
                
                if( is_file($basepath.end($aPaths).".php") ){
                    require_once $basepath.end($aPaths).".php";
                }
            }
        });
        
        /* --------------------------------------------------------
         * CACHE
         * --------------------------------------------------------
         */
        self::setCacheDriver($oCache);
    }

    /**
     * Initiate database driver
     *
     * @param array $aDb Array of CI_DB_driver objects
     * @return void
     *
     * @author ' . __CLASS__ . '
     */
    protected static function setDatabase(array $aDb)
    {
        self::$aDb = array();
        foreach ( $aDb as $sDatabase => $aDbDriver ) {
            if ( !is_a($aDbDriver, "Evolution\CodeIgniterDB\CI_DB_driver") ) {
                throw new \InvalidArgumentException("Array of Evolution\CodeIgniterDB\CI_DB_driver objects expected for " . __METHOD__);
            }
            self::$aDb[ ucfirst($sDatabase) ] = $aDbDriver;
        }

        return self::$aDb;
    }

    /**
     * Initiate cache driver
     *
     * @param \Ormega\CacheInterface|null $oCache
     *
     * @author ' . __CLASS__ . '
     */
    protected static function setCacheDriver(\Ormega\CacheInterface $oCache = null)
    {
        if( !is_null($oCache) ) {
            self::$oCache = $oCache;
        } else {
            self::$oCache = new Simucache();
        }
    }
}
';

    }

    protected function genEntitiesCollection(){
        return '<?php
            
namespace ' . $this->sDirBase . ';

class EntitiesCollection implements \ArrayAccess, \Iterator {

    /**
     * @var array Array of entities
     */
    protected $aEntities = array();
            
    /**
     * Constructor
     * @author ' . __CLASS__ . '
     */
    public function __construct() 
    {
        $this->aEntities = array();
    }
    
    /**
     * Get all keys of the entities array 
     * @return array
     * @author ' . __CLASS__ . '
     */
    public function getArrayKeys()
    {
        return array_keys( $this->aEntities );
    }
    
    /**
     * Is the collection empty ?
     * @return bool
     * @author ' . __CLASS__ . '
     */
    public function isEmpty() 
    {
        return empty( $this->aEntities );
    }
    
    
    /**
     * Execute a function on every elements
     *
     * @param string $sMethodName Method name
     * @pararm array $aArgs Method arguments
     *
     * @return array
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    public function __call( $sMethodName, array $aArgs = array() )
    {
        $aReturn = array();
        
        foreach ( $this->aEntities as $oEntity ){
            if( $oEntity instanceof \Ormega\EntitiesCollection ){
                $aReturn[] = call_user_func_array( array($oEntity, $sMethodName), $aArgs);
            }
            elseif( method_exists($oEntity, $sMethodName) )
                $aReturn[ $oEntity->getPkId() ] = call_user_func_array( array($oEntity, $sMethodName), $aArgs);
        }
        return $aReturn;
    }
        
    /**
     * Check if value is correct to be setted in this collection
     * It must be an array (possibly of array of array...) of \Ormega\EntityInterface
     *
     * @param mixed $value
     *
     * @return bool true if valid
     * @throws \InvalidArgumentException If unvalid
     *
     * @author ' . __CLASS__ . '
     */
    protected function validSetter( $value )
    {
        if( $value instanceof \Ormega\EntityInterface || $value instanceof \Ormega\EntitiesCollection ){
            return true;
        }
        else {
            throw new \InvalidArgumentException("Entity collection expect an \Ormega\EntityInterface as element or an array of \Ormega\EntityInterface");
        }
    }

    /**
     * Replace l\'itérateur sur le premier élément
     *
     * @abstracting Iterator
     * @author ' . __CLASS__ . '
     */
    function rewind() 
    {
        return reset($this->aEntities);
    }

    /**
     * Retourne l\'élément courant
     *
     * @return mixed
     *
     * @abstracting Iterator
     * @author ' . __CLASS__ . '
     */
    function current() 
    {
        return current($this->aEntities);
    }

    /**
     * Retourne la clé de l\'élément courant
     *
     * @return int
     *
     * @abstracting Iterator
     * @author ' . __CLASS__ . '
     */
    function key() 
    {
        return key($this->aEntities);
    }

    /**
     * Se déplace sur l\'élément suivant
     *
     * @abstracting Iterator
     * @author ' . __CLASS__ . '
     */
    function next() 
    {
        return next($this->aEntities);
    }

    /**
     * Vérifie si la position courante est valide
     *
     * @return bool
     *
     * @abstracting Iterator
     * @author ' . __CLASS__ . '
     */
    function valid() 
    {
        return key($this->aEntities) !== null;
    }

    /**
     * Assigne une valeur à une position donnée
     *
     * @param mixed $offset La position à laquelle assigner une valeur.
     * @param mixed $value La valeur à assigner.
     *
     * @abstracting ArrayAccess
     * @author ' . __CLASS__ . '
     */
    public function offsetSet($offset, $value) 
    {
        if( $this->validSetter($value) ){             
            if (is_null($offset)) {
                $this->aEntities[] = & $value;
            } else {
                $this->aEntities[$offset] = & $value;
            }
        }
    }   

    /**
     * Retourne la valeur à la position donnée.
     * Cette méthode est exécutée lorsque l\'on vérifie si une position est empty().
     *
     * @param mixed $offset La position à lire.
     *
     * @return \Ormega\EntityInterface|null
     *
     * @abstracting ArrayAccess
     * @author ' . __CLASS__ . '
     */
    public function offsetGet($offset) 
    {
        return $this->offsetExists($offset) ? $this->aEntities[$offset] : null;
    }

    /**
     * Indique si une position existe dans un tableau
     * Cette méthode est exécutée lorsque la fonction isset() ou empty()
     * est appliquée à un objet qui implémente l\'interface ArrayAccess.
     *
     * @param mixed $offset Une position à vérifier.
     *
     * @return bool Cette fonction retourne TRUE en cas de succès ou FALSE si une erreur survient.
     *
     * @abstracting ArrayAccess
     * @author ' . __CLASS__ . '
     */
    public function offsetExists($offset) 
    {
        return isset($this->aEntities[$offset]);
    }

    /**
     * Supprime un élément à une position donnée
     *
     * @param mixed $offset La position à supprimer.
     *
     * @abstracting ArrayAccess
     * @author ' . __CLASS__ . '
     */
    public function offsetUnset($offset) 
    {
        if( $this->offsetExists($offset) ) {
            unset($this->aEntities[$offset]);
        }
    }
}';
    }

    protected function genEntityInterface() {
        return '<?php
            
namespace ' . $this->sDirBase . ';

interface EntityInterface {
    
    /**
     * @return int Return the primary key ID
     */
    public function getPkId();
}
';
    }

    protected function genQueryInterface() {
        return '<?php
            
namespace ' . $this->sDirBase . ';

interface QueryInterface {
}
';
    }

    protected function genEnumInterface() {
        return '<?php
            
namespace ' . $this->sDirBase . ';

interface EnumInterface {
}
';
    }

    protected function genCacheInterface() {
        return '<?php

namespace ' . $this->sDirBase . ';

/**
 * Interface CacheInterface
 *
 * @package Ormega
 */
interface CacheInterface
{
    /**
     * Get stored data
     *
     * @param  string $sKey Unique cache ID
     *
     * @return mixed           Data stored
     */
    public function get( $sKey );

    /**
     * Store data into cache
     *
     * @param  string $sKey  Unique cache ID
     * @param  mixed  $mData Data to store
     * @param  int    $nTime Stored data lifetime (seconds)
     *
     * @return boolean       True if data successfully stored
     */
    public function save( $sKey, $mData, $nTime );
        
    /**
     * Delete stored data
     *
     * @param  string $sKey  Unique cache ID
     *
     * @return boolean       True if data successfully stored
     */
    public function delete( $sKey );
}
';
    }

    protected function genSimucache() {
        return '<?php

namespace ' . $this->sDirBase . ';

/**
 * Simulate a cache system if no one provided
 *
 * @package  Ormega
 */
class Simucache implements \Ormega\CacheInterface {

    /**
     * @var array Data store
     */
    protected $aData = array();

    /**
     * Get stored data
     *
     * @param  string $sKey Unique cache ID
     *
     * @return mixed           Data stored
     */
    public function get($sKey)
    {
        return isset( $this->aData[$sKey] )? $this->aData[$sKey] : false;
    }

    /**
     * Store data into cache
     *
     * @param  string $sKey  Unique cache ID
     * @param  mixed  $mData Data to store
     * @param  int    $nTime Stored data lifetime (seconds)
     *
     * @return boolean       True if data successfully stored
     */
    public function save( $sKey, $mData, $nTime )
    {
        $this->aData[ $sKey ] = $mData;
        return true;
    }    
    
    /**
     * Delete stored data
     *
     * @param  string $sKey  Unique cache ID
     *
     * @return boolean       True if data successfully stored
     */
    public function delete( $sKey )
    {
        if( isset($this->aData[ $sKey ]) ){
            unset( $this->aData[ $sKey ] );
        }
    }
}
';
    }

    /**
     * Gen code for specifics table beginning with "enum" in is name
     * This classe will only content CONSTANTS
     * The "enum" table must contains 3 fields :
     *      - id
     *      - label
     *      - constant
     *
     * Each constant is a row from the db and is formatted as
     *      CONST *constant* = *id*
     *
     * This allow increased code readability and maintenance
     *
     * @param string $sTable
     *
     * @return string
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function genFileEnum( $sTable )
    {
        $pattern = '/([^\w])/';
        $sClassName = $this->formatPhpClassName($sTable);

        /*
         * Find the constant field
         */
        if( isset($this->aCols[$sTable]['constant']) ){
            $sConstantField = $this->aCols[$sTable]['constant']['Field'];
        } else {
            reset($this->aCols[$sTable]);
            // Go to the first string field
            while( $this->getPhpType( current($this->aCols[$sTable]) ) != 'string' && each($this->aCols[$sTable]) );
            if( current($this->aCols[$sTable]) ){
                $aTemp = current($this->aCols[$sTable]);
                $sConstantField = $aTemp['Field'];
            } else {
                $this->output('No constant field found for the table `'.$sTable.'`');
                return '';
            }
        }

        /*
         * Get data from bdd
         */
        $aFields = array();
        foreach ($this->aCols[$sTable] as $aCol){
            $aFields[] = $aCol['Field'];
        }

        $query = $this->db->query("SELECT ".implode(',',$aFields)." FROM `$sTable`");

        $aTableData = array();
        foreach ( $query->result_array() as $aData ) {
            $aData[$sConstantField] = $this->formatPhpAttrName(
                strtoupper( preg_replace($pattern, '', $aData[$sConstantField]) )
            );

            $aTableData[] = $aData;
        }

        $php = '<?php 
        
namespace ' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirEnum . ';

class ' . $sClassName . ' implements \Ormega\EnumInterface {';
        
        foreach ( $aTableData as $aConstant ) {
            $php .= '
    
    /** 
     * @var int ' . (!empty($aConstant['label'])?$aConstant['label']:'') . '
     */
    const ' . $aConstant[$sConstantField] . ' = ' . $aConstant['id'] . ';';
        }

        foreach( $this->aCols[$sTable] as $aCol ){

            // Do not create special method for the prim key
            // as it'll be referenced by the constant
            if($aCol['Field'] == 'id'){
                continue;
            }

            $php .= '
    
    /**
     * Get the "'.$this->formatPhpFuncName($aCol['Field']).'" associated to an ID
     * @param int $nId
     * @return '.$this->getPhpType($aCol).'
     * @author ' . __CLASS__ . '
     */
    public static function get'.$this->formatPhpFuncName($aCol['Field']).'( $nId )
    {
        $aValues = array(';
            foreach ($aTableData as $aRowData){
                foreach ($aRowData as $sField => $mValue){
                    if( $aCol['Field'] == $sField ) {
                        $php .= '
            '.$aRowData['id'] . ' => "' . addslashes($mValue) . '",';
                    }
                }
            }
            $php .= '
        );
        
        return isset($aValues[ $nId ])? $aValues[ $nId ] : null;
    }
            ';

        }

        $php .= '
        
    /**
     * Get all the constants in a array form
     * @return array
     * @author ' . __CLASS__ . '
     */
    public static function getArray()
    {
        return array(';

        foreach ($aTableData as $aRowData){
            $php .= '
            "' . $aRowData[$sConstantField] . '" => array(';
            foreach ($aRowData as $sField => $mValue){
                    $php .= '
                "'.$sField . '" => "' . addslashes($mValue) . '",';

            }
            $php .= '
            ),';
        }

        $php .= '
        );
    }    
    
    /**
     * Get an ID from a string constant
     * @param string $sConstant
     * @return int
     * @author ' . __CLASS__ . '
     */
    public static function getId( $sConstant )
    {
        switch( strtoupper($sConstant) ){';

        foreach ( $aTableData as $aConstant ) {
            $php .= '
            case "'. $aConstant[$sConstantField] .'":
                return self::'. $aConstant[$sConstantField] .';
                break;';
        }

        $php .= '
            default:
                return 0;
        }
    }
}';

        return $php;
    }

    /**
     * Generate the "public" entity class
     * The entity class is made to "emulate" a table in php
     * Each Entity object represent one row
     * This classes are used to save data (insert and update)
     *
     * The "public" file can be freely modified by end user as it's not
     * overwritten if it already exists
     *
     * @param string $sTable
     *
     * @return string
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function genFileEntity( $sTable )
    {
        $sClassName = $this->formatPhpClassName($sTable);

        $php = '<?php 
        
namespace ' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirEntity . ';

class ' . $sClassName . ' extends ' . $this->sDirPrivate . '\\' . $sClassName . ' {
            
           
}';

        return $php;
    }

    /**
     *
     *
     * @param string $sTable
     *
     * @return string
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function genFileEntityPrivate( $sTable )
    {
        $sClassName = $this->formatPhpClassName($sTable);
        
        $aPks = array();
        foreach ($this->aPrimaryKeys[ $sTable ] as $aPk) {
            $aPks[] = '$this->get'.$this->formatPhpFuncName($aPk['COLUMN_NAME']).'()';
        }
        
        $php = '<?php 
        
namespace ' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirEntity . '\\' . $this->sDirPrivate . ';

class ' . $sClassName . ' implements \Ormega\EntityInterface {
            
    /**
     * @var bool $_isLoadedFromDb for intern usage : let know the class data comes from db or not 
     */
    protected $_isLoadedFromDb;
    
    /**
     * @var bool $_isModified for intern usage : let know the class if data changed from last save
     */
    protected $_isModified;
    
    protected $_aCacheReference = array();
';
        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $php .= $this->genAttribute($aCol);
        }

        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {
            $php .= $this->genForeignAttribute($sTable, $this->aCols[ $sTable ][ $sKeyName ]);
        }

        $php .= $this->genConstructor($sClassName);
        $php .= '
        
    /**
     * Check if the model is loaded from bdd
     * @param boolean $bLoaded
     * @return boolean
     * @author ' . __CLASS__ . '
     */
    public function loaded( $bLoaded = null )
    {
        if (!is_null($bLoaded) && is_bool($bLoaded)) {
            $this->_isLoadedFromDb = $bLoaded;
        }

        return $this->_isLoadedFromDb;
    }
    
    /**
     * Check if the object has been modified since the load 
     * @param boolean $bModified
     * @return boolean
     * @author ' . __CLASS__ . '
     */
    public function modified( $bModified = null )
    {
        if (!is_null($bModified) && is_bool($bModified)) {
            $this->_isModified = $bModified;
            if( $bModified ){
                foreach( $this->_aCacheReference as $sCacheKey ){
                    \\' . $this->sDirBase . '\Orm::cache()->delete($sCacheKey);
                }
            }
        }

        return $this->_isModified;
    }
    
    public function addCacheRef( $sCacheRef )
    {
        $this->_aCacheReference[ $sCacheRef ] = true; 
    }
    
    public function cacheKey()
    {
        return __CLASS__.$this->getPkId();
    }
    
    /**
     * Get a unique identifier composed by all primary keys 
     * with "-" separator
     *
     * @return string
     * 
     * @abstracted \Ormega\EntityInterface
     * @author ' . __CLASS__ . '
     */
    public function getPkId()
    {
        return (string)'.(!empty($aPks) ? implode('."-".', $aPks) : 'uniqid("", true)').';
    }';

        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $php .= $this->genGetter($aCol);
        }
        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {
            $php .= $this->genGetterForeignKey($sTable, $this->aCols[ $sTable ][ $sKeyName ]);
        }
        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $php .= $this->genSetter($sTable, $aCol);
        }
        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {
            $php .= $this->genSetterForeignKey($sTable, $this->aCols[ $sTable ][ $sKeyName ]);
        }

        $php .= $this->genSave($sTable);

        $php .= '
        
    public function __clone(){';
        
        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {
            $php .= '
        if( $this->'.$this->formatPhpForeignAttrName($aKey['COLUMN_NAME']).' ) 
            $this->'.$this->formatPhpForeignAttrName($aKey['COLUMN_NAME'])
                .' = clone $this->'.$this->formatPhpForeignAttrName($aKey['COLUMN_NAME']).';';
        }
        
        $php .= '
    }
        
}';

        return $php;
    }

    protected function genConstructor($sClassName)
    {
        $php = '
    /**
     * ' . $sClassName . ' constructor
     * @return void
     * @author ' . __CLASS__ . '
     */
    public function __construct()
    {
        $this->_isLoadedFromDb  = false;
        $this->modified(false);
    }';

        return $php;
    }

    protected function genAttribute( array $aCol )
    {
        $sAttrName = $this->formatPhpAttrName($aCol['Field']);
        $sType = $this->getPhpType($aCol);

        $php = '
    /**
     * @var ' . $sType . ' $' . $sAttrName . ' Null:' . $aCol['Null'] . ' Maxlenght:' . $this->getMaxLength($aCol) . ' ' . $aCol['Extra'] . '
     */
    protected $' . $sAttrName . ';
        ';

        return $php;
    }

    public function genForeignAttribute( $sTable, array $aCol )
    {
        $aFK = $this->aKeys[ $sTable ][ $aCol['Field'] ];
        $sObjAttrName = $this->formatPhpForeignAttrName($aFK['COLUMN_NAME']);
        
        $sType = '\\' . $this->sDirBase
            . '\\' . $this->sDatabase
            . '\\' . $this->sDirEntity
            . '\\' . $this->formatPhpClassName($aFK['REFERENCED_TABLE_NAME']);

        $php = '
    /**
     * @var ' . $sType . ' $' . $sObjAttrName . ' Null:' . $aCol['Null'] . ' ' . $aCol['Extra'] . '
     */
    protected $' . $sObjAttrName . ';
        ';

        return $php;
    }

    protected function genGetter( array $aCol )
    {
        $sFuncName = $this->formatPhpFuncName($aCol['Field']);
        $sAttrName = $this->formatPhpAttrName($aCol['Field']);

        $php = '
    
    /**
     * @return ' . $this->getPhpType($aCol) . '
     * @author ' . __CLASS__ . '
     */
    public function get' . $sFuncName . '()
    {
        return $this->' . $sAttrName . ';
    }';

        return $php;
    }

    public function genGetterForeignKey( $sTable, array $aCol )
    {
        $aFK = $this->aKeys[ $sTable ][ $aCol['Field'] ];

        $sObjAttrName = $this->formatPhpForeignAttrName($aFK['COLUMN_NAME']);
        $sObjFuncName = $this->formatPhpFuncName($sObjAttrName);
        
        $sType = '\\' . $this->sDirBase
            . '\\' . $this->sDatabase
            . '\\' . $this->sDirEntity
            . '\\' . $this->formatPhpClassName($aFK['REFERENCED_TABLE_NAME']);

        $php = '
    
    /**
     * @return ' . $sType . '
     * @author ' . __CLASS__ . '
     */
    public function get' . $sObjFuncName . '()
    {
        if( is_null($this->'.$sObjAttrName.') ){';

        if( isset($this->aForeignKeys[ $sTable ][ $aCol['Field'] ]) ){
            $aFK = $this->aForeignKeys[ $sTable ][ $aCol['Field'] ];
            $sQuery = '\\' . $this->sDirBase
                . '\\' . $this->sDatabase
                . '\\' . $this->sDirQuery
                . '\\' . $this->formatPhpClassName($aFK['REFERENCED_TABLE_NAME']);

                $php .= '
            $this->' . $sObjAttrName . ' = '.$sQuery.'::create()
                ->filterBy'.$this->formatPhpFuncName( $aFK['REFERENCED_COLUMN_NAME'] )
                .'($this->'.$this->formatPhpAttrName( $aCol['Field'] ).')
                ->findOne();';
        }

        $php .= '
        }
        
        return $this->' . $sObjAttrName . ';
    }';

        return $php;
    }
    
    /**
     * Generate entities setters
     *
     * @param string $sTable Table name
     * @param array $aCol Array with all table columns
     *
     * @return string
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function genSetter( $sTable, array $aCol )
    {
        $sClassName = $this->formatPhpClassName($sTable);
        
        $sFuncName = $this->formatPhpFuncName($aCol['Field']);
        $sAttrName = $this->formatPhpAttrName($aCol['Field']);
        $sType     = $this->getPhpType($aCol);

        $sDefault   = '';
        $sTest      = '!is_' . $sType . '( $' . $sAttrName . ' )';

        if( $this->isNull($aCol) ){
            $sDefault = ' = null';
            $sTest = '!is_null( $' . $sAttrName . ' ) && ' . $sTest;
        }

        $php = '
        
    /**
     * @param ' . $sType . ' $' . $sAttrName . ' Maxlenght:' . $this->getMaxLength($aCol) . '
     * @return '.$sClassName.'
     * @throw \InvalidArgumentException
     * @author ' . __CLASS__ . '
     */
    public function set' . $sFuncName . '( $' . $sAttrName . $sDefault . ' )
    {
        if( '.$sTest.' ) {
            throw new \InvalidArgumentException("Invalid parameter for \"".__METHOD__."\" : (' . $sType . ') expected ; \"$' . $sAttrName . '\" (".gettype($' . $sAttrName . ').") provided");
         }
            
        $this->' . $sAttrName . ' = $' . $sAttrName . ';
        $this->modified(true);
        
        return $this;
    }';

        return $php;
    }

    public function genSetterForeignKey( $sTable, array $aCol )
    {
        $sClassName = $this->formatPhpClassName($sTable);
        
        $aFK = $this->aForeignKeys[ $sTable ][ $aCol['Field'] ];

        $sObjAttrName = $this->formatPhpForeignAttrName($aFK['COLUMN_NAME']);
        $sObjFuncName = $this->formatPhpFuncName($sObjAttrName);
        
        $sType = '\\' . $this->sDirBase
            . '\\' . $this->sDatabase
            . '\\' . $this->sDirEntity
            . '\\' . $this->formatPhpClassName($aFK['REFERENCED_TABLE_NAME']);

        $sAttrName = $this->formatPhpAttrName($aCol['Field']);
        $sReferencedAttr = $this->formatPhpFuncName($aFK['REFERENCED_COLUMN_NAME']);

        $php = '
        
    /**
     * @param ' . $sType . ' $' . $sObjAttrName . '
     * @return '.$sClassName.'
     * @author ' . __CLASS__ . '
     */
    public function set' . $sObjFuncName . '(' . $sType . ' $' . $sObjAttrName . ' )
    {
        $this->' . $sObjAttrName . ' = $' . $sObjAttrName . ';
        $this->' . $sAttrName . ' = $' . $sObjAttrName . '->get' . $sReferencedAttr . '();
        $this->modified(true);
        
        return $this;
    }';

        return $php;
    }

    protected function genSave( $sTable )
    {
        $aUpdateWhere = array();
        $sPrimaryPhpName = '';
        foreach ( $this->aPrimaryKeys[ $sTable ] as $aPrimaryKey ) {
            $sPrimaryName = $aPrimaryKey['COLUMN_NAME'];
            $sPrimaryPhpName = $this->formatPhpAttrName($sPrimaryName);
            $aUpdateWhere[] = $sPrimaryName . ' = '
                . $this->sqlEscQuote . $this->sqlQuote
                . '.$this->' . $sPrimaryPhpName . '.'
                . $this->sqlQuote . $this->sqlEscQuote;
        }

        $php = '
        
    /**
     * Save the object into the database
     * @return bool false if an error occurred ; true otherwise
     * @author ' . __CLASS__ . '
     */
    public function save(){

        $return = true;
        ';
        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {

            $sObjAttrName = $this->formatPhpForeignAttrName($aKey['COLUMN_NAME']);

            $php .= '
        $return = $return && (!$this->' . $sObjAttrName . ' || $this->' . $sObjAttrName . '->save());
        ';
        }

        $php .= '
        if( $this->modified() && $return ){
        ';

        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $sAttrName = $this->formatPhpAttrName($aCol['Field']);

            $sValue = '$this->' . $sAttrName;
            $sSetter = '\\' . $this->sDirBase . '\Orm::driver(__CLASS__)->set('. $this->sqlQuote . $aCol['Field'] . $this->sqlQuote .', ' . $sValue . ');';

            if( !$this->isNull($aCol) ){
                 $php .= '
            
            if( !is_null('.$sValue.') ){
                '.$sSetter.'
            }';
            }
            else {
                $php .= '
            
            '.$sSetter;
            }
        }

        $php .= '
         
            if( !$this->_isLoadedFromDb ){
                $return = $return && \\' . $this->sDirBase . '\Orm::driver(__CLASS__)->insert('. $this->sqlQuote . $sTable . $this->sqlQuote .');
                $this->_isLoadedFromDb = true;';
        if( !empty($sPrimaryPhpName) ) {
            $php .= '
                $this->' . $sPrimaryPhpName . ' = \\' . $this->sDirBase . '\Orm::driver(__CLASS__)->insert_id();';
        }
        
        $php .= '
            }
            else {
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)->where('. $this->sqlQuote . implode(' AND ', $aUpdateWhere) . $this->sqlQuote .');
                $return = $return && \\' . $this->sDirBase . '\Orm::driver(__CLASS__)->update('. $this->sqlQuote . $sTable . $this->sqlQuote .');
            }
            
            if( $return ){
                $this->modified(false);
            }
        }
        
        return $return;
    }';

        return $php;
    }

    /**
     * Generate the code for the 'public' query file
     * This file allow the "SELECT" requests from a table
     * The public one can be freely modified by end user as it's not overwritten
     *  if it already exists
     *
     * @param string $sTable Table name
     *
     * @return string
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    public function genFileQuery( $sTable )
    {

        $sClassName = $this->formatPhpClassName($sTable);

        $php = '<?php 
        
namespace ' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirQuery . ';

class ' . $sClassName . ' extends ' . $this->sDirPrivate . '\\' . $sClassName . ' {

           
}';

        return $php;
    }

    /**
     * Generate the code for the 'private' query file
     * This file allow the "SELECT" requests from a table
     * The private file is overwritten in each generation
     *
     * @param string $sTable Table name
     *
     * @return string
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    public function genFileQueryPrivate( $sTable )
    {

        $sClassName = $this->formatPhpClassName($sTable);

        $php = '<?php 
        
namespace ' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirQuery . '\\' . $this->sDirPrivate . ';

class ' . $sClassName . ' implements \Ormega\QueryInterface {
    
    /**
     * Get an instance of this class to chain methods 
     *      without have to use $var = new ' . $sClassName . '()
     *
     * @return \\' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     *
     * @author '.__CLASS__.'
     */
    public static function create(){
        return new static();
    }
    
    /**
     * Add a limit to the nb result row for the next find() request
     
     * @param int $limit
     * @param int $start
     *
     * @return \\' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     *
     * @author '.__CLASS__.'
     */
    public function limit( $limit, $start = 0 ){
        \\' . $this->sDirBase . '\Orm::driver(__CLASS__)->limit( $limit, $start );
        return $this;
    }
    ';

        $php .= $this->genFilterBy($sTable);
        $php .= $this->genGroupBy($sTable);
        $php .= $this->genOrderBy($sTable);
        $php .= $this->genFind($sTable);

        $php .= '
    
    /**
     * Get the first result from the find() request 
     *
     * @return \\' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirEntity . '\\'.$sClassName.'
     *
     * @author '.__CLASS__.'
     */
    public function findOne(){

        $this->limit(1);
        return $this->find()->rewind();
    }
        
}';

        return $php;
    }

    protected function genFilterBy( $sTable )
    {
        $sClassName = $this->formatPhpClassName($sTable);

        $php = '';

        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $sFuncName = $this->formatPhpFuncName($aCol['Field']);

            $php .= '
    
    /**
     * Add a special where to next find() call
     * @param mixed $value The filter value
     * @param string $operator Can be \Ormega\Orm::OPERATOR_* constant (">", "<=", "=", ...)
     * @return \\' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     * @author '.__CLASS__.'
     */
    public function filterBy' . $sFuncName . '( $value, $operator = \Ormega\Orm::OPERATOR_EQUALS )
    {
        if( $operator == \Ormega\Orm::OPERATOR_IN || $operator == \Ormega\Orm::OPERATOR_NOTIN ){
            if( !is_array($value) ) {
                $value = explode('.$this->sqlQuote.','.$this->sqlQuote.', $value);
            }
        }
        
        switch( $operator ){
            case \Ormega\Orm::OPERATOR_IN:
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
                    ->where_in(' . $this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . $this->sqlQuote . ', $value);
                break;
            case \Ormega\Orm::OPERATOR_NOTIN:
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
                    ->where_not_in(' . $this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . $this->sqlQuote . ', $value);
                break;
            case \Ormega\Orm::OPERATOR_LIKE_PC:
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
                    ->like(' . $this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . $this->sqlQuote . ', $value, '.$this->sqlQuote.'after'.$this->sqlQuote.');
                break;
            case \Ormega\Orm::OPERATOR_PC_LIKE:
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
                    ->like(' . $this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . $this->sqlQuote . ', $value, '.$this->sqlQuote.'before'.$this->sqlQuote.');
                break;
            case \Ormega\Orm::OPERATOR_PC_LIKE_PC:
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
                    ->like(' . $this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . $this->sqlQuote . ', $value, '.$this->sqlQuote.'both'.$this->sqlQuote.');
                break;
            case \Ormega\Orm::OPERATOR_ISNULL:
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
                    ->where(' . $this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . ' IS NULL' . $this->sqlQuote . ');
                break;
            case \Ormega\Orm::OPERATOR_ISNOTNULL:
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
                    ->where(' . $this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . ' IS NOT NULL' . $this->sqlQuote . ');
                break;
            default:
                \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
                    ->where( ' . $this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . ' ' . $this->sqlQuote . '.$operator, $value );
        }
        
        return $this;
    }';

        }

        return $php;
    }

    protected function genGroupBy( $sTable )
    {
        $sClassName = $this->formatPhpClassName($sTable);

        $php = '';

        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $sFuncName = $this->formatPhpFuncName($aCol['Field']);

            $php .= '
    
    /**
     * Add a special groupby to next find() call 
     * @return \\' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     * @author '.__CLASS__.'
     */
    public function groupBy' . $sFuncName . '()
    {
        \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
            ->group_by('.$this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . $this->sqlQuote.');
        return $this;
    }';

        }

        return $php;
    }

    protected function genOrderBy( $sTable )
    {
        $sClassName = $this->formatPhpClassName($sTable);

        $php = '';

        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $sFuncName = $this->formatPhpFuncName($aCol['Field']);

            $php .= '
        
    /**
     * Add a special order to next find() call
     * @param string $order Can be \Ormega\Orm::ORDER_* constant ("ASC", "DESC")
     * @return \\' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     * @author '.__CLASS__.'
     */
    public function orderBy' . $sFuncName . '( $order = \Ormega\Orm::ORDER_ASC )
    {
        \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
            ->order_by('.$this->sqlQuote . $this->db->database .'.'. $sTable . '.' . $aCol['Field'] . $this->sqlQuote.', $order);
        return $this;
    }';

        }

        return $php;
    }

    protected function genFind( $sTable )
    {
        $sClassName = $this->formatPhpClassName($sTable);

        $aColumns = array();
        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $aColumns[] = $this->db->database.'.'.$sTable.'.'.$aCol['Field'];
        }

        $php = '
            
    /**
     * Start the select request composed with any filter, groupby,... set before
     * Return an array of 
     *      \\' . $this->sDirBase . '\\' . $this->sDatabase . '\\' . $this->sDirEntity . '\\'.$sClassName.' object 
     * @return \Ormega\EntitiesCollection
     * @author '.__CLASS__.'
     */
    public function find()
    {
        $aReturn = new \Ormega\EntitiesCollection();
            
        \\' . $this->sDirBase . '\Orm::driver(__CLASS__)
            ->select(' . $this->sqlQuote . implode(',', $aColumns) . $this->sqlQuote . ')
            ->from(' . $this->sqlQuote . $this->db->database .'.'. $sTable . $this->sqlQuote . ');
           
        $sQueryCacheId = md5( \\' . $this->sDirBase . '\Orm::driver(__CLASS__)->get_compiled_select(null, false) );
        
        if( $aCacheRef = \\' . $this->sDirBase . '\Orm::cache()->get($sQueryCacheId) AND is_array($aCacheRef) ){    
            foreach( $aCacheRef as $sCacheRef ){
                $obj = \\' . $this->sDirBase . '\Orm::cache()->get($sCacheRef);
                $aReturn[ $obj->getPkId() ] = $obj;
            }       
        }
        else {
            $aCacheRef = array();
            $query = \\' . $this->sDirBase . '\Orm::driver(__CLASS__)->get();            
            foreach( $query->result() as $row ){
                
                $obj = new \\' . $this->sDirBase
                    . '\\' . $this->sDatabase
                    . '\\' . $this->sDirEntity
                    . '\\'.$sClassName.'();
                ';

        foreach ( $this->aCols[ $sTable ] as $aCol ) {

            $sFuncName = $this->formatPhpFuncName($aCol['Field']);
            $sType = $this->getPhpType($aCol);

            if( $this->isNull($aCol) ){
                $php .= '
                if( !is_null($row->' . $aCol['Field'] . ') ){
                    $obj->set' . $sFuncName . '((' . $sType . ') $row->' . $aCol['Field'] . ');
                }';

            } else {
                $php .= '
                $obj->set' . $sFuncName . '((' . $sType . ') $row->' . $aCol['Field'] . ');';
            }
        }

        $php .= '
                $obj->addCacheRef($sQueryCacheId);
                $obj->loaded(true);
                $obj->modified(false);
                
                $aReturn[ $obj->getPkId() ] = $obj;
                \\' . $this->sDirBase . '\Orm::cache()->save($obj->cacheKey(), $obj, 3600);
                $aCacheRef[] = $obj->cacheKey();
            }            
            \\' . $this->sDirBase . '\Orm::cache()->save($sQueryCacheId, $aCacheRef, 3600);
        }
            
        \\' . $this->sDirBase . '\Orm::driver(__CLASS__)->reset_query();

        return $aReturn;
    }';

        return $php;
    }

    protected function genFiles()
    {
        $sRealBasePath = realpath($this->sBasePath);
        if ( !$sRealBasePath ) {
            throw new \Exception('Destination dir doesnt exists : ' . $sRealBasePath);
        }

        # ./
        if ( !is_dir($sRealBasePath . '/' . $this->sDirBase) ) {
            mkdir($sRealBasePath . '/' . $this->sDirBase, 0775);
        }

        # ./database
        if ( !is_dir($sRealBasePath . '/' . $this->sDirBase . '/' . $this->sDatabase) ) {
            mkdir($sRealBasePath . '/' . $this->sDirBase . '/' . $this->sDatabase, 0775);
        }

        $sRealSourceDir = realpath($sRealBasePath . '/' . $this->sDirBase . '/' . $this->sDatabase);

        # ./Enum
        if ( !is_dir($sRealSourceDir . '/' . $this->sDirEnum) ) {
            mkdir($sRealSourceDir . '/' . $this->sDirEnum, 0775);
        }

        # ./Entity
        if ( !is_dir($sRealSourceDir . '/' . $this->sDirEntity) ) {
            mkdir($sRealSourceDir . '/' . $this->sDirEntity, 0775);
        }
        $sRealSourceEntityDir = realpath($sRealSourceDir . '/' . $this->sDirEntity);

        # ./Entity/Private
        if ( !is_dir($sRealSourceEntityDir . '/' . $this->sDirPrivate) ) {
            mkdir($sRealSourceEntityDir . '/' . $this->sDirPrivate, 0775);
        }

        # ./Query
        if ( !is_dir($sRealSourceDir . '/' . $this->sDirQuery) ) {
            mkdir($sRealSourceDir . '/' . $this->sDirQuery, 0775);
        }
        $sRealSourceQueryDir = realpath($sRealSourceDir . '/' . $this->sDirQuery);

        # ./Query/Private
        if ( !is_dir($sRealSourceQueryDir . '/' . $this->sDirPrivate) ) {
            mkdir($sRealSourceQueryDir . '/' . $this->sDirPrivate, 0775);
        }

        foreach ( $this->aFiles as $aFile ) {

            $this->createFile($sRealBasePath . '/' . $aFile['file'], $aFile['content'], $aFile['erase']);
        }
    }

    /**
     * Create one file
     *
     * @param string $fullFilePath Full path for the new file
     * @param string $content      File content
     * @param bool   $erase        Overwrite the file if it already exists
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function createFile( $fullFilePath, $content, $erase = false )
    {
        $bGen = false;
        $bFileExists = file_exists($fullFilePath);

        if ( !$bFileExists || $erase )
            $bGen = file_put_contents($fullFilePath, $content);

        if ( $bGen )
            $this->output('Gen file "' . $fullFilePath . '" : OK');
        else {
            $sFileExists = $bFileExists ? 'YES' : 'NO';
            $sErase = $erase ? 'YES' : 'NO';
            $this->output(
                'Gen file "'. $fullFilePath . '" : KO ; File already exists ? '
                . $sFileExists . ' ; Overwrite ? ' . $sErase
            );
        }
    }

    /**
     * Is $aCol an unsigned field ?
     *
     * @param array $aCol Column info from "SHOW COLUMNS" request
     *
     * @return bool
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function isUnsigned( array $aCol )
    {
        return strpos($aCol['Type'], 'unsigned') !== false;
    }

    /**
     * Is NULL allowed for a column ?
     *
     * @param array $aCol Field info from "SHOW COLUMNS" request
     *
     * @return bool
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function isNull( array $aCol )
    {
        if( isset($aCol['Null']) && $aCol['Null'] == 'YES') return true;
        else return false;
    }

    protected function getPhpEscape( $var )
    {
        return $this->sqlQuote . '.\\' . $this->sDirBase . '\Orm::driver(__CLASS__)->escape(' . $var . ').' . $this->sqlQuote;
    }

    protected function getPhpType( $aCol )
    {
        preg_match('/^([a-z]+)/', $aCol['Type'], $aMatches);
        $sType = $aMatches[0];

        switch ( $sType ) {
            case 'tinyint':
                if ( $this->getMaxLength($aCol) == 1 ) {
                    $sType = 'bool';
                }
                else {
                    $sType = 'int';
                }
                break;
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'bigint':
            case 'bit':
            case 'year':
                $sType = 'int';
                break;
            case 'float':
            case 'double':
            case 'decimal':
                $sType = 'float';
                break;
            case 'varchar':
            case 'char':
            case 'varbinary':
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
            case 'timestamp':
            case 'datetime':
            case 'date':
            case 'enum':
                $sType = 'string';
                break;
        }

        return $sType;
    }

    protected function getMaxLength( array $aCol )
    {
        $nMaxlength = null;

        preg_match('/\(([0-9]+)\)/', $aCol['Type'], $aMatches);
        if ( isset($aMatches[1]) )
            $nMaxlength = (int)$aMatches[1];
        else {
            switch ( $aCol['Type'] ) {
                case 'date';
                    $nMaxlength = 10;
                    break;
                case 'timestamp':
                case 'datetime':
                    $nMaxlength = 19;
                    break;
                case 'tinytext':
                case 'tinyblob':
                    $nMaxlength = 255;
                    break;
                case 'text':
                case 'blob':
                    $nMaxlength = 65000;
                    break;
                case 'mediumtext':
                case 'mediumblob':
                    $nMaxlength = 16777215;
                    break;
                case 'longtext':
                case 'longblob':
                    $nMaxlength = 4294967295;
                    break;
            }
        }

        return $nMaxlength;
    }

    protected function formatPhpFuncName( $sName )
    {
        $sName = str_replace('_', ' ', $sName);
        return str_replace(array('-', ' ',), '', ucwords($sName));
    }

    protected function formatPhpClassName( $sName )
    {
        $sName = str_replace('_', ' ', $sName);
        return str_replace(array('-',' '), '', ucwords($sName));
    }

    protected function formatPhpAttrName( $sName )
    {
        if( is_numeric(substr($sName, 0, 1)) ){
            $sName = '_'.$sName;
        }
        return $sName;
    }

    /**
     * Return the name of the attribute without the characters after the last underscore (_)
     *
     * @param string $name
     *
     * @return string
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    protected function formatPhpForeignAttrName( $sName )
    {
        return substr( $sName, 0, strrpos( $sName, '_' ) );
    }
}