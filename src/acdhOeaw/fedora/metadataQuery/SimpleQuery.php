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

namespace acdhOeaw\fedora\metadataQuery;

use RuntimeException;

/**
 * Provides SPARQL equeries with simple literal/URI substituion, e.g.
 * 
 * ```
 * new Query('SELECT * WHERE {?a ?@ ?#}', array('http://my.uri/', 'my literal'));
 * ```
 *
 * Named vars are supported as well, e.g.:
 * 
 * ```
 * new Query('SELECT * WHERE {?a ?@b ?#c}', array('c' => 'my literal', 'b' => 'http://my.uri/'));
 * ```
 * 
 * @author zozlak
 */
class SimpleQuery extends Query {
    
    private $query;
    private $values;

    public function __construct(string $query, array $values = array()) {
        $this->query = $query;
        $this->values = $values;
    }
    
    public function setValues(array $values = array()) {
        $this->values = $values;
    }
    
    public function getQuery(): string {
        $query = $this->query;
        $values = $this->values;
        
        $matches = null;
        preg_match_all('/[?][#@][a-zA-Z]?[a-zA-Z0-9_]*/', $query, $matches);
        $matches = count($matches) > 0 ? $matches[0] : array();
        
        foreach ($matches as $i) {
            $varName = substr($i, 2);
            if ($varName != '') {
                if (!isset($this->values[$varName])) {
                    throw new RuntimeException('no value for variable ' . $varName);
                }
                $value = $values[$varName];
                unset($values[$varName]);
            } else {
                if (count($values) == 0) {
                    throw new RuntimeException('number of values lower then the number of variables in the query');
                }
                $value = array_shift($values);
            }
            $value = substr($i, 1, 1) == '#' ? QueryParameter::escapeLiteral($value) : QueryParameter::escapeUri($value);
            $query = preg_replace('/[?][#@]' . $varName . '/', $value, $query, 1);
        }
        if (count($values) > 0) {
            throw new RuntimeException('number of values greater then the number of variables in the query');
        }
        
        return $query;
    }
}
