<?php

/**
 * The MIT License
 *
 * Copyright 2017 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\schema;

use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\exceptions\NotInCache;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Basic class for representing real-world entities to be imported into 
 * the repository.
 *
 * @author zozlak
 */
abstract class Object {

    /**
     * Debug mode switch.
     * @var boolean 
     */
    static public $debug = false;

    /**
     * Repository resource representing given entity.
     * @var \acdhOeaw\fedora\FedoraResource
     */
    private $res;

    /**
     * Entity id.
     * @var string
     */
    private $id;

    /**
     * Fedora connection object.
     * @var \acdhOeaw\fedora\Fedora 
     */
    protected $fedora;

    /**
     * Creates an object representing a real-world entity.
     * 
     * @param Fedora $fedora
     * @param string $id
     */
    public function __construct(Fedora $fedora, string $id) {
        $this->fedora = $fedora;
        $this->id     = $id;
        
        try {
            // not so elegant but saves expensive findResource() call
            $this->res = $fedora->getCache()->getById($this->id);
        } catch (NotInCache $e) {
            
        }
    }

    /**
     * Creates RDF metadata from the real-world entity stored in this object.
     */
    abstract public function getMetadata(): Resource;

    /**
     * Returns repository resource representing given real-world entity.
     * 
     * If it does not exist, it can be created.
     * 
     * @param bool $create should repository resource be created if it does not
     *   exist?
     * @param bool $uploadBinary should binary data of the real-world entity
     *   be uploaded uppon repository resource creation?
     * @return FedoraResource
     */
    public function getResource(bool $create = true, bool $uploadBinary = true): FedoraResource {
        if ($this->res === null) {
            $this->updateRms($create, $uploadBinary);
        }
        return $this->res;
    }

    /**
     * Returns primary id of the real-world entity stored in this object
     * (as it was set up in the object contructor).
     * 
     * Please do not confuse this id with the random internal ACDH repo id.
     * 
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Returns all known ids
     * 
     * @return array list of all ids
     */
    public function getIds(): array {
        $ids  = array($this->id);
        $meta = $this->getMetadata();
        foreach ($meta->allResources(RC::idProp()) as $id) {
            $ids[] = $id->getUri();
        }
        $ids = array_unique($ids);
        return $ids;
    }

    /**
     * Updates repository resource representing a real-world entity stored in
     * this object.
     * 
     * @param bool $create should repository resource be created if it does not
     *   exist?
     * @param bool $uploadBinary should binary data of the real-world entity
     *   be uploaded uppon repository resource creation?
     * @param string $path where to create a resource (if it does not exist).
     *   If it it ends with a "/", the resource will be created as a child of
     *   a given collection). All the parents in the Fedora resource tree have
     *   to exist (you can not create "/foo/bar" if "/foo" does not exist already).
     * @return FedoraResource
     */
    public function updateRms(bool $create = true, bool $uploadBinary = true,
                              string $path = '/'): FedoraResource {
        $created = $this->findResource($create, $uploadBinary, $path);

        // if it has just been created it would be a waste of time to update it
        if (!$created) {
            $current = $this->res->getMetadata();
            $idProp  = RC::idProp();

            $meta = $current->merge($this->getMetadata(), array($idProp));
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
     * Tries to find a repository resource representing a given object.
     * 
     * @param bool $create should repository resource be created if it was not
     *   found?
     * @param bool $uploadBinary should binary data of the real-world entity
     *   be uploaded uppon repository resource creation?
     * @param string $path where to create a resource (if it does not exist).
     *   If it it ends with a "/", the resource will be created as a child of
     *   a given collection). All the parents in the Fedora resource tree have
     *   to exist (you can not create "/foo/bar" if "/foo" does not exist already).
     * @return boolean if a repository resource was found
     */
    protected function findResource(bool $create = true,
                                    bool $uploadBinary = true,
                                    string $path = '/'): bool {
        $ids    = $this->getIds();
        echo self::$debug ? "searching for " . implode(', ', $ids) . "\n" : "";
        $result = '';

        try {
            $this->res = $this->fedora->getResourceByIds($ids);
            $result    = 'found in cache';
        } catch (NotFound $e) {
            if (!$create) {
                throw $e;
            }

            $binary    = $uploadBinary ? $this->getBinaryData() : '';
            $method    = substr($path, -1) == '/' ? 'POST' : 'PUT';
            $this->res = $this->fedora->createResource($this->getMetadata(), $binary, $path, $method);
            $result    = 'not found - created';
        }

        echo self::$debug ? "\t" . $result . " - " . $this->res->getUri(true) . "\n" : "";
        return $result == 'not found - created';
    }

    /**
     * Provides entity binary data.
     * @return type
     */
    protected function getBinaryData(): string {
        return '';
    }

}
