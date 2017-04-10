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
    
    public function assureTitles(string $titleProp) {
        foreach ($this->resources() as $i) {
            if ($i->getLiteral($titleProp) === null) {
                $i->addLiteral($titleProp, $i->getUri());
            }
        }
    }
    
    public function removeLiteralIds() {
        $idProp = $this->fedora->getIdProp();
        foreach ($this->resources() as $i) {
            foreach ($i->allLiterals($idProp) as $j) {
                $i->delete($idProp, $j);
            }
        }
    }

    public function import() {
        $idProp = $this->fedora->getIdProp();

        foreach ($this->resources() as $i) {
            echo $i->getUri() . "\n";

            if (count($i->properties()) == 0) {
                echo "\tno properties - skipping\n";
                continue;
            }

            if ($i->isA('http://xmlns.com/foaf/0.1/Person') || $i->isA('http://xmlns.com/foaf/0.1/Agent')) {
                $i = self::makeAgent($i);
            }

            // promote fully qualified resource URI to id
            if (!$i->isBNode()) {
                $i->addResource($idProp, $i->getUri());
            }

            // it does not make sense to process resource without ids 
            // cause we will be unable to match in the future
            if (count($i->allResources($idProp)) == 0) {
                echo "\tno ids - skipping\n";
                continue;
            }

            if ($this->resource !== null) {
                $i->addResource($this->fedora->getRelProp(), $this->resource->getId());
            }
            
            // find matching resources
            $matches = array();
            foreach ($i->allResources($idProp) as $id) {
                foreach ($this->fedora->getResourcesById($id) as $res) {
                    $matches[$res->getUri(true)] = $res;
                }
            }

            if (count($matches) > 1) {
                throw new RuntimeException('many matching resources for ' . $i->getUri());
            } elseif (count($matches) == 1) {
                echo "\tfound\n";
                $res = array_pop($matches);
                
                // merge metadata assuring ids are kept
                $ids = $res->getMetadata()->allResources($idProp);
                $meta = EasyRdfUtil::mergeMetadata($res->getMetadata(), $i);
                foreach ($ids as $id) {
                    $meta->addResource($idProp, $id->getUri());
                }
                
                $res->setMetadata($meta);
                $res->updateMetadata();
            } else {
                echo "\tcreate\n";
//echo EasyRdfUtil::cloneResource($i)->getGraph()->serialise('ntriples');
                $res = $this->fedora->createResource($i);
            }
        }
    }

}
