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
use EasyRdf_Sparql_Client;
use InvalidArgumentException;
use RuntimeException;
use acdhOeaw\util\EasyRdfUtil;
use zozlak\util\UUID;
use zozlak\util\Config;

/**
 * Description of Fedora
 *
 * @author zozlak
 */
class Fedora {

    /**
     */
    static public function attachData(Request $request, string $body): Request {
        $headers = $request->getHeaders();
        if (file_exists($body)) {
            $headers['Content-Type'] = mime_content_type($body);
            $body = fopen($body, 'rb');
        } elseif (is_array($body) && isset($body['contentType']) && isset($body['data'])) {
            $headers['Content-Type'] = $body['contentType'];
            $body = file_exists($body['data']) ? fopen($body, 'rb') : $body['data'];
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
     */
    private $idNamespace;

    private $sparqlClient;
    
    /**
     * Initializes all static configuratin options.
     * 
     * @param Config $cfg configuration object
     */
    public function __construct(Config $cfg) {
        $this->apiUrl = preg_replace('|/$|', '', $cfg->get('fedoraApiUrl'));
        $this->idProp = $cfg->get('fedoraIdProp');
        $this->idNamespace = $cfg->get('fedoraIdNamespace');
        $authHeader = 'Basic ' . base64_encode($cfg->get('fedoraUser') . ':' . $cfg->get('fedoraPswd'));
        $this->client = new Client(['headers' => ['Authorization' => $authHeader]]);
        $this->sparqlClient = new EasyRdf_Sparql_Client($cfg->get('sparqlUrl'));
    }

    public function getIdProp(){
        return EasyRdfUtil::fixPropName($this->idProp);
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
    public function createResource(EasyRdf_Resource $metadata, $data = '', string $path = ''): FedoraResource {
        $method = $path ? 'PUT' : 'POST';
        $path = $path ? $this->txUrl . '/' . preg_replace('|^/|', '', $path) : $this->txUrl;
        $request = new Request($method, $path);
        $request = self::attachData($request, $data);
        $resp = $this->sendRequest($request);
        $uri = $resp->getHeader('Location')[0];

        if (!$metadata->hasProperty(EasyRdfUtil::fixPropName($this->idProp))) {
            $metadata->addResource($this->idProp, $this->idNamespace . UUID::v4());
        }
        $res = new FedoraResource($this, $uri);
        $res->setMetadata($metadata);
        $res->updateMetadata();

        return $res;
    }

    public function sendRequest(Request $request): Response{
        return $this->client->send($request);
    }
    
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
     * @return \acdhOeaw\fedora\Resource
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
     * @param string $property fully quallified property URI
     * @param string $value optional property value
     * @return array
     * @see begin()
     */
    public function getResourcesByProperty(string $property, string $value = ''): array {
        $query = sprintf('SELECT ?uri ?val WHERE { ?uri %s ?val } ORDER BY ( ?val )', EasyRdfUtil::escapeUri($property));
        $res = $this->sparqlClient->query($query);
        $resources = array();
        foreach ($res as $i) {
            if ($value === '' || (string) $i->val === $value) {
                $uri = $this->sanitizeUri($i->uri);
                $resources[] = new FedoraResource($this, $uri);
            }
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
    private function sanitizeUri(string $uri) {
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