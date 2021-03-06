<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\util;

use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Ontology cache class.
 * 
 * Fetching full ontology (resolving class and property inheritance) from a 
 * triplestore is time consuming. At the same time the ontology changes rarely.
 * Thus caching is a perfect solution.
 * 
 * The ontology is cached in a JSON file (path is taken from the 
 * `repoConfig:ontologyCacheFile` config property). It is automatically refreshed
 * if the repository resource storing the ontology owl (as set in the
 * `repoConfig:ontologyResId`) modification date is newer then the cache file
 * modification time.
 *
 * @author zozlak
 */
class Ontology {

    const FEDORA_LAT_MOD = '<http://fedora.info/definitions/v4/repository#lastModified>';

    private $classes             = [];
    private $properties          = [];
    private $repoObjectClasses   = [];
    private $sharedObjectClasses = [];
    private $containerClasses    = [];

    /**
     * 
     * @param Fedora $fedora repository connection object
     */
    public function __construct(Fedora $fedora) {
        $query   = "
                SELECT ?date WHERE {
                    ?o ?@ ?@ .
                    ?o ?@ ?date
                }
            ";
        $param   = [RC::idProp(), RC::get('ontologyResId'), self::FEDORA_LAT_MOD];
        $query   = new SimpleQuery($query, $param);
        $results = $fedora->runQuery($query);
        $refDate = $results[0]->date;

        $cacheFile    = RC::get('ontologyCacheFile');
        $cacheInvalid = !file_exists($cacheFile) || date('Y-m-d\TH:i:s', filemtime($cacheFile) - 60) < $refDate;
        if ($cacheInvalid) {
            $this->getFromTriplestore($fedora, $cacheFile);
        } else {
            $this->getFromFile($cacheFile);
        }
    }

    /**
     * Returns list of classes inheriting from `repoConfig:fedoraRepoObjectClass`
     * @return array
     */
    public function getRepoObjectClasses(): array {
        return $this->repoObjectClasses;
    }

    public function getSharedObjectClasses(): array {
        return $this->sharedObjectClasses;
    }

    public function getContainerClasses(): array {
        return $this->containerClasses;
    }

    /**
     * Returns class description. It is an object containing following properties:
     * 
     * - `class` class name URI
     * - `clasess` array of inherited class name URIs
     * - `properties` object descriping class properties indexed with property
     *   URIs each property is described by an object with a following structure:
     *     - `property` property URI
     *     - `range` property range (class URI)
     *     - `min` minimum cardinality
     *     - `max` maximum cardinality
     * @param string $class class name URI
     * @return \stdClass
     */
    public function getClass(string $class) {
        return $this->classes->$class ?? null;
    }

    /**
     * Returns property description. It is an object containing following properties:
     * 
     * - `property` - property URI
     * - `range` - property range (class URI)
     * @param string $property property URI
     * @return \stdClass
     */
    public function getProperty(string $property) {
        return $this->properties->$property ?? null;
    }

    /**
     * 
     * @param string $file
     */
    private function getFromFile(string $file) {
        $cache = json_decode(file_get_contents($file));
        foreach ($cache as $k => $v) {
            $this->$k = $v;
        }
    }

