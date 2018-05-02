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
use acdhOeaw\schema\SchemaObject;
use acdhOeaw\util\RepoConfig as RC;

/**
 * ACDH dessimination service is modeled as a Fedora resource described by
 * RDF properties:
 * 
 * - cfg:fedoraTitleProp - a service name
 * - cfg:fedoraServiceLocProp - a service location containing parameter bindings.
 *   A place where parameter is ingested is marked with the "{param_name}" syntax.
 *   Transformations can be applied to the parameter value using the
 *   "{param_name|part(path)|url}" syntax (transformations  can be chained as on 
 *   the example). Transformations shipped with the repo-php-util are provided
 *   in the acdhOeaw\fedora\dissemination\parameter namespace. You can register
 *   other using the acdhOeaw\fedora\dissemination\Parameter::registerTransformation(iTransformation $transformation)
 *   static method before calling acdhOeaw\fedora\dissmination\Service::getRequest().
 *   There are two special parameters: RES_URI and RES_ID (latter one being
 *   resource's repository internal UUID)
 * - cfg:fedoraServiceRetFormatProp - a MIME type provided by this service.
 *   It can contain a weight as used in the HTTP Accept header (e.g. "text/plain; q=0.5")
 * 
 * Dissemination service parameters are modeled as its child resources
 * (cfg:fedoraRelProp) - see Parameter class description for details.
 * 
 * Matching between repository resources and dissemination services can be
 * described in two ways:
 * - using the cfg:fedoraHasServiceProp pointing directly from the resource's
 *   metadata to the dissemination service
 * - by a matching rules defined with the `Service::addMatch()` method
 *   (see below)
 *
 * @author zozlak
 */
class Service extends SchemaObject {

    /**
     * Dissemination service location (URL).
     * 
     * It should contain references to all parameters passed by the query URL
     * in form of `{parameterName|transformation}`. Remarks:
     * - There is also a special `RES_URI` parameter available providing the URI 
     *   of the resource being disseminated
     * - For available transformations see the 
     *   `\acdhOeaw\fedora\dissemination\Parameter::transform()`.
     * 
     * An example value: `https://my.service?res={RES_URI|url}&myParam={myParam|}
     * 
     * @var string
     * @see \acdhOeaw\fedora\dissemination\Parameter::transform()
     */
    private $location;

    /**
     * Return formats provided by the service
     * @var array
     */
    private $format = array();

    /**
     * Should calls to the diss service be reverse proxied?
     * @var bool
     */
    private $revProxy;
    
    /**
     * Parameters used by the service
     * @var array
     */
    private $params = array();

    /**
     * Resources matching rules for the service
     * @var array
     */
    private $matches = array();

    /**
     * Creates an object representing the dissemination service
     * @param Fedora $fedora repository connection object
     * @param string $id dissemination service id
     * @param string $location dissemination service location (see the 
     *   `$location` property description)
     * @param array $format list of return types provided by the dissemination
     *   service
     * @param bool $revProxy should calls to the diss service be reverse proxied?
     * @throws InvalidArgumentException
     */
    public function __construct(Fedora $fedora, string $id, string $location,
                                array $format, bool $revProxy) {
        parent::__construct($fedora, $id);

        if ($id == '' || $location == '' || $format == '') {
            throw new InvalidArgumentException('title, location and mime type have to be specified');
        }

        $this->location = $location;
        $this->format   = $format;
        $this->fedora   = $fedora;
        $this->revProxy = $revProxy;
    }

    /**
     * Defines a dissemination service parameter.
     * @param string $name parameter name
     * @param string $defaultValue default parameter value
     * @param string $rdfProperty RDF property holding parameter value in
     *   resources' metadata
     */
    public function addParameter(string $name, string $defaultValue = '',
                                 string $rdfProperty = '_') {
        $id             = $this->getId() . '/param/' . $name;
        $this->params[] = new Parameter(
            $this->fedora, $id, $this, $name, $defaultValue, $rdfProperty
        );
    }

    /**
     * Defines a matching rule for the dissemination service
     * @param string $property RDF property to be checked in resource's metadata
     * @param string $value expected RDF property value in resource's metadata
     * @param bool $required is this match rule compulsory?
     * @see \acdhOeaw\schema\dissemination\Match
     */
    public function addMatch(string $property, string $value, bool $required) {
        $id              = $this->getid() . '/match/' . (count($this->matches) + 1);
        $this->matches[] = new Match($this->fedora, $id, $this, $property, $value, $required);
    }

    /**
     * Returns metadata describing the dissemination service.
     * @return Resource
     */
    public function getMetadata(): Resource {
        $meta = (new Graph())->resource('.');

        $meta->addType(RC::get('fedoraServiceClass'));

        $meta->addResource(RC::idProp(), $this->getId());

        $meta->addLiteral(RC::titleProp(), $this->getId());

        $meta->addLiteral(RC::get('fedoraServiceLocProp'), $this->location);

        $retProp = RC::get('fedoraServiceRetFormatProp');
        foreach ($this->format as $i) {
            $meta->addLiteral($retProp, $i);
        }
        
        $meta->addLiteral(RC::get('fedoraServiceRevProxyProp'), $this->revProxy);

        return $meta;
    }

    /**
     * Updates the dissemination service definition in the repository.
     * @param bool $create should repository resource be created if it does not
     *   exist?
     * @param bool $uploadBinary should binary data of the real-world entity
     *   be uploaded uppon repository resource creation?
     * @param string $path where to create a resource (if it does not exist).
     *   If it it ends with a "/", the resource will be created as a child of
     *   a given collection). All the parents in the Fedora resource tree have
     *   to exist (you can not create "/foo/bar" if "/foo" does not exist already).
     * @return FedoraResource
     */
    public function updateRms(bool $create = true, bool $uploadBinary = true,
                              string $path = '/'): FedoraResource {
        parent::updateRms($create, $uploadBinary, $path);

        $res      = $this->getResource(false, false);
        $children = [];
        foreach ($res->getChildren() as $i) {
            $children[$i->getUri(true)] = $i;
        }
        $validChildren = [];

        foreach ($this->params as $i) {
            $tmp             = $i->updateRms($create, $uploadBinary, $path);
            $validChildren[] = $tmp->getUri(true);
        }

        foreach ($this->matches as $i) {
            $tmp             = $i->updateRms($create, $uploadBinary, $path);
            $validChildren[] = $tmp->getUri(true);
        }

        $invalidChildren = array_diff(array_keys($children), $validChildren);
        foreach ($invalidChildren as $i) {
            $children[$i]->delete(true, true, false);
        }

        return $res;
    }

}
