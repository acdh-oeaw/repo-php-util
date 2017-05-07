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

namespace acdhOeaw\util;

use RuntimeException;
use DomainException;
use InvalidArgumentException;
use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasProperty;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Class for importing whole metadata graph into the repository.
 *
 * @author zozlak
 */
class MetadataCollection extends Graph {

    const SKIP   = 1;
    const CREATE = 2;

    /**
     * Makes given resource a proper agent
     * 
     * @param \EasyRdf\Resource $res
     * @return \EasyRdf\Resource
     */
    static public function makeAgent(Resource $res): Resource {
        $res->addResource('http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'http://xmlns.com/foaf/0.1/Agent');

        return $res;
    }

    /**
     * Fedora connection object
     * @var \acdhOeaw\fedora\Fedora
     */
    private $fedora;

    /**
     * Parent resource for all imported graph nodes
     * @var \acdhOeaw\fedora\FedoraResource
     */
    private $resource;

    /**
     * Index anyId => resURI
     * @var array
     */
    private $ids;

    /**
     * Index resURI => ACDH Id
     * @var array
     */
    private $acdhIds;

    /**
     * Creates a new metadata parser.
     * 
     * @param Fedora $fedora
     * @param string $file
     * @param string $format
     */
    public function __construct(Fedora $fedora, string $file,
                                string $format = null) {
        parent::__construct();
        $this->parseFile($file, $format);

        $this->fedora    = $fedora;
    }

    /**
     * Sets the repository resource being parent of all resources in the
     * graph imported by the import() method.
     * 
     * @param FedoraResource $res
     * @see import()
     */
    public function setResource(FedoraResource $res) {
        $this->resource = $res;
    }

    /**
     * Imports the whole graph by looping over all resources.
     * 
     * A repository resource is created for every node containing at least one 
     * cfg:fedoraIdProp property and:
     * - containg at least one other property
     * - or being within $namespace
     * - or when $singleOutNmsp equals to MetadataCollection::CREATE
     * 
     * Resources without cfg:fedoraIdProp property are skipped as we are unable
     * to identify them on the next import (which would lead to duplication).
     * 
     * Resource with a fully qualified URI is considered as having
     * cfg:fedoraIdProp (its URI is taken as cfg:fedoraIdProp property value).
     * 
     * Resources in the graph can denote relationships in any way but all
     * object URIs already existing in the repository and all object URIs in the
     * $namespace will be turned into ACDH ids.
     * This means graph can not contain circular dependencies between resources
     * which do not already exist in the repository, like:
     * ```
     * _:a some:Prop _:b .
     * _:b some:Prop _:a .
     * ```
     * The $errorOnCycle parameter determines behaviour of the method when such
     * a cycle exists in the graph.
     * 
     * @param string $namespace repository resources will be created for all
     *   resources in this namespace
     * @param int $singleOutNmsp should repository resources be created
     *   representing URIs outside $namespace (MetadataCollection::SKIP or
     *   MetadataCollection::CREATE)
     * @param bool $errorOnCycle if error should be thrown if not all resources
     *   were imported due to circular dependencies
     * @param bool $verbose
     * @return array
     * @throws InvalidArgumentException
     */
    public function import(string $namespace, int $singleOutNmsp,
                           bool $errorOnCycle = true, bool $verbose = false): array {
        $dict = array(self::SKIP, self::CREATE);
        if (!in_array($singleOutNmsp, $dict)) {
            throw new InvalidArgumentException('singleOutNmsp parameters must be one of MetadataCollection::SKIP, MetadataCollection::CREATE');
        }

        $this->removeLiteralIds($verbose);
        $this->promoteUrisToIds();
        $this->buildIndex();
        $this->mapUris($namespace, false, $verbose);

        $imported     = array();
        $toBeImported = array_values($this->resources());
        $n            = count($toBeImported);
        while (count($toBeImported) > 0 && $n > 0) {
            $n--;
            $i = $toBeImported[$n];

            if ($this->containsWrongRefs($i, $namespace)) {
                echo $verbose ? "Skipping " . $i->getUri() . " - contains wrong references\n" : "";
                continue;
            }

            echo $verbose ? "Importing " . $i->getUri() . "\n" : "";
            try {
                $res = $this->importResource($i, $namespace, $singleOutNmsp, $verbose);
                $id  = $res->getId();

                $uri                         = $res->getUri(true);
                $imported[$uri]              = $res;
                $this->acdhIds[$uri]         = $id;
                $this->acdhIds[$i->getUri()] = $id;

                $this->mapUris($namespace, false, $verbose);
            } catch (DomainException $e) {
                echo $verbose ? "\t" . $e->getMessage() . "\n" : "";
            } finally {
                array_splice($toBeImported, $n, 1);
                $n = count($toBeImported);
            }
        }
        if (count($toBeImported) > 0 && $errorOnCycle) {
            throw new RuntimeException('graph contains cycles');
        }

        return $imported;
    }

    /**
     * Imports single graph node.
     * 
     * @param Resource $res
     * @param string $namespace
     * @param int $onlyIdsOutNmsp
     * @param bool $verbose
     * @return FedoraResource
     * @throws DomainException
     */
    private function importResource(Resource $res, string $namespace,
                                    int $onlyIdsOutNmsp, bool $verbose): FedoraResource {
        $idProp = RC::idProp();

        if (count($res->allResources($idProp)) == 0) {
            // it does not make sense to process resource without ids 
            // cause we will be unable to match in the future
            throw new DomainException("no ids - skipping");
        } elseif ($this->fedora->isAcdhId($res->getUri())) {
            // ACDH ids by definition can be only objects (and never subjects)
            throw new DomainException("ACDH id resource - skipping");
        }

        list($matches, $inNmsp) = $this->findMatches($res, $namespace);
        $action    = $inNmsp ? self::CREATE : $onlyIdsOutNmsp;
        $inNmsp    = $inNmsp ? "in" : "outside";
        $propCount = count($res->propertyUris());

        // special cases for resource containing only ids
        if ($propCount == 1) {
            if ($this->isIdElsewhere($res)) {
                throw new DomainException("single id assigned to another resource - skipping");
            } elseif ($action == self::SKIP) {
                throw new DomainException("only ids (" . $inNmsp . " namespace) - skipping");
            } elseif (count($matches) == 0 && $action == self::CREATE) {
                // add title
                $res->addLiteral(RC::titleProp(), $res->getResource($idProp));
            }
        }

        $res = $this->sanitizeResource($res, $namespace);

        if (count($matches) == 1) {
            $repoRes = array_pop($matches);
            echo $verbose ? "\tupdating " . $repoRes->getUri(true) . "\n" : "";

            $meta = $repoRes->getMetadata();
            $meta->merge($res, array($idProp));

            $repoRes->setMetadata($meta);
            $repoRes->updateMetadata();
        } else {
            echo $verbose ? "\tcreating " : "";
            $repoRes = $this->fedora->createResource($res);
            echo $verbose ? $repoRes . "\n" : "";
        }
        return $repoRes;
    }

    /**
     * Checks if a given resource is a cfg:fedoraIdProp of some other node in
     * the graph.
     * 
     * @param Resource $res
     * @return bool
     */
    private function isIdElseWhere(Resource $res): bool {
        $idProp = RC::idProp();

        $revMatches = $this->reversePropertyUris($res);
        foreach ($revMatches as $prop) {
            if ($prop != $idProp) {
                continue;
            }
            $matches = $this->resourcesMatching($prop, $res);
            foreach ($matches as $i) {
                if ($i->getUri() != $res->getUri()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Checks if a node contains wrong esdges (blank ones or non-id edges
     * pointing to ids in $namespace but not being ACDH ids).
     * 
     * @param Resource $res
     * @param string $namespace
     * @return boolean
     */
    private function containsWrongRefs(Resource $res, string $namespace): bool {
        $idProp = RC::idProp();
        foreach ($res->propertyUris() as $prop) {
            if ($prop == $idProp) {
                continue;
            }
            foreach ($res->allResources($prop) as $val) {
                $valUri   = $val->getUri();
                $inNmsp   = strpos($valUri, $namespace) === 0;
                $isAcdhId = $this->fedora->isAcdhId($valUri);
                if ($val->isBNode() || ($inNmsp && !$isAcdhId)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Changes all URIs into ACDH ids of corresponding repository resources
     * 
     * @param string $namespace
     * @param bool $force
     * @param bool $verbose
     * @throws RuntimeException
     */
    private function mapUris(string $namespace, bool $force, bool $verbose) {
        $idProp = RC::idProp();

        echo $verbose ? "Mapping URIs to ACDH ids...\n" : "";
        $nothingToDo = true;

        foreach ($this->resources() as $res) {
            foreach ($res->propertyUris() as $prop) {
                if ($prop == $idProp) {
                    continue;
                }

                foreach ($res->allResources($prop) as $val) {
                    $uri = $val->getUri();

                    // already points to ACDH id
                    if ($this->fedora->isAcdhId($uri)) {
                        continue;
                    }

                    $known  = isset($this->ids[$uri]);
                    $inNmsp = strpos($uri, $namespace) === 0 || $val->isBNode();
                    if (!$known && $inNmsp && $force) {
                        throw new RuntimeException('uri ' . $val . ' can not be resolved to known resource');
                    } elseif (!$known || !$inNmsp) {
                        continue;
                    }

                    $targetUri = $this->ids[$uri];
                    if (isset($this->acdhIds[$targetUri]) || isset($this->acdhIds[$uri])) {
                        $targetId = isset($this->acdhIds[$targetUri]) ? $this->acdhIds[$targetUri] : $this->acdhIds[$uri];
                        $res->delete($prop, $val);
                        $res->addResource($prop, $targetId);
                        if ($verbose) {
                            echo "\tswitching " . $val . " into " . $targetId . "\n";
                        }
                    } elseif ($verbose) {
                        echo "\t" . $uri . " should be replaced by target resurce's ACDH id but it is not yet known (" . $targetUri . ")\n";
                    }
                    $nothingToDo = false;
                }
            }
        }
        echo $verbose && $nothingToDo ? "\t...nothing to map\n" : "";
    }

    /**
     * Finds repository resources matching given one.
     * 
     * @param Resource $res
     * @param string $namespace
     * @return type
     */
    private function findMatches(Resource $res, string $namespace) {
        $idProp = RC::idProp();

        $matches = array();
        $inNmsp  = false;
        foreach ($res->allResources($idProp) as $id) {
            $inNmsp = $inNmsp || strpos($id, $namespace) === 0;

            $id = $id->getUri();
            if (!isset($this->ids[$id]) || $this->ids[$id] === '_') {
                continue;
            }
            if (!isset($matches[$this->ids[$id]])) {
                $matches[$this->ids[$id]] = $this->fedora->getResourceByUri($this->ids[$id]);
            }
        }
        return array(array_values($matches), $inNmsp);
    }

    /**
     * Promotes subjects being fully qualified URLs to ids.
     */
    private function promoteUrisToIds() {
        $idProp = RC::idProp();

        foreach ($this->resources() as $i) {
            if (!$i->isBNode()) {
                $i->addResource($idProp, $i->getUri());
            }
        }
    }

    /**
     * Builds index used by findMatches()
     * 
     * @throws RuntimeException
     * @see findMatches()
     */
    private function buildIndex() {
        $idProp = RC::idProp();
        $idNmsp = RC::idNmsp();

        $this->ids     = array();
        $this->acdhIds = array();

        $query = new Query();
        $param = (new HasProperty(RC::idProp()))->
            setSubVar('?res')->
            setObjVar('?id');
        $query->addParameter($param);

        $ids = $this->fedora->runQuery($query);
        foreach ($ids as $i) {
            $i->id  = $i->id->getUri();
            $i->res = $i->res->getUri();

            $this->ids[$i->id] = $i->res;
            if (strpos($i->id, $idNmsp) === 0) {
                $this->acdhIds[$i->res] = $i->id;
            }
        }

        foreach ($this->resourcesMatching($idProp) as $i) {
            $matched = array();
            foreach ($i->allResources($idProp) as $j) {
                $j = $j->getUri();

                $matches = $this->fedora->getResourcesById($j);
                if (count($matches) > 1) {
                    throw new RuntimeException('repository inconsitent');
                } elseif (count($matches) == 1) {
                    $res                     = $matches[0];
                    $matched[$res->getUri()] = '';

                    $this->ids[$j]                     = $res->getUri();
                    $this->acdhIds[$res->getUri(true)] = $res->getId();
                    $this->acdhIds[$i->getUri()]       = $res->getId();
                } else {
                    $this->ids[$j] = '_';
                }
            }
            if (count($matched) > 1) {
                throw new RuntimeException('repository inconsitent');
            }

            if ($i->isBNode()) {
                $matched                 = array_keys($matched);
                $this->ids[$i->getUri()] = count($matched) > 0 ? $matched[0] : '_';
            }
        }
    }

    /**
     * Cleans up resource metadata.
     * 
     * @param Resource $res
     * @param string $namespace
     * @return Resource
     * @throws InvalidArgumentException
     */
    private function sanitizeResource(Resource $res, string $namespace): Resource {
        if ($this->containsWrongRefs($res, $namespace)) {
            throw new InvalidArgumentException('resource contains references to blank nodes');
        }

        if ($res->isA('http://xmlns.com/foaf/0.1/Person') || $res->isA('http://xmlns.com/foaf/0.1/Agent')) {
            $res = self::makeAgent($res);
        }

        if ($this->resource !== null) {
            $res->addResource(RC::relProp(), $this->resource->getId());
        }

        return $res;
    }

    /**
     * Removes literal ids from the graph.
     * 
     * @param bool $verbose
     */
    private function removeLiteralIds(bool $verbose = true) {
        echo $verbose ? "Removing literal ids...\n" : "";

        $idProp = RC::idProp();
        foreach ($this->resources() as $i) {
            foreach ($i->allLiterals($idProp) as $j) {
                $i->delete($idProp, $j);
                if ($verbose) {
                    echo "\tremoved " . $j . " from " . $i->getUri() . "\n";
                }
            }
        }
    }

}