    /**
     * 
     * @param Fedora $fedora
     * @param string $file
     */
    private function getFromTriplestore(Fedora $fedora, string $file) {
        $propQuery = '
            prefix owl: <http://www.w3.org/2002/07/owl#>
            prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#>
            select distinct 
              ?domain 
              (coalesce(?propId1, ?propId2) as ?propId) 
              ?rec
              (coalesce(?onClass, ?onDataRange, ?range) as ?range)
              (coalesce(?minQCard, ?minCard, ?card, 0) as ?min)
              (coalesce(?maxQCard, ?maxCard, ?card, 9999) as ?max)
            where {
              ?class ?@ ?@ .
              ?class ?@ / (^?@ / rdfs:subClassOf)* / ^rdfs:domain ?prop .
              ?prop  rdfs:range ?range .
              ?prop ?@ ?exPropId .
              ?prop rdfs:domain ?domain .
              optional {?prop ?@ ?rec .}
              optional {
                ?prop ?@ ?propId1 .
                ?prop ?@ ?propTitle1 .
                minus {
                  ?prop ?@ "true"^^xsd:boolean .
                  ?prop ?@ ?propId1 .
                }
              }
              optional {
                ?prop  rdfs:subPropertyOf / ^?@ ?parentProp .
                ?parentProp ?@ ?propId2 . 
                ?parentProp ?@ ?propTitle2 .
              }
              optional {
                ?class (rdfs:subClassOf / ^?@)+ ?restr .
                ?restr a owl:Restriction .
                ?restr owl:onProperty ?exPropId .
                optional {?restr owl:onClass ?onClass .}
                optional {?restr owl:onDataRange ?onDataRange .}
                optional {?restr owl:minCardinality ?minCard .}
                optional {?restr owl:maxCardinality ?maxCard .}
                optional {?restr owl:cardinality ?card .}
                optional {?restr owl:minQualifiedCardinality ?minQCard .}
                optional {?restr owl:maxQualifiedCardinality ?maxQCard .}
                optional {?restr owl:qualifiedCardinality ?qCard .}
              }
            }
            order by ?domain ?propId ?range
        ';
        $propParam = [
            RC::idProp(), '',
            RC::idProp(), RC::idProp(), RC::idProp(), RC::get('fedoraRecommendedProp'),
            RC::idProp(), RC::titleProp(), RC::get('fedoraRecommendedProp'), RC::idProp(),
            RC::idProp(), RC::idProp(), RC::titleProp(),
            RC::idProp()
        ];

        $query   = '
            prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#>

            SELECT DISTINCT ?classId ?superClassId
            WHERE {
              ?superClass (?@ / ^rdfs:subClassOf)* ?class .
              OPTIONAL {
                ?class ?@ ?classId .
                ?superClass ?@ ?superClassId .
              }
              filter(regex(str(?classId), ?#) && !regex(str(?superClassId), ?#))
            }
            ORDER BY ?class ?superClass
        ';
        $param   = [
            RC::idProp(), RC::idProp(), RC::idProp(),
            '^' . RC::get('fedoraVocabsNamespace'), '^' . RC::get('fedoraRestrictionsNamespace')
        ];
        $query   = new SimpleQuery($query, $param);
        $results = $fedora->runQuery($query);
        foreach ($results as $i) {
            $class = (string) $i->classId;
            if (!isset($this->classes[$class])) {
                $this->classes[$class] = (object) [
                        'class'      => $class,
                        'classes'    => [],
                        'properties' => []
                ];
                $c                     = &$this->classes[$class];

                $propParam[1] = $class;
                $query        = new SimpleQuery($propQuery, $propParam);
                $props        = $fedora->runQuery($query);
                foreach ($props as $j) {
                    $prop            = (string) $j->propId;
                    $c->properties[] = (object) [
                            'property'    => (string) $j->propId,
                            'recommended' => isset($j->rec),
                            'range'       => (string) $j->range,
                            'min'         => (int) ((string) $j->min),
                            'max'         => (int) ((string) $j->max)
                    ];

                    if (!isset($this->properties[$prop])) {
                        $this->properties[$prop] = (object) [
                                'property' => $prop,
                                'range'    => (string) $j->range
                        ];
                    }
                }
            }
            $c            = &$this->classes[$class];
            $c->classes[] = (string) $i->superClassId;

            unset($c);
        }

        foreach ($this->classes as $i) {
            if (in_array(RC::get('fedoraRepoObjectClass'), $i->classes)) {
                $this->repoObjectClasses[] = $i->class;
            }
            if (in_array(RC::get('fedoraSharedObjectClass'), $i->classes)) {
                $this->sharedObjectClasses[] = $i->class;
            }
            if (in_array(RC::get('fedoraContainerClass'), $i->classes)) {
                $this->containerClasses[] = $i->class;
            }
        }

        file_put_contents($file, json_encode([
            'classes'             => $this->classes,
            'properties'          => $this->properties,
            'repoObjectClasses'   => $this->repoObjectClasses,
            'sharedObjectClasses' => $this->sharedObjectClasses,
            'containerClasses'    => $this->containerClasses,
        ]));
        $this->getFromFile($file); // to make sure 
    }

}
