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

use GuzzleHttp\Psr7\Request;
use EasyRdf\Graph;
use EasyRdf\Resource;
use InvalidArgumentException;
use RuntimeException;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\QueryParameter;
use acdhOeaw\fedora\metadataQuery\HasProperty;
use acdhOeaw\fedora\metadataQuery\HasValue;
use acdhOeaw\fedora\metadataQuery\MatchesRegEx;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Represents an already existing Fedora resource.
 * 
 * Allows manipulations like getting/setting metadata 
 * and updating resource contents.
 * 
 * @author zozlak
 */
class FedoraResource {

    /**
     * List of metadata properties managed exclusively by the Fedora.
     * @var array
     * @see getSparqlTriples()
     */
    static private $skipProp = array(
        'http://www.loc.gov/premis/rdf/v1#hasSize',
        'http://www.loc.gov/premis/rdf/v1#hasMessageDigest',
        'http://www.iana.org/assignments/relation/describedby',
        'http://purl.org/dc/terms/extent',
        'http://www.ebu.ch/metadata/ontologies/ebucore/ebucore#filename', // must be set in a Content-Disposition header
    );

    /**
     * Regular expression for filtering out metadata properties managed
     * exclusively by the Fedora
     * @var string
     * @see getSparqlTriples()
     */
    static private $skipPropRegExp = '|^http://fedora[.]info/definitions/v4/repository#|';

    /**
     * Resource's Fedora URI
     * 
     * @var string
     */
    private $uri;

    /**
     * Resource metadata (local copy)
     * 
     * @var \EasyRdf\Resource 
     * @see getMetadata()
     * @see setMetadata()
     * @see updateMetadata()
     */
    private $metadata;

    /**
     * Resource metadata lastly fetched from the Fedora
     * enabling us to perform triples update.
     * 
     * @var \EasyRdf\Resource 
     * @see getMetadata()
     * @see setMetadata()
     * @see updateMetadata()
     */
    private $metadataOld;

    /**
     * Fedora connection object used by this resource
     * @var \acdhOeaw\fedora\Fedora
     */
    private $fedora;

    /**
     * Are object's metadata synchronized with the Fedora
     * @var bool
     */
    private $updated = true;

    /**
     * Creates new resource based on its Fedora URI.
     * 
     * Validity of the provided URI is not checked.
     * 
     * @param Fedora $fedora Fedora connection object providing context for
     *   a created resource
     * @param string $uri resource URI
     */
    public function __construct(Fedora $fedora, string $uri) {
        $this->fedora = $fedora;
        $this->uri    = $uri;
    }

    /**
     * Returns resource's Fedora URI
     * 
     * @param bool $standardized should the Uri be standardized (in the form
     *   used in a triplestore) or current Fedora connection specific
     * @return string
     */
    public function getUri(bool $standardized = false): string {
        return $standardized ? $this->fedora->standardizeUri($this->uri) : $this->uri;
    }

    /**
     * Returns resource's ACDH ID.
     * 
     * If there is no or are many IDs, an error is thrown.
     * 
     * @return string
     * @throws RuntimeException
     * @see getIds()
     */
    public function getId(): string {
        $ids = $this->getIds();
        if (count($ids) === 0) {
            throw new RuntimeException('No ACDH id for ' . $this->getUri());
        }
        $acdhId = null;
        foreach ($ids as $id) {
            $inIdNmsp     = strpos($id, RC::idNmsp()) === 0;
            $inVocabsNmsp = strpos($id, RC::vocabsNmsp()) === 0;
            if ($inIdNmsp || $inVocabsNmsp) {
                if ($acdhId !== null) {
                    throw new RuntimeException('Many ACDH ids for ' . $this->getUri());
                }
                $acdhId = $id;
            }
        }
        if ($acdhId === null) {
            throw new RuntimeException('No ACDH id for ' . $this->getUri());
        }
        return $acdhId;
    }

    /**
     * Returns an array of resource's IDs.
     * 
     * If you want to get an ACDH ID, use the getId() method.
     * 
     * @return array
     * @see getId()
     */
    public function getIds(): array {
        $this->loadMetadata();
        $ids = array();
        foreach ($this->metadata->allResources(RC::idProp()) as $i) {
            $ids[] = $i->getUri();
        }
        return $ids;
    }

    /**
     * Returns the Fedora connection used by this object
     * 
     * @return \acdhOeaw\fedora\Fedora
     */
    public function getFedora(): Fedora {
        return $this->fedora;
    }

    /**
     * Removes the resource from the Fedora
     */
    public function delete() {
        $request = new Request('DELETE', $this->fedora->sanitizeUri($this->getUri()));
        $this->fedora->sendRequest($request);
    }

