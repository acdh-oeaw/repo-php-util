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
use GuzzleHttp\Psr7\Request;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotInCache;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Represents a dissemination service.
 *
 * For a description of the dissemination services data model see the 
 * `\acdhOeaw\schema\dissemination\Service` class description.
 * @author zozlak
 * @see \acdhOeaw\schema\dissemination\Service
 */
class Service extends FedoraResource {

    /**
     * Parameters list
     * @var array
     */
    private $param = array();

    /**
     * Creates a dissemination service object.
     * @param Fedora $fedora repository connection object
     * @param string $uri UTI of the repository resource representing the 
     *   dissemination service
     */
    public function __construct(Fedora $fedora, string $uri = '') {
        try {
            $fedora->getCache()->deleteByUri($fedora->standardizeUri($uri));
        } catch (NotInCache $ex) {
            
        }
        parent::__construct($fedora, $uri);

        $typeProp = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
        $type     = RC::get('fedoraServiceParamClass');
        foreach ($this->getChildrenByProperty($typeProp, $type) as $i) {
            $param = new Parameter($fedora, $i->getUri(true));

            $this->param[$param->getName()] = $param;
        }
    }

    /**
     * Returns all return formats provided by the dissemination service.
     * 
     * Technically return formats are nothing more then strings. There is no
     * requirement forcing them to be mime types, etc. 
     * @return array
     */
    public function getFormats(): array {
        $meta    = $this->getMetadata();
        $formats = array();
        foreach ($meta->all(RC::get('fedoraServiceRetFormatProp')) as $i) {
            $formats[] = new Format((string) $i);
        }
        return $formats;
    }

    /**
     * Returns PSR-7 HTTP request disseminating a given resource.
     * @param FedoraResource $res repository resource to be disseminated
     * @return Request
     * @throws RuntimeException
     */
    public function getRequest(FedoraResource $res): Request {
        $uri    = $this->getLocation();

        $param  = $this->getParameters();
        $values = $this->getParameterValues($param, $res);
        foreach ($values as $k => $v) {
            $uri = str_replace($k, $v, $uri);
        }

        return new Request('GET', $uri);
    }

    /**
     * Gets disseminations service's URL (before parameters subsitution)
     * @return string
     */
    public function getLocation(): string {
        $meta = $this->getMetadata();
        return $meta->getLiteral(RC::get('fedoraServiceLocProp'));
    }

    /**
     * Should the dissemination service request be reverse-proxied?
     * 
     * If it's not set in the metadata, false is assumed.
     * @return bool
     */
    public function getRevProxy(): bool {
        $meta = $this->getMetadata();
        $value = $meta->getLiteral(RC::get('fedoraServiceRevProxyProp'))->getValue();
        return $value ?? false;
    }
    
    /**
     * Returns list of all parameters of a given dissemination service
     * @return array
     */
    private function getParameters(): array {
        $uri    = $this->getLocation();
        $param  = [];
        preg_match_all('#{[^}]+}#', $uri, $param);
        return $param[0];
    }

    /**
     * Evaluates parameter values for a given resource.
     * @param array $param list of parameters
     * @param FedoraResource $res repository resource to be disseminated
     * @return array associative array with parameter values
     * @throws RuntimeException
     */
    private function getParameterValues(array $param, FedoraResource $res): array {
        $values = [];
        foreach ($param as $i) {
            $ii   = explode('|', substr($i, 1, -1));
            $name = array_shift($ii);

            if ($name === 'RES_URI') {
                $value = Parameter::value($res, '', $res->getUri(true), $ii);
            } else if ($name === 'RES_ID') {
                $value = Parameter::value($res, '', $res->getId(), $ii);
            } else if (isset($this->param[$name])) {
                $value = $this->param[$name]->getValue($res, $ii);
            } else {
                throw new RuntimeException('unknown parameter ' . $name);
            }
            $values[$i] = $value;
        }

        return $values;
    }

}
