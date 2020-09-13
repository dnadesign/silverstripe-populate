<?php

namespace DNADesign\Populate;

use Exception;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * @package populate
 */
class Populate
{
    use Configurable;
    use Extensible;

    /**
     * @config
     *
     * @var array
     */
    private static $include_yaml_fixtures = [];

    /**
     * @config
     *
     * An array of classes to clear from the database before importing. While
     * populating SiteTree it may be worth clearing the 'SiteTree' table.
     *
     * @var array
     */
    private static $truncate_classes = [];

    /**
     * @config
     *
     * @var array - Tables that will be truncated
     */
    private static $truncate_tables = [];

    /**
     * Flag to determine if we're already run for this session (i.e to prevent
     * parent calls invoking {@link requireRecords} twice).
     *
     * @var bool
     */
    private static $ran = false;

    /**
     * @var string[] - Used internally to not truncate multiple tables multiple times
     */
    private static $clearedTables = [];

    /**
     * @param bool $force - allows you to bypass the ran check to run this multiple times
     * @return bool
     * @throws Exception
     */
    public static function requireRecords($force = false): bool
    {
        if (self::$ran && !$force) {
            return true;
        }

        self::$ran = true;

        if (!(Director::isDev() || Director::isTest())) {
            throw new Exception('requireRecords can only be run in development or test environments');
        }

        $factory = Injector::inst()->create('DNADesign\Populate\PopulateFactory');

        foreach (self::config()->get('truncate_objects') as $className) {
            self::truncateObject($className);
        }

        foreach (self::config()->get('truncate_tables') as $table) {
            self::truncateTable($table);
        }

        foreach (self::config()->get('include_yaml_fixtures') as $fixtureFile) {
            $fixture = new YamlFixture($fixtureFile);
            $fixture->writeInto($factory);

            $fixture = null;
        }

        $populate = Injector::inst()->create(Populate::class);
        $populate->extend('onAfterPopulateRecords');

        return true;
    }

    /**
     * Delete all the associated tables for a class
     *
     * @param string $className
     */
    private static function truncateObject(string $className): void
    {
        $tables = [];

        // All acenstors or children with tables
        $withTables = array_filter(
            array_merge(
                ClassInfo::ancestry($className),
                ClassInfo::subclassesFor($className)
            ),
            function ($next) {
                return DataObject::getSchema()->classHasTable($next);
            }
        );

        $classTables = [];

        foreach ($withTables as $className) {
            $classTables[$className] = DataObject::getSchema()->tableName($className);
        }

        // Establish tables which store object data that needs to be truncated
        foreach ($classTables as $className => $baseTable) {
            /** @var DataObject|SilverStripe\Versioned\Versioned $obj */
            $obj = Injector::inst()->get($className);

            // Include base tables
            $tables[$baseTable] = $baseTable;

            if (!$obj->hasExtension('SilverStripe\Versioned\Versioned')) {
                // No versioned tables to clear
                continue;
            }

            $stages = $obj->getVersionedStages();

            foreach ($stages as $stage) {
                $table = $obj->stageTable($baseTable, $stage);

                // Include staged table(s)
                $tables[$table] = $table;
            }

            $versionedTable = "{$baseTable}_Versions";

            // Include versions table
            $tables[$versionedTable] = $versionedTable;
        }

        $populate = Injector::inst()->create(Populate::class);
        $populate->extend('updateTruncateObjectTables', $tables, $className, $classTables);

        foreach ($tables as $table) {
            if (!DB::get_schema()->hasTable($table)) {
                // No table to clear
                continue;
            }

            self::truncateTable($table);
        }
    }

    /**
     * Attempts to truncate a table. Outputs messages to indicate if table has
     * already been truncated or cannot be truncated
     *
     * @param string $table
     */
    private static function truncateTable(string $table): void
    {
        if (array_key_exists($table, self::$clearedTables)) {
            DB::alteration_message("$table already truncated", "deleted");

            return;
        }

        DB::alteration_message("Truncating table $table", "deleted");

        try {
            DB::get_conn()->clearTable($table);
        } catch (DatabaseException $databaseException) {
            DB::alteration_message("Couldn't truncate table $table as it doesn't exist", "deleted");
        }

        self::$clearedTables[$table] = true;
    }
}
