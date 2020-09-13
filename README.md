# Populate Module

[![Build Status](https://secure.travis-ci.org/dnadesign/silverstripe-populate.png?branch=master)](http://travis-ci.org/dnadesign/silverstripe-populate)

This module provides a way to populate a database from YAML fixtures and custom
classes. For instance, when a building a web application the pages and default
objects can be defined in YAML and shared around developers. This extends the
`requireDefaultRecords` concept in SilverStripe's DataModel.

## Maintainer Contact

 * Will Rossiter (wilr, will.rossiter@dna.co.nz)

## Requirements

 * SilverStripe 4 ([framework](https://github.com/silverstripe/silverstripe-framework) only)

## Installation Instructions

This module is only ever used during the development phase of the project and has specific code to prevent it from being run in production. Therefore we recommend install it as a dev dependency in composer like so:
```
composer require dnadesign/silverstripe-populate --dev
```

## Setup

First create a new `yml` config file in your config directory `config/populate.yml` (you can use the default `_config.yml` if wanted).

In that file you can now link to your fixture files
```yaml
  DNADesign\Populate\Populate:
    include_yaml_fixtures:
      - 'mysite/fixtures/populate.yml'
```

*If you're sharing test setup with populate, you can specify any number of paths to load fixtures from.*

An example populate.yml might look like the following:
```yaml
Page:
  home:
    Title: "Home"
    Content: "My Home Page"
    ParentID: 0
SilverStripe\Security\Member:
  admin:
    ID: 1
    Email: "admin@example.com"
      PopulateMergeMatch:
        - 'ID'
        - 'Email'
```

Out of the box, the records will be created on when you run the `PopulateTask`
through `/dev/tasks/populate-task/`. To make it completely transparent to
developers during the application build, you can also include this to hook in on `requireDefaultRecords` as part of `dev/build` by including the following in one of your application models `requireDefaultRecords` methods:
```php
use DNADesign\Populate\Populate;

class Page extends SiteTree
{
    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();
        Populate::requireRecords();
    }
}
```
## Configuration options

`include_yaml_fixtures`: An array of YAML files to parse.

Example:
```yaml
DNADesign\Populate\Populate:
  include_yaml_fixtures:
    - 'app/fixtures/populate.yml'
```

`truncate_objects`:  An array of ClassName's whose instances are to be removed from the database prior to importing. Useful to prevent multiple copies of populated content from being imported. You should truncate any objects you create. (Supports Fleunt and Versioned objects)

Example:
```yaml
DNADesign\Populate\Populate:
  truncate_objects:
    - SilverStripe\Assets\Image
```

`truncate_tables`: An array of tables to be truncated. Useful when there's no relation between your populated classes and the table you want truncated

Example:
```yaml
DNADesign\Populate\Populate:
  truncate_tables:
    - Image_Special_Table
```

See *Updating Records* if you wish to merge new and old records rather than
clearing all of them.

## YAML Format

Populate uses the same `FixtureFactory` setup as SilverStripe's unit testing
framework. The basic structure of which is:
```yaml
ClassName:
  somereference:
    FieldName: "Value"
```

Relations are handled by referring to them by their reference value:
```yaml
SilverStripe\Security\Member:
    admin:
      Email: "admin@example.com"

Page:
  homepage:
    AuthorID: =>SilverStripe\Security\Member.admin
```

See [SilverStripe's fixture documentation](https://docs.silverstripe.org/en/4/developer_guides/testing/fixtures/) for more advanced examples, including `$many_many` and `$many_many_extraFields`.

Any object which implements the `Versioned` extension will be automatically
published.

Basic PHP operations can also be included in the YAML file. Any line that is
wrapped in a ` character and ends with a semi colon will be evaled in the
current scope of the importer.
```yaml
	Page:
	  mythankyoupage:
	    ThankYouText: `Page::config()->thank_you_text`;
	    LinkedPage: `sprintf("[Page](%s)", HelpPage::get()->first()->Link())`;
```

### Updating Records

If you do not truncate the entire table, the module will attempt to first look
up an existing record and update that existing record. For this to happen the
YAML must declare the fields to match in the look up. You can use several
options for this.

#### `PopulateMergeWhen`

Contains a WHERE clause to match e.g `"URLSegment = 'home' AND ParentID = 0"`.

	Mysite\PageTypes\HomePage:
	  home:
	    Title: "My awesome homepage"
	    PopulateMergeWhen: "URLSegment = 'home' AND ParentID = 0"

### `PopulateMergeMatch`

Takes a list of fields defined in the YAML and matches them based on the
database to avoid repeating content

	Mysite\PageTypes\HomePage:
	  home:
	  	Title: "My awesome homepage"
	  	URLSegment: 'home'
	  	ParentID: 0
	  	PopulateMergeMatch:
	  	  - URLSegment
	  	  - ParentID

### `PopulateMergeAny`

Takes the first record in the database and merges with that. This option is
suitable for things like `SiteConfig` where you normally only contain a single
record.

	SilverStripe\SiteConfig\SiteConfig:
	  mysiteconfig:
	  	Tagline: "SilverStripe is awesome"
	  	PopulateMergeAny: true

If the criteria meets more than 1 instance, all instances bar the first are
removed from the database so ensure you criteria is specific enough to get the
unique field value.

### Default Assets

The script also handles creating default File and image records through the
`PopulateFileFrom` flag. This copies the file from another path (say mysite) and
puts the file inside your assets folder.

```yml
SilverStripe\Assets\Image:
  lgoptimusl3ii:
    Filename: assets/shop/lgoptimusl3ii.png
    PopulateFileFrom: app/images/demo/large.png

Mysite\PageTypes\Product:
  lgoptimus:
    ProductImage: =>SilverStripe\Assets\Image.lgoptimusl3ii
```

## Extensions

The module also provides extensions that can be opted into depending on your
project needs

### PopulateMySQLExport

This extension outputs the result of the Populate::requireDefaultRecords() as a
SQL Dump on your local machine. This speeds up the process if using Populate as
part of a test suite or some other CI service as instead of manually calling
the task (which will use the ORM) your test case can be fed raw MySQL to import
and hopefully speed up execution times.

To apply the extension add it to Populate, configure the path, flush, then run
`dev/tasks/PopulateTask`

```yaml
DNADesign\Populate\PopulateMySQLExportExtension:
  export_db_path: ~/path.sql

DNADesign\Populate\Populate:
  extensions
    - DNADesign\Populate\PopulateMySQLExportExtension
```
