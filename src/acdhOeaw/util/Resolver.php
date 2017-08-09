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

namespace acdhOeaw\util;

use RuntimeException;
use zozlak\util\Config;
use GuzzleHttp\Psr7\Client;
use GuzzleHttp\Psr7\Request;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasValue;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Description of Resolver
 *
 * @author zozlak
 */
class Resolver {

    const TYPE_PROP      = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
    const SUB_CLASS_PROP = 'http://www.w3.org/2000/01/rdf-schema#subClassOf';

    static public $debug = false;
    private $config;

    public function __construct(string $configFile, string $host) {
        $this->config = new Config($configFile, true);
        $noConfig     = true;
        foreach ($this->config as $key => $value) {
            if (is_array($value) && isset($value['baseUrl']) && preg_replace('|^https?://|', '', $value['baseUrl']) === $host) {
                $this->config->set('baseUrl', $value['baseUrl']);
                $this->config->set('property', $value['property']);
                $noConfig = false;
                break;
            }
        }
        if ($noConfig) {
            throw new RuntimeException('No configuration found for ' . $host, 500);
        }
    }

    public function resolve() {
        $resId    = $this->config->get('baseUrl') . filter_input(\INPUT_SERVER, 'REDIRECT_URL');
        $res      = $this->findResource($resId);
        $dissServ = $res->getDissServices();

        $accept  = $this->parseAccept();
        $request = new Request('GET', $res->getUri(true));
        foreach ($accept as $mime) {
            if (isset($dissServ[$mime])){
                $request = $dissServ[$mime]->getRequest($res);
            }            
        }
        $this->redirect($request->getUri());
        return;
    }

    private function findResource(string $resId): FedoraResource {
        $fedora = new Fedora();
        $res    = $fedora->getResourcesByProperty($this->config->get('property'), $resId);
        if (count($res) == 0) {
            throw new RuntimeException('Not Found', 404);
        } elseif (count($res) > 1) {
            throw new RuntimeException('Internal Server Error - many resources with the given URI', 500);
        }
        return $res[0];
    }

    private function parseAccept(): array {
        $accept       = array();
        $acceptHeader = trim(filter_input(\INPUT_SERVER, 'HTTP_ACCEPT'));
        if ($acceptHeader != '') {
            $tmp = explode(',', $acceptHeader);
            foreach ($tmp as $i) {
                $i    = explode(';', $i);
                $i[0] = trim($i[0]);
                if (count($i) >= 2) {
                    $accept[$i[0]] = floatval(preg_replace('|[^.0-9]|', '', $i[1]));
                } else {
                    $accept[$i[0]] = 1;
                }
            }
            arsort($accept);
            $accept = array_keys($accept);
        }
        $format = filter_input(\INPUT_GET, 'format');
        if ($format) {
            array_unshift($accept, $format);
        }
        return $accept;
    }

    private function redirect(string $location) {
        if (self::$debug) {
            echo 'Location: ' . $location . "\n";
        } else {
            header('Location: ' . $location);
        }
    }

//    private function useDissService(DisseminationService $service) {
//        switch ($this->config->get('mode')) {
//            case 'POST':
//                break;
//            case 'GET':
//                break;
//            default:
//                $this->redirect($service->getUrl());
//        }
//    }
//
//    private function aaa() {
//        $options               = array();
//        $options['sink']       = $output;
//        $options['on_headers'] = function(Response $r) {
//            $this->filterHeaders($r);
//        };
//        $options['verify'] = false;
//        $client            = new Client($options);
//
//        $output = fopen('php://output', 'w');
//    }
}
