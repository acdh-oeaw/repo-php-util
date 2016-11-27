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

use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\util\EasyRdfUtil;
use acdhOeaw\util\SparqlEndpoint;
use DirectoryIterator;
use DomainException;
use EasyRdf_Graph;
use EasyRdf_Resource;
use EasyRdf_Sparql_Result;
use EasyRdf_Sparql_Client;
use RuntimeException;
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

    /**
     * @var \EasyRdf_Sparql_Client
     */
    static private $sparqlClient;

    static public function init(Config $cfg) {
        self::$idProp = $cfg->get('fedoraIdProp');
        self::$relProp = $cfg->get('fedoraRelProp');
        self::$locProp = $cfg->get('fedoraLocProp');
        self::$sizeProp = $cfg->get('fedoraSizeProp');
        self::$containerDir = preg_replace('|/$|', '', $cfg->get('containerDir')) . '/';
        self::$sparqlClient = new EasyRdf_Sparql_Client($cfg->get('sparqlUrl'));
    }

    /**
     * @var FedoraResource
     */
    private $resource;
    private $paths = array();
    private $filter = '';
    private $flatStructure = false;
    private $uploadSizeLimit = 0;
    private $depth = 1000;
    private $includeEmpty = false;

    /**
     * Creates an indexer object for a given Fedora resource.
     * 
     * @param FedoraResource $resource
     * @throws RuntimeException
     */
    public function __construct(FedoraResource $resource) {
        $this->resource = $resource;

        $metadata = $this->resource->getMetadata();
        $locations = $metadata->allLiterals(EasyRdfUtil::fixPropName(self::$locProp));
        if (count($locations) === 0) {
            throw new RuntimeException('Resouce lacks locationpath property');
        }
        foreach ($locations as $i) {
            $loc = preg_replace('|/$|', '', self::$containerDir . $i->getValue());
            /*            if (!file_exists($loc)) {
              throw new RuntimeException('Locationpath does not exist: ' . $loc);
              } */
            if (is_dir($loc)) {
                $this->paths[] = $i->getValue();
            }
        }
    }

    public function setPaths(array $paths) {
        $this->paths = $paths;
    }

    public function setFilter(string $filter) {
        $this->filter = $filter;
    }

    public function setFlatStructure(bool $ifFlat) {
        $this->flatStructure = $ifFlat;
    }

    /**
     * 
     * @param bool $limit maximum size of files uploaded to the repo (0 will cause no files upload)
     */
    public function setUploadSizeLimit(int $limit) {
        $this->uploadSizeLimit = $limit;
    }

    /**
     * 
     * @param int $depth maximum insexing depth (0 - only initial Resource dir, 1 - also its direct subdirectories, etc.)
     */
    public function setDepth(int $depth) {
        $this->depth = $depth;
    }

    /**
     * 
     * @param bool $include should resources be created for empty directories
     */
    public function setIncludeEmptyDirs(bool $include) {
        $this->includeEmpty = $include;
    }

    /**
     * Indexes files in the resource directory.
     * 
     * @param bool $verbose should be verbose
     */
    public function index(bool $verbose = false) {
        foreach ($this->paths as $path) {
            foreach (new DirectoryIterator(self::$containerDir . $path) as $i) {
                if ($i->isDot()) {
                    continue;
                }

                $skip = $this->isSkipped($i);
                $upload = $i->isFile() && $this->uploadSizeLimit > $i->getSize();

                $metadata = $this->createMetadata($path, $i);

                try {
                    // resource already exists and should be updated
                    $res = $this->getResource($path, $i);
                    echo $verbose ? "update " : "";

                    $res->setMetadata($metadata);
                    $res->updateMetadata();
                    if ($upload) {
                        echo $verbose ? "+ upload " : "";
                        $res->updateContent($i->getPathname(), true);
                    }
                } catch (DomainException $e) {
                    // resource does not exist and must be created
                    if (!$skip) {
                        $res = $this->resource->getFedora()->createResource($metadata, $upload ? $i->getPathname() : '');
                        echo $verbose ? "create " : "";
                    } else {
                        echo $verbose ? "skip " : "";
                    }
                }

                echo $verbose ? $i->getPathname() . "\n" : "";
                echo $verbose && !$skip ? "\t" . $res->getId() . "\n\t" . $res->getUri() . "\n" : "";

                // recursion
                if ($i->isDir() && (!$skip || $this->flatStructure && $this->depth > 0)) {
                    echo $verbose ? "entering " . $i->getPathname() . "\n" : "";
                    $ind = clone($this);
                    $ind->setDepth($this->depth - 1);
                    $ind->setPaths(array(substr($i->getPathname(), strlen(self::$containerDir))));
                    $ind->index($verbose);
                    echo $verbose ? "returning " . $path . "\n" : "";
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
     * @return bool
     */
    private function isSkipped(DirectoryIterator $i): bool {
        $isEmptyDir = true;
        if ($i->isDir()) {
            foreach (new DirectoryIterator($i->getPathname()) as $j) {
                if (!$j->isDot()) {
                    $isEmptyDir = false;
                    break;
                }
            }
        }
        $skipDir = (!$this->includeEmpty && ($this->depth == 0 || $isEmptyDir) || $this->flatStructure);
        $skipFile = !preg_match($this->filter, $i->getFilename());
        return $i->isDir() && $skipDir || !$i->isDir() && $skipFile;
    }

    private function createMetadata(string $path, DirectoryIterator $i): EasyRdf_Resource {
        $graph = new EasyRdf_Graph;
        $metadata = $graph->resource('newResource');
        $metadata->addLiteral(self::$locProp, $path . '/' . $i->getFilename());
        $metadata->addLiteral('ebucore:filename', $i->getFilename());
        $metadata->addResource(self::$relProp, $this->resource->getId());
        if ($i->isFile()) {
            $metadata->addLiteral(self::$sizeProp, $i->getSize());
        }
        return $metadata;
    }

    private function getResource(string $path, DirectoryIterator $i): FedoraResource {
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
            $resId = EasyRdfUtil::escapeUri($this->resource->getId());
            $idProp = EasyRdfUtil::escapeUri(self::$idProp);
            $locProp = EasyRdfUtil::escapeUri(self::$locProp);
            $resPath = EasyRdfUtil::escapeLiteral($path);
            $query = sprintf($query, $relProp, $resId, $idProp, $locProp, $resPath);

            $res = self::$sparqlClient->query($query);

            if ($res->numRows() === 0) {
                throw new DomainException('No such resource');
            }
            if ($res->numRows() > 1) {
                throw new RuntimeException('Many resources with a given location path');
            }
            self::$resourceCache[$path] = new FedoraResource($res[0]->child);
        }
        return self::$resourceCache[$path];
    }

    /**
     * Returns an array of the locations listed in Fedora resources
     * location path properties which don't exist in the filesystem.
     * 
     * An array of MissingLocation objects is returned.
     * 
     * Only location paths with literal values are taken into account.
     * Support for location paths being URIs may be added in the future.
     * 
     * The location path property is defined by the "fedoraLocProp" 
     * configuration option (see init()).
     * 
     * @return array 
     * @throws RuntimeException
     */
    public function getMissingLocations(): array {
        $missing = array();
        foreach ($this->getLocations() as $i) {
            if (!get_class($i->path) === 'EasyRdf_Literal') {
                continue; // skip locations being URIs
            }

            $fullPath = self::$containerDir . (string) $i->path;
            if (!file_exists($fullPath)) {
                $missing[] = new Location($fullPath, (string) $i->path, (string) $i->uri);
            }
        }
        return $missing;
    }

    /**
     * Fetches all child resources locations.
     * 
     * @return \EasyRdf_Sparql_Result
     * @throws RuntimeException
     */
    private function getLocations(): EasyRdf_Sparql_Result {
        if (FedoraResource::inTransaction()) {
            throw new RuntimeException('Fedora transaction is active');
        }

        $query = '
            SELECT DISTINCT ?uri ?path
            WHERE { 
                ?uri %s ?path .
                ?uri (%s / ^%s)+ %s
            }
        ';
        $locProp = EasyRdfUtil::escapeUri(self::$locProp);
        $relProp = EasyRdfUtil::escapeUri(self::$relProp);
        $idProp = EasyRdfUtil::escapeUri(self::$idProp);
        $thisUri = EasyRdfUtil::escapeUri($this->resource->getUri());
        $query = sprintf($query, $locProp, $relProp, $idProp, $thisUri);

        $locations = SparqlEndpoint::query($query);
        return $locations;
    }

}
