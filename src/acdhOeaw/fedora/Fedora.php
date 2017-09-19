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

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ClientException;
use EasyRdf\Resource;
use EasyRdf\Sparql\Result;
use RuntimeException;
use BadMethodCallException;
use acdhOeaw\fedora\FedoraCache;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\fedora\exceptions\NoAcdhId;
use acdhOeaw\fedora\exceptions\NotInCache;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\exceptions\AmbiguousMatch;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasProperty;
use acdhOeaw\fedora\metadataQuery\HasValue;
use acdhOeaw\fedora\metadataQuery\MatchesRegEx;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Represents a Fedora connection.
 * 
 * Provides transaction managment and methods for convinient search and creation
 * of Fedora resources.
 *
 * @author zozlak
 */
class Fedora {

    /**
     * Should debug messages be generated when searching for resources
     * @var bool
     */
    static public $debugGetRes = false;

    /**
     * Should debug messages be generated when running SPARQL queries
     * @var bool
     */
    static public $debugSparql = false;

    /**
     * Attaches binary content to a given Guzzle HTTP request
     * 
     * @param \GuzzleHttp\Psr7\Request $request HTTP request
     * @param string $body binary content to be attached
     *   It can be a file name, a string or an URL
     *   If it is URL, a "redirecting Fedora resource" will be created
     * @return \GuzzleHttp\Psr7\Request
     */
    static public function attachData(Request $request, string $body): Request {
        $headers = $request->getHeaders();
        if (file_exists($body)) {
            $filename                       = rawurldecode(basename($body)); // lucky guess - unfortunately it is not clear how to escape header values
            $headers['Content-Type']        = mime_content_type($body);
            $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
            $body                           = fopen($body, 'rb');
        } elseif (is_array($body) && isset($body['contentType']) && isset($body['data']) && isset($body['filename'])) {
            $headers['Content-Type']        = $body['contentType'];
            $headers['Content-Disposition'] = 'attachment; filename="' . rawurldecode($body['filename']) . '"';
            $body                           = file_exists($body['data']) ? fopen($body, 'rb') : $body['data'];
        } elseif (preg_match('|^[a-z0-9]+://|i', $body)) {
            $headers['Content-Type'] = 'message/external-body; access-type=URL; URL="' . $body . '"';
            $body                    = null;
        }
        return new Request($request->getMethod(), $request->getUri(), $headers, $body);
    }

    /**
     * Fedora API base URL
     * 
     * @var string 
     */
    private $apiUrl;

    /**
     * Current transaction URI
     * 
     * @var string
     */
    private $txUrl;

    /**
     * HTTP client object
     * 
     * @var \GuzzleHttp\Client
     */
    private $client;

    /**
     * SPARQL client object
     * @var SparqlClient
     */
    private $sparqlClient;

    /**
     * Resources cache
     * @var \acdhOeaw\fedora\FedoraCache
     */
    private $cache;

    /**
     * Creates Fedora connection object from a given configuration.
     */
    public function __construct() {
        $this->apiUrl       = preg_replace('|/$|', '', RC::get('fedoraApiUrl'));
        $authHeader         = 'Basic ' . base64_encode(RC::get('fedoraUser') . ':' . RC::get('fedoraPswd'));
        $this->client       = new Client(['verify' => false, 'headers' => ['Authorization' => $authHeader]]);
        $this->sparqlClient = new SparqlClient(RC::get('sparqlUrl'), RC::get('fedoraUser'), RC::get('fedoraPswd'));
        $this->cache        = new FedoraCache();
    }

    /**
     * Checks if a given URI belongs to ACDH id namespace(s)
     * @param string $uri uri
     * @return bool
     */
    public function isAcdhId(string $uri): bool {
        return strpos($uri, RC::idNmsp()) === 0 || strpos($uri, RC::vocabsNmsp()) === 0;
    }

    /**
     * Returns a FedoraResource objects cache
     * @return FedoraCache
     */
    public function getCache(): FedoraCache {
        return $this->cache;
    }

    /**
     * Clears local cache. 
     * 
     * In normal usage scenarios should not be used (but can be helpful e.g.
     * while running tests).
     */
    public function __clearCache() {
        $this->cache = new FedoraCache();
    }

