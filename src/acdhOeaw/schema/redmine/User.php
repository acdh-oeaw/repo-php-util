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
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Represents a Redmine user
 * and provides mapping to ACDH repository resource representing a person.
 *
 * @author zozlak
 */
class User extends Redmine {

    /**
     * Returns array of all User objects which can be fetched from the Redmine.
     * 
     * See the Redmine::fetchAll() description for details;
     * 
     * @param \acdhOeaw\fedora\Fedora $fedora Fedora connection
     * @param bool $progressBar should progress bar be displayed 
     *   (makes sense only if the standard output is a console)
     * @return array
     * @see Redmine::fetchAll()
     */
    static public function fetchAll(Fedora $fedora, bool $progressBar): array {
        $param = 'limit=100000&key=' . urlencode(RC::get('redmineApiKey'));
        return self::redmineFetchLoop($fedora, $progressBar, 'users', $param);
    }

    /**
     * Maps Redmine's User ID to the Redmine's User URI
     * @param int $id Redmine ID
     * @return string
     */
    static public function redmineId2repoId(int $id): string {
        return self::apiUrl() . '/users/' . $id;
    }

    public function updateRms(bool $create = true, bool $uploadBinary = true,
                              string $path = '/agent/'): FedoraResource {
        return parent::updateRms($create, $uploadBinary, $path);
    }
}
