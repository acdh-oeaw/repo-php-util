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
 */

namespace acdhOeaw\redmine;

use stdClass;
use RuntimeException;
use BadMethodCallException;
use EasyRdf_Graph;
use EasyRdf_Resource;
use GuzzleHttp\Exception\ClientException;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\EasyRdfUtil;
use zozlak\util\Config;
use zozlak\util\ProgressBar;

/**
 * Base class for all kind of resources in the Redmine
 * (e.g. issues, projects, users)
 *
 * @author zozlak
 */
abstract class Redmine {

    /**
     * Redmine API base URL
     * @var string
     */
    static protected $apiUrl;

    /**
     * Redmine REST API key
     * @var string
     */
    static protected $apiKey;

    /**
     * URI of the property used in Fedora's resource metadata to uniquely identify a resource
     * @var string
     */
    static private $idProp;

    /**
     * Array of objects defining Redmine property to RDF property mappings
     * @var array
     */
    static private $propMap;

    /**
     * Mappings of derived PHP class names to RDF class names
     * @var array
     */
    static private $classes = array();

    /**
     * acdhOeaw\fedora\Fedora object instance
     * It is used to create and interact with Fedora resources
     * @var \acdhOeaw\fedora\Fedora
     * @see fetchAll()
     * @see getById()
     */
    static private $fedora;

    /**
     * Fetches an Redmine object from cache based on its Redmine ID.
     * 
     * If object does not exist in cache, it will be created and added to the cache.
     * 
     * @param int $id redmine ID of an object
     * @return acdhOeaw\redmine\Redmine
     */
    static public abstract function getById(int $id): Redmine;

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
     * @param bool $progressBar should progress bar be displayed 
     *   (makes sense only if the standard output is a console)
     * @param array $filters filters to be passed to the Redmine's issue REST API
     *   in a form of an associative array, e.g. `array('tracker_id' => 5)`
     * @return array
     * @see getRmsId()
     */
    static public abstract function fetchAll(bool $progressBar): array;

    /**
     * Maps Redmine's object ID to the Redmine's object URI
     * @return string
     */
    protected abstract function getIdValue(): string;

    /**
     * Initializes class with configuration settings.
     * 
     * Required configuration parameters include:
     * 
     * - mappingsFile - path to a JSON file describing mappings between Redmine
     *     objects properties and RDF properties
     * - redmineApiUrl - base URL of the Redmine REST API
     * - redmineApiKey - Redmine's REST API access key
     * - redmineIdProp - URI of the RDF property used to store Redmine's object
     *     URI
     * - redmineClasses[] - associative table providing mappings between PHP 
     *     derived from this class and RDF classes
     * 
     * @param \zozlak\util\Config $cfg configuration to be used
     * @param Fedora $fedora a Fedora object instance
     */
    static public function init(Config $cfg, Fedora $fedora) {
        self::$propMap = (array) json_decode(file_get_contents($cfg->get('mappingsFile')));
        self::$apiUrl = $cfg->get('redmineApiUrl');
        self::$apiKey = $cfg->get('redmineApiKey');
        self::$idProp = $cfg->get('redmineIdProp');
        self::$fedora = $fedora;
        self::$classes = $cfg->get('redmineClasses');
    }

    /**
     * 
     * @param bool $progressBar
     * @param string $endpoint
     * @param string $param
     * @return array
     */
    static protected function redmineFetchLoop(bool $progressBar, string $endpoint, string $param): array {
        if ($progressBar) {
            $pb = new ProgressBar(null, 10);
        }
        $objects = [];
        $offset = 0;
        do {
            $flag = false;
            $url = self::$apiUrl . '/' . $endpoint . '.json?offset=' . $offset . '&' . $param;
            $data = file_get_contents($url);
            if ($data) {
                $data = json_decode($data);
                foreach ($data->$endpoint as $i) {
                    $objects[] = get_called_class()::getById($i->id);
                    if ($progressBar) {
                        $pb->next();
                    }
                }
                $flag = $data->offset + $data->limit < $data->total_count;
                $offset += $data->limit;
            }
        } while ($flag);
        if ($progressBar) {
            $pb->finish();
        }
        return $objects;
    }

    /**
     * Redmine ID of an Redmine object
     * 
     * @var int
     */
    protected $id;

    /**
     * Redmine's object metadata
     * 
     * @var \EasyRdf_Resource
     */
    protected $metadata;

    /**
     * FedoraResource representing given Redmine object
     * 
     * @var \acdhOeaw\fedora\FedoraResource
     */
    protected $fedoraRes;

