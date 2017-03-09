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

namespace acdhOeaw\storage;

use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\metadataQuery\Query;
use acdhOeaw\fedora\metadataQuery\HasValue;
use acdhOeaw\fedora\metadataQuery\HasProperty;
use acdhOeaw\util\EasyRdfUtil;
use acdhOeaw\util\SparqlEndpoint;
use DirectoryIterator;
use DomainException;
use EasyRdf\Graph;
use EasyRdf\Resource;
use EasyRdf\Sparql\Result;
use EasyRdf\Literal;
use RuntimeException;
use zozlak\util\Config;

/**
 * Indexes children of a given FedoraResource in a file system
 *
 * @author zozlak
 */
class Indexer {

    /**
     * URI of the RDF property denoting ACDH ID
     * @var string
     */
    static private $idProp;

    /**
     * URI of the RDF property denoting relation of being a child resource
     * @var string
     */
    static private $relProp;

    /**
     * URI of the RDF property denoting resource location 
     * @var string
     */
    static private $locProp;

    /**
     * URI of the RDF property denoting resource size
     * @var string 
     */
    static private $sizeProp;

    /**
     * URI of the RDF property denoting resource title
     * @var string 
     */
    static private $titleProp;

    /**
     * URI of the default RDF class assigned to indexed resources
     * @var string
     */
    static private $defaultClass;

    /**
     * Path to the container root
     * @var string
     */
    static private $containerDir;

    /**
     * FedoraResource objects cache indexed using their location path
     * @var type 
     */
    static private $resourceCache = array();

    /**
     * Initializes class with configuration settings.
     * 
     * Required configuration parameters include:
     * 
     * - fedoraIdProp - URI of the RDF property denoting resource ACDH ID
     * - fedoraRelProp - URI of the RDF property denoting relation of being a child
     * - fedoraLocProp - URI of the RDF property denoting resource location
     * - fedoraSizeProp - URI of the RDF property denoting resource size
     * - fedoraTitleProp - URI of the RDF property denoting resource title
     * - indexerDefaultClass - URI of the default class to be assigned to each 
     *     indexed resource (can be empty)
     * - containerDir - path to the container root (the "fedoraLocProp" property
     *     values are relative to this path)
     * 
     * @param Config $cfg
     */
    static public function init(Config $cfg) {
        self::$idProp = $cfg->get('fedoraIdProp');
        self::$relProp = $cfg->get('fedoraRelProp');
        self::$locProp = $cfg->get('fedoraLocProp');
        self::$sizeProp = $cfg->get('fedoraSizeProp');
        self::$titleProp = $cfg->get('fedoraTitleProp');
        self::$defaultClass = $cfg->get('indexerDefaultClass');
        self::$containerDir = preg_replace('|/$|', '', $cfg->get('containerDir')) . '/';
    }

    /**
     * FedoraResource which children are created by the Indexer
     * @var FedoraResource
     */
    private $resource;

    /**
     * File system paths where resource children are located
     * 
     * It is a concatenation of the container root path coming from the
     * class settings (set by calling init()) and the location path
     * properties of the FedoraResource.
     * 
     * They can be also set manually using the `setPaths()` method
     * 
     * @var array
     */
    private $paths = array();

    /**
     * Regular expression for matching child resource file names.
     * @var string 
     */
    private $filter = '//';

    /**
     * Should children be directly attached to the FedoraResource or maybe
     * each subdirectory should result in a separate collection resource
     * containing its children.
     * @var bool
     */
    private $flatStructure = false;

    /**
     * Maximum size of a child resource (in bytes) resulting in the creation
     * of binary resources.
     * 
     * For child resources bigger then this limit an "RDF only" Fedora resources
     * will be created.
     * 
     * @var int
     */
    private $uploadSizeLimit = 0;

    /**
     * How many subsequent subdirectories should be indexed.
     * 
     * @var int 
     */
    private $depth = 1000;

