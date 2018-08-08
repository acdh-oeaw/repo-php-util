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

namespace acdhOeaw\util;

use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\schema\file\File;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\metaLookup\MetaLookupInterface;
use acdhOeaw\util\metaLookup\MetaLookupException;
use BadMethodCallException;
use DirectoryIterator;
use RuntimeException;

/**
 * Indexes children of a given FedoraResource in a file system
 *
 * @author zozlak
 */
class Indexer {

    const MATCH = 1;
    const SKIP  = 2;

    /**
     * Turns debug messages on
     * @var bool
     */
    static public $debug = false;

    /**
     * Returns standardized value of the containerDir configuration property.
     * @return string
     */
    static public function containerDir(): string {
        return preg_replace('|/$|', '', RC::get('containerDir')) . '/';
    }

    /**
     * FedoraResource which children are created by the Indexer
     * @var FedoraResource
     */
    private $parent;

    /**
     * File system paths where resource children are located
     * 
     * It is a concatenation of the container root path coming from the
     * class settings and the location path
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
     * Regular expression for excluding child resource file names.
     * @var string 
     */
    private $filterNot = '';

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
     * Special value of -1 means "import all no matter their size"
     * 
     * @var int
     */
    private $uploadSizeLimit = -1;

    /**
     * Fedora path in the repo where imported resources are created.
     * @var string
     */
    private $fedoraLoc = '';

    /**
     * URI of an RDF class assigned to indexed collections.
     * @var string
     */
    private $collectionClass;

    /**
     * URI of an RDF class assigned to indexed binary resources.
     * @var type 
     */
    private $binaryClass;

    /**
     * How many subsequent subdirectories should be indexed.
     * 
     * @var int 
     */
    private $depth = 1000;

    /**
     * Number of resource automatically triggering a commit (0 - no auto commit)
     * @var int
     */
    private $autoCommit = 0;

    /**
     * Should resources be created for empty directories.
     * 
     * Skipped if `$flatStructure` equals to `true`
     * 
     * @var bool 
     */
    private $includeEmpty = false;

    /**
     * Should file be skipped if a corresponding Fedora resource doesn't exist?
     * 
     * @var bool
     */
    private $updateOnly = false;

    /**
     * An object providing metadata when given a resource file path
     * @var \acdhOeaw\util\metaLookup\MetaLookupInterface
     */
    private $metaLookup;

    /**
     * Should files without external metadata (provided by the `$metaLookup`
     * object) be skipped.
     * @var bool
     */
    private $metaLookupRequire = false;

    /**
     * Repository connection
     * @var \acdhOeaw\fedora\Fedora
     */
    private $fedora;

    /**
     * Collection of resources commited during the ingestion. Used to handle
     * errors.
     * @var array
     * @see index()
     */
    private $commitedRes;

    /**
     * Collection of indexed resources
     * @var array
     * @see index()
     */
    private $indexedRes;

    /**
     * Creates an indexer object for a given Fedora resource.
     * 
     * @param FedoraResource $resource
     */
    public function __construct(FedoraResource $resource = null) {
        $this->binaryClass     = RC::get('indexerDefaultBinaryClass');
        $this->collectionClass = RC::get('indexerDefaultCollectionClass');

        if ($resource !== null) {
            $this->setParent($resource);
            $this->fedora = $resource->getFedora();
        }
    }

    /**
     * Sets the repository connection object
     * @param \acdhOeaw\util\Fedora $fedora
     */
    public function setFedora(Fedora $fedora): Indexer {
        $this->fedora = $fedora;
        return $this;
    }

    /**
     * Sets the parent resource for the indexed files
     * @param FedoraResource $resource
     */
    public function setParent(FedoraResource $resource): Indexer {
        $this->parent = $resource;
        $this->fedora = $this->parent->getFedora();
        $metadata     = $this->parent->getMetadata();
        $locations    = $metadata->allLiterals(RC::locProp());
        foreach ($locations as $i) {
            $loc = preg_replace('|/$|', '', self::containerDir() . $i->getValue());
            if (is_dir($loc)) {
                $this->paths[] = $i->getValue();
            }
        }
        return $this;
    }

    /**
     * Overrides file system paths to look into for child resources.
     * 
     * @param array $paths
     * @return \acdhOeaw\util\Indexer
     */
    public function setPaths(array $paths): Indexer {
        $this->paths = $paths;
        return $this;
    }

    /**
     * Controls the automatic commit behaviour.
     * 
     * Even when you use autocommit, you should commit your transaction after
     * `Indexer::index()` (the only exception is when you set auto commit to 1
     * forcing commiting each and every resource separately but you probably 
     * don't want to do that for performance reasons).
     * @param int $count number of resource automatically triggering a commit 
     *   (0 - no auto commit)
     * @return \acdhOeaw\util\Indexer
     */
    public function setAutoCommit(int $count): Indexer {
        $this->autoCommit = $count;
        return $this;
    }

