<?php

/*
 * The MIT License
 *
 * Copyright 2016 zozlak.
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

namespace acdhOeaw\fedora;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use EasyRdf_Graph;
use EasyRdf_Resource;
use InvalidArgumentException;
use RuntimeException;
use acdhOeaw\util\EasyRdfUtil;
use acdhOeaw\util\SparqlEndpoint;
use zozlak\util\UUID;
use zozlak\util\Config;

/**
 * Represents an already existing Fedora resource.
 * 
 * Allows manipulations like getting/setting metadata 
 * and updating resource contents.
 * 
 * Static functions allow to manage Fedora session,
 * create new resources and search for existing ones.
 *
 * @author zozlak
 */
class FedoraResource {

    /**
     * List of metadata properties managed exclusively by the Fedora
     * @var array
     */
    static private $skipProp = array(
        'http://www.loc.gov/premis/rdf/v1#hasSize',
        'http://www.loc.gov/premis/rdf/v1#hasMessageDigest',
        'http://www.iana.org/assignments/relation/describedby'
    );

    /**
     * Regular expression for filtering out metadata properties managed
     * exclusively by the Fedora
     * @var string
     */
    static private $skipPropRegExp = '|^http://fedora[.]info/definitions/v4/repository#|';

    /**
     * Fedora API base URL
     * 
     * @var string 
     * @see init()
     */
    static private $apiUrl;

    /**
     * Current transaction URI
     * 
     * @var string
     * @see init()
     */
    static private $txUrl;

    /**
     * HTTP client object
     * 
     * @var \GuzzleHttp\Client
     */
    static private $client;

    /**
     * Fully qualified URI of the RDF property used to identify resources
     * 
     * @var string 
     * @see getId()
     * @see getIds()
     * @see updateContent()
     * @see init()
     */
    static private $idProp;

    /**
     * The namespace in which resource ids are created
     * 
     * At the moment ids are created by this class but at some point this will
     * be moved to the doorkeeper. When it's done, this property will be gone.
     * 
     * Ids are v4 UUIDs.
     * 
     * @var string
     * @see getId()
     * @see getIds()
     * @see updateContent()
     * @see init()
     */
    static private $idNamespace;

    /**
     * Initializes all static configuratin options.
     * 
     * @param Config $cfg configuration object
     */
    static public function init(Config $cfg) {
        self::$apiUrl = preg_replace('|/$|', '', $cfg->get('fedoraApiUrl'));
        self::$idProp = $cfg->get('fedoraIdProp');
        self::$idNamespace = $cfg->get('fedoraIdNamespace');
        $authHeader = 'Basic ' . base64_encode($cfg->get('fedoraUser') . ':' . $cfg->get('fedoraPswd'));
        self::$client = new Client(['headers' => ['Authorization' => $authHeader]]);
    }

    /**
     * Creates a resource in the Fedora and returns corresponding Resource object
     * 
     * If the object's metadata does not contain the id property 
     * (as defined by the "fedoraIdProp" configuration option - see init()),
     * a new ID will be assigned (a v4 UUID appended to the namespace defined
     * by the "fedoraIdNamespace" configuration option).
     * This feature is to be removed in the future, when the ids generation
     * when be handled by the doorkeeper.
     * 
     * @param EasyRdf_Resource $metadata resource metadata
     * @param mixed $data optional resource data as a string, file name or an array: ['content-type' => 'foo', 'data' => 'bar']
     * @param string $path optional Fedora resource path (if empty, resource will be created in the Fedora root)
     * @return \acdhOeaw\rms\FedoraResource
     */
    static public function factory(EasyRdf_Resource $metadata, $data = '', string $path = ''): FedoraResource {
        $method = $path ? 'PUT' : 'POST';
        $path = $path ? self::$txUrl . '/' . preg_replace('|^/|', '', $path) : self::$txUrl;
        $resp = self::uploadContent($method, $path, $data);
        $uri = $resp->getHeader('Location')[0];

        if (!$metadata->hasProperty(EasyRdfUtil::fixPropName(self::$idProp))) {
            $metadata->addResource(self::$idProp, self::$idNamespace . UUID::v4());
        }
        $res = new FedoraResource($uri, false);
        $res->setMetadata($metadata);
        $res->updateMetadata();

        return $res;
    }
 
