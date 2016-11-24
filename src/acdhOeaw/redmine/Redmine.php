<?php

/*
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

    static protected $apiUrl;
    static protected $apiKey;
    static private $idProp;
    static private $propMap;

    /**
     * @var \acdhOeaw\fedora\Fedora
     */
    static private $fedora;

    /**
     * Creates a redmine object based on its redmine ID
     * 
     * @param int $id redmine ID (single number) of an object
     * @return acdhOeaw\redmine\Redmine created object
     */
    static public abstract function getById(int $id): Redmine;

    static public abstract function fetchAll(bool $progressBar): array;

    /**
     * Returns redmine's API URI corresponding to a given object.
     * 
     * @return string URI
     */
    protected abstract function getIdValue(): string;

    static public function init(Config $cfg, Fedora $fedora) {
        self::$propMap = (array) json_decode(file_get_contents($cfg->get('mappingsFile')));
        self::$apiUrl = $cfg->get('redmineApiUrl');
        self::$apiKey = $cfg->get('redmineApiKey');
        self::$idProp = $cfg->get('redmineIdProp');
        self::$fedora = $fedora;
    }

    static protected function redmineFetchLoop(bool $progressBar, string $endpoint, string $param, string $class): array {
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

    protected $id;

    /**
     * @var \EasyRdf_Resource
     */
    protected $metadata;

    /**
     *
     * @var \acdhOeaw\fedora\FedoraResource
     */
    protected $fedoraRes;

    /**
     * Must not be called directly as this can lead to duplication
     * 
     * @param stdClass $data
     * @throws BadMethodCallException
     */
    public function __construct(stdClass $data) {
        if (isset(get_called_class()::$cache[$data->id])) {
            throw new BadMethodCallException('Object already exists, call ' . get_called_class() . '::getById() instead');
        }
//echo 'New ' . get_class($this) . ' ' . $data->id . "\n";

        $this->id = $data->id;
        $this->metadata = $this->mapProperties((array) $data);
    }

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
     * If resource already exists mapping must preserve already existing
     * properties (especialy fedoraId!) and also take care about deleting
     * ones being "updated".
     * That is because in RDF there is nothing like updating a triple.
     * Triples can be only deleted or added.
     * "Updating a triple" means deleting its old value and inserting 
     * the new one.
     * 
     * @param array $data
     * @return type
     */
    private function mapProperties(array $data) {
        $this->getRmsUri(false); // to load metadata if resource already exists

        if ($this->fedoraRes) {
            $res = $this->fedoraRes->getMetadata();
        } else {
            $graph = new EasyRdf_Graph();
            $res = $graph->resource('.');
        }

        $res->delete(EasyRdfUtil::fixPropName(self::$idProp));
        $res->addResource(self::$idProp, $this->getIdValue());
        foreach (self::$propMap as $redmineProp => $rdfProp) {
            $res->delete(EasyRdfUtil::fixPropName($rdfProp->uri));

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
                $this->fedoraRes = self::$fedora->createResource($this->metadata);
            }
        }
        return $this->fedoraRes ? $this->fedoraRes->getUri() : '';
    }

    public function updateRms() {
        if (!$this->fedoraRes) {
            $this->getRmsUri(true);
        }
        $this->fedoraRes->setMetadata($this->metadata);
        $this->fedoraRes->updateMetadata();
    }

    private function getRmsId(): string {
        if (!$this->fedoraRes) {
            $this->getRmsUri(true);
        }
        return $this->fedoraRes->getId();
    }

}
