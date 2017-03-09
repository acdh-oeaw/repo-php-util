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
    static public function  escapeUri(string $uri): string {
        self::initSerializer();
        return self::$serializer->serialiseValue(new EasyRdf_Resource($uri));
    }

    /**
     * Escapes a given string as a literal
     * 
     * @param string $literal
     * @return string
     */
    static public function escapeLiteral(string $literal): string {
        self::initSerializer();
        $value = self::$serializer->serialiseValue(new EasyRdf_Literal($value));
        return $value;
    }

    /**
     * Checks it a given string is a valid SPARQL variable name
     * (see https://www.w3.org/TR/2013/REC-sparql11-query-20130321/#rVARNAME)
     * 
     * Current implementation is more restrictive that the SPARQL standard
     * as Unicode ranges #x00B7, #x0300-#x036F, #x203F-#x2040, #x00C0-#x00D6,
     * #x00D8-#x00F6, #x00F8-#x02FF, #x0370-#x037D, #x037F-#x1FFF, 
     * #x200C-#x200D, #x2070-#x218F, #x2C00-#x2FEF, #x3001-#xD7FF,
     * #xF900-#xFDCF, #xFDF0-#xFFFD and #x10000-#xEFFFF are NOT allowed.
     * 
     * @param string $variable SPARQL variable name to match
     */
    static public function isVariable(string $variable): bool {
        return preg_match('|^[?$][a-zA-Z0-9_]+|', $variable);
    }

    /**
     * Checks if a given string is a valid SPARQL path operator
     * 
     * @param string $op
     * @return bool
     */
    static public function isPathOp(string $op): bool {
        return in_array($op, array('!', '^', '|', '/', '*', '+', '?', '!^', '(', ')'));
    }

    /**
     * Checks if a given string is a valid left-side SPARQL path operator
     * 
     * @param string $op
     * @return bool
     */
    static public function isPathOpLeft(string $op): bool {
        return in_array($op, array('!', '^', '!^'));
    }

    /**
     * Checks if a given string is a valid right-side SPARQL path operator
     * 
     * @param string $op
     * @return bool
     */
    static public function isPathOpRight(string $op): bool {
        return in_array($op, array('*', '+', '?'));
    }

    /**
     * Checks if a given string is a valid both-sides SPARQL path operator
     * 
     * @param string $op
     * @return bool
     */
    static public function isPathOpTwoSided(string $op): bool {
        return in_array($op, array('/', '|'));
    }

    /**
     * Checks if a given string is an URI
     * 
     * @param string $string
     * @return bool
     */
    static public function isUri(string $string): bool {
        return preg_match('#[a-z0-9+.-]+://#', $string);
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
            $prop = self::fixPropName($prop);
            foreach ($resource->allLiterals($prop) as $i) {
                $res->addLiteral($prop, $i->getValue());
            }
            foreach ($resource->allResources($prop) as $i) {
                $res->addResource($prop, $i->getUri());
            }
        }

        return $res;
    }

    /**
     * Serializes given resource to ntriples taking care of escaping.
     * 
     * Replaces EasyRdf ntriples serializer which does not escape special values
     * in literal strings.
     * 
     * @param EasyRdf_Resource $resource resource to serialize
     * @return string
     */
    static public function serialiseResource(EasyRdf_Resource $resource) {
        return $resource->getGraph()->serialise('ntriples');
    }

    /**
     * Merges two metadata sets.
     * 
     * The final metadata contains:
     * 
     * - All properties existing in `new`.
     * - Those properties from `cur` which do not exist in `new`.
     * 
     * @param EasyRdf_Resource $cur current metadata
     * @param EasyRdf_Resource $new metadata to be merged with current metadata
     * @return EasyRdf_Resource
     */
    static public function mergeMetadata(EasyRdf_Resource $cur, EasyRdf_Resource $new): EasyRdf_Resource {
        $cur = self::cloneResource($cur, $new->propertyUris());
        
        foreach ($new->propertyUris() as $prop) {
            $prop = self::fixPropName($prop);
            foreach ($new->allLiterals($prop) as $i) {
                $cur->addLiteral($prop, $i->getValue());
            }
            foreach ($new->allResources($prop) as $i) {
                $cur->addResource($prop, $i->getUri());
            }
        }
        return $cur;
    }

}
