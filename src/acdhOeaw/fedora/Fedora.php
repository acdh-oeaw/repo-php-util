<?php

/**
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
use EasyRdf_Resource;
use EasyRdf_Sparql_Client;
use RuntimeException;
use BadMethodCallException;
use acdhOeaw\util\EasyRdfUtil;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasProperty;
use acdhOeaw\fedora\metadataQuery\HasValue;
use acdhOeaw\fedora\metadataQuery\MatchesRegEx;
use zozlak\util\Config;

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
            $headers['Content-Type'] = mime_content_type($body);
            $body = fopen($body, 'rb');
        } elseif (is_array($body) && isset($body['contentType']) && isset($body['data'])) {
            $headers['Content-Type'] = $body['contentType'];
            $body = file_exists($body['data']) ? fopen($body, 'rb') : $body['data'];
        } elseif (preg_match('|^[a-z0-9]+://|i', $body)) {
            $headers['Content-Type'] = 'message/external-body; access-type=URL; URL="' . $body . '"';
            $body = null;
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
     * Fully qualified URI of the RDF property used to identify resources
     * 
     * @var string 
     * @see getId()
     * @see getIds()
     * @see updateContent()
     */
    private $idProp;

    /**
     * Sparql client object
     * @var \EasyRdf_Sparql_Client
     */
    private $sparqlClient;
    private $relProp;

    public function getRelProp() {
        return $this->relProp;
    }

    public function query($query) {
        return $this->sparqlClient->query($query);
    }

    /**
     * Creates Fedora connection object from a given config.
     * 
     * Required configuration parameters include:
     * 
     * - fedoraApiUrl - base URL of the Fedora REST API
     * - fedoraIdProp - URI of the RDF property denoting resource ACDH ID
     * - fedoraUser - login required to connect to the Fedora REST API
     * - fedoraPswd - password required to connect to the Fedora REST API
     * - sparqlUrl - SPARQL endpoint URL
     * 
     * @param \zozlak\util\Config $cfg configuration object
     */
    public function __construct(Config $cfg) {
        $this->apiUrl = preg_replace('|/$|', '', $cfg->get('fedoraApiUrl'));
        $this->idProp = $cfg->get('fedoraIdProp');
        $this->relProp = $cfg->get('fedoraRelProp');
        $authHeader = 'Basic ' . base64_encode($cfg->get('fedoraUser') . ':' . $cfg->get('fedoraPswd'));
        $this->client = new Client(['headers' => ['Authorization' => $authHeader]]);
        $this->sparqlClient = new EasyRdf_Sparql_Client($cfg->get('sparqlUrl'));
    }

    /**
     * Returns URI of the RDF property denoting ACDH ID as set upon object creation.
     * @return string
     */
    public function getIdProp(): string {
        return EasyRdfUtil::fixPropName($this->idProp);
    }

    /**
     * Creates a resource in the Fedora and returns corresponding Resource object
     * 
     * @param EasyRdf_Resource $metadata resource metadata
     * @param mixed $data optional resource data as a string, 
     *   file name or an array: ['content-type' => 'foo', 'data' => 'bar']
     * @param string $path optional Fedora resource path (see also the `$method`
     *   parameter)
     * @param string $method creation method to use - POST or PUT, by default POST
     * @return \acdhOeaw\rms\FedoraResource
     * @throws \BadMethodCallException
     */
    public function createResource(EasyRdf_Resource $metadata, $data = '', string $path = '', string $method = 'POST'): FedoraResource {
        if (!in_array($method, array('POST', 'PUT'))) {
            throw new BadMethodCallException('method must be PUT or POST');
        }
        $path = $path ? $this->txUrl . '/' . preg_replace('|^/|', '', $path) : $this->txUrl;
        $request = new Request($method, $path);
        $request = self::attachData($request, $data);
        $resp = $this->sendRequest($request);
        $uri = $resp->getHeader('Location')[0];
        $res = new FedoraResource($this, $uri);

        // merge the metadata created by Fedora (and Doorkeeper) upon resource creation
        // with the ones provided by user
        $curMetadata = $res->getMetadata();
        foreach ($metadata->propertyUris() as $prop) {
            $prop = EasyRdfUtil::fixPropName($prop);
            if ($curMetadata->hasProperty($prop)) {
                $curMetadata->delete($prop);
            }
            foreach ($metadata->allLiterals($prop) as $i) {
                $curMetadata->addLiteral($prop, $i->getValue());
            }
            foreach ($metadata->allResources($prop) as $i) {
                $curMetadata->addResource($prop, $i->getUri());
            }
        }

        $res->setMetadata($curMetadata);
        $res->updateMetadata();

        return $res;
    }

    /**
     * Sends a given HTTP request to the Fedora.
     * 
     * @param Request $request request to be send
     * @return GuzzleHttp\Psr7\Response
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
        $uri = $this->sanitizeUri($uri);
        return new FedoraResource($this, $uri);
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
     * @return \acdhOeaw\fedora\FedoraResource
     * @throws RuntimeException
     * @see getResourcesById()
     */
    public function getResourceById(string $value): FedoraResource {
        $res = $this->getResourcesByProperty($this->idProp, $value);
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
    public function getResourcesById(string $value): array {
        return $this->getResourcesByProperty($this->idProp, $value);
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
     * @return array
     * @see begin()
     */
    public function getResourcesByProperty(string $property, string $value = ''): array {
        $query = new Query();
        if ($value != '') {
            $param = new HasValue($property, $value);
        } else {
            $param = new HasProperty($property);
        }
        $query->addParameter($param);
        return $this->getResourcesByQuery($query);
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
     * @return array
     * @see begin()
     */
    public function getResourcesByPropertyRegEx(string $property, string $regEx, string $flags = 'i'): array {
        $query = new Query();
        $query->addParameter(new MatchesRegEx($property, $regEx, $flags));
        return $this->getResourcesByQuery($query);
    }

    public function getResourcesByQuery(Query $query, string $resVar = '?res') {
        $resVar = preg_replace('|^[?]|', '', $resVar);
        $query = $query->getQuery();
//echo "\n".$query;
        $results = $this->sparqlClient->query($query);
        $resources = array();
        foreach ($results as $i) {
            $uri = $i->$resVar;
            $uri = $this->sanitizeUri($uri);
            $resources[] = new FedoraResource($this, $uri);
        }
        return $resources;
    }

    /**
     * Adjusts URI to the current object state by setting up the proper base
     * URL and the transaction id.
     * 
     * @param string $uri resource URI
     * @return string 
     */
    public function sanitizeUri(string $uri) {
        $baseUrl = !$this->txUrl ? $this->apiUrl : $this->txUrl;
        $uri = preg_replace('|^https?://[^/]+/rest/(tx:[-0-9a-zA-Z]+/)?|', '', $uri);
        $uri = $baseUrl . '/' . $uri;
        return $uri;
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
    public function begin() {
        $resp = $this->client->post($this->apiUrl . '/fcr:tx');
        $this->txUrl = $resp->getHeader('Location')[0];
    }

    /**
     * Rolls back the current Fedora transaction.
     * 
     * @see begin()
     * @see commit()
     */
    public function rollback() {
        $this->client->post($this->txUrl . '/fcr:tx/fcr:rollback');
        $this->txUrl = null;
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
     * will be finally uvailable in the triplestore associated with the Fedora.
     * 
     * @see begin()
     * @see rollback()
     */
    public function commit() {
        $this->client->post($this->txUrl . '/fcr:tx/fcr:commit');
        $this->txUrl = null;
    }

    /**
     * Retruns true if a Fedora transaction is opened and false otherwise.
     * 
     * @return bool
     */
    public function inTransaction(): bool {
        return $this->txUrl !== null;
    }

}
