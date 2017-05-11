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

namespace acdhOeaw\schema\file;

use RuntimeException;
use InvalidArgumentException;
use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\schema\Object;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Description of File
 *
 * @author zozlak
 */
class File extends Object {

    /**
     * Detected operating system path enconding.
     * @var string
     */
    static private $pathEncoding;

    /**
     * Tries to detect path encoding used in the operating system by looking
     * into locale settings.
     * @throws RuntimeException
     */
    static private function detectPathEncoding() {
        if (self::$pathEncoding) {
            return;
        }
        foreach (explode(';', setlocale(LC_ALL, 0)) as $i) {
            $i = explode('=', $i);
            if ($i[0] === 'LC_CTYPE') {
                $tmp = preg_replace('|^.*[.]|', '', $i[1]);
                if (is_numeric($tmp)) {
                    self::$pathEncoding = 'windows-' . $tmp;
                    break;
                } else if (preg_match('|utf-?8|i', $tmp)) {
                    self::$pathEncoding = 'utf-8';
                    break;
                } else {
                    throw new RuntimeException('Operation system encoding can not be determined');
                }
            }
        }
    }

    /**
     * Sanitizes file path - turns all \ into / and assures it is UTF-8 encoded.
     * @param string $path
     * @param string $pathEncoding
     * @return string
     */
    static public function sanitizePath(string $path, string $pathEncoding = null): string {
        if ($pathEncoding === null) {
            self::detectPathEncoding();
            $pathEncoding = self::$pathEncoding;
        }
        $path = iconv($pathEncoding, 'utf-8', $path);
        $path = str_replace('\\', '/', $path);
        return $path;
    }

    /**
     * Extracts relative path from a full path (by skipping cfg:containerDir)
     * @param string $fullPath
     */
    static public function getRelPath(string $fullPath): string {
        $contDir = RC::get('containerDir');
        if (!strpos($fullPath, $contDir) === 0) {
            throw new InvalidArgumentException('path is outside the container');
        }
        $contDir = preg_replace('|/$|', '', $contDir);
        return substr($fullPath, strlen($contDir) + 1);
    }

    /**
     *
     * @var string 
     */
    private $path;

    /**
     * 
     * @param Fedora $fedora
     * @param type $id
     */
    public function __construct(Fedora $fedora, string $id) {
        $this->path = $id;

        $prefix = RC::get('containerToUriPrefix');
        $id     = self::sanitizePath($id);
        $id     = self::getRelPath($id);
        $id     = str_replace('%2F', '/', rawurlencode($id));
        parent::__construct($fedora, $prefix . $id);
    }

    /**
     * 
     * @param string $class
     * @param string $parent
     * @return Resource
     */
    public function getMetadata(string $class = null, string $parent = null): Resource {
        $graph = new Graph();
        $meta  = $graph->resource('.');
        
        $meta->addResource(RC::idProp(), $this->getId());
        
        if ($class != '') {
            $meta->addResource('http://www.w3.org/1999/02/22-rdf-syntax-ns#type', $class);
        }
        
        if ($parent) {
            $meta->addResource(RC::locProp(), $parent);
        }
        
        $meta->addLiteral(RC::locProp(), self::getRelPath($this->path));
        
        $meta->addLiteral(RC::titleProp(), basename($this->path));
        $meta->addLiteral('http://www.ebu.ch/metadata/ontologies/ebucore/ebucore#filename', basename($this->path));
        $meta->addLiteral('http://www.ebu.ch/metadata/ontologies/ebucore/ebucore#hasMimeType', mime_content_type($this->path));
        if (is_file($this->path)) {
            $meta->addLiteral(RC::get('fedoraSizeProp'), filesize($this->path));
        }

        return $meta;
    }

    /**
     * Returns file path (cause path is supported by the `Fedora->create()`). 
     * @return string
     */
    protected function getBinaryData(): string {
        return $this->path;
    }

}
