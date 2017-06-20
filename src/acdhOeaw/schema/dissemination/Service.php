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

use InvalidArgumentException;
use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\schema\Object;
use acdhOeaw\util\RepoConfig as RC;

/**
 * ACDH dessimination service is modeled as a Fedora resource described by
 * RDF properties:
 * 
 * - cfg:fedoraTitleProp - a service name
 * - cfg:fedoraServiceLocProp - a service location containing parameter bindings
 *   (copy of the Fedora 3 wsdl:binding/wsdl:operation/http:operation/@location)
 * - cfg:fedoraServiceRetMimeProp - a MIME type provided by this service
 * - cfg:fedoraServiceSupportsProp - a class supported by this service
 *   (use ldp:Container to support all resources in the repository)
 * - cfg:ciriloIdProp - a Fedora 3 service ID used to match a resource in 
 *   Fedora 4 with a corresponding service deployment method of Fedora 3
 * 
 * Dissemination service parameters are modeled as its child resources
 * (cfg:fedoraRelProp) - see Parameter class description for details.
 *
 * @author zozlak
 */
class Service extends Object {

    private $location;
    private $retMime;
    private $params   = array();
    private $supports = array();

    public function __construct(Fedora $fedora, string $id, string $location,
                                string $retMime, array $supports) {
        parent::__construct($fedora, $id);

        if ($id == '' || $location == '' || $retMime == '' || count($supports) == 0) {
            throw new InvalidArgumentException('title, location, mime type and supported classes have to be specified');
        }

        $this->location = $location;
        $this->retMime  = $retMime;
        $this->supports = $supports;
        $this->fedora   = $fedora;
    }

    public function addParameter(string $name, bool $byValue, bool $required,
                                 string $defaultValue = '',
                                 string $rdfProperty = '_') {
        $id             = $this->getId() . '/' . $name;
        $this->params[] = new Parameter(
            $this->fedora, $id, $this, $name, $byValue, $required, $defaultValue, $rdfProperty
        );
    }

    public function getMetadata(): Resource {
        $meta = (new Graph())->resource('.');

        $meta->addType(RC::get('fedoraServiceClass'));
        
        $meta->addResource(RC::idProp(), $this->getId());

        $meta->addLiteral(RC::titleProp(), $this->getId());

        $meta->addLiteral(RC::get('fedoraServiceLocProp'), $this->location);

        $retProp = RC::get('fedoraServiceRetMimeProp');
        $meta->addLiteral($retProp, $this->retMime);

        $supProp = RC::get('fedoraServiceSupportsProp');
        foreach ($this->supports as $i) {
            $meta->addResource($supProp, $i);
        }

        return $meta;
    }

    public function updateRms(bool $create = true, bool $uploadBinary = true,
                              string $path = '/'): FedoraResource {
        parent::updateRms($create, $uploadBinary, $path);

        foreach ($this->params as $i) {
            $i->updateRms($create, $uploadBinary, $path);
        }

        return $this->getResource(false, false);
    }

}