    /**
     * Replaces resource metadata with a given RDF graph.
     * 
     * New metadata are not automatically written back to the Fedora.
     * Use the updateMetadata() method to write them back.
     * 
     * @param EasyRdf\Resource $metadata
     * @see updateMetadata()
     */
    public function setMetadata(Resource $metadata) {
        $this->metadata = $metadata;
        $this->updated  = false;
    }

    /**
     * Writes resource metadata back to the Fedora
     * and then fetches them by calling getMetadata().
     * 
     * Do not be surprised that the metadata read back from the Fedora can 
     * (and for sure will) differ from the one written by you.
     * This is because Fedora (and/or doorkeeper) will add/modify some triples
     * (e.g. fedora:lastModified).
     * 
     * Be aware that as Fedora generates errors when you try to set properties
     * Fedora considers its private once, such properties will be ommited in the
     * update (see `getSparqlTriples()` method documentation for details).
     * 
     * @param string $mode chooses the way the update is done:
     *   ADD simply adds current triples. All already existing triples 
     *     (also old value of the triples you altered) are kept.
     *   UPDATE old values of already existing triples are updated with current
     *     values, new triples are added and all other triples are kept.
     *   OVERWRITE all existing triples are removed, then all current triples
     *     are added.
     * @see getMetadata()
     * @see setMetadata()
     * @see getSparqlTriples()
     */
    public function updateMetadata(string $mode = 'UPDATE') {
        if (!$this->updated) {
            if (!in_array($mode, array('ADD', 'UPDATE', 'OVERWRITE'))) {
                throw new InvalidArgumentException('Mode should be one of ADD, UPDATE or OVERWITE');
            }
            if (!$this->metadata) {
                throw new RuntimeException('Get or set metadata first with getMetadata() or setMetadata()');
            }

            switch ($mode) {
                case 'ADD':
                    $delete = '';
                    $where  = '';
                    break;
                case 'UPDATE':
                    if (!$this->metadataOld) {
                        $metadataTmp    = $this->metadata;
                        $this->loadMetadata(true);
                        $this->metadata = $metadataTmp;
                    }
                    $delete = self::getSparqlTriples($this->metadataOld);
                    $where  = '';
                    break;
                case 'OVERWRITE':
                    $delete = '<> ?prop ?value .';
                    $where  = '<> ?prop ?value . FILTER (!regex(str(?prop), "^http://fedora[.]info")';
                    foreach (self::$skipProp as $i) {
                        $where .= ' && str(?prop) != str(' . QueryParameter::escapeUri($i) . ')';
                    }
                    $where .= ')';
                    break;
            }
            $insert = self::getSparqlTriples($this->metadata);
            $body   = sprintf('DELETE {%s} INSERT {%s} WHERE {%s}', $delete, $insert, $where);

            $headers = array('Content-Type' => 'application/sparql-update');
            $request = new Request('PATCH', $this->uri . '/fcr:metadata', $headers, $body);
            $this->fedora->sendRequest($request);
        }

        // read metadata after the update
        $this->loadMetadata(true);
        $this->updated = true;
    }

    /**
     * Returns resource metadata.
     * 
     * Fetches them from the Fedora if they were not fetched already.
     * 
     * A deep copy of metadata is returned meaning adjusting the returned object
     * does not automatically affect the resource metadata.
     * Use the setMetadata() method to write back the changes you made.
     * 
     * @param bool $force enforce fetch from Fedora 
     *   (when you want to make sure metadata are in line with ones in the Fedora 
     *   or e.g. reset them back to their current state in Fedora)
     * @return \EasyRdf\Resource
     * @see updateMetadata()
     * @see setMetadata()
     */
    public function getMetadata(bool $force = false): Resource {
        $this->loadMetadata($force);
        return $this->metadata->copy();
    }

    /**
     * Loads current metadata from the Fedora.
     * 
     * @param bool $force enforce fetch from Fedora 
     *   (when you want to make sure metadata are in line with ones in the Fedora 
     *   or e.g. reset them back to their current state in Fedora)
     */
    private function loadMetadata(bool $force = false) {
        if (!$this->metadata || $force) {
            $request = new Request('GET', $this->uri . '/fcr:metadata');
            $resp    = $this->fedora->sendRequest($request);

            $graph          = new Graph();
            $graph->parse($resp->getBody());
            $this->metadata = $graph->resource($this->uri);

            if (count($this->metadata->propertyUris()) === 0) {
                throw new RuntimeException('No resource metadata. Please check a value of the fedoraApiUrl configuration property.');
            }

            $this->metadataOld = $this->metadata->copy();
        }
    }

