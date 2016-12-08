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

namespace acdhOeaw\storage;

use acdhOeaw\fedora\FedoraResource;

/**
 * Simple container class for describing 
 * Fedora resource location in the file system
 *
 * @author zozlak
 */
class Location {

    /**
     * Absolute path to the file
     * @var string
     */
    public $fullPath;

    /**
     * Relative path to the file
     * @var string
     */
    public $relativePath;

    /**
     * Fedora resource object
     * @var \acdhOeaw\fedora\FedoraResource
     */
    public $resource;

    /**
     * Creates a new instance of the class
     * 
     * @param string $fullPath absolute path to the file
     * @param string $relPath relative path to the file
     * @param \acdhOeaw\fedora\FedoraResource $res Fedora resource
     */
    public function __construct(string $fullPath, string $relPath, FedoraResource $res) {
        $this->fullPath = $fullPath;
        $this->relativePath = $relPath;
        $this->resource = $res;
    }
}
