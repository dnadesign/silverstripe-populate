<?php

namespace DNADesign\Populate;

use Exception;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;

/**
 * @package populate
 */
class PopulateTask extends BuildTask
{
    /**
     * @var string
     */
    private static $segment = 'populate-task';

    /**
     * @param HTTPRequest $request
     * @throws Exception
     */
	public function run($request) {
		Populate::requireRecords();
	}
}