    /**
     * Updates resource binary content in the Fedora.
     * 
     * If the resource is not a binary resource (in Fedora terms), 
     * it can be converted.
     * This means the existing Fedora resource will be deleted and the new one
     * will be created.
     * This means the resource will change its Fedora URI but the id property
     * indicated by the "fedoraIdProp" config option (see init()) will be
     * preserved.
     * This means until you are using the id property values (and not Fedora URIs) 
     * to denote resources in your metadata, your metadata consistency will be preserved.
     * 
     * @param mixed $data resource data as a string, file name 
     *   or an array: ['content-type' => 'foo', 'data' => 'bar']
     * @param bool $convert should not binary resource be converted
     * @throws \DomainException
     * @see init()
     */
    public function updateContent($data, bool $convert = false) {
        if (empty($data)) {
            return;
        }
        $this->loadMetadata();
        if ($this->isA('http://www.w3.org/ns/ldp#NonRDFSource')) {
            $request = new Request('PUT', $this->getUri());
            $request = Fedora::attachData($request, $data);
            $this->fedora->sendRequest($request);
        } else if ($convert) {
            $this->fedora->sendRequest(new Request('DELETE', $this->getUri()));
            $newRes    = $this->fedora->createResource($this->metadata, $data);
            $this->uri = $newRes->getUri();
        } else {
            throw new RuntimeException('Resource is not a binary one. Turn on the $convert parameter if you are sure what you are doing.');
        }
        $this->loadMetadata(true);
    }

    /**
     * Naivly checks if the resource is of a given class.
     * 
     * Naivly means that a given rdfs:type triple must exist in the resource
     * metadata.
     * 
     * @param type $class
     * @return bool
     */
    public function isA(string $class): bool {
        $this->loadMetadata();
        $types = $this->metadata->allResources('http://www.w3.org/1999/02/22-rdf-syntax-ns#type');
        foreach ($types as $i) {
            if ($i->getUri() === $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the SPARQL query object returning resource's children
     * 
     * @return Query
     */
    public function getChildrenQuery(): Query {
        $query = new Query();
        $query->addParameter($this->getChildrenQueryParameter());
        return $query;
    }

    /**
     * Return the SPARQL query triple object denoting relation of being
     * this resource's child
     * 
     * @return QueryParameter
     */
    public function getChildrenQueryParameter(): QueryParameter {
        return new HasValue(RC::relProp(), $this->getId());
    }

    /**
     * Returns all resource's children
     * 
     * @return array
     */
    public function getChildren(): array {
        return $this->fedora->getResourcesByQuery($this->getChildrenQuery());
    }

    /**
     * Returns all resource's children having a given property or a given value
     * of a given property.
     * 
     * @param string $property fully qualified URI of the property
     * @param string $value property value (optional)
     * @return array
     */
    public function getChildrenByProperty(string $property, string $value = ''): array {
        $query = $this->getChildrenQuery();
        if ($value != '') {
            $param = new HasValue($property, $value);
        } else {
            $param = new HasProperty($property);
        }
        $query->addParameter($param);
        return $this->fedora->getResourcesByQuery($query);
    }

    /**
     * Returns all resource's children with a given property matching a given
     * regular expression
     * 
     * @param string $property fully qualified URI of the property
     * @param string $regEx regular expression to match
     * @param string $flags regular expression flags
     * @return array
     */
    public function getChildrenByPropertyRegEx(string $property, string $regEx,
                                               string $flags = 'i'): array {
        $query = $this->getChildrenQuery();
        $query->addParameter(new MatchesRegEx($property, $regEx, $flags));
        return $this->fedora->getResourcesByQuery($query);
    }

    /**
     * Serializes metadata to a form suitable for Fedora's SPARQL-update query.
     * 
     * This means the "ntriples" format with subject URIs compliant with current
     * Fedora connection and excluding properties Fedora reserves for itself 
     * (and rises errors when they are provided from the outside).
     * 
     * @param \EasyRdf\Resource $metadata metadata to serialize
     * @return string
     * @see $skipProp
     * @see $skipPropRegExp
     */
    private function getSparqlTriples(Resource $metadata): string {
        $uri = $this->fedora->sanitizeUri($this->uri);
        $res = $metadata->copy(self::$skipProp, self::$skipPropRegExp, $uri);

        $rdf = $res->getGraph()->serialise('ntriples');
        return $rdf;
    }

    /**
     * Debugging helper allowing to take a look at the resource metadata 
     * in a console-friendly way
     * 
     * @return string
     */
    public function __getSparqlTriples(): string {
        return self::getSparqlTriples($this->metadata);
    }

    /**
     * Provides short string representation of the object
     * 
     * @return type
     */
    public function __toString() {
        return $this->getUri();
    }

}
