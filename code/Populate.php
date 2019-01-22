<?php

namespace DNADesign\Populate;

use SilverStripe\Assets\File;
use SilverStripe\Control\Director;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObjectSchema;
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
	private static $include_yaml_fixtures = array();

	/**
	 * @config
	 *
	 * An array of classes to clear from the database before importing. While
	 * populating sitetree it may be worth clearing the 'SiteTree' table.
	 *
	 * @var array
	 */
	private static $truncate_objects = array();

	/**
	 * Flag to determine if we're already run for this session (i.e to prevent
	 * parent calls invoking {@link requireRecords} twice).
	 *
	 * @var bool
	 */
	private static $ran = false;

	/**
	 * @var bool
	 *
	 * @throws Exception
	 */
	public static function requireRecords($force = false) {
		if(self::$ran && !$force) {
			return true;
		}

		self::$ran = true;

		if(!(Director::isDev() || Director::isTest())) {
			throw new \Exception('requireRecords can only be run in development or test environments');
		}

		/** @var PopulateFactory $factory */
		$factory = Injector::inst()->create(PopulateFactory::class);

		foreach(self::config()->get('truncate_objects') as $objName) {
			$versions = array();

			if(class_exists($objName)) {
				if (in_array($objName, ClassInfo::subclassesFor(File::class))) {
                    foreach(DataList::create($objName) as $obj) {
                        /** @var File $obj */
                        $obj->deleteFile();
                    }
                }

                // Get one of the objects, check for the versioned extensions and get all stages to truncate
                $obj = new $objName();

                // if the object has the versioned extension, make sure we delete
                // that as well
                if($obj->hasExtension('SilverStripe\Versioned\Versioned')) {
                    foreach($obj->getVersionedStages() as $stage) {
                        $versions[$stage] = true;
                    }
                }
			}

			if($versions) {
				self::truncate_versions($objName, $versions);
			}

			foreach((array)ClassInfo::dataClassesFor($objName) as $table) {
				self::truncate_table($table);
				self::truncate_versions($table, $versions);
			}

			self::truncate_table($objName);
		}

		foreach(self::config()->get('include_yaml_fixtures') as $fixtureFile) {
			DB::alteration_message(sprintf('Processing %s', $fixtureFile), 'created');
			$fixture = new YamlFixture($fixtureFile);
			$fixture->writeInto($factory);

			$fixture = null;
		}

		// hook allowing extensions to clean up records, modify the result or
		// export the data to a SQL file (for importing performance).
		$static = !(isset($this) && get_class($this) == __CLASS__);

		if($static) {
			$populate = Injector::inst()->create(Populate::class);
		} else {
			$populate = $this;
		}

		DB::alteration_message("");
        DB::alteration_message("");
		DB::alteration_message("Processing failed fixtures", "created");
		$factory->processFailedFixtures();

		$populate->extend('onAfterPopulateRecords');

		return true;
	}

	private static function truncate_table($class) {
        /** @var DataObjectSchema $schema */
	    $schema = Injector::inst()->get(DataObjectSchema::class);
        $split = explode('_', $class);
        $table = $schema->tableName($split[0]);

        if(!empty($split[1])) $table = sprintf('%s_%s', $table, $split[1]); // Re-add '_versions' etc.

	    DB::alteration_message("Truncating table $table", "deleted");

		if(ClassInfo::hasTable($table)) {
			if(method_exists(DB::get_conn(), 'clearTable')) {
				DB::get_conn()->clearTable($table);
			} else {
				DB::query("TRUNCATE \"$table\"");
			}
		}
	}

	private static function truncate_versions($table, $versions) {
		self::truncate_table($table .'_Versions');

		foreach($versions as $stage => $v) {
			self::truncate_table($table . '_'. $stage);
		}
	}
}
