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

namespace acdhOeaw\schema\dissemination;

use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\schema\SchemaObject;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Each parameter is described by RDF properties:
 * 
 * - cfg:fedoraServiceParamRdfPropertyProp - an RDF property used in Fedora
 *   resources to denote given parameter value
 * - cfg:fedoraServiceParamDefaultValueProp - a default parameter value
 * 
 * Parameters are used only to extract a "raw" value which then can be transformed
 * according to rules set in service's location string.
 * 
 * Typically you shouldn't create the Parameter object on your own but use the
 * `\achdOeaw\schema\dissemination\Service::addParameter()` method instead.
 * 
 * @author zozlak
 */
class Parameter extends SchemaObject {

    /**
     * Dissemination service parameter id
     * @var string 
     */
    private $serviceId;

    /**
     * Dissemination service parameter name
     * @var string 
     */
    private $name;

    /**
     * Parameter default value.
     * @var string 
     */
    private $defaultValue;

    /**
     * RDF property holding parameter value in resources' metadata.
     * @var string
     */
    private $rdfProperty;

    /**
     * Creates the parameter object
     * @param \acdhOeaw\fedora\Fedora $fedora repository connection object
     * @param string $id parameter id
     * @param \acdhOeaw\schema\dissemination\Service $service dissemination
     *   service using this parameter
     * @param string $name parameter name
     * @param string $defaultValue default parameter value
     * @param string $rdfProperty RDF property storing parameter value in 
     *   resources' metadata
     */
    public function __construct(Fedora $fedora, string $id, Service $service,
                                string $name, string $defaultValue,
                                string $rdfProperty) {
        parent::__construct($fedora, $id);

        $this->serviceId    = $service->getResource()->getId();
        $this->name         = $name;
        $this->defaultValue = $defaultValue;
        $this->rdfProperty  = $rdfProperty;
    }

    /**
     * Returns metadata describing the dissemination service parameter
     * @return Resource
     */
    public function getMetadata(): Resource {
        $meta = (new Graph())->resource('.');

        $meta->addResource(RC::relProp(), $this->serviceId);
        $meta->addResource(RC::idProp(), $this->getId());
        $meta->addType(RC::get('fedoraServiceParamClass'));

        $titleProp = RC::get('fedoraTitleProp');
        $meta->addLiteral($titleProp, $this->name);

        $defaultValueProp = RC::get('fedoraServiceParamDefaultValueProp');
        $meta->addLiteral($defaultValueProp, $this->defaultValue);

        $redPropertyProp = RC::get('fedoraServiceParamRdfPropertyProp');
        $meta->addLiteral($redPropertyProp, $this->rdfProperty);

        return $meta;
    }

}
