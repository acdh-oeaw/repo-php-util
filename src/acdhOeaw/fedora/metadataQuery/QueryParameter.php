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

/**
 *
 * @author zozlak
 */
abstract class QueryParameter {

    static private $counter = 0;
    static private $objVarPrefix = '?v';
    protected $subVar = '?res';
    protected $objVar;
    protected $optional = false;

    public function __construct() {
        self::$counter++;
        $this->objVar = self::$objVarPrefix . self::$counter;
    }

    public function setSubVar(string $subVar): QueryParameter {
        $this->subVar = $subVar;
        return $this;
    }

    public function setObjVar(string $objVar): QueryParameter {
        $this->objVar = $objVar;
        return $this;
    }

    public function setOptional(bool $optional): QueryParameter {
        $this->optional = $optional;
        return $this;
    }

    public function getSubVar(): string {
        return $this->subVar;
    }

    public function getObjVar(): string {
        return $this->objVar;
    }

    public function getOptional(): bool {
        return $this->optional;
    }

    abstract public function getWhere(): string;

    abstract public function getFilter(): string;

    protected function applyOptional(string $query) {
        if ($this->optional) {
            return 'OPTIONAL {' . $query . '}';
        }
        return $query;
    }

}
