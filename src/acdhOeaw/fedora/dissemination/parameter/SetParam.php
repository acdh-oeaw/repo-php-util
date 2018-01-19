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

namespace acdhOeaw\fedora\dissemination\parameter;

/**
 * Assuming value is an URL, sets a given query parameter value.
 * If a parameter already exists in the query string, it's overwritten.
 *
 * @author zozlak
 */
class SetParam implements iTransformation {

    /**
     * Returns transformation name
     */
    public function getName(): string {
        return 'set';
    }

    /**
     * Sets a given query parameter value in the URL. If the parameter already
     * exists, its value is overwritten.
     * @param string $value URL to be transformed
     * @param string $paramName query parameter name
     * @param string $paramValue query parameter value
     * @return string
     */
    public function transform(string $value, string $paramName = '',
                              string $paramValue = ''): string {
        $value = parse_url($value);

        if (!isset($value['query'])) {
            $value['query'] = '';
        }
        $param             = [];
        parse_str($value['query'], $param);
        $param[$paramName] = $paramValue;
        $value['query']    = http_build_query($param);

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