    /**
     * Creates a resource in the Fedora and returns corresponding Resource object
     * 
     * @param EasyRdf\Resource $metadata resource metadata
     * @param mixed $data optional resource data as a string, 
     *   file name or an array: ['content-type' => 'foo', 'data' => 'bar']
     * @param string $path optional Fedora resource path (see also the `$method`
     *   parameter)
     * @param string $method creation method to use - POST or PUT, by default POST
     * @return \acdhOeaw\rms\FedoraResource
     * @throws \BadMethodCallException
     */
    public function createResource(Resource $metadata, $data = '',
                                   string $path = '', string $method = 'POST'): FedoraResource {
        if (!in_array($method, array('POST', 'PUT'))) {
            throw new BadMethodCallException('method must be PUT or POST');
        }
        $baseUrl = $this->txUrl ? $this->txUrl : $this->apiUrl;
        $path    = $path ? $this->sanitizeUri($path) : $baseUrl;
        $request = new Request($method, $path);
        $request = self::attachData($request, $data);
        try {
            $resp = $this->sendRequest($request);
        } catch (ClientException $e) {
            if (strpos($e->getMessage(), 'tombstone resource') === false) {
                throw $e;
            }
            throw new Deleted();
        }
        $uri = $resp->getHeader('Location')[0];
        $res = $this->getResourceByUri($uri);

        // merge the metadata created by Fedora (and Doorkeeper) upon resource creation
        // with the ones provided by user
        $curMetadata = $res->getMetadata()->merge($metadata, array(RC::idProp()));
        $res->setMetadata($curMetadata);
        $res->updateMetadata();

        return $res;
    }

    /**
     * Sends a given HTTP request to the Fedora.
     * 
     * @param Request $request request to be send
     * @return \GuzzleHttp\Psr7\Response
     */
    public function sendRequest(Request $request): Response {
        return $this->client->send($request);
    }

    /**
     * Returns a FedoraResource based on a given URI.
     * 
     * Request URI is imported into the current connection meaning base
     * Fedora API URL will and the current transaction URI (if there is 
     * an active transaction) will replace ones in passed URI.
     * 
     * It is not checked if a resource with a given URI exists.
     * 
     * @param string $uri
     * @return \acdhOeaw\fedora\FedoraResource
     */
    public function getResourceByUri(string $uri): FedoraResource {
        $uri = $this->standardizeUri($uri);
        try {
            return $this->cache->getByUri($uri);
        } catch (NotInCache $e) {
            return new FedoraResource($this, $uri);
        }
    }

    /**
     * Finds Fedora resources with a given id property value
     * (as it is defined by the "fedoraIdProp" configuration option - see the init() method).
     * 
     * If there is no or are many such resources, an error is thrown.
     * 
     * @param string $id
     * @param bool $checkIfExist should we make sure resource was not deleted
     *   during the current transaction
     * @return \acdhOeaw\fedora\FedoraResource
     * @throws NotFound
     * @throws AmbiguousMatch
     */
    public function getResourceById(string $id, bool $checkIfExist = true): FedoraResource {
        $res = $this->getResourcesByProperty(RC::idProp(), $id, false);
        try {
            $res[] = $this->cache->getById($id);
        } catch (NotInCache $e) {
            
        }

        if ($checkIfExist) {
            $res = $this->verifyResources($res);
        }

        switch (count($res)) {
            case 0:
                throw new NotFound();
            case 1:
                return array_pop($res);
            default:
                $res1 = array_pop($res);
                $res2 = array_pop($res);
                if ($res1->getUri(true) !== $res2->getUri(true)) {
                    throw new AmbiguousMatch();
                }
                return $res1;
        }
    }

    /**
     * Extracts id properties form metadata based on the "fedoraIdProp" configuration property
     * and calls `getResourceByIds()`
     * @param Resource $metadata
     * @param bool $checkIfExist should we make sure resource was not deleted
     *   during the current transaction
     * @return type
     * @see getResourcesByIds()
     */
    public function getResourceByMetadata(Resource $metadata,
                                          bool $checkIfExist = true) {
        $ids = array();
        foreach ($metadata->allResources(RC::idProp()) as $i) {
            $ids[] = $i->getUri();
        }
        return $this->getResourceByIds($ids, $checkIfExist);
    }

