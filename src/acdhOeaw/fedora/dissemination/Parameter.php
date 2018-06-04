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
use acdhOeaw\fedora\dissemination\parameter\iTransformation;
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
     * Stores list of registered transformations
     * @var array
     */
    static private $transformations = [
        'add'    => 'acdhOeaw\\fedora\\dissemination\\parameter\\AddParam',
        'base64' => 'acdhOeaw\\fedora\\dissemination\\parameter\\Base64Encode',
        'part'   => 'acdhOeaw\\fedora\\dissemination\\parameter\\UriPart',
        'set'    => 'acdhOeaw\\fedora\\dissemination\\parameter\\SetParam',
        'substr' => 'acdhOeaw\\fedora\\dissemination\\parameter\\Substr',
        'url'    => 'acdhOeaw\\fedora\\dissemination\\parameter\\UrlEncode',
        'rawurldecode'    => 'acdhOeaw\\fedora\\dissemination\\parameter\\RawUrlDecode',
    ];

    /**
     * Registers a new transformation
     * @param iTransformation $transformation transformation to be registered
     */
    static public function registerTransformation(iTransformation $transformation) {
        self::$transformations[$transformation->getName()] = get_class($transformation);

        print_r(self::$transformations);
    }

    /**
     * Returns parameter value for a given resource.
     * @param FedoraResource $res repository resource to return the value for
     * @param string $valueProp RDF property holding the parameter value. If
     *   empty, the $default value is used.
     * @param string $default parameter default value
     * @param string $transformations transformations to be applied to the parameter value
     * @return string
     */
    static public function value(FedoraResource $res, string $valueProp,
                                 string $default, array $transformations): string {
        $value = $default;

        if ($valueProp !== '') {
            $matches = $res->getMetadata()->all($valueProp);
            if (count($matches) > 0) {
                $value = (string) $matches[0];
            }
        }

        foreach ($transformations as $i) {
            $matches = [];
            preg_match('|^([^(]+)([(].*[)])?$|', $i, $matches);
            $name    = $matches[1];
            if (!isset(self::$transformations[$name])) {
                throw new RuntimeException('unknown transformation');
            }

            $param = [$value];
            if (isset($matches[2])) {
                $tmp = explode(',', substr($matches[2], 1, -1));
                foreach ($tmp as $j) {
                    $param[] = trim($j);
                }
            }

            $transformation = new self::$transformations[$name]();
            $value          = $transformation->transform(...$param);
        }

        return $value;
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
     * @param string $transformations transformations to be applied to the value
     * @return string
     * @see transform()
     */
    public function getValue(FedoraResource $res, array $transformations = []): string {
        $overwrite = filter_input(INPUT_GET, $this->getName());
        if ($overwrite !== null) {
            $valueProp = '';
            $default = $overwrite;
        } else {
            $meta      = $this->getMetadata();
            $default   = $meta->all(RC::get('fedoraServiceParamDefaultValueProp'));
            $default   = count($default) > 0 ? (string) $default[0] : '';
            $valueProp = $meta->all(RC::get('fedoraServiceParamRdfPropertyProp'));
            if (count($valueProp) > 0) {
                $valueProp = (string) $valueProp[0];
            } else {
                $valueProp = '';
            }
        }
        return self::value($res, $valueProp, $default, $transformations);
    }

}