    /**
     * Makes an HTTP request with a given method and body.
     * 
     * This is a low level function used by factory() and updateContent().
     * 
     * The implementation uses PSR-7 Request interface.
     * 
     * @param string $method HTTP method to use
     * @param string $uri request URI
     * @param string $body request body as a string, file name or an array: ['content-type' => 'foo', 'data' => 'bar']
     * @return \GuzzleHttp\Psr7\Response
     * @see factory()
     * @see updateContent()
     */
    static private function uploadContent(string $method, string $uri, string $body): Response {
        $headers = array();
        if (file_exists($body)) {
            $headers['Content-Type'] = mime_content_type($body);
            $body = fopen($body, 'rb');
        } elseif (is_array($body) && isset($body['contentType']) && isset($body['data'])) {
            $headers['Content-Type'] = $body['contentType'];
            $body = file_exists($body['data']) ? fopen($body, 'rb') : $body['data'];
        }

        $req = new Request($method, $uri, $headers, $body);
        $resp = self::$client->send($req);
        return $resp;
    }

    /**
     * Finds Fedora resources with a given id property value
     * (as it is defined by the "fedoraIdProp" configuration option - see the init() method).
     * 
     * If there is no or are many such resources, an error is thrown.
     * 
     * Be aware that only property values at the beginning of the current transaction
     * are be searched (see documentation of the begin() method).
     * 
     * @param string $value
     * @return \acdhOeaw\fedora\Resource
     * @throws RuntimeException
     * @see getResourcesById()
     */
    static public function getResourceById(string $value): Resource {
        $res = self::getResourcesByProperty(self::$idProp, $value);
        if (count($res) !== 1) {
            throw new RuntimeException((count($res) == 0 ? "No" : "Many") . " resources found");
        }
        return $res[0];
    }

    /**
     * Finds all Fedora resources with a given id property value
     * (as it is defined by the "fedoraIdProp" configuration option - see the init() method).
     * 
     * If you are sure only one resource with a given id should exist
     * (but it's RDF, so think about it twice), take a look at the getResourceById() method.
     * 
     * Be aware that only property values at the beginning of the current transaction
     * are be searched (see documentation of the begin() method).
     * 
     * @param string $value
     * @return array
     * @see getResourceById()
     */
    static public function getResourcesById(string $value): array {
        return self::getResourcesByProperty(self::$idProp, $value);
    }

    /**
     * Finds all Fedora resources having a given RDF property value.
     * 
     * If the value is not provided, all resources with a given property set
     * (to any value) are returned.
     * 
     * Be aware that all property values introduced during the transaction
     * are not taken into account (see documentation of the begin() method)
     * 
     * @param string $property fully quallified property URI
     * @param string $value optional property value
     * @return array
     * @see begin()
     */
    static public function getResourcesByProperty(string $property, string $value = ''): array {
        $query = sprintf('SELECT ?uri ?val WHERE { ?uri %s ?val } ORDER BY ( ?val )', EasyRdfUtil::escapeUri($property));
        $res = SparqlEndpoint::query($query);
        $resources = array();
        foreach ($res as $i) {
            if ($value === '' || (string) $i->val === $value) {
                $resources[] = new FedoraResource((string) $i->uri, false);
            }
        }
        return $resources;
    }

    /**
     * Cleans up the resource URI by skipping Fedora base URL and transation
     * URI (if they exist) and prepending the Fedora base URL or current 
     * transaction URI (based on the $skipTx parameter).
     * 
     * @param string $uri resource URI
     * @param bool $skipTx should Fedora base URL be prepended instead of 
     *   current transaction URI (if transaction is not opened, Fedora base
     *   URL is used no matter this parameter value)
     * @return string 
     */
    static private function sanitizeUri(string $uri, bool $skipTx) {
        $baseUrl = $skipTx || !self::$txUrl ? self::$apiUrl : self::$txUrl;
        $uri = preg_replace('|^https?://[^/]+/rest/(tx:[-0-9a-zA-Z]+/)?|', '', $uri);
        $uri = $baseUrl . '/' . $uri;
        return $uri;
    }