    /**
     * Should resources be created for empty directories.
     * 
     * Skipped if `$flatStructure` equals to `true`
     * 
     * @var bool 
     */
    private $includeEmpty = false;

    /**
     * Encoding used by file system paths.
     * 
     * By default it is guessed from current locale.
     * 
     * @var string
     * @see setPathEncoding()
     */
    private $pathEncoding = 'UTF-8';

    /**
     * Creates an indexer object for a given Fedora resource.
     * 
     * @param FedoraResource $resource
     * @param string $encoding character encoding used by the operation system
     *   (will be autodetected if not provided)
     * @throws RuntimeException
     */
    public function __construct(FedoraResource $resource, string $encoding = null) {
        $this->resource = $resource;

        $metadata = $this->resource->getMetadata();
        $locations = $metadata->allLiterals(self::$locProp);
        if (count($locations) === 0) {
            throw new RuntimeException('Resource lacks locationpath property');
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

        // detect path encoding
        if (empty($encoding)) {
            foreach (explode(';', setlocale(LC_ALL, 0)) as $i) {
                $i = explode('=', $i);
                if ($i[0] === 'LC_CTYPE') {
                    $tmp = preg_replace('|^.*[.]|', '', $i[1]);
                    if (is_numeric($tmp)) {
                        $this->pathEncoding = 'windows-' . $tmp;
                    } else if (preg_match('|utf-?8|i', $tmp)) {
                        $this->pathEncoding = 'utf-8';
                    } else {
                        throw new RuntimeException('Operation system encoding can not be determined');
                    }
                }
            }
        } else {
            $this->pathEncoding = $encoding;
        }
    }

    /**
     * Overrides file system paths to look into for child resources.
     * 
     * @param array $paths
     */
    public function setPaths(array $paths) {
        $this->paths = $paths;
    }

    /**
     * Sets file name filter for child resources.
     * 
     * @param string $filter regular expression conformant with preg_replace()
     */
    public function setFilter(string $filter) {
        $this->filter = $filter;
    }

    /**
     * Sets if child resources be directly attached to the indexed FedoraResource
     * (`$ifFlat` equals to `true`) or a separate collection Fedora resource
     * be created for each subdirectory (`$ifFlat` equals to `false`).
     * 
     * @param bool $ifFlat
     */
    public function setFlatStructure(bool $ifFlat) {
        $this->flatStructure = $ifFlat;
    }

    /**
     * Sets size treshold for uploading child resources as binary resources.
     * 
     * For files bigger then this treshold a "pure RDF" Fedora resources will
     * be created containing full metadata but no binary content.
     * 
     * @param bool $limit maximum size in bytes (0 will cause no files upload)
     */
    public function setUploadSizeLimit(int $limit) {
        $this->uploadSizeLimit = $limit;
    }

    /**
     * Sets maximum indexing depth. 
     * 
     * @param int $depth maximum indexing depth (0 - only initial Resource dir, 1 - also its direct subdirectories, etc.)
     */
    public function setDepth(int $depth) {
        $this->depth = $depth;
    }

    /**
     * Sets if Fedora resources should be created for empty directories.
     * 
     * Note this setting is skipped when the `$flatStructure` is set to `true`.
     * 
     * @param bool $include should resources be created for empty directories
     * @see setFlatStructure()
     */
    public function setIncludeEmptyDirs(bool $include) {
        $this->includeEmpty = $include;
    }

    /**
     * Does the indexing.
     * 
     * @param bool $verbose should be verbose
     * @return array a list FedoraResource objects representing indexed resources
     */
    public function index(bool $verbose = false): array {
        $indexedRes = array();

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

                    $metadata = EasyRdfUtil::mergeMetadata($res->getMetadata(), $metadata);
                    $res->setMetadata($metadata);
                    $res->updateMetadata();
                    if ($upload) {
                        echo $verbose ? "+ upload " : "";
                        $res->updateContent($i->getPathname(), true);
                    }

                    $indexedRes[] = $res;
                } catch (DomainException $e) {
                    // resource does not exist and must be created
                    if (!$skip) {
                        $res = $this->resource->getFedora()->createResource($metadata, $upload ? $i->getPathname() : '');
                        $indexedRes[] = $res;
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
                    if (!$this->flatStructure) {
                        $ind->resource = $res;
                    }
                    $ind->setDepth($this->depth - 1);
                    $ind->setPaths(array(substr($i->getPathname(), strlen(self::$containerDir))));
                    $recRes = $ind->index($verbose);
                    $indexedRes = array_merge($indexedRes, $recRes);
                    echo $verbose ? "returning " . $path . "\n" : "";
                }
            }
        }

