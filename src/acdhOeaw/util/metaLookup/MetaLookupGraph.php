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

namespace acdhOeaw\util\metaLookup;

use RuntimeException;
use EasyRdf\Resource;
use EasyRdf\Graph;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Searches for file metadata inside an RDF graph.
 *
 * @author zozlak
 */
class MetaLookupGraph {

    /**
     * Debug flag - setting it to true causes loggin messages to be displayed.
     * @var bool
     */
    static public $debug = false;

    /**
     * Graph with all metadata
     * @var \EasyRdf\Graph
     */
    private $graph;

    /**
     * 
     * @param \EasyRdf\Graph $graph metadata graph
     */
    public function __construct(Graph $graph) {
        $this->graph = $graph;
    }

    /**
     * Searches for metadata of a given file.
     * @param string $path path to the file (just for conformance with
     *   the interface, it is not used)
     * @param \EasyRdf\Resource $meta file's metadata 
     * @return \EasyRdf\Resource fetched metadata
     * @throws \RuntimeException
     */
    public function getMetadata(string $path, Resource $meta = null): Resource {
        if ($meta == null) {
            return(new Graph())->resource('.');
        }

        $candidates = array();
        foreach ($meta->allResources(RC::idProp()) as $id) {
            foreach ($this->graph->resourcesMatching(RC::idProp(), $id) as $i) {
                $candidates[$i->getUri()] = $i;
            }
        }

        if (count($candidates) == 1) {
            echo self::$debug ? "  metadata found\n" : '';
            return array_pop($candidates);
        } else if (count($candidates) > 1) {
            throw new RuntimeException('more then one metadata resource');
        }

        echo self::$debug ? "  metadata not found\n" : '';
        return(new Graph())->resource('.');
    }

}
