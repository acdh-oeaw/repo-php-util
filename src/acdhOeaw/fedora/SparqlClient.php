<?php

/*
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
 */

namespace acdhOeaw\fedora;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use EasyRdf_Sparql_Result;

/**
 * Simple SPARQL client with HTTP basic authentication support.
 * 
 * There is no support for UPDATE queries.
 *
 * @author zozlak
 */
class SparqlClient {

    /**
     * SPARQL endpoint URL
     * @var string
     */
    private $url;
    /**
     * Guzzle client object
     * @var GuzzleHttp\Client
     */
    private $client;

    /**
     * Creates SPARQL client object.
     * 
     * @param string $url SPARQL endpoint URL
     * @param string $user HTTP basic authentication user name
     * @param string $password HTTP basic authentication password
     */
    public function __construct(string $url, string $user = '', string $password = '') {
        $this->url = $url;

        $headers = array(
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/sparql-results+json'
        );
        if ($user != '' && $password != '') {
            $authHeader = 'Basic ' . base64_encode($user . ':' . $password);
            $headers['Authorization'] = $authHeader;
        }
        $this->client = new Client(['headers' => $headers]);
    }

    /**
     * Runs a given query.
     * 
     * There is no support for UPDATE queries.
     * 
     * @param string $query SPARQL query to be run
     * @return EasyRdf_Sparql_Result
     */
    public function query(string $query): EasyRdf_Sparql_Result {
        $request = new Request('GET', $this->url . '?query=' . rawurlencode($query));
        $response = $this->client->send($request);
        $body = $response->getBody();
        $result = new EasyRdf_Sparql_Result($body, 'application/sparql-results+json');
        return $result;
    }

}
