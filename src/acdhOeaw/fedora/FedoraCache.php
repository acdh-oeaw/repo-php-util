<?php

/**
 * The MIT License
 *
 * Copyright 2016 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\fedora;

use EasyRdf\Resource;
use GuzzleHttp\Exception\ClientException;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\AlreadyInCache;
use acdhOeaw\fedora\exceptions\CacheInconsistent;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\exceptions\NotInCache;
use acdhOeaw\schema\Object;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Provides FedoraResources cache (e.g. for a given Fedora object)
 *
 * @author zozlak
 */
class FedoraCache {

    /**
     * Id to URI mappings
     * @var array
     */
    private $id2uri = array();

    /**
     * URI to id mappings
     * @var array
     */
    private $uri2id = array();

    /**
     * Resources cache
     * @var array
     */
    private $cache = array();

    public function __construct() {
        
    }

    /**
     * Removes given resource from cache.
     * @param string $uri resource URI
     */
    public function deleteByUri(string $uri) {
        if (!isset($this->cache[$uri])) {
            throw new NotInCache();
        }
        foreach ($this->uri2id[$uri] as $id) {
            unset($this->id2uri[$id]);
        }
        unset($this->uri2id[$uri]);
        unset($this->cache[$uri]);
    }

    public function deleteById(string $id) {
        if (!isset($this->id2uri[$id])) {
            throw new NotInCache();
        }
        $this->deleteByUri($this->id2uri[$id]);
    }

    public function delete(FedoraResource $res) {
        $this->deleteByUri($res->getUri(true));
    }

    public function reload(FedoraResource $res) {
        if (isset($this->cache[$res->getUri(true)])) {
            $this->delete($res);
        }
        $this->add($res);
    }

    public function add(FedoraResource $res) {
        $uri = $res->getUri(true);
        if (isset($this->cache[$uri])) {
            throw new AlreadyInCache();
        }
        try {
            $ids                = $res->getIds();
            $this->cache[$uri]  = $res;
            $this->uri2id[$uri] = array();
            foreach ($ids as $id) {
                $this->uri2id[$uri][] = $id;
                $this->id2uri[$id]    = $uri;
            }
        } catch (ClientException $e) {
            switch ($e->getCode()) {
                case 404:
                    throw new NotFound();
                case 410:
                    throw new Deleted();
                default:
                    throw $e;
            }
        }
    }

    public function getById(string $id): FedoraResource {
        if (!isset($this->id2uri[$id])) {
            throw new NotInCache();
        }
        return $this->cache[$this->id2uri[$id]];
    }

    public function getByUri(string $uri): FedoraResource {
        if (!isset($this->cache[$uri])) {
            throw new NotInCache();
        }
        return $this->cache[$uri];
    }

    public function getByMeta(Resource $meta): FedoraResource {
        $matches = array();
        foreach ($meta->allResources(RC::idProp()) as $id) {
            try {
                $match                         = $this->getById($id->getUri());
                $matches[$match->getUri(true)] = $match;
            } catch (NotInCache $e) {
                
            }
        }
        switch (count($matches)) {
            case 0:
                throw new NotInCache();
            case 1:
                return array_pop($matches);
            default:
                throw new CacheInconsistent();
        }
    }

    public function getByObj(Object $obj): FedoraResource {
        return $this->getByMeta($obj->getMetadata());
    }

}
