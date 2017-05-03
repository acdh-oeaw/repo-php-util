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

/**
 * Description of HasValue
 *
 * @author zozlak
 */
class HasValue extends HasProperty {

    protected $value;

    public function __construct($property, string $value) {
        parent::__construct($property);
        $this->value = $value;
    }

    public function getWhere(): string {
        $valIsUri = self::isUri($this->value);
        $val      = $valIsUri ? self::escapeUri($this->value) : self::escapeLiteral($this->value);

        $type  = '';
        /*
          if (!is_numeric($this->value) && !$valIsUri) {
          $type = '^^xsd:string';
          }
         */
        $query = $this->subVar . ' ' . $this->property . ' ' . $val . $type . ' .';
        return $this->applyOptional($query);
    }

    public function getFilter(): string {
        return '';
    }

}