    /**
     * Creates a new Redmine object.
     * 
     * Must not be called directly as this can lead to duplication.
     * Use the getById() method instead.
     * 
     * @param stdClass $data Redmine's object properties fetched from the
     *   Redmine REST API
     * @throws BadMethodCallException
     * @see getById()
     */
    public function __construct(stdClass $data) {
        if (isset(get_called_class()::$cache[$data->id])) {
            throw new BadMethodCallException('Object already exists, call ' . get_called_class() . '::getById() instead');
        }
//echo 'New ' . get_class($this) . ' ' . $data->id . "\n";

        $this->id = $data->id;
        $this->metadata = $this->mapProperties((array) $data);
    }

    /**
     * Adds RDF property to the metadata according to mapping rules.
     * 
     * @param EasyRdf_Resource $res metadata
     * @param stdClass $prop property mapping object
     * @param string $value Redmine property value
     * @throws RuntimeException
     */
    private function addValue(EasyRdf_Resource $res, stdClass $prop, string $value) {
        if (!$value) {
            return;
        }

        $value = str_replace('\\', '/', $value); // ugly workaround for windows-like paths; should be applied only to location_path property
        if ($prop->template !== '') {
            $value = str_replace(['%REDMINE_URL%', '%VALUE%'], [self::$apiUrl, $value], $prop->template);
        }

        if ($prop->redmineClass) {
            if ($prop->redmineClass === 'self') {
                $value = get_class($this)::getById($value);
            } else {
                $value = $prop->redmineClass::getById($value);
            }
            $value = $value->getRmsId();
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
     * @param array $data associative array with Redmine's resource properties
     *   fetched from the Redmine REST API
     * @return \EasyRdf_Resource
     */
    protected function mapProperties(array $data): EasyRdf_Resource {
        $this->getRmsUri(false); // to load metadata if resource already exists

        if ($this->fedoraRes) {
            $res = $this->fedoraRes->getMetadata();
        } else {
            $graph = new EasyRdf_Graph();
            $res = $graph->resource('.');
        }

        $res->delete(EasyRdfUtil::fixPropName(self::$idProp));
        $res->addResource(self::$idProp, $this->getIdValue());

        $res->delete('rdf:type');
        $res->addResource('rdf:type', self::$classes[get_called_class()]);

        $deletedProps = array();
        foreach (self::$propMap as $redmineProp => $rdfProp) {
            if (!in_array($rdfProp->uri, $deletedProps)) {
                $res->delete(EasyRdfUtil::fixPropName($rdfProp->uri));
                $deletedProps[] = $rdfProp->uri;
            }

            $value = null;
            if (isset($data[$redmineProp])) {
                $value = $data[$redmineProp];
            } elseif (isset($data['custom_fields'])) {
                foreach ($data['custom_fields'] as $cf) {
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
                        echo "\n" . 'Adding value for the ' . $redmineProp . ' of the ' . $this->getIdValue() . ' failed: ' . $e->getMessage() . "\n";
                        throw $e;
                    }
                }
            }
        }
        return $res;
    }

    /**
     * Returns URI of the Fedora resource representing given Redmine object.
     * 
     * If Fedora resource does not exist, it is created or not depending on the
     * $create param.
     * 
     * @param bool $create should Fedora resource be created if it does not 
     *   exist yet
     * @return string
     * @throws RuntimeException
     */
    private function getRmsUri(bool $create = false): string {
        if (!$this->fedoraRes) {
            $resources = self::$fedora->getResourcesByProperty(self::$idProp, $this->getIdValue());
            if (count($resources) > 1) {
                foreach ($resources as $i) {
                    print_r([$i->getUri(), $i->getIds()]);
                }
                throw new RuntimeException('Many matching Fedora resources');
            } elseif (count($resources) == 1) {
                $this->fedoraRes = $resources[0];
            } else if ($create) {
                try {
                    $this->fedoraRes = self::$fedora->createResource($this->metadata);
                } catch (ClientException $e) {
                    echo $this->metadata->getGraph()->serialise('ntriples') . "\n";
                    throw $e;
                }
            }
        }
        return $this->fedoraRes ? $this->fedoraRes->getUri() : '';
    }

    /**
     * Saves Redmine object to Fedora.
     */
    public function updateRms() {
        if (!$this->fedoraRes) {
            $this->getRmsUri(true);
        }
        $this->metadata = EasyRdfUtil::mergeMetadata($this->fedoraRes->getMetadata(), $this->metadata);
        $this->fedoraRes->setMetadata($this->metadata);
        try {
            $this->fedoraRes->updateMetadata();
        } catch (ClientException $e) {
            echo $this->metadata->getGraph()->serialise('ntriples') . "\n";
            throw $e;
        }
    }

    /**
     * Returns corresponding Fedora resource ACDH ID.
     * 
     * If the corresponding Fedora resource does not exist, it is created.
     * 
     * @return string
     */
    private function getRmsId(): string {
        if (!$this->fedoraRes) {
            $this->getRmsUri(true);
        }
        return $this->fedoraRes->getId();
    }

}
