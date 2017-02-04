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
use EasyRdf_Graph;
use RuntimeException;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;

/**
 * Description of Service
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
                $metadata = (new EasyRdf_Graph())->resource('.');
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
            $services[] = new Service($s, $dom);
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
        $this->location = (string) $sdep->xpath('//wsdl:binding/wsdl:operation[@name="' . $service['operationName'] . '"]/http:operation')[0];
        $this->location = preg_replace('#http[s]://[^/]+/#i', self::$config->get('ciriloLocationBase'), $this->location);
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

        $pidProp = EasyRdfUtil::fixPropName(self::$config->get('ciriloIdProp'));
        $meta->delete($pidProp);
        $meta->addLiteral($pidProp, $this->pid);
        
        $titleProp = EasyRdfUtil::fixPropName(self::$config->get('fedoraTitleProp'));
        $meta->delete($titleProp);
        $meta->addLiteral($titleProp, $this->title);
        
        $locProp = EasyRdfUtil::fixPropName(self::$config->get('fedoraServiceLocProp'));
        $meta->delete($locProp);
        $meta->addLiteral($locProp, $this->location);
        
        $retProp = EasyRdfUtil::fixPropName(self::$config->get('fedoraServiceRetMimeProp'));
        $meta->delete($retProp);
        $meta->addLiteral($retProp, $this->retMime);
        
        $supProp = EasyRdfUtil::fixPropName(self::$config->get('fedoraServiceSupportsProp'));
        $meta->delete($supProp);
        foreach ($this->suports as $i) {
            $meta->addLiteral($supProp, $i);
        }

        $fedoraRes->setMetadata($meta);
        $fedoraRes->updateMetadata();

        foreach($this->params as $p) {
            $p->updateRms($fedoraRes);
        }
    }
    
}
