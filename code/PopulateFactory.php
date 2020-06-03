<?php

namespace DNADesign\Populate;

use Exception;
use phpDocumentor\Reflection\DocBlock\Tags\Version;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Upload;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FixtureBlueprint;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * @package populate
 */
class PopulateFactory extends FixtureFactory
{

    /**
     * Creates the object in the database as the original object will be wiped.
     *
     * @param string $class
     * @param string $identifier
     * @param array $data
     * @return DataObject|Versioned|null
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function createObject($class, $identifier, $data = null)
    {
        DB::alteration_message("Creating $identifier ($class)", "created");

        if ($data) {
            foreach ($data as $k => $v) {
                if (!(is_array($v)) && preg_match('/^`(.)*`;$/', $v)) {
                    $str = substr($v, 1, -2);
                    $pv = null;

                    eval("\$pv = $str;");

                    $data[$k] = $pv;
                }
            }
        }

        // for files copy the source dir if the image has a 'PopulateFileFrom'
        // Follows silverstripe/asset-admin logic, see AssetAdmin::apiCreateFile()
        if (isset($data['PopulateFileFrom'])) {
            if (!isset($data['Filename'])) {
                throw new \Exception('When passing "PopulateFileFrom", you must also pass "Filename" with the path that you want to file to be stored at (e.g. assets/test.jpg)');
            }

            $fixtureFilePath = BASE_PATH . '/' . $data['PopulateFileFrom'];
            $upload = Upload::create();
            $upload->setReplaceFile(true);

            $folder = Folder::find_or_make(
                str_replace('assets/', '', dirname($data['Filename']))
            );

            $info = new \finfo(FILEINFO_MIME_TYPE);

            $tmpFile = [
                'name' => isset($data['Name']) ? $data['Name'] : basename($data['Filename']),
                'type' => $info->file($fixtureFilePath),
                'tmp_name' => $fixtureFilePath,
                'error' => 0,
                'size' => filesize($fixtureFilePath)
            ];

            // Disable is_uploaded_file() check in Upload_Validator
            $oldUseIsUploadedFile = Upload_Validator::config()->get('use_is_uploaded_file');
            Upload_Validator::config()->set('use_is_uploaded_file', false);
            if (!$upload->validate($tmpFile)) {
                $errors = $upload->getErrors();
                $message = array_shift($errors);
                throw new Exception(sprintf('Error while populating from file %s: %s', $data['Filename'], $message));
            }

            $fileClass = File::get_class_for_file_extension(File::get_file_extension($tmpFile['name']));
            /** @var File $file */
            $file = Injector::inst()->create($fileClass);

            $uploadResult = $upload->loadIntoFile($tmpFile, $file, $folder->getFilename());
            if (!$uploadResult) {
                throw new Exception(sprintf('Failed to load file %s', $data['Filename']));
            }
            Upload_Validator::config()->set('use_is_uploaded_file', $oldUseIsUploadedFile);

            $file->ParentID = $folder->ID;
            $f = $file->toMap();

            if ($file->exists()) {
                $data['FileHash'] = $f['File']->Hash;
                $data['FileFilename'] = $f['File']->Filename;
                $data['ParentID'] = $f['File']->ParentID;
            }

        }

        // if any merge labels are defined then we should create the object
        // from that
        $lookup = null;
        $mode = null;

        if (isset($data['PopulateMergeWhen'])) {
            $mode = 'PopulateMergeWhen';

            $lookup = DataList::create($class)->where(
                $data['PopulateMergeWhen']
            );

            unset($data['PopulateMergeWhen']);

        } else {
            if (isset($data['PopulateMergeMatch'])) {
                $mode = 'PopulateMergeMatch';
                $filter = array();

                foreach ($data['PopulateMergeMatch'] as $field) {
                    $filter[$field] = $data[$field];
                }

                if (!$filter) {
                    throw new \Exception('Not a valid PopulateMergeMatch filter');
                }

                $lookup = DataList::create($class)->filter($filter);

                unset($data['PopulateMergeMatch']);
            } else {
                if (isset($data['PopulateMergeAny'])) {
                    $mode = 'PopulateMergeAny';
                    $lookup = DataList::create($class);

                    unset($data['PopulateMergeAny']);
                }
            }
        }

        if ($lookup && $lookup->count() > 0) {
            $existing = $lookup->first();

            foreach ($lookup as $old) {
                if ($old->ID == $existing->ID) {
                    continue;
                }

                if ($old->hasExtension(Versioned::class)) {
                    foreach ($old->getVersionedStages() as $stage) {
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
        } else {
            $obj = parent::createObject($class, $identifier, $data);
        }

        if ($obj->hasExtension(Versioned::class)) {
            /** @var DataObject|Versioned $obj */
            $obj->write();

            if (Populate::config()->get('enable_publish_recursive')) {
                $obj->publishRecursive();
            } else {
                $obj->publishSingle();
            }

            $obj->flushCache();
        }

        return $obj;
    }
}
