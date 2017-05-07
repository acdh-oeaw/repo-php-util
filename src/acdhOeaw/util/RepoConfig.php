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

namespace acdhOeaw\util;

use zozlak\util\Config;
use InvalidArgumentException;

/**
 * Description of RepoConfig
 *
 * @author zozlak
 */
class RepoConfig {

    /**
     *
     * @var \zozlak\util\Config
     */
    static private $config;

    static public function init(string $configFile) {
        self::$config = new Config($configFile);
    }

    static public function get(string $property) {
        $value = @self::$config->get($property);
        if ($value === null) {
            throw new InvalidArgumentException('configuration property ' . $property . ' does not exist');
        }
        return $value;
    }

    static public function set(string $property, $value) {
        self::$config->set($property, $value);
    }

    static public function idProp() {
        return self::get('fedoraIdProp');
    }

    static public function idNmsp() {
        return self::get('fedoraIdNamespace');
    }

    static public function titleProp() {
        return self::get('fedoraTitleProp');
    }

    static public function locProp() {
        return self::get('fedoraLocProp');
    }

    static public function relProp() {
        return self::get('fedoraRelProp');
    }

    static public function vocabsNmsp() {
        return self::get('fedoraVocabsNamespace');
    }

}
