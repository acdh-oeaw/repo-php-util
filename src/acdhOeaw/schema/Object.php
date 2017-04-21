<?php

/*
 * The MIT License
 *
 * Copyright 2017 zozlak.
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

namespace acdhOeaw\schema;

use RuntimeException;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;
use zozlak\util\Config;

/**
 * Description of Object
 *
 * @author zozlak
 */
abstract class Object {

    static public $debug = false;
    static private $cache = array();

    /**
     *
     * @var \zozlak\util\Config
     */
    static protected $config;

    static public function init(Config $cfg) {
        self::$config = $cfg;
    }

    /**
     *
     * @var \acdhOeaw\fedora\FedoraResource
     */
    private $res;
    private $id;

    /**
     *
     * @var \acdhOeaw\fedora\Fedora 
     */
    protected $fedora;

    public function __construct(Fedora $fedora, string $id) {
        $this->fedora = $fedora;
        $this->id = $id;
    }

    abstract public function getMetadata(): Resource;

    public function getResource(): FedoraResource {
        if ($this->res === null) {
            $this->updateRms();
        }
        return $this->res;
    }

    public function getId(): string {
        return $this->id;
    }

    public function updateRms() {
        $this->findResource();

        $current = $this->res->getMetadata();
        $idProp = array($this->fedora->getIdProp());

        $meta = EasyRdfUtil::mergePreserve($current, $this->getMetadata(), $idProp);
        $this->res->setMetadata($meta);
        $this->res->updateMetadata();
    }

    private function findResource() {
        if (self::$debug) {
            echo "searching for " . $this->id . "\n";
        }
        $res = '';
        
        if (isset(self::$cache[$this->id])) {
            $this->res = self::$cache[$this->id];
            $res = 'found in cache';
        } else {
            $matches = $this->fedora->getResourcesById($this->id);
            if (count($matches) == 0) {
                $this->res = $this->fedora->createResource($this->getMetadata());
                $res = 'not found - created';
            } elseif (count($matches) == 1) {
                $this->res = $matches[0];
                $res = 'found';
            } else {
                throw new RuntimeException('many matching resources');
            }
            self::$cache[$this->id] = $this->res;
        }
        
        if (self::$debug) {
            echo "\t" . $res . " - " . $this->res->getUri(true) . "\n";
        }
    }

}
