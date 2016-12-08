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
 * Represents a Redmine project
 * and provides mapping to ACDH repository resource representing a project.
 *
 * @author zozlak
 */
class Project extends Redmine {

    /**
     * Stores all instances of the class identyfing them by their Redmine IDs
     * @var array
     * @see getById()
     */
    static protected $cache = [];

    /**
     * Fetches a Project object from cache based on its Redmine ID.
     * 
     * If object does not exist in cache, it will be created and added to the cache.
     * 
     * @param int $id Redmine's project ID
     * @return \acdhOeaw\redmine\Redmine
     */
    static public function getById(int $id): Redmine {
//echo 'get project ' . $id . "\n";
        if (!isset(self::$cache[$id])) {
            $url = self::$apiUrl . '/projects/' . urlencode($id) . '.json?key=' . urlencode(self::$apiKey);
            $data = json_decode(file_get_contents($url));
            self::$cache[$id] = new Project($data->project);
        }
        return self::$cache[$id];
    }

    /**
     * Returns array of all Project objects which can be fetched from the Redmine.
     * 
     * See the Redmine::fetchAll() description for details;
     * 
     * @param bool $progressBar should progress bar be displayed 
     *   (makes sense only if the standard output is a console)
     * @return array
     * @see Redmine::fetchAll()
     */
    static public function fetchAll(bool $progressBar): array {
        $param = 'limit=100000&key=' . urlencode(self::$apiKey);
        $param .= "&project_id=34";
        return self::redmineFetchLoop($progressBar, 'projects', $param);
    }

    /**
     * Maps Redmine's Project ID to the Redmine's Project URI
     * @return string
     */
    public function getIdValue(): string {
        return self::$apiUrl . '/projects/' . $this->id;
    }

}
