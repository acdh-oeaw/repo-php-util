<?php

/**
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
 * 
 * @package repo-php-util
 * @copyright (c) 2018, Austrian Centre for Digital Humanities
 * @license https://opensource.org/licenses/MIT
 */

namespace acdhOeaw\fedora\dissemination\parameter;

/**
 * Assuming value is an URL extracts given parts of the URL.
 *
 * @author zozlak
 */
class UriPart implements iTransformation {

    /**
     * Returns transformation name
     */
    public function getName(): string {
        return 'part';
    }

    /**
     * Extracts given URL parts.
     * @param string $value URL to be transformed
     * @param ... $parts parts to be extracted. One of: scheme (e.g. "https", 
     *   "ftp", etc.), host, port, user, pass, path, query, fragment 
     *   (part of the URL following #)
     * @return string
     */
    public function transform(string $value, string ...$parts): string {
        $value = parse_url($value);

        $toUnset = ['scheme', 'host', 'port', 'user', 'pass', 'path', 'query', 'fragment'];
        $toUnset = array_intersect(array_diff($toUnset, $parts), array_keys($value));

        foreach ($toUnset as $i) {
            unset($value[$i]);
        }

        $scheme   = isset($value['scheme']) ? $value['scheme'] . '://' : '';
        $host     = isset($value['host']) ? $value['host'] : '';
        $port     = isset($value['port']) ? ':' . $value['port'] : '';
        $user     = isset($value['user']) ? $value['user'] : '';
        $pass     = isset($value['pass']) ? ':' . $value['pass'] : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($value['path']) ? $value['path'] : '';
        $query    = isset($value['query']) ? '?' . $value['query'] : '';
        $fragment = isset($value['fragment']) ? '#' . $value['fragment'] : '';
        return $scheme . $user . $pass . $host . $port . $path . $query . $fragment;
    }

}
