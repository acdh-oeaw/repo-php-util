# repo-php-utils

Set of classes for working with the ACDH repository stack.

# Installation

* obtain composer (https://getcomposer.org/)
* prepare `composer.json` file containing:
  ```
  {
    "require": {
        "acdh-oeaw/repo-php-util": "*"
    }
  }
  ```
* Run `composer install`
* Copy and adjust files:
    * `vendor/acdh-oeaw/repo-php-util/config.ini.sample` (service URLs, credentials, metadata schema fundamentals)
    * `vendor/acdh-oeaw/repo-php-util/property_mappings.json` (Redmine issues property mappings)

# Initialization

* Load composer
* Read config from `config.ini`
* Create an object of `acdhOeaw\fedora\Fedora` class
* If you want to use the Indexer and/or the Redmine classes, call their static initialize methods

```php
require_once 'vendor/autoload.php';

$config = new zozlak\util\Config('config.ini');

$fedora = new acdhOeaw\fedora\Fedora($conf);
acdhOeaw\redmine\Redmine::init($config, $fedora);
acdhOeaw\storage\Indexer::init($config);
```

# Usage

(it is assumed that you already run the initialization code, especially that the `$fedora` object is created)

## Working with Fedora resources

A Fedora resource is represented by the `achdOeaw\fedora\FedoraResource` class.

This class provides you basic methods to manipulate both resource's metadata and binary content (see examples below).

In general you should not create `FedoraResource` objects directly but always use proper `Fedora` class method (see examples below).  
If you want to know more, please read the `Fedora` class documentation, especially parts on transactions handling.

The metadata are represented by the [EasyRdf Resource](http://www.easyrdf.org/docs/api/EasyRdf_Resource.html) object.

**Updating metadata in RDF can be tricky**, so please read examples on this topic provided below.

**All resource modifications must be done within a Fedora transaction** so all the `$fedora->begin()` and `$fedora->commit()` in the code examples are really needed.

### Creating a new Fedora resource

Prepare resource metadata and (optionally) its binary content and call the `createResource()` method of the `Fedora` class.

```php
$graph = new EasyRdf_Graph();
$metadata = $graph->resource('.'); // the resource URI you provide here is irrelevant and can be any string, just it can not be empty; it is an EasyRdf library limitation
$metadata->addLiteral('http://my.data/#property', 'myDataPropertyValue');
$metadata->addResource('http://my.object/#property', 'http://my.Object/Property/Value');

$fedora->begin();
$resource1 = $fedora->createResource($metadata, 'pathToFile'); // with binary data from file
$resource1 = $fedora->createResource($metadata, 'myResourceData (...)'); // with binary data from string
$resource2 = $fedora->createResource($metadata); // without binary data
$fedora->commit();
```

### Finding already existing Fedora resources

If you know the resource ACDH ID you can use the `getResourceById()` method.

```php
$resource = $fedora->getResourceById('https://id.acdh.oeaw.ac.at/ba83b0d6-86cd-4340-bfd7-ab5a2edb345a');
echo $resource->__getSparqlTriples();
```

If you know resource's metadata property value, you can search for all resources having such a value with the `getResourcesByProperty()` method.

```php
$resources = $fedora->getResourceByProperty('http://www.w3.org/2000/01/rdf-schema#seeAlso', 'https://redmine.acdh.oeaw.ac.at/issues/5488');
echo count($resources);
echo $resources[0]->__getSparqlTriples();
```

Of course if you know the resource's Fedora URI, you can use it as well (with the `getResourceByUri()` method).

```php
$resource = $fedora->getResourceByUri('http://fedora.apollo.arz.oeaw.ac.at/rest/92/35/a8/40/9235a840-5f0e-4f24-971d-c0c557f43d9e');
echo $resource->__getSparqlTriples();
```

### Updating resource metadata

**Updating RDF metadata is a little tricky.**
The main problem is that an update of a metadata property value is not well defined therefore can not be done automatically for you.

Lets assume we have an existing metadata triple `<ourResource> <ourProperty> "currentValue"` and a new triple `<ourResource> <ourProperty> "currentValue"`.  
There is no way to outomatically decide if the new triple should replace the old one or be added next to it.  
This is because RDF triples are uniquely identified by all their components (subject, property and object) and change in any of components (also in the object) 
alters this unique identifier and makes it unable to match it with a previous value of a triple.

This means the only way to avoid triples multiplication is to always delete all previous metadata and add all current values.  
It is automatically done by the library but it means you must always provide a full metadata set when calling the `setMetadata()` method 
if you do not want to loose any metadata triples.

**Remember:**

* Always take current resource metadata as a basis. 
    * The only exception might be if you are sure the new triples do not exist in the current metadata and do not interfere in any way with current metadata.  
      In such a case remember to use `updateMetadata('ADD')`.  
* Remember to delete all metadata values before adding current ones (remember, there is no update, just delete and add).
    * If a property can have multiple values, assure you are deleting it only once (do not repeat deletion for the every new value you encounter).
* Think twice when dealing with `rdfs:identifier` and `rdf:isPartOf` properties (these two are very important).

Due to a bug in the EasyRdf library use `achdOeaw\util\EasyRdfUtil::fixPropName()` to pass property URI to EasyRdf's library methods.

**Good example.**

```php
$myProperty = \achdOeaw\util\EasyRdfUtil::fixPropName('http://my.new/#property'

$fedora->begin();

$resource = $fedora->getResourcesByProperty($conf->get('redmineIdProp'), 'https://redmine.acdh.oeaw.ac.at/issues/5488')[0];
$metadata = $resource->getMetadata();
$metadata->delete($myProperty));
foreach(array('value1', 'value2') as $i){
    $metadata->addLiteral($myProperty, $i);
}
$resource->setMetadata($metadata);
$resource->updateMetadata();

$fedora->commit();
```

**Bad example 1.** You will end up with a resource having only your new triples. All other metadata will be lost.

```php
$myProperty = \achdOeaw\util\EasyRdfUtil::fixPropName('http://my.new/#property'

$fedora->begin();

$graph = new EasyRdf_Graph();
$metadata = $graph->resource('.');
foreach(array('value1', 'value2') as $i){
    $metadata->addLiteral($myProperty, $i);
}
$resource->setMetadata($metadata);
$resource->updateMetadata();

$fedora->commit();
```

**Bad example 2.** You will end up with both old and new values of your property.

```php
$myExistingProperty = \achdOeaw\util\EasyRdfUtil::fixPropName('http://my.existing/#property'

$fedora->begin();

$resource = $fedora->getResourcesByProperty($conf->get('redmineIdProp'), 'https://redmine.acdh.oeaw.ac.at/issues/5488')[0];
$metadata = $resource->getMetadata();
foreach(array('value1', 'value2') as $i){
    $metadata->addLiteral($myExistingProperty, $i);
}
$resource->setMetadata($metadata);
$resource->updateMetadata();

$fedora->commit();
```

**Bad example 3.** You will end up with only last added value of your property.

```php
$myMultivalueProperty = \achdOeaw\util\EasyRdfUtil::fixPropName('http://my.existing/#property'

$fedora->begin();

$resource = $fedora->getResourcesByProperty($conf->get('redmineIdProp'), 'https://redmine.acdh.oeaw.ac.at/issues/5488')[0];
$metadata = $resource->getMetadata();
foreach(array('value1', 'value2') as $i){
    $metadata->delete($myMultivalueProperty);
    $metadata->addLiteral($myMultivalueProperty, $i);
}
$resource->setMetadata($metadata);
$resource->updateMetadata();

$fedora->commit();
```

### Updating resource binary data

Updating resource binary data is easy. Just obtain the `acdhOeaw\fedora\FedoraResource` object (see above) and call the `updateContent()` method.

```php
$fedora->begin();

$resource = $fedora->getResourceById('https://id.acdh.oeaw.ac.at/myResource');
$resource->updateContent('pathToFile'); // with data in file
$resource->updateContent('new content of the resource'); // with data passed directly

$fedora->commit();
```

## Synchronizing Redmine with Fedora

There is a set of classes for syncing various Redmine objects (projects, users and issues) with Fedora: 
`acdhOeaw\redmine\Project`, `acdhOeaw\redmine\User` and `acdhOeaw\redmine\Issue`

Using them is very simple - the static `fetchAll()` method creates PHP objects representing Redmine objects of a given kind 
which can be then saved/updated in the Fedora by calling their `updateRms()` method.

Additionally the `Issue` class `fetchAll()` method allows you to specify any filters accepted by the Redmine REST API.

E.g. synchronization of all the Redmine issues with `tracker_id` equal to `5` can be done like that:

```php
$fedora->begin();

$issues = acdhOeaw\redmine\Redmine::fetchAllIssues(true, ['tracker_id' => 5]);
foreach ($issues as $i) {
    $i->updateRms();
}

$fedora->commit();
```

## Indexing files in the filesystem

Library providex the `acdhOeaw\storage\Indexer` class which automates the process of ingesting/updating binary content into the Fedora.

The `Indexer` class is created on top of the `acdhOeaw\fedora\FedoraResource` object which means you must instanciate a `FedoraResource` object first.

The `Indexer` class is highly configurable - see the class documentation for all the details.

Below we will index all xml files in a given directory and its direct subdirectories putting them as a direct children of the `FedoraResouce` 
(meaning no Fedora collection resource will be created for subdirectories found in the file system). 
All files smaller then 100 MB will be ingested into the repository and for bigger files pure metadata Fedora resources will be created.

```php
$fedora->begin();

$resource = $fedora->getResourcesByProperty($conf->get('redmineIdProp'), 'https://redmine.acdh.oeaw.ac.at/issues/5488')[0];
$ind = new acdhOeaw\storage\Indexer($resource);
$ind->setFilter('|[.]xml$|i');
$ind->setPaths(array('directory/to/index/path'));
$ind->setUploadSizeLimit(100000000);
$ind->setDepth(1);
$ind->setFlatStructure(true);
$ind->index(true);

$fedora->commit();
```
