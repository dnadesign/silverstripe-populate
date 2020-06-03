<?php

namespace DNADesign\Populate\Tests;

use DNADesign\Populate\PopulateFactory;
use DNADesign\Populate\Tests\PopulateFactoryTest\PopulateFactoryTestObject;
use DNADesign\Populate\Tests\PopulateFactoryTest\PopulateFactoryTestVersionedObject;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Versioned\Versioned;

/**
 * @package populate
 */
class PopulateFactoryTest extends SapphireTest implements TestOnly
{

    /**
     * @var PopulateFactory
     */
    private $factory;

    protected static $fixture_file = "PopulateFactoryTest.yml";

    protected $usesDatabase = true;

    protected static $extra_dataobjects = array(
        PopulateFactoryTestObject::class,
        PopulateFactoryTestVersionedObject::class
    );

    public function setUp()
    {
        parent::setUp();
        $this->factory = new PopulateFactory();
    }

    /**
     * Test version support. If an object has versioned then both the live and
     * staging tables should be updated. Other live records should be removed
     * as well.
     */
    public function testVersionedObjects()
    {
        $versioned = $this->objFromFixture(PopulateFactoryTestVersionedObject::class, 'objV1');

        $versioned->publish('Stage', 'Live');

        $obj = $this->factory->createObject(PopulateFactoryTestVersionedObject::class, 'test', array(
            'Content' => 'Updated Version Foo',
            'PopulateMergeWhen' => "Title = 'Version Foo'"
        ));

        $this->assertEquals($versioned->ID, $obj->ID);
        $this->assertEquals('Updated Version Foo', $obj->Content);

        $check = Versioned::get_one_by_stage(
            PopulateFactoryTestVersionedObject::class,
            'Live',
            "Title = 'Version Foo'"
        );

        $this->assertEquals('Updated Version Foo', $check->Content);
    }

    /**
     * As a utility you can include code to be evaluated the the yaml using
     * Field: `something::foo()`;
     */
    public function testCreateObjectPhpEval()
    {
        $obj = $this->factory->createObject(PopulateFactoryTestObject::class, 'test', array(
            'Title' => '`sprintf("hi")`;'
        ));

        $this->assertEquals('hi', $obj->Title);
    }

    /**
     * When a populatemergewhen clause is supplied, make sure it merges. If no
     * record found, one should be created
     *
     */
    public function testCreateObjectPopulateMergeWhen()
    {
        $obj = $this->factory->createObject(PopulateFactoryTestObject::class, 'test', array(
            'Title' => 'Updated',
            'PopulateMergeWhen' => "Title = 'Foo'"
        ));

        $this->assertEquals('Foo Content', $obj->Content, 'Records merged');

        $obj = $this->factory->createObject(PopulateFactoryTestObject::class, 'test', array(
            'Title' => 'Updated',
            'PopulateMergeWhen' => "Title = 'This title is unknown'"
        ));

        $this->assertGreaterThan(0, $obj->ID);
        $this->assertEquals('Updated', $obj->Title);
    }

    /**
     * When populatemergematch is provided then the matching should be done on
     * the given fields
     */
    public function testCreateObjectPopulateMergeMatch()
    {
        $id = PopulateFactoryTestObject::get()->filter(array(
            'Title' => 'Foo'
        ))->first()->ID;

        $obj = $this->factory->createObject(PopulateFactoryTestObject::class, 'test', array(
            'Title' => 'Foo',
            'Content' => 'This has been replaced',
            'PopulateMergeMatch' => array(
                'Title'
            )
        ));

        $this->assertEquals($id, $obj->ID, 'ID value has not changed');
        $this->assertEquals('This has been replaced', $obj->Content);
    }

    /**
     * When a lookup matches more than one page, only the first one should be
     * removed.
     */
    public function testMultipleMatchesRemoved()
    {
        $this->factory->createObject(PopulateFactoryTestObject::class, 'test', array(
            'Title' => 'Updated',
            'PopulateMergeAny' => true
        ));

        $list = PopulateFactoryTestObject::get();

        $this->assertEquals(1, $list->count());
        $this->assertEquals('Updated', $list->first()->Title);
    }
}
