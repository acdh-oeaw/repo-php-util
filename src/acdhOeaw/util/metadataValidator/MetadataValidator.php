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

namespace acdhOeaw\util\metadataValidator;

use RuntimeException;
use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\metadataValidator\Error;

/**
 * Validates metadata (EasyRdf\Graph or EasyRdf\Resource) against the ontology
 * in the Fedora.
 *
 * @author zozlak
 */
class MetadataValidator {

    const RDFS_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';

    /**
     * Stores RDF properties definitions fetched from the repository.
     * @var array
     */
    static private $cache = array();

    /**
     * Stores inheritence list for all classes as a map. Key is class URI,
     * value is an array of inherited class URIs (including class URI).
     * @var array 
     */
    static private $classes;

    /**
     * Checks a given RDF graph against ontology in a given Fedora repository.
     * @param Graph $graph RDF graph to be checked
     * @param Fedora $fedora repository connection object
     * @return array list of errors
     */
    static public function validateGraph(Graph $graph, Fedora $fedora): array {
        $results = array();
        foreach ($graph->resources() as $i) {
            $results = array_merge($results, self::validateResource($i, $fedora));
        }
        return $results;
    }

    /**
     * Checks a given EasyRdf\Resource against ontology in a given repository.
     * @param Resource $res
     * @param Fedora $fedora repository connection object
     * @return array list of errors
     */
    static public function validateResource(Resource $res, Fedora $fedora): array {
        $results = array();
        foreach ($res->propertyUris() as $prop) {
            if ($prop === self::RDFS_TYPE) {
                $tmp = self::checkClass($res, $fedora);
            } else {
                $tmp = self::checkProperty($prop, $res, $fedora);
            }
            $results = array_merge($results, $tmp);
        }
        return $results;
    }

    /**
     * Checks a given FedoraResource. Simple overlay on top of the 
     * validateResource() method.
     * @param FedoraResource $res
     * @return array list of errors
     */
    static public function validateFedoraResource(FedoraResource $res): array {
        return self::validateResource($res->getMetadata(), $res->getFedora());
    }

    /**
     * Pretty prints a list of errors.
     * @param array $results list of errors
     * @param string $format print format: tsv or text
     */
    static public function printResults(array $results, string $format = 'text') {
        if ($format == 'tsv') {
            echo "subject\tmessage\tproperty\tvalue\n";
            foreach ($results as $i) {
                echo $i . "\n";
            }
        } else {
            $len = array(9, 9, 10, 7);
            foreach ($results as $i) {
                $len[0] = max($len[0], mb_strlen($i->resUri) + 2);
                $len[1] = max($len[1], mb_strlen($i->message) + 2);
                $len[2] = max($len[2], mb_strlen($i->property) + 2);
                $len[3] = max($len[3], mb_strlen($i->value) + 2);
            }
            $format = sprintf("%% %ds%% %ds%% %ds%% %ds\n", $len[0], $len[1], $len[2], $len[3]);
            printf($format, 'subject', 'message', 'property', 'value');
            foreach ($results as $i) {
                printf($format, $i->resUri, $i->message, $i->property, $i->value);
            }
        }
    }

    /**
     * Checks if a resource is of known class
     * @param Resource $res resource to be checked
     * @param Fedora $fedora repository connection object
     * @return array list of errors
     */
    static private function checkClass(Resource $res, Fedora $fedora): array {
        if (self::$classes === null) {
            self::loadClasses($fedora);
        }
        $ret = array();
        foreach ($res->allResources(self::RDFS_TYPE) as $i) {
            $i = $i->getUri();
            if (!isset(self::$classes[$i])) {
                $ret[] = new Error($res->getUri(), 'unknown class', $i);
            }
        }
        return $ret;
    }

