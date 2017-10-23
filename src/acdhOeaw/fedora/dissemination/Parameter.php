<?php

/**
 * The MIT License
 *
 * Copyright 2017 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\fedora\dissemination;

use RuntimeException;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Represents a dissemination service parameter.
 *
 * For a description of the dissemination services data model see the 
 * `\acdhOeaw\schema\dissemination\Service` class description.
 * @author zozlak
 * @see \acdhOeaw\schema\dissemination\Service
 */
class Parameter extends FedoraResource {

    /**
     * Performs parameter value transformation.
     * 
     * Supported transformations are:
     * - identity (no transformation)
     * - base64 encoding
     * - url encoding
     * @param string $value value to be transformed
     * @param string $method transformation to be applied (''/'base64'/'url')
     * @return string
     * @throws RuntimeException
     */
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

    /**
     * Returns parameter value for a given resource.
     * @param FedoraResource $res repository resource to return the value for
     * @param string $valueProp RDF property holding the parameter value
     * @param string $default parameter default value
     * @param string $method transformation to be applied on the parameter value
     * @return string
     * @see transform()
     */
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

    /**
     * Creates a dissemination service parameter object.
     * @param Fedora $fedora repository connection object
     * @param string $uri UTI of the repository resource representing the 
     *   dissemination service parameter
     */
    public function __construct(Fedora $fedora, string $uri = '') {
        try {
            $fedora->getCache()->deleteByUri($fedora->standardizeUri($uri));
        } catch (NotInCache $ex) {

        }
        parent::__construct($fedora, $uri);
    }
    
    /**
     * Returns parameter name
     * @return string
     */
    public function getName(): string {
        $meta = $this->getMetadata();
        return (string) $meta->getLiteral(RC::titleProp());
    }

    /**
     * Return parameter value for a given repository resource
     * @param FedoraResource $res repository resource to get the value for
     * @param string $method transformation to be applied on the value
     * @return string
     * @see transform()
     */
    public function getValue(FedoraResource $res, string $method): string {
        $meta      = $this->getMetadata();
        $default   = $meta->all(RC::get('fedoraServiceParamDefaultValueProp'));
        $default   = count($default) > 0 ? (string) $default[0] : '';
        $valueProp = $meta->all(RC::get('fedoraServiceParamRdfPropertyProp'));
        $valueProp = count($valueProp) > 0 ? (string) $valueProp[0] : '';
        return self::value($res, $valueProp, $default, $method);
    }

}
