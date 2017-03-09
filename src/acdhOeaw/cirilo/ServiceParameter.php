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

namespace acdhOeaw\cirilo;

use SimpleXMLElement;
use EasyRdf_Graph;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;
use zozlak\util\Config;

/**
 * Description of ServiceParameter
 *
 * @author zozlak
 */
class ServiceParameter {

    static private $cache = array();

    /**
     *
     * @var \zozlak\util\Config
     */
    static private $config;

    /**
     *
     * @var \acdhOeaw\fedora\Fedora
     */
    static private $fedora;

    static private function getFedoraResource(ServiceParameter $p, FedoraResource $service): FedoraResource {

        $id = $service->getId() . '/' . $p->title;
//echo $id;
        if (!isset(self::$cache[$id])) {
            $candidates = $service->getChildrenByProperty(self::$config->get('fedoraTitleProp'), $p->title);
            if (count($candidates) === 1) {
                self::$cache[$id] = $candidates[0];
//echo ' found ' . "\n";
            } elseif (count($candidates) === 0) {
                $metadata = (new EasyRdf_Graph())->resource('.');
                $metadata->addLiteral(self::$config->get('fedoraTitleProp'), $p->title);
                self::$cache[$id] = self::$fedora->createResource($metadata);
//echo ' not found ' . self::$cache[$id]->getUri() . "\n";
            } else {
                throw new RuntimeException('Many resources with matching given service parameter');
            }
        }

        return self::$cache[$id];
    }

    static public function init(Config $cfg, Fedora $fedora) {
        self::$config = $cfg;
        self::$fedora = $fedora;
    }

    public $title;
    public $byValue;
    public $required;
    public $defaultValue;
    public $rdfProperty;

    public function __construct(SimpleXMLElement $p, string $rdfPropPrefix) {
        $this->title = (string) $p['parmName'];
        $this->byValue = (string) $p['passBy'] == 'VALUE';
        $this->required = ((string) $p['required']) === 'true';
        $this->defaultValue = (string) $p['defaultValue'];
        $this->rdfProperty = $rdfPropPrefix . (string) $p['parmName'];
    }

    public function updateRms(FedoraResource $service) {
        $fedoraRes = self::getFedoraResource($this, $service);
        $meta = $fedoraRes->getMetadata();

        $relProp = self::$config->get('fedoraRelProp');
        $meta->delete($relProp);
        $meta->addResource($relProp, $service->getId());
        
        $titleProp = self::$config->get('fedoraTitleProp');
        $meta->delete($titleProp);
        $meta->addLiteral($titleProp, $this->title);
        
        $byValueProp = self::$config->get('fedoraServiceParamByValueProp');
        $meta->delete($byValueProp);
        $meta->addLiteral($byValueProp, $this->byValue);
        
        $requiredProp = self::$config->get('fedoraServiceParamRequiredProp');
        $meta->delete($requiredProp);
        $meta->addLiteral($requiredProp, $this->required);
        
        $defaultValueProp = self::$config->get('fedoraServiceParamDefaultValueProp');
        $meta->delete($defaultValueProp);
        $meta->addLiteral($defaultValueProp, $this->defaultValue);
        
        $redPropertyProp = self::$config->get('fedoraServiceParamRdfPropertyProp');
        $meta->delete($redPropertyProp);
        $meta->addResource($redPropertyProp, $this->rdfProperty);

        $fedoraRes->setMetadata($meta);
        $fedoraRes->updateMetadata();
//echo $fedoraRes->getUri() . "\n";
    }

}
