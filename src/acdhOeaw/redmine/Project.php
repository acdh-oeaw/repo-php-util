<?php

/*
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

    static protected $cache = [];

    static public function getById(int $id): Redmine {
//echo 'get project ' . $id . "\n";
        if (!isset(self::$cache[$id])) {
            $url = self::$apiUrl . '/projects/' . urlencode($id) . '.json?key=' . urlencode(self::$apiKey);
            $data = json_decode(file_get_contents($url));
            self::$cache[$id] = new Project($data->project);
        }
        return self::$cache[$id];
    }

    static public function fetchAll(bool $progressBar): array {
        $param = 'limit=100000&key=' . urlencode(self::$apiKey);
        $param .= "&project_id=34";
        return self::redmineFetchLoop($progressBar, 'projects', $param, 'acdhOeaw\\redmine\\Project');
    }

    public function getIdValue(): string {
        return self::$apiUrl . '/projects/' . $this->id;
    }

}
