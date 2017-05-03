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
use DomainException;
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

    /**
     *
     * @var boolean 
     */
    static public $debug  = false;

    /**
     *
     * @var array
     */
    static private $cache = array();

    /**
     *
     * @var \zozlak\util\Config
     */
    static protected $config;

    /**
     * 
     * @param \zozlak\util\Config $cfg
     */
    static public function init(Config $cfg) {
        self::$config = $cfg;
    }

    /**
     * 
     */
    static public function clearCache() {
        self::$cache = array();
    }

    /**
     *
     * @var \acdhOeaw\fedora\FedoraResource
     */
    private $res;

    /**
     *
     * @var string
     */
    private $id;

    /**
     *
     * @var \acdhOeaw\fedora\Fedora 
     */
    protected $fedora;

    /**
     * 
     * @param Fedora $fedora
     * @param string $id
     */
    public function __construct(Fedora $fedora, string $id) {
        $this->fedora = $fedora;
        $this->id     = $id;
    }

    /**
     * 
     */
    abstract public function getMetadata(): Resource;

    /**
     * 
     * @param bool $create
     * @param bool $uploadBinary
     * @return FedoraResource
     */
    public function getResource(bool $create = true, bool $uploadBinary = true): FedoraResource {
        if ($this->res === null) {
            $this->updateRms($create, $uploadBinary);
        }
        return $this->res;
    }

    /**
     * 
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * 
     * @param bool $create
     * @param bool $uploadBinary
     * @return FedoraResource
     */
    public function updateRms(bool $create = true, bool $uploadBinary = true): FedoraResource {
        $created = $this->findResource($create, $uploadBinary);

        // if it has just been created it would be a waste of time to update it
        if (!$created) {
            $current = $this->res->getMetadata();
            $idProp  = array($this->fedora->getIdProp());

            $meta = EasyRdfUtil::mergePreserve($current, $this->getMetadata(), $idProp);
            $this->res->setMetadata($meta);
            $this->res->updateMetadata();

            $binaryContent = $this->getBinaryData();
            if ($create && $binaryContent !== '') {
                $this->res->updateContent($binaryContent, true);
            }
        }

        return $this->res;
    }

    /**
     * 
     * @param bool $create
     * @param bool $uploadBinary
     * @return boolean
     * @throws RuntimeException
     */
    protected function findResource(bool $create = true,
                                    bool $uploadBinary = true): bool {
        echo self::$debug ? "searching for " . $this->id . "\n" : "";
        $result = '';

        if (isset(self::$cache[$this->id])) {
            $res    = self::$cache[$this->id];
            $result = 'found in cache';
        } else {
            $matches = $this->fedora->getResourcesById($this->id);
            if (count($matches) == 0) {
                if ($create) {
                    $binary = $uploadBinary ? $this->getBinaryData() : '';
                    $res    = $this->fedora->createResource($this->getMetadata(), $binary);
                    $result = 'not found - created';
                } else {
                    throw new DomainException('resource not found');
                }
            } elseif (count($matches) == 1) {
                $res    = $matches[0];
                $result = 'found';
            } else {
                throw new RuntimeException('many matching resources');
            }
            self::$cache[$this->id] = $res;
        }

        echo self::$debug ? "\t" . $result . " - " . $this->res->getUri(true) . "\n" : "";

        $this->res = $res;
        return $result == 'not found - created';
    }

    /**
     * Provides resource's binary data.
     * @return type
     */
    protected function getBinaryData(): string {
        return '';
    }

}
