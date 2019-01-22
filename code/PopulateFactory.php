<?php

namespace DNADesign\Populate;

use function basename;
use function dirname;
use Exception;
use function file_get_contents;
use function hash_equals;
use InvalidArgumentException;
use phpDocumentor\Reflection\DocBlock\Tags\Version;
use function sha1;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Upload;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FixtureBlueprint;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use function sizeof;
use function str_replace;

/**
 * @package populate
 */
class PopulateFactory extends FixtureFactory {
	/**
	 * List of fixtures that failed to be created due to YAML fixture lookup failures (e.g. because of a dependency that
	 * isn't met at the time of creation). We re-try creation of these after all other fixtures have been created.
	 *
	 * @var array
	 */
	private $failedFixtures = [];

	/**
	 * Creates the object in the database as the original object will be wiped.
	 *
	 * @param string $class
	 * @param string $identifier
	 * @param array $data
	 */
	public function createObject($class, $identifier, $data = null) {
		DB::alteration_message("Creating $identifier ($class)", "created");

		if($data) {
			foreach($data as $k => $v) {
				if(!(is_array($v)) && preg_match('/^`(.)*`;$/', $v)) {
					$str = substr($v, 1, -2);
					$pv = null;

					eval("\$pv = $str;");

					$data[$k] =	$pv;
				}
			}
		}

		// for files copy the source dir if the image has a 'PopulateFileFrom'
        // Follows silverstripe/asset-admin logic, see AssetAdmin::apiCreateFile()
		if(isset($data['PopulateFileFrom'])) {
		    $file = $this->populateFile($data);

		    if ($file) {
		        // Skip the rest of this method (populateFile sets all other values on the object), just return the created file
                if (!isset($this->fixtures[$class])) {
                    $this->fixtures[$class] = [];
                }

                $this->fixtures[$class][$identifier] = $file->ID;
                return $file;
            }
		}

		// if any merge labels are defined then we should create the object
		// from that
		$lookup = null;
		$mode = null;

		if(isset($data['PopulateMergeWhen'])) {
			$mode = 'PopulateMergeWhen';

			$lookup = DataList::create($class)->where(
				$data['PopulateMergeWhen']
			);

			unset($data['PopulateMergeWhen']);

		} else if(isset($data['PopulateMergeMatch'])) {
			$mode = 'PopulateMergeMatch';
			$filter = array();

			foreach($data['PopulateMergeMatch'] as $field) {
				$filter[$field] = $data[$field];
			}

			if(!$filter) {
				throw new \Exception('Not a valid PopulateMergeMatch filter');
			}

			$lookup = DataList::create($class)->filter($filter);

			unset($data['PopulateMergeMatch']);
		} else if(isset($data['PopulateMergeAny'])) {
			$mode = 'PopulateMergeAny';
			$lookup = DataList::create($class);

			unset($data['PopulateMergeAny']);
		}

		if($lookup && $lookup->count() > 0) {
			$existing = $lookup->first();

			foreach($lookup as $old) {
				if($old->ID == $existing->ID) {
					continue;
				}

				if($old->hasExtension(Versioned::class)) {
					foreach($old->getVersionedStages() as $stage) {
						$old->deleteFromStage($stage);
					}
				}

				$old->delete();
			}

			$blueprint = new FixtureBlueprint($class);
			$obj = $blueprint->createObject($identifier, $data, $this->fixtures);
			$latest = $obj->toMap();

			unset($latest['ID']);

			$existing->update($latest);
			$existing->write();

			$obj->delete();

			$this->fixtures[$class][$identifier] = $existing->ID;

			$obj = $existing;
			$obj->flushCache();
		}
		else {
			try {
				$obj = parent::createObject($class, $identifier, $data);
			} catch (InvalidArgumentException $e) {
				$this->failedFixtures[] = [
					'class' => $class,
					'id' => $identifier,
					'data' => $data
				];

				DB::alteration_message(sprintf('Exception: %s', $e->getMessage()), 'error');

				DB::alteration_message(
					sprintf('Failed to create %s (%s), queueing for later', $identifier, $class),
					'error'
				);

				return null;
			}
		}

		if($obj->hasExtension(Versioned::class)) {
			foreach($obj->getVersionedStages() as $stage) {
				if($stage !== Versioned::DRAFT) {

					$obj->writeToStage(Versioned::DRAFT);
					$obj->publish(Versioned::DRAFT, $stage);
				}
			}

			$obj->flushCache();
		}

		return $obj;
	}

