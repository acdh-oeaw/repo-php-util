# repo-php-util

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

* (optional but very useful) Declare a shortcat for the configuration class
* Load composer
* Initialize configuration using `config.ini` file
* Create an object of `acdhOeaw\fedora\Fedora` class

```php
use acdhOeaw\util\RepoConfig as RC;

require_once 'vendor/autoload.php';

RC::init('config.ini');
$fedora = new acdhOeaw\fedora\Fedora();
```

# Documentation

You can find detailed API documentation in the `docs` folder.

To read it on-line go to https://rawgit.com/acdh-oeaw/repo-php-util/master/docs/index.html

# Usage

(it is assumed that you already run the initialization code, especially that the `$fedora` object is created)

## Working with Fedora resources

A Fedora resource is represented by the `achdOeaw\fedora\FedoraResource` class.

This class provides you basic methods to manipulate both resource's metadata and binary content (see examples below).

In general you should not create `FedoraResource` objects directly but always use proper `Fedora` class method (see examples below).  
If you want to know more, please read the `Fedora` class documentation, especially parts on transaction handling.

The metadata are represented by the [EasyRdf Resource](http://www.easyrdf.org/docs/api/EasyRdf_Resource.html) object.

**Updating metadata in RDF can be tricky**, so please read examples on this topic provided below.

**All resource modifications must be done within a Fedora transaction** so all the `$fedora->begin()` and `$fedora->commit()` in the code examples are really needed.

### Creating a new Fedora resource

Prepare resource metadata and (optionally) its binary content and call the `createResource()` method of the `Fedora` class.

```php
$graph = new EasyRdf\Graph();
$metadata = $graph->resource('.'); // the resource URI you provide here is irrelevant and can be any string (the EasyRdf library requires it to be non-empty)
$metadata->addLiteral('http://my.data/#property', 'myDataPropertyValue');
$metadata->addResource('http://my.object/#property', 'http://my.Object/Property/Value');

$fedora->begin();
$resource1 = $fedora->createResource($metadata, 'pathToFile'); // with binary data from file, at the repository root
$resource2 = $fedora->createResource($metadata, 'myResourceData (...)'); // with binary data from string, at the repository root
$resource3 = $fedora->createResource($metadata); // without binary data, at the repository root
$resource4 = $fedora->createResource($metadata, '', '/my/collection'); // without binary data, as a child of a given Fedora collection (the collection has to exist)
$resource5 = $fedora->createResource($metadata, 'pathToFile', '/my/resource', 'PUT'); // with binary data from file, at a given location (the '/my' collection has to exist)
$fedora->commit();
```

### Finding already existing Fedora resources

If you know the resource ACDH ID you can use the `getResourceById()` method.

```php
$resource = $fedora->getResourceById('https://id.acdh.oeaw.ac.at/ba83b0d6-86cd-4340-bfd7-ab5a2edb345a');
echo $resource->__metaToString();
```

If you know resource's metadata property value, you can search for all resources having such a value with the `getResourcesByProperty()` method.

```php
$resources = $fedora->getResourceByProperty('http://www.w3.org/2000/01/rdf-schema#seeAlso', 'https://redmine.acdh.oeaw.ac.at/issues/5488');
echo count($resources);
echo $resources[0]->__metaToString();
```

Of course if you know the resource's Fedora URI, you can use it as well (with the `getResourceByUri()` method).

```php
$resource = $fedora->getResourceByUri('http://fedora.apollo.arz.oeaw.ac.at/rest/92/35/a8/40/9235a840-5f0e-4f24-971d-c0c557f43d9e');
echo $resource->__metaToString();
```

### Updating resource metadata

**Updating RDF metadata is a little tricky.**
The main problem is that an update of a metadata property value is not well defined, therefore can not be done automatically for you.

Lets assume we have an existing metadata triple `<ourResource> <ourProperty> "currentValue"` and a new triple `<ourResource> <ourProperty> "newValue"`.  
There is no way to automatically decide if the new triple should replace the old one or be added next to it.  
This is because RDF triples are uniquely identified by all their component values (subject, property and object) and change of any component (also the object) 
cause the new triple not to match its previous form.

This means the only way to avoid triples multiplication is to delete previous metadata and add all current triples.  
It is automatically done by the library but it means you must always provide a full metadata set when calling the `setMetadata()` method if you do not want to loose any metadata triples.

**Remember:**

* Always take current resource metadata as a basis. 
    * The only exception might be if you are sure the new triples do not exist in the current metadata and do not interfere with current metadata (basically there are no common properties between old and new metadata).  
      In such a case use the `updateMetadata('ADD')` method.  
* Remember to delete all metadata values before adding current ones (remember, there is no update, just delete and add).
    * If a property can have multiple values, assure you are deleting it only once (do not repeat deletion for the every new value you encounter).
* Think twice when dealing with `rdfs:identifier` and `rdf:isPartOf` properties (these two are very important).

**Good example.**

```php
$myProperty = 'http://my.new/#property'

$fedora->begin();

$resource = $fedora->getResourcesById('https://redmine.acdh.oeaw.ac.at/issues/5488');
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
$myProperty = 'http://my.new/#property'

$fedora->begin();

$graph = new EasyRdf\Graph();
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
$myExistingProperty = 'http://my.existing/#property'

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
$myMultivalueProperty = 'http://my.existing/#property'

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
`acdhOeaw\schema\redmine\Project`, `acdhOeaw\schema\redmine\User` and `acdhOeaw\schema\redmine\Issue`

Using them is very simple - the static `fetchAll()` method creates PHP objects representing Redmine objects of a given kind 
which can be then saved/updated in the Fedora by calling their `updateRms()` method.

Additionally the `Issue` class `fetchAll()` method allows you to specify any filters accepted by the Redmine REST API.

E.g. synchronization of all the Redmine issues with `tracker_id` equal to `5` can be done like that:

```php
$fedora->begin();

$issues = acdhOeaw\redmine\Redmine::fetchAllIssues($fedora, true, ['tracker_id' => 5]);
foreach ($issues as $i) {
    $i->updateRms();
}

$fedora->commit();
```

## Indexing files in the filesystem

Library providex the `acdhOeaw\util\Indexer` class which automates the process of ingesting/updating binary content into the Fedora.

The `Indexer` class is created on top of the `acdhOeaw\fedora\FedoraResource` object which will be a parent for ingested resources.
This means you must instanciate such an object first.

The `Indexer` class is highly configurable - see the class documentation for all the details.

Below we will index all xml files in a given directory and its direct subdirectories putting them as a direct children of the `FedoraResouce` 
(meaning no collection resource will be created for subdirectories found in the file system). 
All files smaller then 100 MB will be ingested into the repository and for bigger files pure metadata Fedora resources will be created.

```php
$fedora->begin();

$resource = $fedora->getResourceById('https://redmine.acdh.oeaw.ac.at/issues/5488');
$ind = new acdhOeaw\util\Indexer($resource);
$ind->setFilter('|[.]xml$|i');
$ind->setPaths(array('directoryToIndex')); // read next chapter
$ind->setUploadSizeLimit(100000000);
$ind->setDepth(1);
$ind->setFlatStructure(true);
$ind->index();

$fedora->commit();
```

### How files are matched with repository resources

A file is matched with a repository resource by comparing existing resources' ids with an adjusted file path.  
The file path is adjusted by:

* substituting the `containerDir` configuration property value with the `containerToUriPrefix` configuration property value
* changing character encoding to UTF-8
* switching all `\` into `/`

**It is extremely important to assure that your `containerDir` and `containerToUriPrefix` configuration property values are proper!**
(if they lead to generation of the ids you expect)

**If they are not, you risk data duplication on the next import.**

#### Example 1

* config.ini:
  ```ini
  containerDir=./
  containerToUriPrefix=acdhContainer://
  ```
* file path: `./myProject/myCollection/myFile.xml`  

The file will match a resource having an id `acdhContainer://myProject/myCollection/myFile.xml`

#### Example 2

* config.ini:
  ```ini
  containerDir=C:\my\data\dir\
  containerToUriPrefix=https://id.acdh.oeaw.ac.at/
  ```
* file path: `C:\my\data\dir\myProject\myCollection\myFile.xml`  
 
The file will match a resource having an id `https://id.acdh.oeaw.ac.at/myProject/myCollection/myFile.xml`

### Matching indexed data with their metadata

The `Indexer` object can be provided with a *metadata lookup object*.

A *metadata lookup object* is provided with a file path and the metadata extracted from that file. Given that data it should find and return auxiliary metadata for a given file.

At the moment two *metadata lookup* implementations exist:

* `MetaLookupFile` class which searches for the auxiliary metadata in additional files (matching by file name), e.g.
  ```php
  // for the file path `/my/file/path.xml` search for metadata in files `/my/file/path.xml.ttl`, `/my/file/path/meta/path.xml.ttl` and `/some/dir/path.xml.ttl`
  // locations are searched in the given order, first metadata file found is used
  // such a file must contain only one resource being triples subject (if there are more, an exception is rised)
  $metaLookup = new acdhOeaw\util\metaLookup\MetaLookupFile(array('.', './meta', '/some/dir'), '.ttl');

  $ind = new Indexer($someResource);
  $ind->setMetaLookup($metaLookup);
  $ind->index();
  ```
* `MetaLookupGraph` class which searches for the auxiliary metadata in a given RDF graph (matching by id as described in the previous chapter), e.g.
  ```php
  $graph = new EasyRdf\Graph();
  $graph->parseFile('pathToMetadataFile.ttl');
  $metaLookup = new acdhOeaw\util\metaLookup\MetaLookupGraph($graph);

  $ind = new Indexer($someResource);
  $ind->setMetaLookup($metaLookup);
  $ind->index();
  ```

## Importing set of RDF data

If you have a bunch of data in a form of an RDF graph, you can ingest it easily with the `MetadataCollection` class.

## Managing access rights

Every `FedoraResource` object provides a corresponding `WebAcl` object which can be used for access rights management, e.g. to grant write to `user` and read to public:

```php
$fedora->begin();
$res = $fedora->getResourceById('https://my.id');
$aclObj = $res->getAcl();
$aclObj->grant(acdhOeaw\fedora\acl\WebAclRule::USER, 'user1', acdhOeaw\fedora\acl\WebAclRule::WRITE);
$aclObj->grant(acdhOeaw\fedora\acl\WebAclRule::USER, acdhOeaw\fedora\acl\WebAclRule::PUBLIC_USER, acdhOeaw\fedora\acl\WebAclRule::READ);
$fedora->commit();
```

Rights can be automatically applied also to children (in ACDH repo terms), e.g. here to direct children and second-order children:


```php
$fedora->begin();
$res = $fedora->getResourceById('https://my.id');
$aclObj = $res->getAcl();
$aclObj->grant(acdhOeaw\fedora\acl\WebAclRule::USER, 'user1', acdhOeaw\fedora\acl\WebAclRule::WRITE, 2);
$fedora->commit();
```

Simlarly a `revoke()` method exists as well as methods for managing RDF-class based access rules.

See documenation for details.
