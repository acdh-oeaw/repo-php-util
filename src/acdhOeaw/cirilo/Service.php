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

use zozlak\util\Config;
use SimpleXMLElement;
use EasyRdf\Graph;
use RuntimeException;
use Exception;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;

/**
 * Transforms Fedora 3 service deployment resource
 * (see http://fedorarepository.org/sites/fedorarepository.org/files/documentation/3.2.1/Creating%20a%20Service%20Deployment.html)
 * into an ACDH repository dissemination service description (being a set of
 * Fedora resources).
 * 
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
 * (cfg:fedoraRelProp). Each parameter is described by RDF properties:
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
class Service {

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

    static private function getFedoraResource(Service $s): FedoraResource {
        if (!isset(self::$cache[$s->pid])) {
            $candidates = self::$fedora->getResourcesByProperty(self::$config->get('ciriloIdProp'), $s->pid);
            if (count($candidates) === 1) {
                self::$cache[$s->pid] = $candidates[0];
            } elseif (count($candidates) === 0) {
                $metadata = (new EasyRdf\Graph())->resource('.');
                $metadata->addLiteral(self::$config->get('fedoraTitleProp'), $s->title);
                self::$cache[$s->pid] = self::$fedora->createResource($metadata);
            } else {
                throw new RuntimeException('Many resources with matching given fedora 3 pid');
            }
        }

        return self::$cache[$s->pid];
    }

    static public function init(Config $cfg, Fedora $fedora) {
        self::$config = $cfg;
        self::$fedora = $fedora;
        ServiceParameter::init($cfg, $fedora);
    }

    static public function fromSdepFile(string $sdepFile) {
        $dom = simplexml_load_file($sdepFile);
        $dom->registerXPathNamespace('fmm', 'http://fedora.comm.nsdlib.org/service/methodmap');

        $services = array();
        foreach ($dom->xpath('//fmm:Method') as $s) {
            try {
                $services[] = new Service($s, $dom);
            } catch (Exception $e) {
                echo "\t" . $e . "\n";
            }
        }
        return $services;
    }

    private $pid; // fedora 3 PID
    private $title;
    private $location;
    private $retMime;
    private $params = array();
    private $suports = array();

    public function __construct(SimpleXMLElement $service, SimpleXMLElement $sdep) {
        $service->registerXPathNamespace('fmm', 'http://fedora.comm.nsdlib.org/service/methodmap');
        $sdep->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $sdep->registerXPathNamespace('fedora-model', 'info:fedora/fedora-system:def/model#');
        $sdep->registerXPathNamespace('http', 'http://schemas.xmlsoap.org/wsdl/http/');
        $sdep->registerXPathNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $sdep->registerXPathNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/');

        $this->pid = $sdep->xpath('//dc:identifier')[0] . '/' . $service['operationName'];
        $this->title = (string) $service['operationName'];
        $this->location = (string) $sdep->xpath('//wsdl:binding/wsdl:operation[@name="' . $service['operationName'] . '"]/http:operation')[0]['location'];
        $this->location = preg_replace('#https?://[^/]+/#i', self::$config->get('ciriloLocationBase'), $this->location);
        $this->retMime = (string) $service->xpath('./fmm:MethodReturnType')[0]['wsdlMsgTOMIME'];

        foreach ($sdep->xpath('//fedora-model:isContractorOf/@rdf:resource') as $i) {
            $this->suports[] = (string) $i;
        }

        $rdfPrefix = self::$config->get('ciriloServiceParamRdfPrefix');
        foreach ($service->xpath('./fmm:DatastreamInputParm') as $i) {
            $this->params[] = new ServiceParameter($i, $rdfPrefix);
        }
        foreach ($service->xpath('./fmm:UserInputParm') as $i) {
            $this->params[] = new ServiceParameter($i, $rdfPrefix);
        }
        foreach ($service->xpath('./fmm:DefaultInputParm') as $i) {
            $this->params[] = new ServiceParameter($i, $rdfPrefix);
        }
    }

    public function updateRms() {
        
        $fedoraRes = self::getFedoraResource($this);
        $meta = $fedoraRes->getMetadata();

        $pidProp = self::$config->get('ciriloIdProp');
        $meta->delete($pidProp);
        $meta->addLiteral($pidProp, $this->pid);
        
        $titleProp = self::$config->get('fedoraTitleProp');
        $meta->delete($titleProp);
        $meta->addLiteral($titleProp, $this->title);
        
        $locProp = self::$config->get('fedoraServiceLocProp');
        $meta->delete($locProp);
        $meta->addLiteral($locProp, $this->location);
        
        $retProp = self::$config->get('fedoraServiceRetMimeProp');
        $meta->delete($retProp);
        $meta->addLiteral($retProp, $this->retMime);
        
        $supProp = self::$config->get('fedoraServiceSupportsProp');
        $meta->delete($supProp);
        foreach ($this->suports as $i) {
            $meta->addLiteral($supProp, $i);
        }

/*
        if($this->retMime == 'text/html'){
            $meta->addResource($supProp, 'https://vocabs.acdh.oeaw.ac.at/#DigitalResource');
            $meta->addResource($supProp, 'http://www.w3.org/ns/ldp#Container');
        }
*/
 
        $fedoraRes->setMetadata($meta);
        $fedoraRes->updateMetadata();

        foreach($this->params as $p) {
            $p->updateRms($fedoraRes);
        }
    }
    
}