        return $indexedRes;
    }

    /**
     * Checks if a given file system node should be skipped during import.
     * 
     * @param DirectoryIterator $i file system node
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

    /**
     * Creates metadata for an indexed file system node
     * 
     * @param string $path node location base directory relatively to the container
     * @param DirectoryIterator $i file system node
     * @return EasyRdf\Resource
     */
    private function createMetadata(string $path, DirectoryIterator $i): EasyRdf\Resource {
        $graph = new EasyRdf\Graph;
        $metadata = $graph->resource('newResource');
        if (self::$defaultClass != '') {
            $metadata->addResource('http://www.w3.org/1999/02/22-rdf-syntax-ns#type', self::$defaultClass);
        }
        $path = str_replace('\\', '/', $path . '/' . $i->getFilename());
        $metadata->addLiteral(self::$locProp, $path);
        $metadata->addLiteral(self::$titleProp, $i->getFilename());
        $metadata->addLiteral('ebucore:filename', $i->getFilename());
        $metadata->addLiteral('ebucore:hasMimeType', mime_content_type($i->getPathname()));
        $metadata->addResource(self::$relProp, $this->resource->getId());
        if ($i->isFile()) {
            $metadata->addLiteral(self::$sizeProp, $i->getSize());
        }
        return $metadata;
    }

    /**
     * Finds Fedora resource corresponding to a given file system entry.
     * 
     * Search is based on matching parent resource and location path property
     * values.
     * 
     * @param string $path path node location base dir relatively to the container
     * @param DirectoryIterator $i file system node
     * @return FedoraResource
     * @throws DomainException
     * @throws RuntimeException
     */
    private function getResource(string $path, DirectoryIterator $i): FedoraResource {
        $path = $path . '/' . $i->getFilename();
        if (!isset(self::$resourceCache[$path])) {
            $path = iconv($this->pathEncoding, 'utf-8', $path);
            /*
             *  SELECT ?child
             *  WHERE {
             *      ?child $relProp $parentResId .
             *      ?child $idProp ?id .
             *      ?child $locProp $path .
             */
            $query = new Query();
            $query->addParameter((new HasValue(self::$relProp, $this->resource->getId()))->setSubVar('?child'));
            $query->addParameter((new HasProperty(self::$idProp))->setSubVar('?child'));
            $query->addParameter((new HasValue(self::$locProp, $path))->setSubVar('?child'));

            $res = $this->resource->getFedora()->runQuery($query);

            if ($res->numRows() === 0) {
                throw new DomainException('No such resource');
            }
            if ($res->numRows() > 1) {
                throw new RuntimeException('Many resources with a given location path');
            }
            self::$resourceCache[$path] = $this->resource->getFedora()->getResourceByUri($res[0]->child);
        }
        return self::$resourceCache[$path];
    }

    /**
     * Returns an array of locations listed in Fedora resources
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
            if (!$i->path instanceof Literal) {
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
     * Fetches all child resource locations.
     * 
     * @return \EasyRdf\Sparql\Result
     * @throws \RuntimeException
     */
    private function getLocations(): Result {
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
