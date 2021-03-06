<?php

/**
 * The MIT License
 *
 * Copyright 2016 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\fedora\exceptions;

use Exception;
use Throwable;

/**
 * Description of FedoraCacheInconsistent
 *
 * @author zozlak
 */
class AlreadyInCache extends Exception {

    /**
     * Thrown when a resource is already in cache
     * @param string $message error message
     * @param int $code error code
     * @param Throwable $previous previous exception (for chaining)
     */
    public function __construct(string $message = "already in cache", int $code = 6,
                                Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }

}
