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

/**
 * Description of Fedora
 *
 * @author zozlak
 */
class Fedora {

    static private $apiUrl;
    static private $txUrl;
    static private $client;

    static public function init(string $apiUrl, string $user, string $password) {
        self::$apiUrl = preg_replace('|/$|', '', $apiUrl);
        $authHeader = 'Basic ' . base64_encode($user . ':' . $password);
        self::$client = new Client(['headers' => ['Authorization' => $authHeader]]);
    }

    static public function createResource(EasyRdf_Graph $metadata, $data = '', string $path = '') {
        $options = [
            'body' => Psr7\stream_for($data),
            'headers' => []
        ];
        if (file_exists($data)) {
            $options['headers']['Content-Type'] = mime_content_type($data);
        } elseif (is_array($data) && isset($data['contentType']) && isset($data['data'])) {
            $options['headers']['Content-Type'] = $data['contentType'];
            $data = $data['data'];
        }

        if ($path) {
            $reqUrl = self::$txUrl . '/' . preg_replace('|^/|', '', $path);
            $resp = self::$client->put($reqUrl, $options);
        } else {
            $resp = self::$client->post(self::$txUrl, $options);
        }
        $uri = $resp->getHeader('Location')[0];

        self::updateResourceMetadata($uri, $metadata);

        return mb_substr($uri, mb_strlen(self::$txUrl) + 1);
    }

    static public function updateResourceMetadata(string $uri, EasyRdf_Graph $metadata) {
        $options = [
            'body' => sprintf('INSERT {%s} WHERE {}', self::getSparqlTriples($metadata)),
            'headers' => ['Content-Type' => 'application/sparql-update']
        ];
        self::$client->patch($uri . '/fcr:metadata', $options);
    }

    static public function getResourcesByProperty(string $property, string $value = '') {
        $query = sprintf('SELECT ?uri ?val WHERE { ?uri <%s> ?val } ORDER BY ( ?val )', $property);
        $res = SparqlEndpoint::query($query);
        $uri = [];
        foreach ($res as $i) {
            if ($value === '' || (string) $i->val === $value) {
                $uri[] = (string) $i->uri;
            }
        }
        return $uri;
    }

    static public function getResourceMetadata($uri, bool $skipTx = false) {
        $baseUrl = $skipTx || !self::$txUrl ? self::$apiUrl : self::$txUrl;

        if (self::$txUrl && mb_strpos($uri, self::$txUrl) === 0) {
            $uri = mb_substr($uri, mb_strlen(self::$txUrl) + 1);
        } elseif (self::$apiUrl && mb_strpos($uri, self::$apiUrl) === 0) {
            $uri = mb_substr($uri, mb_strlen(self::$apiUrl) + 1);
        }
        $uri = $baseUrl . '/' . $uri;
        $resp = self::$client->get($uri . '/fcr:metadata');

        $graph = new EasyRdf_Graph();
        $graph->parse($resp->getBody());
        return $graph->resource($uri);
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

    static private function getSparqlTriples(EasyRdf_Graph $graph) {
        $rdf = "\n" . $graph->serialise('ntriples') . "\n";
        $rdf = preg_replace('|\n<[^>]*>|', "\n<>", $rdf);
        return $rdf;
    }

}
