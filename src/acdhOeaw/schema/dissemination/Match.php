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
use acdhOeaw\fedora\Fedora;
use acdhOeaw\schema\Object;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Describes matching beetween a dissemination service and resources it can
 * disseminate.
 *
 * Every match is described by an RDF property and its expected value.
 * Additionaly each match can be marked as compulsory or optional.
 * 
 * A resource fulfils a given set of matches if:
 * - all compulsory matches are met
 * - at least one of optional matches is met
 * 
 * E.g. for a set of matches:
 * - rdf:type, https://my.res/class", required
 * - ebucore:hasMimeType, "text/xml", optional
 * - ebucore:hasMimeType, "application/xml", optional
 * 
 * will match both resources:
 * - &lt;res1&gt; rdf:type &lt;https://my.res.class&gt;
 *   &lt;res1&gt; ebucore:hasMimeType "text/xml"
 * - &lt;res2&gt; rdf:type &lt;https://my.res.class&gt;
 *   &lt;res1&gt; ebucore:hasMimeType "application/xml"
 * 
 * but won't match a resource:
 * - &lt;res1&gt; rdf:type &lt;https://my.res.class&gt;
 * 
 * Typically you shouldn't create the Match object on your own but use the
 * `\achdOeaw\schema\dissemination\Service::addMatch()` method instead.
 * 
 * @author zozlak
 */
class Match extends Object {

    /**
     * Identifier of a dissemination service given rule is applied to
     * @var string
     */
    private $serviceId;

    /**
     * RDF property name to match against
     * @var string
     */
    private $property;

    /**
     * Expected RDF property value
     * @var string 
     */
    private $value;

    /**
     * Is match required
     * @var bool
     */
    private $required;

    /**
     * Creates a match object
     * @param \acdhOeaw\fedora\Fedora $fedora repository connection object
     * @param string $id match id
     * @param \acdhOeaw\schema\dissemination\Service $service dissemination
     *   service using this match
     * @param string $property RDF property checked by this match
     * @param string $value expected RDF property value
     * @param bool $required is this match required?
     */
    public function __construct(Fedora $fedora, string $id, Service $service,
                                string $property, string $value, bool $required) {
        parent::__construct($fedora, $id);

        $this->serviceId = $service->getResource()->getId();
        $this->property  = $property;
        $this->value     = $value;
        $this->required  = $required;
    }

    /**
     * Returns metadata describing the match
     * @return Resource
     */
    public function getMetadata(): Resource {
        $meta = (new Graph())->resource('.');

        $meta->addResource(RC::relProp(), $this->serviceId);
        $meta->addResource(RC::idProp(), $this->getId());
        $meta->addLiteral(RC::titleProp(), 'A dissemination service matching rule');

        $meta->addResource(RC::get('fedoraServiceMatchProp'), $this->property);
        $meta->addLiteral(RC::get('fedoraServiceMatchValue'), $this->value);
        $meta->addLiteral(RC::get('fedoraServiceMatchRequired'), $this->required);

        return $meta;
    }

}