    /**
     * Allows to turn on/off the "update only" mode. In the "update only" mode
     * files which don't have corresponding Fedora resources are skipped.
     * 
     * By default the "update only" mode is disabled.
     * 
     * @param bool $updateOnly should files be skipped by the Indexer if
     *   corresponding Fedora resources don't exist?
     * @return \acdhOeaw\util\Indexer
     */
    public function setUpdateOnly(bool $updateOnly): Indexer {
        $this->updateOnly = $updateOnly;
        return $this;
    }

    /**
     * Sets default RDF class for imported collections.
     * 
     * Overrides setting read form the `cfg::indexerDefaultCollectionClass` 
     * configuration property.
     * @param string $class
     * @return \acdhOeaw\util\Indexer
     */
    public function setCollectionClass(string $class): Indexer {
        $this->collectionClass = $class;
        return $this;
    }

    /**
     * Sets default RDF class for imported binary resources.
     * 
     * Overrides setting read form the `cfg::indexerDefaultBinaryClass` 
     * configuration property.
     * @param string $class
     * @return \acdhOeaw\util\Indexer
     */
    public function setBinaryClass(string $class): Indexer {
        $this->binaryClass = $class;
        return $this;
    }

    /**
     * Sets file name filter for child resources.
     * 
     * You can choose if file names must match or must not match (skip) the 
     * filter using the $type parameter. You can set both match and skip
     * filters by calling setFilter() two times (once with 
     * `$type = Indexer::MATCH` and second time with `$type = Indexer::SKIP`).
     * 
     * Filter is applied only to file names but NOT to directory names.
     * 
     * @param string $filter regular expression conformant with preg_replace()
     * @param int $type decides if $filter is a match or skip filter (can be
     *   one of Indexer::MATCH and Indexer::SKIP)
     * @return \acdhOeaw\util\Indexer
     */
    public function setFilter(string $filter, int $type = self::MATCH): Indexer {
        switch ($type) {
            case self::MATCH:
                $this->filter    = $filter;
                break;
            case self::SKIP:
                $this->filterNot = $filter;
                break;
            default:
                throw new BadMethodCallException('wrong $type parameter');
        }
        return $this;
    }

    /**
     * Sets if child resources be directly attached to the indexed FedoraResource
     * (`$ifFlat` equals to `true`) or a separate collection Fedora resource
     * be created for each subdirectory (`$ifFlat` equals to `false`).
     * 
     * @param bool $ifFlat
     * @return \acdhOeaw\util\Indexer
     */
    public function setFlatStructure(bool $ifFlat): Indexer {
        $this->flatStructure = $ifFlat;
        return $this;
    }

    /**
     * Sets a location where the resource will be placed.
     * 
     * Can be absolute (but will be sanitized anyway) or relative to the 
     * repository root.
     * 
     * Given location must already exist.
     * 
     * Note that this parameter is used ONLY if the resource DOES NOT EXISTS.
     * If it exists already, its location is not changed.
     * 
     * @param string $fedoraLoc fedora location 
     * @return \acdhOeaw\util\Indexer
     */
    public function setFedoraLocation(string $fedoraLoc): Indexer {
        $this->fedoraLoc = $fedoraLoc;
        return $this;
    }

    /**
     * Sets size treshold for uploading child resources as binary resources.
     * 
     * For files bigger then this treshold a "pure RDF" Fedora resources will
     * be created containing full metadata but no binary content.
     * 
     * @param bool $limit maximum size in bytes; 0 will cause no files upload,
     *   special value of -1 (default) will cause all files to be uploaded no 
     *   matter their size
     * @return \acdhOeaw\util\Indexer
     */
    public function setUploadSizeLimit(int $limit): Indexer {
        $this->uploadSizeLimit = $limit;
        return $this;
    }

    /**
     * Sets maximum indexing depth. 
     * 
     * @param int $depth maximum indexing depth (0 - only initial Resource dir, 1 - also its direct subdirectories, etc.)
     * @return \acdhOeaw\util\Indexer
     */
    public function setDepth(int $depth): Indexer {
        $this->depth = $depth;
        return $this;
    }

    /**
     * Sets if Fedora resources should be created for empty directories.
     * 
     * Note this setting is skipped when the `$flatStructure` is set to `true`.
     * 
     * @param bool $include should resources be created for empty directories
     * @return \acdhOeaw\util\Indexer
     * @see setFlatStructure()
     */
    public function setIncludeEmptyDirs(bool $include): Indexer {
        $this->includeEmpty = $include;
        return $this;
    }

