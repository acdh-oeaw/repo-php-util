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

use EasyRdf_Resource;
use acdhOeaw\util\EasyRdfUtil;

/**
 * Represents a Redmine user
 * and provides mapping to ACDH repository resource representing a person.
 *
 * @author zozlak
 */
class User extends Redmine {

    /**
     * Stores all instances of the class identyfing them by their Redmine IDs
     * @var array
     * @see getById()
     */
    static protected $cache = [];

    /**
     * Fetches an User object from cache based on its Redmine ID.
     * 
     * If object does not exist in cache, it will be created and added to the cache.
     * 
     * @param int $id Redmine's issue ID
     * @return \acdhOeaw\redmine\Redmine
     */
    static public function getById(int $id): Redmine {
//echo 'get user ' . $id . "\n";
        if (!isset(self::$cache[$id])) {
            $url = self::$apiUrl . '/users/' . urlencode($id) . '.json?key=' . urlencode(self::$apiKey);
            $data = json_decode(file_get_contents($url));
            self::$cache[$id] = new User($data->user);
        }
        return self::$cache[$id];
    }

    /**
     * Returns array of all User objects which can be fetched from the Redmine.
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
        return self::redmineFetchLoop($progressBar, 'users', $param);
    }

    /**
     * Maps Redmine's User ID to the Redmine's User URI
     * @return string
     */
    protected function getIdValue(): string {
        return self::$apiUrl . '/users/' . $this->id;
    }

    /**
     * Maps Redmine's object properties to and RDF graph.
     * 
     * Extends mapping provided by the base Redmine class with mappings
     * specific to the Redmine users.
     * 
     * @param array $data associative array with Redmine's resource properties
     *   fetched from the Redmine REST API
     * @return \EasyRdf_Resource
     * @see \acdhOeaw\redmine\Redmine::mapProperties()
     */
    protected function mapProperties(array $data): EasyRdf_Resource {
        $res = parent::mapProperties($data);
        
        $given = $res->getLiteral(EasyRdfUtil::fixPropName('http://xmlns.com/foaf/0.1/givenName'));
        $family = $res->getLiteral(EasyRdfUtil::fixPropName('http://xmlns.com/foaf/0.1/familyName'));
        $title = trim($given . ' ' . $family);
        
        $res->delete(EasyRdfUtil::fixPropName('http://xmlns.com/foaf/0.1/name'));
        $res->addLiteral('http://xmlns.com/foaf/0.1/name', $title);
        $res->delete(EasyRdfUtil::fixPropName('http://purl.org/dc/elements/1.1/title'));
        $res->addLiteral('http://purl.org/dc/elements/1.1/title', $title);
        
        return $res;
    }
}
