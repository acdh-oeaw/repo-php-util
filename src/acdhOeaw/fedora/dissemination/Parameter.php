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

namespace acdhOeaw\fedora\dissemination;

use RuntimeException;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotInCache;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Description of Parameter
 *
 * @author zozlak
 */
class Parameter extends FedoraResource {

    static public function transform(string $value, string $method) {
        switch ($method) {
            case '':
                return $value;
            case 'base64':
                return base64_encode($value);
            case 'url':
                return rawurlencode($value);
            default:
                throw new RuntimeException('unknown transformation');
        }
    }

    static public function value(FedoraResource $res, string $valueProp,
                                 string $default, string $method): string {
        $value = $default;

        if ($valueProp === '.') { // special case - resource URI
            $value = $res->getUri(true);
        } else if ($valueProp) {
            $matches = $res->getMetadata()->all($valueProp);
            if (count($matches) > 0) {
                $value = (string) $matches[0];
            }
        }

        return self::transform($value, $method);
    }

    public function __construct(Fedora $fedora, string $uri = '') {
        try {
            $fedora->getCache()->deleteByUri($fedora->standardizeUri($uri));
        } catch (NotInCache $ex) {
            
        }
        parent::__construct($fedora, $uri);
    }

    public function getName(): string {
        $meta = $this->getMetadata();
        return (string) $meta->getLiteral(RC::titleProp());
    }

    public function getValue(FedoraResource $res, string $method): string {
        $meta    = $this->getMetadata();
        $default = $meta->all(RC::get('fedoraServiceParamDefaultValueProp'));
        $valueProp = $meta->getResource(RC::get('fedoraServiceParamRdfPropertyProp'));
        return self::value($res, $valueProp, $default, $method);
    }

}
