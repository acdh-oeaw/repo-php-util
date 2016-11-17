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
use EasyRdf_Graph;
use EasyRdf_Resource;
use acdhOeaw\rms\Fedora;

/**
 * Base class for all kind of resources in the Redmine
 * (e.g. issues, projects, users)
 *
 * @author zozlak
 */
abstract class Redmine {

    static private $idProperty = 'http://vocabs.acdh.oeaw.ac.at/#pid';
    static protected $propMap;
    static protected $baseUrl;
    static protected $apiKey;

    /**
     * Creates a redmine object based on its redmine ID
     * 
     * @param int $id redmine ID (single number) of an object
     * @return acdhOeaw\redmine\Redmine created object
     */
    static public abstract function getById(int $id): Redmine;

    /**
     * Returns redmine's API URI corresponding to a given object.
     * 
     * @return string URI
     */
    protected abstract function getIdValue(): string;

    static public function init(string $propMappingsFile, string $baseUrl, string $apiKey = '') {
        self::$propMap = (array) json_decode(file_get_contents($propMappingsFile));
        self::$baseUrl = $baseUrl;
        self::$apiKey = $apiKey;
    }

    static private function redmineFetchLoop(string $endpoint, string $param, string $class) {
        $objects = [];
        $offset = 0;
        do {
            $flag = false;
            $url = self::$baseUrl . '/' . $endpoint . '.json?offset=' . $offset . '&' . $param;
            $data = @file_get_contents($url);
            if ($data) {
                $data = json_decode($data);
                foreach ($data->$endpoint as $i) {
                    $objects[] = new $class($i);
                }
                $flag = $data->offset + $data->limit < $data->total_count;
                $offset += $data->limit;
            }
        } while ($flag);
        return $objects;
    }

    static public function fetchAllProjects() {
        $param = 'limit=100000&key=' . urlencode(self::$apiKey);
        return self::redmineFetchLoop('projects', $param, 'acdhOeaw\\redmine\\Project');
    }

    static public function fetchAllUsers() {
        $param = 'limit=100000&key=' . urlencode(self::$apiKey);
        return self::redmineFetchLoop('users', $param, 'acdhOeaw\\redmine\\User');
    }

    static public function fetchAllIssues(array $filters = []) {
        $param = ['key=' . urlencode(self::$apiKey), 'limit' => 1000000];
        foreach ($filters as $k => $v) {
            $param[] = urlencode($k) . '=' . urlencode($v);
        }
        $param = implode('&', $param);

        return self::redmineFetchLoop('issues', $param, 'acdhOeaw\\redmine\\Issue');
    }

    protected $id;
    protected $metadata;
    protected $uri = '';

    public function __construct(stdClass $data) {
//echo 'New ' . get_class($this) . ' ' . $data->id . "\n";
        $this->id = $data->id;
        $this->metadata = $this->mapProperties((array) $data)->getGraph();
    }

    protected function addValue(EasyRdf_Resource $res, stdClass $prop, string $value) {
        if (!$value) {
            return;
        }

        $value = str_replace('\\', '/', $value);
        if ($prop->template !== '') {
            $value = str_replace(['%REDMINE_URL%', '%VALUE%'], [self::$baseUrl, $value], $prop->template);
        }

        if ($prop->redmineClass) {
            if ($prop->redmineClass === 'self') {
                $value = get_class($this)::getById($value);
            } else {
                $value = $prop->redmineClass::getById($value);
            }
            $value = $value->getIdValue();
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

    protected function mapProperties(array $data) {
        $graph = new EasyRdf_Graph();
        $res = $graph->resource('.');
        $res->addResource(self::$idProperty, $this->getIdValue());
        foreach (self::$propMap as $redmineProp => $rdfProp) {
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
                    }
                }
            }
        }
        return $res;
    }

    public function getUri(bool $create = false) {
        if ($this->uri) {
            return $this->uri;
        }
        $resources = Fedora::getResourcesByProperty(self::$idProperty, $this->getIdValue());
        if (count($resources) > 1) {
            throw new RuntimeException('Many matching Fedora resources');
        } elseif (count($resources) == 1) {
            $this->uri = $resources[0];
        } elseif ($create) {
            $this->updateRms();
        }
        return $this->uri;
    }

    public function updateRms() {
        $uri = $this->getUri(false);
        if ($uri) {
            Fedora::updateResourceMetadata($uri, $this->metadata);
        } else {
            $this->uri = Fedora::createResource($this->metadata);
        }
    }

}
