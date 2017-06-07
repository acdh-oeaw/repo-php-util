<?php

/**
 * The MIT License
 *
 * Copyright 2016 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * 
 * @package repo-php-util
 * @copyright (c) 2017, Austrian Centre for Digital Humanities
 * @license https://opensource.org/licenses/MIT
 */

namespace acdhOeaw\schema\redmine;

use stdClass;
use RuntimeException;
use BadMethodCallException;
use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\schema\Object;
use acdhOeaw\util\RepoConfig as RC;
use zozlak\util\ProgressBar;

/**
 * Base class for all kind of resources in the Redmine
 * (e.g. issues, projects, users)
 *
 * @author zozlak
 */
abstract class Redmine extends Object {

    /**
     * RDF property used to store Redmine entity URI
     * @var string
     */
    static protected $seeAlsoProp = 'http://www.w3.org/2000/01/rdf-schema#seeAlso';

    /**
     * Array of objects defining Redmine property to RDF property mappings
     * @var array
     */
    static private $propMap;

    /**
     * Returns a standardized value of the redmineApiUrl configuration property.
     * @return string
     */
    static protected function apiUrl(): string {
        return preg_replace('|/$|', '', RC::get('redmineApiUrl'));
    }
    
    /**
     * Returns array of all objects of a given kind which can be fetched from the Redmine.
     * 
     * The number of object depends on the permissions provided by you Redmine 
     * API key. Only objects you can access with your key will be returned and
     * it may cause problems if your Redmine API key does not allow you access
     * to related Redmine objects (e.g. parent issues, users assigned to an issue, etc.).
     * 
     * It is important to understand that as a side effect of this function 
     * Fedora resources are created (if do not exist already) for all Redmine 
     * objects linked to the fetched ones (e.g. parent issues and projects, 
     * users marked as issue creators and assignees, etc.). This is because 
     * to include such linked resources in the metadata of the fetched ones, 
     * the ACDH IDs of linked resources are required and these IDs are not known 
     * before resources are not created in the Fedora.
     * 
     * @param \acdhOeaw\fedora\Fedora $fedora Fedora connection
     * @param bool $progressBar should progress bar be displayed 
     *   (makes sense only if the standard output is a console)
     * @return array
     * @see getRmsId()
     */
    static public abstract function fetchAll(Fedora $fedora, bool $progressBar): array;

    /**
     * Maps Redmine's object ID to the Redmine's object URI
     * @param int $id Redmine ID
     * @return string
     */
    static public abstract function redmineId2repoId(int $id): string;

    /**
     * Fetches all objects from a given Redmine API endpoint.
     * 
     * Redmine limits number of results returned by one API call to 100
     * therefore it may be needed to call the API many times.
     * @param Fedora $fedora
     * @param bool $progressBar
     * @param string $endpoint
     * @param string $param
     * @return array
     */
    static protected function redmineFetchLoop(Fedora $fedora,
                                               bool $progressBar,
                                               string $endpoint, string $param): array {
        if ($progressBar) {
            $pb = new ProgressBar(null, 10);
        }
        $class   = get_called_class();
        $objects = [];
        $offset  = 0;
        do {
            $flag = false;
            $url  = RC::get('redmineApiUrl') . '/' . $endpoint . '.json?offset=' . $offset . '&' . $param;
            $data = file_get_contents($url);
            if ($data) {
                $data = json_decode($data);
                foreach ($data->$endpoint as $i) {
                    $objects[] = new $class($fedora, $class::redmineId2repoId($i->id), (array) $i);
                    if ($progressBar) {
                        $pb->next();
                    }
                }
                $flag   = $data->offset + $data->limit < $data->total_count;
                $offset += $data->limit;
            }
        } while ($flag);
        if ($progressBar) {
            $pb->finish();
        }
        return $objects;
    }

    /**
     * Fetches given Redmine entity description from the Redmine API URI.
     * @param string $url
     * @return array
     * @throws RuntimeException
     */
    static protected function fetchData(string $url): array {
        $url  .= '.json?key=' . urlencode(RC::get('redmineApiKey'));
        $data = file_get_contents($url);
        if ($data) {
            $data = (array) json_decode($data);
            $data = array_pop($data);
            return (array) $data;
        }
        throw new RuntimeException('Fetching data failed');
    }