    /**
     * Serialises metadata to a form suitable for Fedora's SPARQL-update query.
     * 
     * This means the "ntriples" format with blank subject URIs and excluding
     * properties Fedora reserves for itself (and rises errors when they are
     * provided from the outside).
     * 
     * Reserved Fedora properties include all in the http://fedora.info/definitions/v4/repository#
     * namespace as well as premis:hasSize, premis:hasMessageDigest, iana:describedby.
     * 
     * @param \EasyRdf_Resource $metadata metadata to serialise
     * @return string
     */
    static private function getSparqlTriples(EasyRdf_Resource $metadata): string {
        // make a deep copy of the metadata graph excluding forbidden properties
        $res = EasyRdfUtil::cloneResource($metadata, self::$skipProp, self::$skipPropRegExp);

        // serialize graph to ntriples format and convert all subjects to <>
        $rdf = "\n" . $res->getGraph()->serialise('ntriples') . "\n";
        $pattern = '|\n' . EasyRdfUtil::escapeUri($metadata->getUri()) . '|';
        $rdf = preg_replace($pattern, "\n<>", $rdf);

        return $rdf;
    }

    /**
     * Starts new Fedora transaction.
     * 
     * Only one transaction can be opened at the same time, 
     * so make sure you commited previous transactions before starting a new one.
     * 
     * Be aware that all metadata modyfied during the transaction will be not
     * visible in the triplestore coupled with the Fedora until the transaction
     * is commited.
     * 
     * @see rollback()
     * @see commit()
     */
    static public function begin() {
        $resp = self::$client->post(self::$apiUrl . '/fcr:tx');
        self::$txUrl = $resp->getHeader('Location')[0];
    }

    /**
     * Rolls back the current Fedora transaction.
     * 
     * @see begin()
     * @see commit()
     */
    static public function rollback() {
        self::$client->post(self::$txUrl . '/fcr:tx/fcr:rollback');
        self::$txUrl = null;
    }

    /**
     * Commits the current Fedora transaction.
     * 
     * After the commit all the metadata modified during the transaction 
     * will be finally uvailable in the triplestore associated with the Fedora.
     * 
     * @see begin()
     * @see rollback()
     */
    static public function commit() {
        self::$client->post(self::$txUrl . '/fcr:tx/fcr:commit');
        self::$txUrl = null;
    }

    /**
     * Retruns true if a Fedora transaction is opened and false otherwise.
     * 
     * @return bool
     */
    static public function inTransaction(): bool {
        return self::$txUrl !== null;
    }

    /**
     * Resource's Fedora URI
     * 
     * @var string
     */
    private $uri;

    /**
     * Resource metadata (local copy)
     * 
     * @var \EasyRdf_Resource 
     * @see getMetadata()
     * @see setMetadata()
     * @see updateMetadata()
     */
    private $metadata;

    /**
     * Resource metadata fetched from the Fedora
     * enabling us to perform triples update.
     * 
     * @var \EasyRdf_Resource 
     * @see getMetadata()
     * @see setMetadata()
     * @see updateMetadata()
     */
    private $metadataOld;

    /**
     * Creates new resource based on its Fedora URI.
     * 
     * URI can be passed in various formats (see the $uri parameter) and will
     * be internally coverted to the fully qualified form depending on the
     * $skipTx parameter value (see the sanitizeUri() method).
     * 
     * Validity of the provided URI is not checked.
     * 
     * @param string $uri fedora resource URI in a fully qualified form,
     *   fully qualified form including transaction URI
     *   or a path within Fedora
     * @param bool $skipTx should 
     * @see sanitizeUri()
     */
    public function __construct(string $uri, bool $skipTx = false) {
        $this->uri = self::sanitizeUri($uri, $skipTx);
    }

    /**
     * Returns resource Fedora URI
     * 
     * @return string
     */
    public function getUri(): string {
        return $this->uri;
    }

    /**
     * Returns resource id.
     * 
     * If there is no or are many ids, an error is thrown.
     * 
     * @return string
     * @throws RuntimeException
     * @see getIds()
     */
    public function getId(): string {
        $ids = $this->getIds();
        if (count($ids) !== 1) {
            throw new RuntimeException((count($ids) == 0 ? 'No' : 'Many') . ' ids for ' . $this->getUri());
        }
        return $ids[0];
    }

