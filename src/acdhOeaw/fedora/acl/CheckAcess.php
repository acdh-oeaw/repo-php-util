<?php

/*
 * The MIT License
 *
 * Copyright 2018 zozlak.
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

namespace acdhOeaw\fedora\acl;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use acdhOeaw\fedora\FedoraResource;

/**
 * Checks if credentials provided with a currently handled request allow
 * access to a given Fedora resource
 *
 * @author zozlak
 */
class CheckAcess {

    /**
     *
     * @var \GuzzleHttp\Client 
     */
    static private $client;

    /**
     * Checks if a given resource is accessible with same credentials as ones
     * provided in the current request.
     * @param FedoraResource $res
     * @return bool
     * @throws RequestException
     */
    static public function check(FedoraResource $res): bool {
        if (self::$client === null) {
            self::$client = new Client([
                'verify'  => false,
            ]);
        }

        $headers = [];
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
        }
        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            $cookies[] = $k . '=' . $v;
        }
        if (count($cookies) > 0) {
            $headers['Cookie'] = implode('; ', $cookies);
        }

        $req = new Request('GET', $res->getUri() . '/fcr:metadata', $headers);
        try {
            $resp = self::$client->send($req);
            return true;
        } catch (RequestException $ex) {
            if ($ex->hasResponse()) {
                $resp = $ex->getResponse();
                if (in_array($resp->getStatusCode(), [401, 403])) {
                    return false;
                }
            }
            throw $ex;
        }
    }

}
