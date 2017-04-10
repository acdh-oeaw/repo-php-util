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
use InvalidArgumentException;
use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;

/*
  TODO
  - dopisać sprawdzanie unikalności locationpath w doorkeeperze

  - iterujemy się przez wszystkie resource w grafie
  - jesli nie są blank nodes, to promujemy subject do dct:identifier
  - sprawdzamy, czy mają jakiś dct:identifier - jeśli nie, rzucamy błędem
  - próbujemy im dopasować resource w repozytorium
  - jeśli więcej niż jeden, rzucamy błędem
  - jeśli dokładnie jeden, aktualizujemy
  - jeśli brak, to dodajemy
  - dać możliwość "dry run"
  - przesunąć wszystkie ACDH ID do https://id.acdh.oeaw.ac.at/uuid/
 */

/**
 * Description of Graph
 *
 * @author zozlak
 */
class MetadataCollection extends Graph {

    const SKIP = 1;
    const CREATE = 2;
    const UPDATE = 3;

    /**
     * Assures a resource will have foaf:name and dc:title
     * 
     * @param \EasyRdf\Resource $res
     * @return \EasyRdf\Resource
     */
    static public function makeAgent(Resource $res): Resource {
        $name = $res->getLiteral('http://xmlns.com/foaf/0.1/name');
        $given = $res->getLiteral('http://xmlns.com/foaf/0.1/givenName');
        $family = $res->getLiteral('http://xmlns.com/foaf/0.1/familyName');
        $title = trim($given . ' ' . $family);
        $title = trim($title ? $title : $name);

        $res->delete('http://xmlns.com/foaf/0.1/name');
        $res->addLiteral('http://xmlns.com/foaf/0.1/name', $title);
        $res->delete('http://purl.org/dc/elements/1.1/title');
        $res->addLiteral('http://purl.org/dc/elements/1.1/title', $title);

        $res->addResource('http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'http://xmlns.com/foaf/0.1/Agent');

        return $res;
    }

    /**
     *
     * @var \acdhOeaw\fedora\Fedora
     */
    private $fedora;

    /**
     *
     * @var \acdhOeaw\fedora\FedoraResource
     */
    private $resource;

    public function __construct(Fedora $fedora, string $file, string $format = null) {
        parent::__construct();
        $this->parseFile($file, $format);

        $this->fedora = $fedora;
    }

    public function setResource(FedoraResource $res) {
        $this->resource = $res;
    }

    /**
     * Removes literal ids from the graph.
     * 
     * @param bool $verbose
     */
    public function removeLiteralIds(bool $verbose = true) {
        if ($verbose) {
            echo "removing literal ids...\n";
        }
        $idProp = $this->fedora->getIdProp();
        foreach ($this->resources() as $i) {
            foreach ($i->allLiterals($idProp) as $j) {
                $i->delete($idProp, $j);
                if ($verbose) {
                    echo "\tremoved " . $j . " from " . $i->getUri() . "\n";
                }
            }
        }
    }

    /**
     * Imports the whole graph by looping over all resources.
     * 
     * @param string $namespace
     * @param int $singleInNmsp
     * @param int $singleOutNmsp
     * @param bool $verbose
     * @return array
     * @throws InvalidArgumentException
     */
    public function import(string $namespace, int $singleInNmsp, int $singleOutNmsp, bool $verbose = true): array {
        $dict = array(self::SKIP, self::CREATE, self::UPDATE);
        if (!in_array($singleInNmsp, $dict) || !in_array($singleOutNmsp, $dict)) {
            throw new InvalidArgumentException('singleInNmsp and singleOutNmsp parameters must be one of MetadataCollection::SKIP, MetadataCollection::CREATE and MetadataCollection::UPDATE');
        }

        $ids = $this->sanitizeIds();

        $resources = array();
        foreach ($this->resources() as $i) {
            if ($verbose) {
                echo $i->getUri() . "\n";
            }

            try {
                $resources[] = $this->importResource($i, $namespace, $singleInNmsp, $singleOutNmsp, $ids, $verbose);
            } catch (DomainException $e) {
                if ($verbose) {
                    echo "\t" . $e->getMessage() . "\n";
                }
            }
        }
        return $resources;
    }

    /**
     * Imports a single resource.
     * 
     * @param Resource $res
     * @param string $namespace
     * @param int $singleInNmsp
     * @param int $singleOutNmsp
     * @param array $ids
     * @return FedoraResource
     * @throws DomainException
     * @throws RuntimeException
     */
    private function importResource(Resource $res, string $namespace, int $singleInNmsp, int $singleOutNmsp, array $ids, bool $verbose): FedoraResource {
        $idProp = $this->fedora->getIdProp();

        if (count($res->allResources($idProp)) == 0) {
            // it does not make sense to process resource without ids 
            // cause we will be unable to match in the future
            throw new DomainException("no ids - skipping");
        } elseif (isset($ids[$res->getUri()])) {
            // ACDH ids by definition can be only objects (and never subjects)
            throw new DomainException("id resource - skipping");
        }

        list($matches, $inNmsp) = $this->findMatches($res, $namespace);
        $propCount = count($res->propertyUris());

        // special cases for resource containing only ids
        if ($propCount == 1 && $inNmsp && $singleInNmsp == self::SKIP) {
            throw new DomainException("only ids (in namespace) - skipping");
        } elseif ($propCount == 1 && !$inNmsp && $singleOutNmsp == self::SKIP) {
            throw new DomainException("only ids (outside namespace) - skipping");
        }

        $res = $this->sanitizeResource($res);

        if (count($matches) == 1) {
            if ($propCount == 1 && $inNmsp && $singleInNmsp != self::UPDATE) {
                throw new DomainException("found (in namespace) - skipping update");
            } elseif ($propCount == 1 && !$inNmsp && $singleOutNmsp != self::UPDATE) {
                throw new DomainException("found (outside namespace) - skipping update");
            }
            
            $repoRes = array_pop($matches);
            echo $verbose ? "\tupdating " . $repoRes->getUri(true) . "\n" : "";
            $meta = $this->mergeMetadata($repoRes->getMetadata(), $res);
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
     * Cleans up id properties in the graph.
     * 
     * Especially promotes subjects being fully qualified URLs to ids.
     * @return array
     */
    private function sanitizeIds(): array {
        $idProp = $this->fedora->getIdProp();

        $ids = array();
        foreach ($this->resources() as $i) {
            // promote fully qualified resource URI to id
            if (!$i->isBNode()) {
                $i->addResource($idProp, $i->getUri());
            }
            foreach ($i->allResources($idProp) as $j) {
                $ids[$j->getUri()] = '';
            }
        }
        return $ids;
    }

    /**
     * Merges metadata preservind ids from the old metadata.
     * 
     * @param Resource $old
     * @param Resource $new
     * @return Resource
     */
    private function mergeMetadata(Resource $old, Resource $new): Resource {
        $idProp = $this->fedora->getIdProp();
        $ids = $old->allResources($idProp);
        $new = EasyRdfUtil::mergeMetadata($old, $new);
        foreach ($ids as $id) {
            $new->addResource($idProp, $id->getUri());
        }
        return $new;
    }

    /**
     * Cleans up resource metadata.
     * 
     * @param Resource $res
     * @return Resource
     */
    private function sanitizeResource(Resource $res): Resource {
        if ($res->isA('http://xmlns.com/foaf/0.1/Person') || $res->isA('http://xmlns.com/foaf/0.1/Agent')) {
            $res = self::makeAgent($res);
        }

        if ($this->resource !== null) {
            $res->addResource($this->fedora->getRelProp(), $this->resource->getId());
        }

        return $res;
    }

    /**
     * Finds matching resources already existing in the repository.
     * 
     * @param Resource $res
     * @param string $namespace
     * @return array
     * @throws \RuntimeException
     */
    private function findMatches(Resource $res, string $namespace): array {
        $idProp = $this->fedora->getIdProp();
        $ids = $res->allResources($idProp);

        $matches = array();
        $inNmsp = false;
        foreach ($ids as $id) {
            $nmspTmp = strpos($id, $namespace) === 0;
            foreach ($this->fedora->getResourcesById($id) as $res) {
                $uri = $res->getUri(true);
                $matches[$uri] = $res;
                if (count($matches) > 1) {
                    throw new RuntimeException('many matching resources for id ' . $id);
                }
            }
            $inNmsp = $inNmsp || $nmspTmp;
        }

        return array($matches, $inNmsp);
    }

}
