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

namespace acdhOeaw\fedora\metadataQuery;

use BadMethodCallException;

/**
 * Description of hasParameter
 *
 * @author zozlak
 */
class HasProperty extends QueryParameter {

    protected $property = '';

    public function __construct($property) {
        parent::__construct();
        if (!is_array($property)) {
            if (self::isVariable($property)) {
                $this->property = $property;
            } else {
                $this->property = self::escapeUri($property);
            }
        } else {
            foreach ($property as &$i) {
                if (self::isUri($i)) {
                    $i = self::escapeUri($i);
                } elseif (!self::isPathOp($i)) {
                    throw new BadMethodCallException('property does not describe a valid sparql property path');
                }
            }
            unset($i);
            $this->property = implode(' ', $property);
        }
    }

    public function getWhere(): string {
        $query = $this->subVar . ' ' . $this->property . ' ' . $this->objVar . ' .';
        return $this->applyOptional($query);
    }

    public function getFilter(): string {
        return '';
    }

}
