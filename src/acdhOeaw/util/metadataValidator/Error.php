<?php

/**
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
 * 
 * @package repo-php-util
 * @copyright (c) 2017, Austrian Centre for Digital Humanities
 * @license https://opensource.org/licenses/MIT
*/

namespace acdhOeaw\util\metadataValidator;

/**
 * Simple container for metadata validation errors
 *
 * @author zozlak
 */
class Error {

    /**
     * URI of the resource for which an error occured
     * @var string
     */
    public $resUri;

    /**
     * Error message
     * @var string
     */
    public $message;

    /**
     * Value which caused an error
     * @var string
     */
    public $value;

    /**
     * RDF property for which an error occured
     * @var string
     */
    public $property;

    /**
     * Creates an error object.
     * @param string $resUri URI of the resource for which an error occured
     * @param string $message error message
     * @param string $value value which caused an error
     * @param string $property RDF property for which an error occured
     */
    public function __construct(string $resUri, string $message,
                                string $value = '', string $property = '') {
        $this->resUri   = $resUri;
        $this->message  = $message;
        $this->value    = $value;
        $this->property = $property;
    }

    /**
     * Nice string serialization
     * @return string
     */
    public function __toString() {
        return $this->resUri . "\t" . $this->message . "\t" . $this->property . "\t" . $this->value;
    }

}
