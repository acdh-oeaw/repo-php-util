# repo-php-utils

Set of classes for working with the ACDH repository stack.

## Installation

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

## Initialization

* Load composer
* Read config from `config.ini` and `property_mappings.json` files you prepared during the installation and pass them to static class initializers.

```php
require_once 'vendor/autoload.php';

$config = new zozlak\util\Config('config.ini');

acdhOeaw\util\SparqlEndpoint::init($config->get('sparqlUrl'));
acdhOeaw\redmine\Redmine::init($config);
acdhOeaw\FedoraResource\FedoraResource::init($config);
acdhOeaw\storage\Indexer::init($config);

```

## Usage

### Synchronizing Redmine with Fedora

```php
acdhOeaw\fedora\FedoraResource::begin();

$issues = acdhOeaw\redmine\Redmine::fetchAllIssues(true, ['tracker_id' => 5]);
foreach ($issues as $i) {
    $i->updateRms();
}

acdhOeaw\fedora\FedoraResource::commit();
```

### Synchronizing filesystem with Fedora

```php
acdhOeaw\fedora\FedoraResource::begin();

$res = new acdhOeaw\fedora\FedoraResource('http://fedora.localhost/rest/0c/c3/d0/ba/0cc3d0ba-2836-41d2-aa97-9c1d56907068');
$ind = new acdhOeaw\storage\Indexer($res);
$ind->index(1000, 2, false, true);

acdhOeaw\fedora\FedoraResource::commit();
```

### Finding location paths listed in the Fedora but missing in the container

```php
$res = new acdhOeaw\fedora\FedoraResource('http://fedora.localhost/rest/0c/c3/d0/ba/0cc3d0ba-2836-41d2-aa97-9c1d56907068');
$ind = new acdhOeaw\storage\Indexer($res);
print_r($ind->getMissingLocations());
```