    /**
     * Returns an array of resource ids.
     * 
     * If you are sure only one id should be present 
     * (but it's RDF, so think about it twice), 
     * take a look at the getId() method.
     * 
     * @return array
     * @see getId()
     */
    public function getIds(): array {
        $this->loadMetadata();
        $ids = array();
        foreach ($this->metadata->allResources(EasyRdfUtil::fixPropName(self::$idProp)) as $i) {
            $ids[] = $i->getUri();
        }
        return $ids;
    }

    /**
     * Replaces resource metadata with a given RDF graph.
     * 
     * New metadata are not automatically written back to the Fedora.
     * Use the updateMetadata() method to write them back.
     * 
     * @param EasyRdf_Resource $metadata
     * @see updateMetadata()
     */
    public function setMetadata(EasyRdf_Resource $metadata) {
        $this->metadata = $metadata;
    }

    /**
     * Writes resource metadata back to the Fedora
     * and then fetches them by calling getMetadata().
     * 
     * Do not be surprised that the metadata read back from the Fedora can 
     * (and for sure will) differ from the one which were written by you.
     * This is because Fedora (and/or doorkeeper) will add/modify some triples
     * (e.g. fedora:lastModified).
     * 
     * Be aware that as Fedora generates errors when you try to set properties
     * Fedora considers its private once, such properties will be ommited in the
     * update (see getSparqlTriples() method documentation for details).
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
        if (!in_array($mode, array('ADD', 'UPDATE', 'OVERWRITE'))) {
            throw new InvalidArgumentException('Mode should be one of ADD, UPDATE or OVERWITE');
        }
        if (!$this->metadata){
            throw new RuntimeException('Get or set metadata first with getMetadata() or setMetadata()');
        }

        switch ($mode) {
            case 'ADD':
                $delete = '';
                $where = '';
                break;
            case 'UPDATE':
                if(!$this->metadataOld){
                    $metadataTmp = $this->metadata;
                    $this->loadMetadata(true);
                    $this->metadata = $metadataTmp;
                }
                $delete = self::getSparqlTriples($this->metadataOld);
                $where = '';
                break;
            case 'OVERWRITE':
                $delete = '<> ?prop ?value .';
                $where = '<> ?prop ?value . FILTER (!regex(str(?prop), "^http://fedora[.]info")';
                foreach(self::$skipProp as $i){
                    $where .= ' && str(?prop) != str(' . EasyRdfUtil::escapeUri($i). ')';
                }
                $where .= ')';
                break;
        }
        $insert = self::getSparqlTriples($this->metadata);
        $body = sprintf('DELETE {%s} INSERT {%s} WHERE {%s}', $delete, $insert, $where);

        $options = [
            'body' => $body,
            'headers' => ['Content-Type' => 'application/sparql-update']
        ];
        self::$client->patch($this->uri . '/fcr:metadata', $options);
        // read metadata after the update
        $this->loadMetadata(true);
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
     * @return \EasyRdf_Resource
     * @see updateMetadata()
     * @see setMetadata()
     */
    public function getMetadata(bool $force = false): EasyRdf_Resource {
        $this->loadMetadata($force);
        return EasyRdfUtil::cloneResource($this->metadata);
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
            $resp = self::$client->get($this->uri . '/fcr:metadata');

            $graph = new EasyRdf_Graph();
            $graph->parse($resp->getBody());
            $this->metadata = $graph->resource($this->uri);

            $this->metadataOld = EasyRdfUtil::cloneResource($this->metadata);
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
        $this->loadMetadata();
        if ($this->isA('http://www.w3.org/ns/ldp#NonRDFSource')) {
            self::uploadContent('PUT', $this->getUri(), $data);
        } else if ($convert) {
            self::$client->send(new Request('DELETE', $this->getUri()));
            $newRes = self::factory($this->metadata, $data);
            $this->uri = $newRes->getUri();
        } else {
            throw new RuntimeException('Resource is not a binary one. Turn on the $convert parameter if you are sure what you are doing.');
        }
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
        $types = $this->metadata->allResources(EasyRdfUtil::fixPropName('http://www.w3.org/1999/02/22-rdf-syntax-ns#type'));
        foreach ($types as $i) {
            if ($i->getUri() === $class) {
                return true;
            }
        }
        return false;
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

    public function __toString() {
        return $this->getUri();
    }

}
