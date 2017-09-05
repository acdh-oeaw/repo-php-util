<?php

/**
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
 * 
 * @package repo-php-util
 * @copyright (c) 2017, Austrian Centre for Digital Humanities
 * @license https://opensource.org/licenses/MIT
 */

namespace acdhOeaw\util\metadataValidator;

use RuntimeException;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Represents an ontology property
 *
 * @author zozlak
 */
class OntologyProperty {

    /**
     * SPARQL query fetching property definition.
     * @var string
     */
    static private $propQuery = "
        select ?fRes ?domain ?range
        where {
            ?fRes a ?@ .
            ?fRes ?@ ?@ .
            optional { ?fRes <http://www.w3.org/2000/01/rdf-schema#domain> ?domain . }
            optional { ?fRes <http://www.w3.org/2000/01/rdf-schema#range> ?range . }
            filter regex(str(?fRes), ?#)
        }
    ";

    /**
     * Property URI
     * @var string
     */
    private $property;

    /**
     * Is property an owl:ObjectProperty?
     * @var bool
     */
    private $object;

    /**
     * Property's rdfs:domain URI
     * @var string
     */
    private $domain = '';

    /**
     * Property's rdfs:range URI
     * @var string
     */
    private $range = '';

    /**
     * Creates RDF property object by fetching its definition form the repository.
     * @param string $property RDF property URI
     * @param Fedora $fedora repository connection object
     * @throws RuntimeException
     */
    public function __construct(string $property, Fedora $fedora) {
        $this->property = $property;

        $query       = new SimpleQuery(self::$propQuery);
        $ontologyLoc = RC::get('fedoraApiUrl') . '/' . RC::get('doorkeeperOntologyLocation') . '/';
        $values      = array('', RC::idProp(), $property, $ontologyLoc);

        $values[0] = 'http://www.w3.org/2002/07/owl#DatatypeProperty';
        $query->setValues($values);
        $dRes      = $fedora->runQuery($query);

        $values[0] = 'http://www.w3.org/2002/07/owl#ObjectProperty';
        $query->setValues($values);
        $oRes      = $fedora->runQuery($query);

        $this->object = count($oRes) > 0;

        if (count($dRes) == 0 && count($oRes) == 0) {
            throw new RuntimeException('Property ' . $property. ' not found', 10);
        }
        if (count($dRes) > 0 && count($oRes) > 0) {
            throw new RuntimeException('Property ' . $property. ' defined as both datatype and object property', 11);
        }
        $res = count($dRes) > 0 ? $dRes : $oRes;
        if (count($res) > 1) {
            throw new RuntimeException('Property ' . $property. ' defines multiple domains and/or ranges', 12);
        }
        
        $res = $res[0];
        if (isset($res->domain)) {
            $this->domain = (string) $res->domain;
        }
        if (isset($res->range)) {
            $this->range = (string) $res->range;
        }
    }

    /**
     * Returns if the property is an owl:ObjectProperty
     * @return bool
     */
    public function isObject(): bool {
        return $this->object;
    }
    
    /**
     * Fetches matching property values from a given resource and splits them
     * into two groups: values matching and not matching property type 
     * (rdfs:ObjectProperty => URI value, rdfs:DatatypeProperty => literal value)
     * @param Resource $res resource to fetch property values from
     * @return array
     */
    public function fetchValues(Resource $res): array {
        if ($this->object) {
            $match    = $res->allResources($this->property);
            $notMatch = $res->allLiterals($this->property);
        } else {
            $match    = $res->allLiterals($this->property);
            $notMatch = $res->allResources($this->property);
        }
        return array($match, $notMatch);
    }

    /**
     * Returns property's rdfs:domain URI
     * @return string
     */
    public function getDomain(): string {
        return $this->domain;
    }

    /**
     * Returns property's rdfs:range URI
     * @return string
     */
    public function getRange(): string {
        return $this->range;
    }

}