    /**
     * Finds Fedora resources matching any of provided ids.
     * @param array $ids
     * @param bool $checkIfExist should we make sure resource was not deleted
     *   during the current transaction
     * @return \acdhOeaw\fedora\FedoraResource
     * @throws NotFound
     * @throws AmbiguousMatch
     */
    public function getResourceByIds(array $ids, bool $checkIfExist = true): FedoraResource {
        echo self::$debugGetRes ? "[Fedora] searching for " . implode(', ', $ids) . "\n" : '';

        $matches = array();
        foreach ($ids as $id) {
            try {
                $res                         = $this->getResourceById($id, $checkIfExist);
                $matches[$res->getUri(true)] = $res;
            } catch (NotFound $e) {
                
            }
        }

        switch (count($matches)) {
            case 0:
                echo self::$debugGetRes ? "  not found\n" : '';
                throw new NotFound();
            case 1:
                echo self::$debugGetRes ? "  found\n" : '';
                return array_pop($matches);
            default:
                echo self::$debugGetRes ? "  ambiguosus match: " . implode(', ', array_keys($matches)) . "\n" : '';
                throw new AmbiguousMatch();
        }
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
     * @param string $property fully qualified property URI
     * @param string $value optional property value
     * @param bool $checkIfExist should we make sure resource was not deleted
     *   during the current transaction
     * @return array
     * @see begin()
     */
    public function getResourcesByProperty(string $property, string $value = '',
                                           bool $checkIfExist = true): array {
        $query = new Query();
        if ($value != '') {
            $param = new HasValue($property, $value);
        } else {
            $param = new HasProperty($property);
        }
        $query->addParameter($param);
        return $this->getResourcesByQuery($query, '?res', $checkIfExist);
    }

    /**
     * Finds all Fedora resources with a given RDF property matching given regular expression.
     * 
     * Be aware that all property values introduced during the transaction
     * are not taken into account (see documentation of the begin() method)
     * 
     * @param string $property fully qualified property URI
     * @param string $regEx regular expression to match against
     * @param string $flags regular expression flags (by default "i" - case insensitive)
     * @param bool $checkIfExist should we make sure resource was not deleted
     *   during the current transaction
     * @return array
     * @see begin()
     */
    public function getResourcesByPropertyRegEx(string $property, string $regEx,
                                                string $flags = 'i',
                                                bool $checkIfExist = true): array {
        $query = new Query();
        $query->addParameter(new MatchesRegEx($property, $regEx, $flags));
        return $this->getResourcesByQuery($query, '?res', $checkIfExist);
    }

    /**
     * Finds all Fedora resources satisfying a given SPARQL query.
     * 
     * Be aware that the triplestore state is not affected by all actions
     * performed during the active transaction.
     * 
     * @param Query $query SPARQL query fetching resources from the triplestore
     * @param string $resVar name of the SPARQL variable containing resource 
     *   URIs
     * @param bool $checkIfExist should we make sure resource was not deleted
     *   during the current transaction
     * @return array
     * @see begin()
     */
    public function getResourcesByQuery(Query $query, string $resVar = '?res',
                                        bool $checkIfExist = true): array {
        $resVar    = preg_replace('|^[?]|', '', $resVar);
        $results   = $this->runQuery($query);
        $resources = array();
        foreach ($results as $i) {
            try {
                $resources[] = $this->getResourceByUri($i->$resVar);
            } catch (Deleted $e) {
                
            }
        }
        if ($checkIfExist) {
            $resources = $this->verifyResources($resources);
        }
        return $resources;
    }

    /**
     * Removes from an array resources which do not exist anymore.
     * @param array $resources
     * @param bool $force should resource be always checked (true) or maybe it
     *   is enough if metadata were already fetched during this session (false) 
     * @return array
     * @throws \acdhOeaw\fedora\ClientException
     */
    private function verifyResources(array $resources, bool $force = false): array {
        $passed = array();
        foreach ($resources as $key => $res) {
            try {
                $res->getMetadata($force);
                $passed[$key] = $res;
            } catch (ClientException $e) {
                // "410 gone" means the resource was deleted during current transaction
                if ($e->getCode() !== 410) {
                    throw $e;
                } else {
                    $this->cache->delete($res);
                }
            }
        }
        return $passed;
    }

    /**
     * Runs a SPARQL query defined by a Query object against repository
     * triplestore.
     * 
     * @param Query $query query to run
     * @return \EasyRdf\Sparql\Result
     */
    public function runQuery(Query $query): Result {
        $query = $query->getQuery();

        echo self::$debugSparql ? $query . "\n" : '';

        return $this->runSparql($query);
    }

    /**
     * Runs a SPARQL against repository triplestore.
     * 
     * @param string $query SPARQL query to run
     * @return \EasyRdf\Sparql\Result
     */
    public function runSparql(string $query): Result {
        return $this->sparqlClient->query($query);
    }

    /**
     * Adjusts URI to the current object state by setting up the proper base
     * URL and the transaction id.
     * 
     * @param string $uri resource URI
     * @return string 
     */
    public function sanitizeUri(string $uri): string {
        if ($uri == '') {
            throw new BadMethodCallException('URI is empty');
        }
        $baseUrl = !$this->txUrl ? $this->apiUrl : $this->txUrl;
        $uri     = preg_replace('|^/|', '', $uri);
        $uri     = preg_replace('|^https?://[^/]+/rest/?(tx:[-0-9a-zA-Z]+/)?|', '', $uri);
        $uri     = $baseUrl . '/' . $uri;
        return $uri;
    }

    /**
     * Transforms an URI into "a canonical form" used in the triplestore to
     * denote triples subject.
     * 
     * @param string $uri URI to transform
     * @return string
     */
    public function standardizeUri(string $uri): string {
        if ($uri == '') {
            throw new BadMethodCallException('URI is empty');
        }
        if (substr($uri, 0, 1) === '/') {
            $uri = substr($uri, 1);
        }
        $uri = preg_replace('|^https?://[^/]+/rest/(tx:[-0-9a-zA-Z]+/)?|', '', $uri);
        $uri = $this->apiUrl . '/' . $uri;
        return $uri;
    }

    /**
     * Starts new Fedora transaction.
     * 
     * Only one transaction can be opened at the same time, 
     * so make sure you committed previous transactions before starting a new one.
     * 
     * Be aware that all metadata modified during the transaction will be not
     * visible in the triplestore coupled with the Fedora until the transaction
     * is committed.
     * 
     * @see rollback()
     * @see commit()
     */
    public function begin() {
        $resp = $this->client->post($this->apiUrl . '/fcr:tx');
        $loc  = $resp->getHeader('Location');
        if (count($loc) == 0) {
            throw new RuntimeException('wrong response from fedora');
        }
        $this->txUrl = $loc[0];
    }

    /**
     * Rolls back the current Fedora transaction.
     * 
     * @see begin()
     * @see commit()
     */
    public function rollback() {
        if ($this->txUrl) {
            $this->client->post($this->txUrl . '/fcr:tx/fcr:rollback');
            $this->txUrl = null;
        }
    }

    /**
     * Overrides the transaction URI to be used by the Fedora connection.
     * 
     * Use with care.
     * 
     * @param type $txUrl
     */
    public function setTransactionId($txUrl) {
        $this->txUrl = $txUrl;
    }

    /**
     * Commits the current Fedora transaction.
     * 
     * After the commit all the metadata modified during the transaction 
     * will be finally available in the triplestore associated with the Fedora.
     * 
     * @see begin()
     * @see rollback()
     */
    public function commit() {
        $this->client->post($this->txUrl . '/fcr:tx/fcr:commit');
        $this->txUrl = null;
    }

    /**
     * Returns true if a Fedora transaction is opened and false otherwise.
     * 
     * @return bool
     */
    public function inTransaction(): bool {
        return $this->txUrl !== null;
    }

    /**
     * Tries to switch references to all repository resources into their UUIDs.
     * 
     * Changes are done in-place!
     * @param Resource $meta metadata to apply changes to
     * @return Resource
     */
    public function fixMetadataReferences(Resource $meta): Resource {
        $properties = array_diff($meta->propertyUris(), array(RC::idProp()));
        foreach ($properties as $p) {
            foreach ($meta->allResources($p) as $obj) {
                $id  = null;
                $uri = $obj->getUri();
                try {
                    $res = $this->getResourceById($uri);
                    $id  = $res->getId();
                } catch (NotFound $e) {
                    try {
                        $res = $this->getResourceByUri($uri);
                        $id  = $res->getId();
                    } catch (NotFound $e) {
                        
                    } catch (NoAcdhId $e) {
                        
                    } catch (ClientException $e) {
                        
                    }
                } catch (NoAcdhId $e) {
                    
                } catch (ClientException $e) {
                    
                }

                if ($id !== null && $id !== $obj->getUri()) {
                    $meta->delete($p, $obj);
                    $meta->addResource($p, $id);
                }
            }
        }
        return $meta;
    }

}
