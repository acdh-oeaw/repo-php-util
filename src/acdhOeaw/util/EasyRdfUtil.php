<?php

/**
 * The MIT License
 *
 * Copyright 2016 zozlak.
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

use EasyRdf_Namespace;
use EasyRdf_Literal;
use EasyRdf_Graph;
use EasyRdf_Resource;
use EasyRdf_Serialiser_Ntriples;

/**
 * Set of helpers and workarounds for the EasyRdf library
 *
 * @author zozlak
 */
class EasyRdfUtil {

    /**
     * Serializer object
     * 
     * @var \EasyRdf_Serialiser_Ntriples
     */
    static private $serializer;

    /**
     * Provides a workaround for EasyRdf buggy handling of fully qualified
     * property URIs.
     * 
     * @param string $property
     * @return string
     */
    static public function fixPropName(string $property): string {
        return join(':', EasyRdf_Namespace::splitUri($property, true));
    }

    /**
     * Escapes a given string as an URI
     * 
     * @param string $uri
     * @return string
     */
    static public function escapeUri(string $uri): string {
        self::initSerializer();
        return self::$serializer->serialiseValue(new EasyRdf_Resource($uri));
    }

    /**
     * Escapes a given strin as a literal
     * 
     * @param string $literal
     * @return string
     */
    static public function escapeLiteral(string $literal): string {
        self::initSerializer();
        return self::$serializer->serialiseValue(new EasyRdf_Literal($literal));
    }

    /**
     * Initializes serializer used by `escapeLiteral()` and `escapeResource()`
     */
    static private function initSerializer() {
        if (!self::$serializer) {
            self::$serializer = new EasyRdf_Serialiser_Ntriples();
        }
    }

    /**
     * Returns a deep copy of the given EasyRdf_Resouerce 
     * optionally excluding given properties.
     * 
     * @param \EasyRdf_Resource $resource metadata to clone
     * @param array $skipProp a list of fully qualified property URIs to skip
     * @param string $skipRegExp regular expression matchin fully qualified property URIs to skip
     * @return \EasyRdf_Resource
     */
    static public function cloneResource(EasyRdf_Resource $resource, array $skipProp = array(), string $skipRegExp = '/^$/'): EasyRdf_Resource {
        $graph = new EasyRdf_Graph();
        $res = $graph->resource($resource->getUri());

        foreach ($resource->propertyUris() as $prop) {
            if (in_array($prop, $skipProp) || preg_match($skipRegExp, $prop)) {
                continue;
            }
            $prop = EasyRdfUtil::fixPropName($prop);
            foreach ($resource->allLiterals($prop) as $i) {
                $res->addLiteral($prop, $i->getValue());
            }
            foreach ($resource->allResources($prop) as $i) {
                $res->addResource($prop, $i->getUri());
            }
        }

        return $res;
    }

}
