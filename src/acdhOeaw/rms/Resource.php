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

namespace acdhOeaw\rms;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use EasyRdf_Graph;
use EasyRdf_Resource;
use acdhOeaw\EasyRdfUtil;
use zozlak\util\UUID;
use zozlak\util\Config;

/**
 * Represents already existing Fedora resource
 * 
 * Static functions allow to manage Fedora session
 * create new resources and search for existing ones
 *
 * @author zozlak
 */
class Resource {

    static private $apiUrl;
    static private $txUrl;
    static private $client;
    static private $idProp;
    static private $idNamespace;

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
     * @param EasyRdf_Resource $metadata resource metadata
     * @param mixed $data optional resource data as a string, file name or an array: ['content-type' => 'foo', 'data' => 'bar']
     * @param string $path optional Fedora resource path (if empty, resource will be created in the Fedora root)
     * @return type
     */
    static public function factory(EasyRdf_Resource $metadata, $data = '', string $path = ''): Resource {
        $headers = array();
        if (file_exists($data)) {
            $headers['Content-Type'] = mime_content_type($data);
            $data = fopen($data, 'rb');
        } elseif (is_array($data) && isset($data['contentType']) && isset($data['data'])) {
            $headers['Content-Type'] = $data['contentType'];
            if (file_exists($data['data'])) {
                $data = fopen($data, 'rb');
            }
        }
        $options = array(
            'body' => Psr7\stream_for($data),
            'headers' => $headers
        );

        if ($path) {
            $reqUrl = self::$txUrl . '/' . preg_replace('|^/|', '', $path);
            $resp = self::$client->put($reqUrl, $options);
        } else {
            $resp = self::$client->post(self::$txUrl, $options);
        }
        $uri = $resp->getHeader('Location')[0];

        $metadata->addResource(self::$idProp, self::$idNamespace . UUID::v4());
        $res = new Resource($uri, false);
        $res->setMetadata($metadata);
        $res->update();

        return $res;
    }

    static public function getResourcesById(string $value) {
        return self::getResourcesByProperty(self::$idProp, $value);
    }

    static public function getResourcesByProperty(string $property, string $value = '') {
        $query = sprintf('SELECT ?uri ?val WHERE { ?uri %s ?val } ORDER BY ( ?val )', EasyRdfUtil::escapeUri($property));
        $res = SparqlEndpoint::query($query);
        $resources = array();
        foreach ($res as $i) {
            if ($value === '' || (string) $i->val === $value) {
                $resources[] = new Resource((string) $i->uri, false);
            }
        }
        return $resources;
    }

    static private function sanitizeUri(string $uri, bool $skipTx) {
        $baseUrl = $skipTx || !self::$txUrl ? self::$apiUrl : self::$txUrl;

        if (self::$txUrl && mb_strpos($uri, self::$txUrl) === 0) {
            $uri = mb_substr($uri, mb_strlen(self::$txUrl) + 1);
        } elseif (self::$apiUrl && mb_strpos($uri, self::$apiUrl) === 0) {
            $uri = mb_substr($uri, mb_strlen(self::$apiUrl) + 1);
        }
        $uri = $baseUrl . '/' . $uri;
        return $uri;
    }

    static public function begin() {
        $resp = self::$client->post(self::$apiUrl . '/fcr:tx');
        self::$txUrl = $resp->getHeader('Location')[0];
    }

    static public function rollback() {
        self::$client->post(self::$txUrl . '/fcr:tx/fcr:rollback');
        self::$txUrl = null;
    }

    static public function commit() {
        self::$client->post(self::$txUrl . '/fcr:tx/fcr:commit');
        self::$txUrl = null;
    }

    private $uri;

    /**
     * @var EasyRdf_Resource
     */
    private $metadata;

    public function __construct(string $uri, bool $skipTx = false) {
        $this->uri = self::sanitizeUri($uri, $skipTx);
    }

    public function getUri(): string {
        return $this->uri;
    }

    public function getIds(): array {
        $this->getMetadata();
        $ids = array();
        foreach ($this->metadata->allResources(EasyRdfUtil::fixPropName(self::$idProp)) as $i) {
            $ids[] = $i->getUri();
        }
        return $ids;
    }

    public function setMetadata(EasyRdf_Resource $metadata) {
        $this->metadata = $metadata;
    }

    public function update() {
        $options = [
            'body' => sprintf('INSERT {%s} WHERE {}', $this->getSparqlTriples()),
            'headers' => ['Content-Type' => 'application/sparql-update']
        ];
        self::$client->patch($this->uri . '/fcr:metadata', $options);
    }

    public function getMetadata(): EasyRdf_Resource {
        if (!$this->metadata) {
            $resp = self::$client->get($this->uri . '/fcr:metadata');

            $graph = new EasyRdf_Graph();
            $graph->parse($resp->getBody());
            $this->metadata = $graph->resource($this->uri);
        }
        return $this->metadata;
    }

    private function getSparqlTriples(): string {
        $rdf = "\n" . $this->metadata->getGraph()->serialise('ntriples') . "\n";
        $rdf = preg_replace('|\n<[^>]*>|', "\n<>", $rdf);
        return $rdf;
    }
    
}