    /**
     * Sets a class providing metadata for indexed files.
     * @param MetaLookupInterface $metaLookup
     * @param bool $require should files lacking external metadata be skipped
     * @return \acdhOeaw\util\Indexer
     */
    public function setMetaLookup(MetaLookupInterface $metaLookup,
                                  bool $require = false): Indexer {
        $this->metaLookup        = $metaLookup;
        $this->metaLookupRequire = $require;
        return $this;
    }

    /**
     * Performs the indexing.
     * @return array a list FedoraResource objects representing indexed resources
     */
    public function index(): array {
        list($indexed, $commited) = $this->__index();

        $this->indexedRes  = array();
        $this->commitedRes = array();
        return $indexed;
    }

    /**
     * Performs the indexing.
     * @return array a two-element array with first element containing a collection
     *   of indexed resources and a second one containing a collection of commited
     *   resources
     */
    private function __index(): array {
        $this->indexedRes  = array();
        $this->commitedRes = array();

        if (count($this->paths) === 0) {
            throw new RuntimeException('No paths set');
        }

        try {
            foreach ($this->paths as $path) {
                foreach (new DirectoryIterator(self::containerDir() . $path) as $i) {
                    $this->indexEntry($i);
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof IndexerException) {
                $this->commitedRes = array_merge($this->commitedRes, $e->getCommitedResources());
            }
            throw new IndexerException($e->getMessage(), $e->getCode(), $e, $this->commitedRes);
        }

        return array($this->indexedRes, $this->commitedRes);
    }

    /**
     * Processes single directory entry
     * @param DirectoryIterator $i
     * @return array
     */
    private function indexEntry(DirectoryIterator $i) {
        if ($i->isDot()) {
            return;
        }

        echo self::$debug ? $i->getPathname() . "\n" : "";
        
        $skip   = $this->isSkipped($i);
        $upload = $i->isFile() && ($this->uploadSizeLimit > $i->getSize() || $this->uploadSizeLimit === -1);

        if (!$skip) {
            $class  = $i->isDir() ? $this->collectionClass : $this->binaryClass;
            $parent = $this->parent === null ? null : $this->parent->getId();
            $file   = new File($this->fedora, $i->getPathname(), $class, $parent);
            if ($this->metaLookup) {
                $file->setMetaLookup($this->metaLookup, $this->metaLookupRequire);
            }
            try {
                $res = $file->updateRms(!$this->updateOnly, $upload, $this->fedoraLoc);

                $this->indexedRes[$i->getPathname()] = $res;
                echo self::$debug ? "\t" . ($file->getCreated() ? "create " : "update ") . ($upload ? "+ upload " : "") . "\n" : '';
                $this->handleAutoCommit();
            } catch (MetaLookupException $e) {
                if ($this->metaLookupRequire) {
                    $skip = true;
                } else {
                    throw $e;
                }
            } catch (NotFound $e) {
                if ($this->updateOnly) {
                    $skip = true;
                } else {
                    throw $e;
                }
            }
        }
        if ($skip) {
            echo self::$debug ? "\tskip\n" : "";
        }

        echo self::$debug && !$skip ? "\t" . $res->getId() . "\n\t" . $res->getUri() . "\n" : "";

        // recursion
        if ($i->isDir() && (!$skip || $this->flatStructure && $this->depth > 0)) {
            echo self::$debug ? "entering " . $i->getPathname() . "\n" : "";
            $ind = clone($this);
            if (!$this->flatStructure) {
                $ind->parent = $res;
            }
            $ind->setDepth($this->depth - 1);
            $path              = File::getRelPath($i->getPathname());
            $ind->setPaths(array($path));
            list($recRes, $recCom) = $ind->__index();
            $this->indexedRes  = array_merge($this->indexedRes, $recRes);
            $this->commitedRes = array_merge($this->commitedRes, $recCom);
            echo self::$debug ? "going back from " . $path : "";
            $this->handleAutoCommit();
            echo self::$debug ? "\n" : "";
        }
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
        $skipDir         = (!$this->includeEmpty && ($this->depth == 0 || $isEmptyDir) || $this->flatStructure);
        $filenameInclude = preg_match($this->filter, $i->getFilename());
        $filenameExclude = strlen($this->filterNot) > 0 && preg_match($this->filterNot, $i->getFilename());
        $skipFile        = !$filenameInclude || $filenameExclude;
        return $i->isDir() && $skipDir || !$i->isDir() && $skipFile;
    }

    /**
     * Performs autocommit if needed
     * @return bool if autocommit was performed
     */
    private function handleAutoCommit(): bool {
        $diff = count($this->indexedRes) - count($this->commitedRes);
        if ($diff >= $this->autoCommit && $this->autoCommit > 0) {
            echo self::$debug ? " + autocommit" : '';
            $this->fedora->commit();
            $this->commitedRes = $this->indexedRes;
            $this->fedora->begin();
            return true;
        }
        return false;
    }

}
