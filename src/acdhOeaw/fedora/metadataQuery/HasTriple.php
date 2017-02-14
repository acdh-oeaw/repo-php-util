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

use acdhOeaw\util\EasyRdfUtil;
use BadMethodCallException;

/**
 * Description of HasTriple
 *
 * @author zozlak
 */
class HasTriple extends HasProperty {

    protected $property = '';

    public function __construct(string $sub, $prop, string $obj) {
        parent::__construct($prop);

        if (!EasyRdfUtil::isVariable($sub) && !EasyRdfUtil::isUri($sub)) {
            throw new BadMethodCallException('$sub parameter must be a valid SPARQL variable name or an URI');
        }
        $this->subVar = EasyRdfUtil::isVariable($sub) ? $sub : EasyRdfUtil::escapeUri($sub);

        if (!EasyRdfUtil::isVariable($obj)) {
            $obj = EasyRdfUtil::isUri($obj) ? EasyRdfUtil::escapeUri($sub) : EasyRdfUtil::escapeLiteral($sub);
        }
        $this->objVar = $obj;
    }

    public function getWhere(): string {
        $query = $this->subVar . ' ' . $this->property . ' ' . $this->objVar . ' .';
        return $this->applyOptional($query);
    }

    public function getFilter(): string {
        return '';
    }

}