    /**
     * Checks if a given property is defined in the ontology and if all its
     * values for a given resource match property definition provided by the
     * ontology.
     * @param string $property RDF property to be checked
     * @param Resource $res resource containing given property values
     * @param Fedora $fedora repository connection object
     * @return array list of errors 
     * @throws RuntimeException
     */
    static private function checkProperty(string $property, Resource $res,
                                          Fedora $fedora): array {
        $uri = $res->getUri();
        $ret = array();
        try {
            $propDef = self::getPropertyDef($property, $fedora);

            if (!self::checkRangeDomain($res, $propDef->getDomain())) {
                $ret[] = new Error($uri, 'wrong domain', '', $property);
            }

            list($matchType, $wrongType) = $propDef->fetchValues($res);
            foreach ($wrongType as $i) {
                $ret[] = new Error($uri, 'wrong value type (literal/uri)', (string) $i, $property);
            }
            foreach ($matchType as $i) {
                if (!self::checkRangeDomain($i, $propDef->getRange())) {
                    $ret[] = new Error($uri, 'wrong range', $i->getUri(), $property);
                }
            }
        } catch (RuntimeException $e) {
            switch ($e->getCode()) {
                case 10:
                    $ret[] = new Error($uri, 'unknown property', '', $property);
                    break;
                case 11:
                    $ret[] = new Error($uri, 'wrongly defined property', '', $property);
                    break;
                case 12:
                    $ret[] = new Error($uri, 'wrongly defined property', '', $property);
                    break;
                default:
                    throw $e;
            }
        }
        return $ret;
    }

    /**
     * Fetches property definition from cache.
     * Creates a deifinition if it does not exist.
     * @param string $property RDF property URI
     * @param Fedora $fedora repository connection object
     * @return \acdhOeaw\util\metadataValidator\OntologyProperty
     */
    static private function getPropertyDef(string $property, Fedora $fedora): OntologyProperty {
        if (!isset(self::$cache[$property])) {
            self::$cache[$property] = new OntologyProperty($property, $fedora);
        }
        return self::$cache[$property];
    }

    /**
     * Checks if a given subject/value is of a given class taking into account
     * class inheritance.
     * 
     * Literal values are not checked at the moment!
     * @param type $res subject/value to be checked
     * @param string $class class a subject/value should match
     * @return bool
     */
    static private function checkRangeDomain($res, string $class): bool {
        if ($class == '') {
            return true;
        }

        if (is_a($res, 'EasyRdf\Literal')) {
            //TODO check xsd types against literal values
            return true;
        } else {
            $classes = array();
            foreach ($res->allResources(self::RDFS_TYPE) as $i) {
                $i         = $i->getUri();
                $classes[] = $i;
                if (isset(self::$classes[$i])) {
                    $classes = array_merge($classes, self::$classes[$i]);
                }
            }
            return in_array($class, $classes);
        }
    }

    /**
     * Loads classes defined in the ontology.
     * @param Fedora $fedora repository connection object
     */
    static private function loadClasses(Fedora $fedora) {
        $ontologyLoc = RC::get('fedoraApiUrl') . '/' . RC::get('doorkeeperOntologyLocation') . '/';

        $query = new SimpleQuery("
            select ?fRes ?id ?parent
            where {
                ?fRes a <http://www.w3.org/2002/07/owl#Class> .
                ?fRes ?@ ?id .
                optional { ?fRes <http://www.w3.org/2000/01/rdf-schema#subClassOf> / (^?@ / <http://www.w3.org/2000/01/rdf-schema#subClassOf>)* ?parent . }
                filter regex(str(?fRes), ?#)
            }
        ");
        $query->setValues(array(RC::idProp(), RC::idProp(), $ontologyLoc));
        $res   = $fedora->runQuery($query);

        self::$classes = array();
        foreach ($res as $i) {
            $id = $i->id->getUri();
            if (!isset(self::$classes[$id])) {
                self::$classes[$id] = array($id);
            }
            if (isset($i->parent) && $i->parent) {
                self::$classes[$id][] = $i->parent->getUri();
            }
        }
    }

}
