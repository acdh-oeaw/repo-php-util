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

namespace acdhOeaw\storage;

use FilesystemIterator;
use DirectoryIterator;
use DomainException;
use RuntimeException;
use EasyRdf_Resource;
use acdhOeaw\rms\Resource;
use acdhOeaw\rms\SparqlEndpoint;
use acdhOeaw\EasyRdfUtil;
use zozlak\util\Config;

/**
 * Indexes resources in a filesystem
 *
 * @author zozlak
 */
class Indexer {

    static private $idProp;
    static private $relProp;
    static private $locProp;
    static private $sizeProp;
    static private $containerDir;
    static private $resourceCache = array();

    static public function init(Config $cfg) {
        self::$idProp = $cfg->get('fedoraIdProp');
        self::$relProp = $cfg->get('fedoraRelProp');
        self::$locProp = $cfg->get('fedoraLocProp');
        self::$sizeProp = $cfg->get('fedoraSizeProp');
        self::$containerDir = preg_replace('|/$|', '', $cfg->get('containerDir')) . '/';
    }

    /**
     * @var \acdhOeaw\rms\Resource
     */
    private $resource;
    private $paths = array();

    public function __construct(Resource $resource) {
        $this->resource = $resource;

        $metadata = $this->resource->getMetadata();
        $locations = $metadata->allLiterals(EasyRdfUtil::fixPropName(self::$locProp));
        if (count($locations) === 0) {
            throw new RuntimeException('Resouce lacks locationpath property');
        }
        foreach ($locations as $i) {
            $loc = preg_replace('|/$|', '', self::$containerDir . $i->getValue());
            if (!is_dir($loc)) {
                throw new RuntimeException('Locationpath does not exist: ' . $loc);
            }
            $this->paths[] = $i->getValue();
        }
    }

    /**
     * 
     * @param bool $upload should resource be uploaded to the repository
     * @param int $depth maximum insexing depth (0 - only initial Resource dir, 1 - also its direct subdirectories, etc.)
     * @param bool $empty should resources be created for empty directories
     */
    public function index(bool $upload = false, int $depth = 1000000, bool $empty = false) {
        foreach ($this->paths as $path) {
            foreach (new DirectoryIterator(self::$containerDir . $path) as $i) {
                if ($i->isDot()) {
                    continue;
                }

                $metadata = $this->createMetadata($path, $i);
                try {
                    $res = $this->getResource($path, $i);
                    $res->setMetadata($metadata);
                    $res->update();
                    echo "found ";
                } catch (DomainException $e) {
                    if (!$this->isEmpty($i, $depth, $empty)) {
                        $res = Resource::factory($metadata, $upload && $i->isFile() ? $i->getPathname() : '');
                        echo "created ";
                    } else {
                        echo "skipped ";
                    }
                }
                echo $i->getPathname() . "\n\t" . $this->getResourceId() . "\n\t" . $this->resource->getUri() . "\n";

                if ($i->isDir() && $depth > 0) {
                    echo "entering subdir\n";
                    $ind = new Indexer($res);
                    $ind->index($upload, $depth - 1, $empty);
                }
            }
        }
    }

    /**
     * Checks if a fiven filesystem node should be skipped during import.
     * This happens only if the $empty parameter is TRUE, the node is 
     * a directory and this directory is empty or the maximum indexing depth
     * was reached.
     * 
     * @param DirectoryIterator $i
     * @param int $depth
     * @param bool $empty
     * @return bool
     */
    private function isEmpty(DirectoryIterator $i, int $depth, bool $empty): bool {
        $isEmptyDir = true;
        if ($i->isDir()) {
            foreach (new DirectoryIterator($i->getPathname()) as $j) {
                if (!$i->isDot()) {
                    $isEmptyDir = false;
                    break;
                }
            }
        }
        return !$empty && $i->isDir() && ($depth == 0 || $isEmptyDir);
    }

    private function getResourceId(): string {
        $ids = $this->resource->getIds();
        if (count($ids) !== 1) {
            throw new RuntimeException((count($ids) == 0 ? 'No' : 'Many') . ' ids');
        }
        return $ids[0];
    }

    private function createMetadata(string $path, DirectoryIterator $i): EasyRdf_Resource {
        $graph = new \EasyRdf_Graph;
        $metadata = $graph->resource('newResource');
        $metadata->addLiteral(self::$locProp, $path . '/' . $i->getFilename());
        $metadata->addLiteral('ebucore:filename', $i->getFilename());
        $metadata->addResource(self::$relProp, $this->getResourceId());
        if ($i->isFile()) {
            $metadata->addLiteral(self::$sizeProp, $i->getSize());
        }
        return $metadata;
    }

    private function getResource(string $path, DirectoryIterator $i): Resource {
        $path = $path . '/' . $i->getFilename();
        if (!isset(self::$resourceCache[$path])) {
            $query = '
                SELECT ?child
                WHERE {
                    ?child %s %s .
                    ?child %s ?id .
                    ?child %s %s^^xsd:string .
                }
            ';
            $relProp = EasyRdfUtil::escapeUri(self::$relProp);
            $resId = EasyRdfUtil::escapeUri($this->getResourceId());
            $idProp = EasyRdfUtil::escapeUri(self::$idProp);
            $locProp = EasyRdfUtil::escapeUri(self::$locProp);
            $resPath = EasyRdfUtil::escapeLiteral($path);
            $query = sprintf($query, $relProp, $resId, $idProp, $locProp, $resPath);

            $res = SparqlEndpoint::query($query);

            if ($res->numRows() === 0) {
                throw new DomainException('No such resource');
            }
            if ($res->numRows() > 1) {
                throw new RuntimeException('Many resources with a given location path');
            }
            self::$resourceCache[$path] = new Resource($res[0]->child);
        }
        return self::$resourceCache[$path];
    }

}
