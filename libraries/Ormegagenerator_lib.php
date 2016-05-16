<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Class Ormega\Generator
 *
 * @package  Ormega
 * @category External
 * @version  20160411
 */
class Ormegagenerator_lib
{
    public $sBasePath = '';
    public $sTableFilter = '.*';

    public $sqlQuote = '"';
    public $sqlEscQuote = '\'';

    public $verbose = true;

    protected $db;

    protected $aTables = array();
    protected $aCols = array();
    protected $aKeys = array();
    protected $aPrimaryKeys = array();
    protected $aForeignKeys = array();
    protected $aFiles = array();

    protected $sDirBase = __NAMESPACE__;
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
     *                      'dir_base' => relative path where to generate models (relative to app root)
     *
     * @author Matthieu Dos Santos <m.dossantos@santiane.fr>
     */
    public function __construct( array $config )
    {
        if( !isset( $config['db'] ) || !is_a($config['db'], 'CI_DB_driver') ){
            throw new InvalidArgumentException('Instance of CI_DB_driver needed to start the model generator');
        }

        $this->db = $config['db'];

        if( isset($config['table_filter']) )
            $this->sTableFilter = $config['table_filter'];

        if( isset($config['base_path']) ) {}
            $this->sBasePath = $config['base_path'];

        if( isset($config['dir_base']) ) {}
            $this->sDirBase = $config['dir_base'];
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

        $this->output('Config : Filter tables with "'. $this->sTableFilter .'" regular expression');
        $this->output('Config : Gerenarate models in dir "'. $this->sDirBase .'"');

        $this->getTables();

        $this->aFiles[] = array(
            'file'    => $this->sDirBase . '/Orm.php',
            'content' => $this->genOrm(),
            'erase'   => true,
        );

        foreach ( $this->aTables as $sTable ) {

            $this->getFields($sTable);
            $this->getKeys($sTable);

            if ( strpos($sTable, 'enum_') === 0 ) {

                $this->aFiles[] = array(
                    'file'    => $this->sDirBase . '/' . $this->sDirEnum . '/' . $this->formatPhpClassName($sTable) . '.php',
                    'content' => $this->genFileEnum($sTable),
                    'erase'   => true,
                );
            }
            else {

                $this->aFiles[] = array(
                    'file'    => $this->sDirBase . '/' . $this->sDirEntity . '/' . $this->formatPhpClassName($sTable) . '.php',
                    'content' => $this->genFileEntity($sTable),
                    'erase'   => false,
                );

                $this->aFiles[] = array(
                    'file'    => $this->sDirBase . '/' . $this->sDirEntity . '/' . $this->sDirPrivate . '/' . $this->formatPhpClassName($sTable) . '.php',
                    'content' => $this->genFileEntityPrivate($sTable),
                    'erase'   => true,
                );

                $this->aFiles[] = array(
                    'file'    => $this->sDirBase . '/' . $this->sDirQuery . '/' . $this->formatPhpClassName($sTable) . '.php',
                    'content' => $this->genFileQuery($sTable),
                    'erase'   => false,
                );

                $this->aFiles[] = array(
                    'file'    => $this->sDirBase . '/' . $this->sDirQuery . '/' . $this->sDirPrivate . '/' . $this->formatPhpClassName($sTable) . '.php',
                    'content' => $this->genFileQueryPrivate($sTable),
                    'erase'   => true,
                );
            }
        }

        $this->genFiles();

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
        $query = $this->db->query('SHOW TABLES');
        foreach( $query->result_array() as $aTable ) {
            $aTable = array_values($aTable);
            if ( empty($this->sTableFilter) || preg_match('/' . $this->sTableFilter . '/', $aTable[0]) )
                $this->aTables[] = $aTable[0];
        }

        $this->output('Table list : ' . implode(' ; ', $this->aTables));
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
    const OPERATOR_IN = "IN";
    const OPERATOR_NOTIN = "NOT IN";
    const OPERATOR_PC_LIKE_PC = "%LIKE%";
    const OPERATOR_PC_LIKE = "%LIKE";
    const OPERATOR_LIKE_PC = "LIKE%";
    const ORDER_ASC = "ASC";
    const ORDER_DESC = "DESC";
    
    /**
     * @var \Ormega\DbInterface $db
     */
    protected static $db;

    /**
     * Obtain the database driver set in the init() method
     * @return \Ormega\DbInterface
     */
    public static function driver(){
        return self::$db;
    }
    
    /**
     * Initiate the orm with a database connection (can be adapted to any driver
     *      as long as it implement the \Ormega\DbInterface interface).
     * Define an autoload for all Ormega generated classes
     *
     * @param \Ormega\DbInterface $db Database interface
     * @return void
     */
    public static function init(CI_DB $db){

        self::$db = $db;

        spl_autoload_register(function($class){

            $aPaths = explode("\\\", $class);

            if( isset($aPaths[0]) && $aPaths[0] == __NAMESPACE__ ) {
                $basepath = __DIR__."/";

                if( isset($aPaths[1]) && is_dir($basepath.$aPaths[1]) ){
                    $basepath = $basepath.$aPaths[1]."/";

                    if( isset($aPaths[2]) && is_dir($basepath.$aPaths[2]) ){
                        $basepath = $basepath.$aPaths[2]."/";
                    }
                }

                if( is_file($basepath.end($aPaths).".php") ){
                    require_once $basepath.end($aPaths).".php";
                }
            }
        });
    }
}';
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
                  WHERE TABLE_NAME = '$sTable'"
        );
        foreach ( $query->result_array() as $aKey ) {
            if( isset($this->aCols[ $sTable ][ $aKey['COLUMN_NAME'] ]) ) {
                $this->aKeys[ $sTable ][ $aKey['COLUMN_NAME'] ] = $aKey;
                if ( $aKey['CONSTRAINT_NAME'] == 'PRIMARY' )
                    $this->aPrimaryKeys[ $sTable ][ $aKey['COLUMN_NAME'] ] = $aKey;
                if ( $this->isGenerableForeignKey($sTable, $this->aCols[ $sTable ][ $aKey['COLUMN_NAME'] ]) )
                    $this->aForeignKeys[ $sTable ][ $aKey['COLUMN_NAME'] ] = $aKey;
            }
        }
    }

    protected function isGenerableForeignKey( $sTable, array $aCol )
    {
        return
            isset($this->aKeys[ $sTable ][ $aCol['Field'] ])
            && !isset($this->aPrimaryKeys[ $sTable ][ $aCol['Field'] ])
            && strpos($aCol['Field'], 'enum_') === false;
    }

    protected function formatPhpClassName( $sName )
    {
        return str_replace('_', '', ucwords($sName, '_'));
    }

    /**
     * Gen code for specifics table beginning with "enum_" in is name
     * This classe will only content CONSTANTS
     * The "enum_" table must contains 3 fields :
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
        $sClassName = $this->formatPhpClassName($sTable);

         $query = $this->db->query("SELECT id, label, constant FROM `$sTable`");

        $aConstants = array();
        foreach ( $query->result_array() as $aData ) {
            $aConstants[] = $aData;
        }

        $php = '<?php 
        
namespace ' . $this->sDirBase . '\\' . $this->sDirEnum . ';

class ' . $sClassName . ' {
';
        foreach ( $aConstants as $aConstant ) {
            $php .= '
    
    /**
     * @var int ' . $aConstant['label'] . '
     */
    const ' . strtoupper($aConstant['constant']) . ' = ' . $aConstant['id'] . ';';
        }

        $php .= '
        
    /**
     * Get all the constants in a array form
     * @return array
     * @author ' . __CLASS__ . '
     */
    public static function getArray(){
        return array(
            ';

        foreach ( $aConstants as $aConstant ) {
            $php .= '"' . $aConstant['constant'] . '" => array("id"=>"' . $aConstant['id'] . '", "label"=>"' . $aConstant['label'] . '", "constant"=>"' . $aConstant['constant'] . '"),';
        }

        $php .= '
        );
    }    
}';

        return $php;
    }

    /**
     * Generate the "public" entity class
     * The entity class is made to "emulate" a table in php
     * Each Entity object represente one row
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
        
namespace ' . $this->sDirBase . '\\' . $this->sDirEntity . ';

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

        $php = '<?php 
        
namespace ' . $this->sDirBase . '\\' . $this->sDirEntity . '\\' . $this->sDirPrivate . ';

class ' . $sClassName . ' {
            
    /**
     * @var bool $_isLoadedFromDb for intern usage : let know the class data comes from db or not 
     */
    protected $_isLoadedFromDb = false;
    
    /**
     * @var bool $_isModified for intern usage : let know the class if data changed from last save
     */
    protected $_isModified     = false;