    /**
     * @param bool $recurse Marker for whether we are recursing - should be false when calling from outside this method
     * @throws Exception
     */
	public function processFailedFixtures($recurse = false)
	{
		if ($this->failedFixtures) {
            $failed = $this->failedFixtures;
		    $numFailing = sizeof($failed);

		    // Reset $this->failedFixtures so that continual failures can be re-attempted
		    $this->failedFixtures = [];

			foreach ($failed as $fixture) {
				// createObject returns null if the object failed to create
                // This also re-populates $this->failedFixtures so we can re-compare
				$obj = $this->createObject($fixture['class'], $fixture['id'], $fixture['data']);

				if (is_null($obj)) {
					DB::alteration_message(
						sprintf('Further attempt to create %s (%s) still failed', $fixture['id'], $fixture['class']),
						'error'
					);
				}
			}

			if (sizeof($this->failedFixtures) > 0 && sizeof($failed) > sizeof($this->failedFixtures)) {
			    // We made some progress because there are less failed fixtures now than there were before, so run again
                $this->processFailedFixtures(true);
            }

            // Our final run gets here - either we made no progress on object creation, or there were some fixtures with
            // broken or circular relations that can't be resolved - list these at the end.
            if (!$recurse && sizeof($this->failedFixtures) > 0) {
			    $message = sprintf("Some fixtures (%d) couldn't be created:", sizeof($this->failedFixtures));
			    DB::alteration_message("");
                DB::alteration_message("");
                DB::alteration_message($message, "error");

                foreach ($this->failedFixtures as $fixture) {
                    DB::alteration_message(sprintf('%s (%s)', $fixture['id'], $fixture['class']));
                }
            }
		}
	}

    /**
     * @param array $data
     * @return File The created (or updated) File object
     * @throws Exception If anything is missing and the file can't be processed
     */
	private function populateFile($data)
    {
        if(!isset($data['Filename']) || !isset($data['PopulateFileFrom'])) {
            throw new Exception('When passing "PopulateFileFrom", you must also pass "Filename" with the path that you want to file to be stored at (e.g. assets/test.jpg)');
        }

        $fixtureFilePath = BASE_PATH . '/'. $data['PopulateFileFrom'];
        $filenameWithoutAssets = str_replace('assets/', '', $data['Filename']);

        // Find the existing object (if one exists)
        /** @var File $existingObj */
        $existingObj = File::find($filenameWithoutAssets);
        if ($existingObj && $existingObj->exists()) {
            $file = $existingObj;

            // If the file hashes match, and the file already exists, we don't need to update anything.
            $hash = $existingObj->File->getHash();
            if (hash_equals($hash, sha1(file_get_contents($fixtureFilePath)))) {
                return true;
            }
        } else {
            $file = Image::create();
        }

        $folder = Folder::find_or_make(dirname($filenameWithoutAssets));
        $filename = basename($filenameWithoutAssets);

        // We could just use $data['Filename'], but we need to allow for filsystem abstraction
        $filePath = File::join_paths($folder->getFilename(), $filename);

        $fileCfg = [
            // if there's a filename conflict we've got new content so overwrite it.
            'conflict' => AssetStore::CONFLICT_OVERWRITE,
            'visibility' => AssetStore::VISIBILITY_PUBLIC
        ];

        // Set any other attributes that the file may need (e.g. Title)
        foreach($data as $k => $v) {
            if (in_array($k, [ 'PopulateFileFrom', 'Filename' ])) {
                continue;
            }

            $file->$k = $v;
        }

        $file->ParentID = $folder->ID;
        $file->setFromString(file_get_contents($fixtureFilePath), $filePath, null, null, $fileCfg);
        $file->write();
        $file->publishRecursive();
        return $file;
    }
}
