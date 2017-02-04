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

namespace acdhOeaw\redmine;

/**
 * Represents a Redmine issue
 * and provides mapping to ACDH repository resource representing a resource.
 *
 * @author zozlak
 */
class Issue extends Redmine {

    /**
     * Stores all instances of the class identifying them by their Redmine IDs
     * @var array
     * @see getById()
     */
    static protected $cache = [];

    /**
     * Fetches an Issue object from cache based on its Redmine ID.
     * 
     * If object does not exist in cache, it will be created and added to the cache.
     * 
     * @param int $id Redmine's issue ID
     * @return \acdhOeaw\redmine\Redmine
     */
    static public function getById(int $id): Redmine {
//echo 'get issue ' . $id . "\n";
        if (!isset(self::$cache[$id])) {
            $url = self::$apiUrl . '/issues/' . urlencode($id) . '.json?key=' . urlencode(self::$apiKey);
            $data = json_decode(file_get_contents($url));
            self::$cache[$id] = new Issue($data->issue);
        }
        return self::$cache[$id];
    }

    /**
     * Returns array of all Issue objects which can be fetched from the Redmine.
     * 
     * See the Redmine::fetchAll() description for details;
     * 
     * @param bool $progressBar should progress bar be displayed 
     *   (makes sense only if the standard output is a console)
     * @param array $filters filters to be passed to the Redmine's issue REST API
     *   in a form of an associative array, e.g. `array('tracker_id' => 5)`
     * @return array
     * @see Redmine::fetchAll()
     */
    static public function fetchAll(bool $progressBar, array $filters = array()): array {
        $param = ['key=' . urlencode(self::$apiKey), 'limit' => 1000000];
        foreach ($filters as $k => $v) {
            $param[] = urlencode($k) . '=' . urlencode($v);
        }
        $param = implode('&', $param);

        return self::redmineFetchLoop($progressBar, 'issues', $param);
    }

    /**
     * Maps Redmine's Issue ID to the Redmine's Issue URI
     * @return string
     */
    public function getIdValue(): string {
        return self::$apiUrl . '/issues/' . $this->id;
    }

}
