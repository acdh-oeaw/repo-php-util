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
 * Provides access to repository configuration properties in a form of
 * a singleton object.
 *
 * @author zozlak
 */
class RepoConfig {

    /**
     *
     * @var \zozlak\util\Config
     */
    static private $config;

    /**
     * Parses a configuration file.
     * @param string $configFile path to the ini file storing configuration
     */
    static public function init(string $configFile) {
        self::$config = new Config($configFile, true);
    }

    /**
     * Returns given configuration property value.
     * 
     * If property is not set, throws an error.
     * 
     * Property value can be a literal as well as an composed object (e.g. array
     * depending on the parsed ini file content).
     * @param string $property configuration property name
     * @param bool $noException should exception be avoided when property is not 
     *   defined?
     * @return mixed configuration property value
     * @throws InvalidArgumentException
     */
    static public function get(string $property, bool $noException = false) {
        $value = @self::$config->get($property);
        if ($value === null && $noException === false) {
            throw new InvalidArgumentException('configuration property ' . $property . ' does not exist');
        }
        return $value;
    }

    /**
     * Sets a given configuration property value.
     * 
     * The value is change only in the running script. The change is not
     * propagated to the source configuration file.
     * @param string $property configuration property name
     * @param mixed $value value to set
     */
    static public function set(string $property, $value) {
        self::$config->set($property, $value);
    }

    /**
     * Shorthand method for getting fedoraIdProp configuration property value.
     * @return string
     */
    static public function idProp(): string {
        return self::get('fedoraIdProp');
    }

    /**
     * Shorthand method for getting fedoraIdNamespace configuration property value.
     * @return string
     */
    static public function idNmsp(): string {
        return self::get('fedoraUuidNamespace');
    }

    /**
     * Shorthand method for getting fedoraTitleProp configuration property value.
     * @return string
     */
    static public function titleProp(): string {
        return self::get('fedoraTitleProp');
    }

    /**
     * Shorthand method for getting fedoraLocProp configuration property value.
     * @return string
     */
    static public function locProp(): string {
        return self::get('fedoraLocProp');
    }

    /**
     * Shorthand method for getting fedoraRelProp configuration property value.
     * @return string
     */
    static public function relProp(): string {
        return self::get('fedoraRelProp');
    }

    /**
     * Shorthand method for getting fedoraVocabsNamespace configuration property value.
     * @return string
     */
    static public function vocabsNmsp(): string {
        return self::get('fedoraVocabsNamespace');
    }

}
