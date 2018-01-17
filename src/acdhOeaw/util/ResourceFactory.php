<?php

/*
 * The MIT License
 *
 * Copyright 2018 zozlak.
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

namespace acdhOeaw\util;

use EasyRdf\Graph;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Makes dummy resources creation easier.
 * Helps writing tests.
 *
 * @author zozlak
 */
class ResourceFactory {

    /**
     *
     * @var Fedora
     */
    static private $fedora;

    static public function init(Fedora $fedora) {
        self::$fedora = $fedora;
    }

    /**
     * Creates a new resource
     * @param array $properties list of RDF properties (key - property, value - 
     *   property value); 'id', 'title' and 'parent' are handled automatically
     * @param string $location where to create the resource
     * @param string $method creation method (POST or PUT)
     * @return FedoraResource
     */
    static public function create(array $properties = [],
                                  string $location = '/',
                                  string $method = 'POST'): FedoraResource {
        if (isset($properties['id']) && !is_array($properties['id'])) {
            try {
                return self::$fedora->getResourceById($properties['id']);
            } catch (NotFound $e) {
                
            }
        }

        if (!isset($properties['id'])) {
            $properties['id'] = 'https://random.id/' . microtime(true);
        }
        if (!isset($properties['title'])) {
            $properties['title'] = 'dummy title';
        }

        $meta = (new Graph())->resource('.');
        foreach ($properties as $p => $v) {
            switch ($p) {
                case 'id':
                    $p = RC::idProp();
                    break;
                case 'title':
                    $p = RC::titleProp();
                    break;
                case 'parent':
                    $p = RC::relProp();
                    break;
            }
            if (!is_array($v)) {
                $v = [$v];
            }
            foreach ($v as $i) {
                if ($i instanceof FedoraResource) {
                    $i = $i->getId();
                }

                if (preg_match('|^https?://|', $i)) {
                    $meta->addResource($p, $i);
                } else {
                    $meta->addLiteral($p, $i);
                }
            }
        }
        $res = self::$fedora->createResource($meta, '', $location, $method);
        return $res;
    }

    /**
     * Removes all access control rules from the repository
     * @param type $fedora Fedora connection object
     */
    static public function removeAcl(Fedora $fedora, bool $verbose = true) {
        echo $verbose ? "removing ACL rules\n" : '';
        
        $fedora->rollback();
        
        $fedora->begin();
        $resp = $fedora->getResourcesByProperty('http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'http://www.w3.org/ns/auth/acl#Authorization');
        foreach ($resp as $k => $rule) {
            echo $verbose ? "  removing rule " . ($k + 1) . "/" . count($resp) . "\n" : '';
            $rule->delete(true);
        }
        $fedora->commit();
        
        $fedora->begin();
        $resp = $fedora->getResourcesByProperty('http://www.w3.org/1999/02/22-rdf-syntax-ns#type', 'http://fedora.info/definitions/v4/webac#Acl');
        foreach ($resp as $k => $rule) {
            if ($rule->getUri(true) === 'https://fedora.localhost/rest/acl') {
                continue;
            }
            echo $verbose ? "  removing ACL " . ($k + 1) . "/" . count($resp) . "\n" : '';
            $rule->delete(true, true);
        }
        $fedora->commit();
        
        $fedora->begin();
        $resp = $fedora->getResourcesByProperty('http://www.w3.org/ns/auth/acl#accessControl');
        foreach ($resp as $k => $res) {
            if ($res->getUri(true) === 'https://fedora.localhost/rest/') {
                continue;
            }
            echo $verbose ? "  removing ACL link " . ($k + 1) . "/" . count($resp) . "\n" : '';
            $meta = $res->getMetadata();
            $meta->delete('http://www.w3.org/ns/auth/acl#accessControl');
            $res->setMetadata($meta);
            $res->updateMetadata(FedoraResource::OVERWRITE);
        }
        $fedora->commit();
        
        echo $verbose ? "  ended\n" : '';
        $fedora->__refreshCache(); // make all cached FedoraResource object aware of the changes made
    }

}
