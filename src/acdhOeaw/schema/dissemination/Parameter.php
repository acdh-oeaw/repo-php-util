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

namespace acdhOeaw\schema\dissemination;

use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\schema\Object;
use acdhOeaw\fedora\Fedora;

/**
 * Each parameter is described by RDF properties:
 * 
 * - cfg:fedoraServiceParamRdfPropertyProp - an RDF property used in Fedora
 *   resources to denote given parameter value
 * - cfg:fedoraServiceParamDefaultValueProp - a default parameter value
 * - cfg:fedoraServiceParamRequiredProp - if the parameter is required
 *   (it is not really used by ACDH dissemination services)
 * - cfg:fedoraServiceParamByValueProp - if the parameter value should be
 *   treated as an URI to fetch data from (when "true") or be passed as it is
 *   (when "false").
 *   While it was an important property for Fedora 3, for ACDH dissemination
 *   services only the combination of default value equal to "." and pass by
 *   value equal to "false" is used (as it denotes the current Fedora 4 resource
 *   URI should be used as a parameter value).
 * 
 * There is a special case when a parameter is not passed by value and its
 * default value is ".". In such a case a current resource's URI is taken as the
 * parameter value.
 * This is needed because of the differences between Fedora 3 and Fedora 4 data
 * models.
 *
 * @author zozlak
 */
class Parameter extends Object {
    
    private $serviceId;
    private $byValue;
    private $required;
    private $defaultValue;
    private $rdfProperty;

    public function __construct(Fedora $fedora, string $id, Service $service, bool $byValue, bool $required, string $defaultValue, string $rdfProperty) {
        parent::__construct($fedora, $id);

        $this->serviceId = $service->getResource()->getId();
        $this->byValue = $byValue;
        $this->required = $required;
        $this->defaultValue = $defaultValue;
        $this->rdfProperty = $rdfProperty;        
    }
    
    public function getMetadata(): Resource {
        $meta = (new Graph())->resource('.');
        
        $relProp = self::$config->get('fedoraRelProp');
        $meta->addResource($relProp, $this->serviceId);

        $idProp = $this->fedora->getIdProp();
        $meta->addResource($idProp, $this->getId());
        
        $titleProp = self::$config->get('fedoraTitleProp');
        $meta->addLiteral($titleProp, $this->getId());
        
        $byValueProp = self::$config->get('fedoraServiceParamByValueProp');
        $meta->addLiteral($byValueProp, $this->byValue);
        
        $requiredProp = self::$config->get('fedoraServiceParamRequiredProp');
        $meta->addLiteral($requiredProp, $this->required);
        
        $defaultValueProp = self::$config->get('fedoraServiceParamDefaultValueProp');
        $meta->addLiteral($defaultValueProp, $this->defaultValue);
        
        $redPropertyProp = self::$config->get('fedoraServiceParamRdfPropertyProp');
        $meta->addLiteral($redPropertyProp, $this->rdfProperty);
        
        return $meta;
    }
}
