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
 * 
 * @package repo-php-util
 * @copyright (c) 2017, Austrian Centre for Digital Humanities
 * @license https://opensource.org/licenses/MIT
 */

namespace acdhOeaw\schema\redmine;

use acdhOeaw\fedora\Fedora;

/**
 * Represents a Redmine issue
 * and provides mapping to ACDH repository resource representing a resource.
 *
 * @author zozlak
 */
class Issue extends Redmine {

    /**
     * Returns array of all Issue objects which can be fetched from the Redmine.
     * 
     * See the Redmine::fetchAll() description for details;
     * 
     * @param \acdhOeaw\fedora\Fedora $fedora Fedora connection
     * @param bool $progressBar should progress bar be displayed 
     *   (makes sense only if the standard output is a console)
     * @param array $filters filters to be passed to the Redmine's issue REST API
     *   in a form of an associative array, e.g. `array('tracker_id' => 5)`
     * @return array
     * @see Redmine::fetchAll()
     */
    static public function fetchAll(Fedora $fedora, bool $progressBar,
                                    array $filters = array()): array {
        $param = ['key=' . urlencode(self::$apiKey), 'limit' => 1000000];
        foreach ($filters as $k => $v) {
            $param[] = urlencode($k) . '=' . urlencode($v);
        }
        $param = implode('&', $param);

        return self::redmineFetchLoop($fedora, $progressBar, 'issues', $param);
    }

    /**
     * Maps Redmine's Issue ID to the Redmine's User URI
     * @param int $id Redmine ID
     * @return string
     */
    static public function redmineId2repoId(int $id): string {
        return self::$apiUrl . '/issues/' . $id;
    }

}
