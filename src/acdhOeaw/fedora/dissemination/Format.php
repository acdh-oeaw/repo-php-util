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

namespace acdhOeaw\fedora\dissemination;

use RuntimeException;

/**
 * Container describing dissemination service return format.
 * Format consists of a format name (in most cases MIME type but there can be
 * exceptions) and weight (as weights used in the HTTP Accept header).
 *
 * @author zozlak
 */
class Format {

    /**
     * Return format name (typically a MIME type)
     * @var string
     */
    public $format;

    /**
     * Return format weight
     * @var float
     */
    public $weight = 1;

    /**
     * Creates a return format description.
     * @param string $value return type description in format "type" or
     *   "type; q=weight", where weight is a number between 0 and 1
     */
    public function __construct(string $value) {
        $value        = explode(';', $value);
        $this->format = trim($value[0]);
        if (count($value) > 1) {
            $matches = [];
            preg_match('/^ *q=(0[.][0-9]+) *$/', $value[1], $matches);
            if (!isset($matches[1])) {
                throw new RuntimeException('Bad weight specification');
            }
            $this->weight = (float) $matches[1];
        }
    }

    /**
     * Provides pretty-print serializaton
     * @return string
     */
    public function __toString() {
        return $this->format . '; q=' . $this->weight;
    }

}