';
        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $php .= $this->genAttribute($aCol);
        }
        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {
            $php .= $this->genForeignAttribute($sTable, $this->aCols[ $sTable ][ $sKeyName ]);
        }
        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $php .= $this->genGetter($aCol);
        }
        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {
            $php .= $this->genGetterForeignKey($sTable, $this->aCols[ $sTable ][ $sKeyName ]);
        }
        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $php .= $this->genSetter($aCol);
        }
        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {
            $php .= $this->genSetterForeignKey($sTable, $this->aCols[ $sTable ][ $sKeyName ]);
        }

        $php .= $this->genSave($sTable);

        $php .= '
        
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

    protected function formatPhpAttrName( $sName )
    {
        return $sName;
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
            case 'int':
                $sType = 'int';
                break;
            case 'float':
                $sType = 'float';
                break;
            case 'varchar':
            case 'text':
            case 'tinytext':
            case 'timestamp':
            case 'datetime':
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
                case 'timestamp':
                case 'datetime':
                    $nMaxlength = 19;
                    break;
                case 'tinytext':
                    $nMaxlength = 255;
                    break;
                case 'text':
                    $nMaxlength = 65000;
                    break;
            }
        }

        return $nMaxlength;
    }

    public function genForeignAttribute( $sTable, array $aCol )
    {

        $aFK = $this->aKeys[ $sTable ][ $aCol['Field'] ];

        $sObjAttrName = $this->formatPhpAttrName($aFK['REFERENCED_TABLE_NAME']);

        $sType = '\\' . $this->sDirBase . '\\' . $this->sDirEntity . '\\' . $this->formatPhpClassName($sObjAttrName);

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

    protected function formatPhpFuncName( $sName )
    {
        return str_replace('_', '', ucwords($sName, '_'));
    }

    public function genGetterForeignKey( $sTable, array $aCol )
    {

        $aFK = $this->aKeys[ $sTable ][ $aCol['Field'] ];

        $sObjFuncName = $this->formatPhpFuncName($aFK['REFERENCED_TABLE_NAME']);
        $sObjAttrName = $this->formatPhpAttrName($aFK['REFERENCED_TABLE_NAME']);

        $sType = '\\' . $this->sDirBase . '\\' . $this->sDirEntity . '\\' . $this->formatPhpClassName($sObjAttrName);

        $php = '
    
    /**
     * @return ' . $sType . '
     * @author ' . __CLASS__ . '
     */
    public function get' . $sObjFuncName . '()
    {
        return $this->' . $sObjAttrName . ';
    }';

        return $php;
    }

    protected function genSetter( array $aCol )
    {
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
     * @throw \InvalidArgumentException
     * @author ' . __CLASS__ . '
     */
    public function set' . $sFuncName . '( $' . $sAttrName . $sDefault . ' )
    {
        if( '.$sTest.' )
            throw new \InvalidArgumentException("Invalid parameter for ".__METHOD__." : (' . $sType . ') excepted ($' . $sAttrName . ') provided");
            
        $this->' . $sAttrName . ' = $' . $sAttrName . ';
        $this->_isModified = true;
        
        return $this;
    }';

        return $php;
    }

    public function genSetterForeignKey( $sTable, array $aCol )
    {

        $aFK = $this->aKeys[ $sTable ][ $aCol['Field'] ];

        $sObjFuncName = $this->formatPhpFuncName($aFK['REFERENCED_TABLE_NAME']);
        $sObjAttrName = $this->formatPhpAttrName($aFK['REFERENCED_TABLE_NAME']);

        $sType = '\\' . $this->sDirBase . '\\' . $this->sDirEntity . '\\' . $this->formatPhpClassName($sObjAttrName);

        $sAttrName = $this->formatPhpAttrName($aCol['Field']);
        $sReferencedAttr = $this->formatPhpFuncName($aFK['REFERENCED_COLUMN_NAME']);

        $php = '
        
    /**
     * @param ' . $sType . ' $' . $sObjAttrName . '
     * @author ' . __CLASS__ . '
     */
    public function set' . $sObjFuncName . '(' . $sType . ' $' . $sObjAttrName . ' )
    {
        $this->' . $sObjAttrName . ' = $' . $sObjAttrName . ';
        $this->' . $sAttrName . ' = $' . $sObjAttrName . '->get' . $sReferencedAttr . '();
        $this->_isModified = true;
        
        return $this;
    }';

        return $php;
    }

    protected function genSave( $sTable )
    {
        $aUpdateWhere = array();
        foreach ( $this->aPrimaryKeys[ $sTable ] as $aPrimaryKey ) {
            $sPrimaryName = $aPrimaryKey['COLUMN_NAME'];
            $sPrimaryPhpName = $this->formatPhpAttrName($sPrimaryName);
            $aUpdateWhere[] = $sPrimaryName . ' = ' . $this->sqlEscQuote . $this->sqlQuote . '.$this->' . $sPrimaryPhpName . '.' . $this->sqlQuote . $this->sqlEscQuote;
        }

        $php = '
        
    /**
     * Save the object into the database
     * @return bool false if an error occurred ; true otherwise
     * @author ' . __CLASS__ . '
     */
    public function save(){;

        $return = true;
        ';
        foreach ( $this->aForeignKeys[ $sTable ] as $sKeyName => $aKey ) {

            $aFK = $this->aKeys[ $sTable ][ $aKey['COLUMN_NAME'] ];

            $sObjAttrName = $this->formatPhpAttrName($aFK['REFERENCED_TABLE_NAME']);

            $php .= '
        $return = $return && $this->' . $sObjAttrName . '->save();';
        }

        $php .= '          
        if( $this->_isModified && $return ){ 
        ';

        foreach ( $this->aCols[ $sTable ] as $aCol ) {
            $sAttrName = $this->formatPhpAttrName($aCol['Field']);

            $sValue = '$this->' . $sAttrName;

            $php .= '
            \\' . $this->sDirBase . '\Orm::driver()->set('. $this->sqlQuote . $aCol['Field'] . $this->sqlQuote .', ' . $sValue . ');';
        }

         $php .= ' 
         
            if( !$this->_isLoadedFromDb ){ 
                $return = $return && \\' . $this->sDirBase . '\Orm::driver()->insert('. $this->sqlQuote . $sTable . $this->sqlQuote .'); 
                $this->' . $sPrimaryPhpName . ' = \\' . $this->sDirBase . '\Orm::driver()->insert_id();
                $this->_isLoadedFromDb = true;
            }
            else {
                \\' . $this->sDirBase . '\Orm::driver()->where('. $this->sqlQuote . implode(' AND ', $aUpdateWhere) . $this->sqlQuote .');
                $return = $return && \\' . $this->sDirBase . '\Orm::driver()->update('. $this->sqlQuote . $sTable . $this->sqlQuote .');
            }
            
            if( $return ){ 
                $this->_isModified = false;
            }
        }
        
        return $return;
    }';

        return $php;
    }

    protected function getPhpEscape( $var )
    {
        return $this->sqlQuote . '.\\' . $this->sDirBase . '\Orm::driver()->escape(' . $var . ').' . $this->sqlQuote;
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
        
namespace ' . $this->sDirBase . '\\' . $this->sDirQuery . ';

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
        
namespace ' . $this->sDirBase . '\\' . $this->sDirQuery . '\\' . $this->sDirPrivate . ';

class ' . $sClassName . ' {
    
    /**
     * Get an instance of this class to chain methods 
     *      without have to use "$var = new ' . $sClassName . '()
     *
     * @return \\' . $this->sDirBase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     *
     * @author '.__CLASS__.'
     */
    public static function create(){
        return new self();
    }
    
    /**
     * Add a limit to the nb result row for the next find() request
     
     * @param int $limit
     * @param int $start
     *
     * @return \\' . $this->sDirBase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     *
     * @author '.__CLASS__.'
     */
    public function limit( $limit, $start = 0 ){
        \\' . $this->sDirBase . '\Orm::driver()->limit( $limit, $start );
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
     * @return \\' . $this->sDirBase . '\\' . $this->sDirEntity . '\\'.$sClassName.'
     *
     * @author '.__CLASS__.'
     */
    public function findOne(){

        $this->limit(1);
        $aReturn = $this->find();
        
        return !empty($aReturn[0])? $aReturn[0] : null;
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
     * @return \\' . $this->sDirBase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     * @author '.__CLASS__.'
     */
    public function filterBy' . $sFuncName . '( $value, $operator = \Ormega\Orm::OPERATOR_EQUALS )
    {
        if( $operator == \Ormega\Orm::OPERATOR_IN || \Ormega\Orm::OPERATOR_NOTIN ){
            if( !is_array($value) ) {
                $value = explode('.$this->sqlQuote.','.$this->sqlQuote.', $value);
            }
        }
        
        switch( $operator ){
            case \Ormega\Orm::OPERATOR_IN:
                \\' . $this->sDirBase . '\Orm::driver()->where_in(' . $this->sqlQuote . $aCol['Field'] . $this->sqlQuote . ', $value);
                break;
            case \Ormega\Orm::OPERATOR_NOTIN:
                \\' . $this->sDirBase . '\Orm::driver()->where_not_in(' . $this->sqlQuote . $aCol['Field'] . $this->sqlQuote . ', $value);
                break;
            case \Ormega\Orm::OPERATOR_LIKE_PC:
                \\' . $this->sDirBase . '\Orm::driver()->like(' . $this->sqlQuote . $aCol['Field'] . $this->sqlQuote . ', $value, '.$this->sqlQuote.'after'.$this->sqlQuote.');
                break;
            case \Ormega\Orm::OPERATOR_PC_LIKE:
                \\' . $this->sDirBase . '\Orm::driver()->like(' . $this->sqlQuote . $aCol['Field'] . $this->sqlQuote . ', $value, '.$this->sqlQuote.'before'.$this->sqlQuote.');
                break;
            case \Ormega\Orm::OPERATOR_PC_LIKE_PC:
                \\' . $this->sDirBase . '\Orm::driver()->like(' . $this->sqlQuote . $aCol['Field'] . $this->sqlQuote . ', $value, '.$this->sqlQuote.'both'.$this->sqlQuote.');
                break;
            default:
                \\' . $this->sDirBase . '\Orm::driver()->where( ' . $this->sqlQuote . $aCol['Field'] . ' ' . $this->sqlQuote . '.$operator, $value );
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
     * @return \\' . $this->sDirBase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     * @author '.__CLASS__.'
     */
    public function groupBy' . $sFuncName . '()
    {
        \\' . $this->sDirBase . '\Orm::driver()->group_by('.$this->sqlQuote . $aCol['Field'] . $this->sqlQuote.');
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
     * @return \\' . $this->sDirBase . '\\' . $this->sDirQuery . '\\'.$sClassName.'
     * @author '.__CLASS__.'
     */
    public function orderBy' . $sFuncName . '( $order = \Ormega\Orm::ORDER_ASC )
    {
        \\' . $this->sDirBase . '\Orm::driver()->order_by('.$this->sqlQuote . $aCol['Field'] . $this->sqlQuote.', $order);
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
            $aColumns[] = $aCol['Field'];
        }

        $php = '
            
    /**
     * Start the select request composed with any filter, groupby,... set before
     * Return an array of 
     *      \\' . $this->sDirBase . '\\' . $this->sDirEntity . '\\'.$sClassName.' object 
     * @return array
     * @author '.__CLASS__.'
     */
    public function find(){

        $aReturn = array();

        $query = \\' . $this->sDirBase . '\Orm::driver()->select(' . implode(',', $aColumns) . ')->get(' . $sTable . ');
        
        foreach( $query->result() as $row ){
            
            $obj = new \\' . $this->sDirBase . '\\' . $this->sDirEntity . '\\'.$sClassName.'();
            ';

        foreach ( $this->aCols[ $sTable ] as $aCol ) {

            $sFuncName = $this->formatPhpFuncName($aCol['Field']);
            $sType = $this->getPhpType($aCol);

            if( $this->isNull($aCol) ){
                 $php .= '
                if( !is_null($row->' . $aCol['Field'] . ') ){
                    $obj->set' . $sFuncName . '((' . $sType . ') $row->' . $aCol['Field'] . ');
                }
                ';
            } else {
                $php .= '
                $obj->set' . $sFuncName . '((' . $sType . ') $row->' . $aCol['Field'] . ');
                ';
            }
        }

        $php .= '
            
            $aReturn[] = $obj;
        }

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
        $sRealSourceDir = realpath($sRealBasePath . '/' . $this->sDirBase);

        # ./Enum
        if ( !is_dir($sRealSourceDir . '/' . $this->sDirEnum) ) {
            mkdir($sRealSourceDir . '/' . $this->sDirEnum, 0775);
        }
        $sRealSourceEnumDir = realpath($sRealSourceDir . '/' . $this->sDirEnum);

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
            $this->output('Gen file "' . $fullFilePath . '" : KO ; File already exists ? ' . $sFileExists . ' ; Overwrite ? ' . $sErase);
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

    /* @TODO load in the Query generated class
     * protected function genLoad( $sTable ){
     * $sPKName = $this->formatPhpAttrName( $this->aKeys[ $sTable
     * ]['PRIMARY']['Column_name'] );
     * $php = '
     *
     * public function load(){
     *
     * if( !$this->_isLoadedFromDb && $this->'.$sPKName.' ){
     * $res = \Omega\Orm::driver()->query(
     * '.$this->sqlQuote.'SELECT * FROM '.$sTable.' WHERE '.$this->aKeys[
     * $sTable ]['PRIMARY']['Column_name'].' =
     * '.$this->getPhpEscape('$this->'.$sPKName, 'int').'
     * );
     * $this->_isLoadedFromDb = true;
     * }
     * }';
     *
     * return $php;
     * }
     */
}