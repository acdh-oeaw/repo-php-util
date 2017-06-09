<?php

/**
 * The MIT License
 *
 * Copyright 2016 Austrian Centre for Digital Humanities.
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
use InvalidArgumentException;
use EasyRdf\Resource;
use EasyRdf\Graph;

/**
 * Implements metadata lookup by searching in a given metadata locations for
 * a file with an original file name with a given extension appended.
 *
 * @author zozlak
 */
class MetaLookupFile implements MetaLookupInterface {

    /**
     * Debug flag - setting it to true causes loggin messages to be displayed.
     * @var bool
     */
    static public $debug = false;
    
    /**
     * Array of possible metadata locations (relative and/or absolute)
     * @var array
     */
    private $locations;

    /**
     * Suffix added to a file name to form a metadata file name.
     * @var string
     */
    private $extension;

    /**
     * Metadata file format
     * @var string
     */
    private $format;

    /**
     * Creates a new MetaLookupFile instance.
     * @param array $locations location to search for metadata files in
     *   (both absolute and relative paths allowed)
     * @param string $extension suffix added to the original filename to form
     *   a metadata file name
     * @param string $format metadata format understandable for 
     *   \EasyRdf\Graph::parseFile() (if null format will be autodetected)
     */
    public function __construct(array $locations = array(),
                                string $extension = '.ttl', $format = null) {
        $this->locations = $locations;
        $this->extension = $extension;
        $this->format    = $format;
    }

    /**
     * Searches for metadata of a given file.
     * @param string $path path to the file
     * @param \EasyRdf\Resource $meta file's metadata (just for conformance with
     *   the interface, they are not used)
     * @return \EasyRdf\Resource fetched metadata
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function getMetadata(string $path, Resource $meta = null): Resource {
        if (!file_exists($path)) {
            throw new InvalidArgumentException('no such file');
        }
        $dir  = dirname($path);
        $name = basename($path) . $this->extension;

        $graph = new Graph();
        foreach ($this->locations as $loc) {
            if (substr($loc, 0, 1) !== '/') {
                $loc = $dir . '/' . $loc;
            }
            $loc = $loc . '/' . $name;
            
            echo self::$debug ? '  trying metadata location ' . $loc . "...\n" : '';
            if (file_exists($loc)) {
                echo self::$debug ? "    found\n" : '';
                
                $graph->parseFile($loc, $this->format);
                $candidates = array();
                foreach ($graph->resources() as $res) {
                    if (count($res->propertyUris()) > 0) {
                        $candidates[] = $res;
                    }
                }
                
                if (count($candidates) == 1) {
                    return $candidates[0];
                } else if (count($candidates) > 1) {
                    throw new RuntimeException('more then one metadata resource');
                } else{
                    echo self::$debug ? "      but no metadata inside\n" : '';
                }
            } else {
                echo self::$debug ? "    NOT found\n" : '';
            }
        }

        return $graph->resource('.');
    }

}