    /**
     * Raw data obtained from the Redmine API
     * 
     * @var array 
     */
    protected $data;

    /**
     * Creates a new Redmine object.
     * 
     * Must not be called directly as this can lead to duplication.
     * Use the getById() method instead.
     * 
     * @param \acdhOeaw\fedora\Fedora $fedora Fedora connection
     * @param string $id repository ID for a Fedora object
     * @param array $data Redmine's object properties fetched from the
     *   Redmine REST API (will be fetched automatically if not provided)
     * @throws BadMethodCallException
     * @see getById()
     */
    public function __construct(Fedora $fedora, string $id, array $data = null) {
        parent::__construct($fedora, $id);

        if (self::$propMap === null) {
            self::$propMap = (array) json_decode(file_get_contents(RC::get('redmineMappingsFile')));
        }

        if ($data === null) {
            $data = self::fetchData($id);
        }
        $this->data = $data;
    }

    /**
     * Maps Redmine's object properties to and RDF graph.
     * 
     * If resource already exists mapping must preserve already existing
     * properties (especialy fedoraId) and also take care about deleting
     * ones being "updated".
     * That is because in RDF there is nothing like updating a triple.
     * Triples can be only deleted or added.
     * "Updating a triple" means deleting its old value and inserting 
     * the new one.
     * 
     * @return \EasyRdf\Resource
     */
    public function getMetadata(): Resource {
        $graph = new Graph();
        $res   = $graph->resource('.');

        $res->addResource(RC::idProp(), $this->getId());
        $res->addResource(self::$seeAlsoProp, $this->getId());

        $classes = RC::get('redmineClasses');
        $res->addResource('rdf:type', $classes[get_called_class()]);

        foreach (self::$propMap as $redmineProp => $rdfProp) {
            $value = null;
            if (isset($this->data[$redmineProp])) {
                $value = $this->data[$redmineProp];
            } elseif (isset($this->data['custom_fields'])) {
                foreach ($this->data['custom_fields'] as $cf) {
                    if ($cf->name === $redmineProp && isset($cf->value)) {
                        $value = $cf->value;
                    }
                }
            }
            if (is_object($value)) {
                $value = $value->id;
            }
            if ($value !== null) {
                if (!is_array($value)) {
                    $value = [$value];
                }
                foreach ($value as $v) {
                    try {
                        $this->addValue($res, $rdfProp, $v);
                    } catch (RuntimeException $e) {
                        echo "\n" . 'Adding value for the ' . $redmineProp . ' of the ' . $this->data['id'] . ' failed: ' . $e->getMessage() . "\n";
                        throw $e;
                    }
                }
            }
        }
        return $res;
    }

    /**
     * Adds RDF property to the metadata according to mapping rules.
     * 
     * @param EasyRdf\Resource $res metadata
     * @param stdClass $prop property mapping object
     * @param string $value Redmine property value
     * @throws RuntimeException
     */
    private function addValue(Resource $res, stdClass $prop, string $value) {
        if (!$value) {
            return;
        }

        $value = str_replace('\\', '/', $value); // ugly workaround for windows-like paths; should be applied only to location_path property
        if ($prop->template !== '') {
            $value = str_replace(['%REDMINE_URL%', '%VALUE%'], [RC::get('redmineApiUrl'), $value], $prop->template);
        }

        if ($prop->redmineClass) {
            $class = $prop->redmineClass === 'self' ? get_class($this) : $prop->redmineClass;
            $obj   = new $class($this->fedora, $class::redmineId2repoId($value));
            $value = $obj->getResource(true)->getId();
        }

        if ($prop->class === 'dataProperty') {
            $res->addLiteral($prop->uri, $value);
        } else {
            if (preg_match('|^[a-zA-Z][a-zA-Z0-9]*:|', $value)) {
                $res->addResource($prop->uri, $value);
            } else {
                throw new RuntimeException('dataProperty must be an URI (' . $value . ')');
            }
        }
    }

}
