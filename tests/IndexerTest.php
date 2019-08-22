<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\repoPhpUtil;

use EasyRdf\Graph;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\util\Indexer;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\metaLookup\MetaLookupFile;
use acdhOeaw\util\metaLookup\MetaLookupGraph;

/**
 * Description of IndexerTest
 *
 * @author zozlak
 */
class IndexerTest extends TestBase {

    static private $res;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        \acdhOeaw\schema\SchemaObject::$debug = true;
        //MetaLookupFile::$debug                               = true;
        //MetaLookupGraph::$debug                              = true;
        Indexer::$debug                                      = true;
        \acdhOeaw\fedora\Fedora::$debug = true;

        self::$repo->begin();
        $id = 'http://my.test/id';
        try {
            self::$res = self::$repo->getResourceById($id);
        } catch (NotFound $ex) {
            $meta      = (new Graph())->resource('.');
            $meta->addLiteral(RC::titleProp(), 'test parent');
            $meta->addLiteral(RC::locProp(), 'data');
            $meta->addResource(RC::idProp(), $id);
            self::$res = self::$repo->createResource($meta);
        }
        self::$repo->commit();
    }

    static public function tearDownAfterClass(): void {
        parent::tearDownAfterClass();

        if (self::$res) {
            self::$repo->begin();
            self::$res->delete(true, true, true);
            self::$repo->commit();
        }
    }

    private $ind;
    private $tmpDir = __DIR__ . '/tmp/';
    private $tmpContent;

    public function setUp(): void {
        parent::setUp();
        $this->ind = new Indexer(self::$res);
        $this->ind->setDepth(1);
        $this->ind->setUploadSizeLimit(10000000);
        if (file_exists($this->tmpDir)) {
            system("rm -fR " . $this->tmpDir);
        }
        $this->tmpContent = file_get_contents(__DIR__ . '/data/sample.xml');
    }

    public function tearDown(): void {
        parent::tearDown();
        if (file_exists($this->tmpDir)) {
            system("rm -fR " . $this->tmpDir);
        }
        file_put_contents(__DIR__ . '/data/sample.xml', $this->tmpContent);
    }

    /**
     * @group indexer
     */
    public function testSimple(): void {
        $this->ind->setFilter('/txt|xml/', Indexer::MATCH);
        $this->ind->setFilter('/^(skiptest.txt)$/', Indexer::SKIP);
        self::$repo->begin();
        $indRes = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(6, count($indRes));
    }

    /**
     * @group indexer
     */
    public function testSkipNotExist(): void {
        $this->testSimple();

        $this->ind->setFilter('', Indexer::SKIP);
        $this->ind->setSkip(Indexer::SKIP_NOT_EXIST);
        self::$repo->begin();
        $indRes = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(6, count($indRes));
    }

    /**
     * @group indexer
     */
    public function testSkipExist(): void {
        $indRes1 = $indRes2 = [];

        $this->ind->setFilter('/txt/', Indexer::MATCH);
        self::$repo->begin();
        $indRes1 = $this->ind->index();
        $this->noteResources($indRes1);
        self::$repo->commit();

        $this->assertEquals(4, count($indRes1));

        $this->ind->setSkip(Indexer::SKIP_EXIST);
        $this->ind->setFilter('/(txt|xml)$/', Indexer::MATCH);
        self::$repo->begin();
        $indRes2 = $this->ind->index();
        $this->noteResources($indRes2);
        self::$repo->commit();

        $this->assertEquals(2, count($indRes2));
    }

    /**
     * @group indexer
     */
    public function testSkipBinaryExist(): void {
        $indRes1 = $indRes2 = [];

        $this->ind->setFilter('/txt/', Indexer::MATCH);
        self::$repo->begin();
        $indRes1 = $this->ind->index();
        $this->noteResources($indRes1);
        self::$repo->commit();

        $this->assertEquals(4, count($indRes1));

        $this->ind->setSkip(Indexer::SKIP_BINARY_EXIST);
        $this->ind->setFilter('/(txt|xml)$/', Indexer::MATCH);
        self::$repo->begin();
        $indRes2 = $this->ind->index();
        $this->noteResources($indRes2);
        self::$repo->commit();

        $this->assertEquals(4, count($indRes2));
    }

    /**
     * @group indexer
     */
    public function testMetaFromFile(): void {
        $metaLookup = new MetaLookupFile(['.'], '.ttl');
        $this->ind->setDepth(0);
        $this->ind->setMetaLookup($metaLookup);
        $this->ind->setFilter('/sample.xml$/');
        self::$repo->begin();
        $indRes     = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(1, count($indRes));
        $meta = array_pop($indRes)->getMetadata();
        $this->assertEquals('sample value', (string) $meta->getLiteral('https://some.sample/property'));
    }

    /**
     * @group indexer
     */
    public function testMetaFromGraph(): void {
        $graph      = new Graph();
        $graph->parseFile(__DIR__ . '/data/sample.xml.ttl');
        $metaLookup = new MetaLookupGraph($graph, RC::idProp());
        $this->ind->setDepth(0);
        $this->ind->setMetaLookup($metaLookup);
        $this->ind->setFilter('/sample.xml$/');
        self::$repo->begin();
        $indRes     = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(1, count($indRes));
        $meta = array_pop($indRes)->getMetadata();
        $this->assertEquals('sample value', (string) $meta->getLiteral('https://some.sample/property'));
    }

    /**
     * @group indexer
     */
    public function testSkipWithoutMetaInFile(): void {
        $metaLookup = new MetaLookupFile(['.'], '.ttl');
        self::$repo->begin();
        $this->ind->setMetaLookup($metaLookup, true);
        $this->ind->setFilter('/xml$/');
        $indRes     = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(1, count($indRes));
        $meta = array_pop($indRes)->getMetadata();
        $this->assertEquals('sample value', (string) $meta->getLiteral('https://some.sample/property'));
    }

    /**
     * @group indexer
     */
    public function testWithoutMetaInGraph(): void {
        $metaLookup = new MetaLookupFile(['.'], '.ttl');
        self::$repo->begin();
        $this->ind->setMetaLookup($metaLookup, true);
        $this->ind->setFilter('/sample.xml$/');
        $indRes     = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(1, count($indRes));
        $meta = array_pop($indRes)->getMetadata();
        $this->assertEquals('sample value', (string) $meta->getLiteral('https://some.sample/property'));
    }

    /**
     * @group indexer
     */
    public function testMergeOnExtMeta(): void {
        $idProp    = RC::idProp();
        $titleProp = RC::titleProp();
        $commonId  = 'https://my.id.nmsp/' . rand();
        $fileName  = rand();

        self::$repo->begin();

        $meta = (new Graph())->resource('.');
        $meta->addResource($idProp, $commonId);
        $meta->addLiteral($titleProp, 'sample title');
        $res1 = self::$repo->createResource($meta);
        $this->noteResources([$res1]);

        $meta->delete($titleProp);
        mkdir($this->tmpDir);
        file_put_contents($this->tmpDir . $fileName . '.ttl', $meta->getGraph()->serialise('turtle'));
        file_put_contents($this->tmpDir . $fileName, 'sample content');
        $this->ind->setPaths([basename($this->tmpDir)]);
        $this->ind->setFilter('/^' . $fileName . '$/');
        $this->ind->setMetaLookup(new MetaLookupFile(['.'], '.ttl'));
        $indRes = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(1, count($indRes));
        $this->assertEquals($res1->getUri(), array_pop($indRes)->getUri());
    }

    /**
     * @group indexer
     */
    public function testMergeAndDeleted(): void {
        $idProp    = RC::idProp();
        $titleProp = RC::titleProp();
        $commonId  = 'https://my.id.nmsp/' . rand();
        $fileName  = rand();

        //first instance of a resource created in a separate transaction
        self::$repo->begin();
        $meta = (new Graph())->resource('.');
        $meta->addResource($idProp, $commonId);
        $meta->addLiteral($titleProp, 'sample title');
        $res2 = self::$repo->createResource($meta);
        $this->noteResources([$res2]);
        self::$repo->commit();

        // main transaction
        self::$repo->begin();
        $res2->delete(true, true, true);
        $res3 = self::$repo->createResource($meta);
        $this->noteResources([$res3]);
        $res3->delete(true, true, true);
        $res4 = self::$repo->createResource($meta);
        $this->noteResources([$res4]);

        // preparare files on a disk
        $meta->delete($titleProp);
        mkdir($this->tmpDir);
        file_put_contents($this->tmpDir . $fileName . '.ttl', $meta->getGraph()->serialise('turtle'));
        file_put_contents($this->tmpDir . $fileName, 'sample content');
        // index
        $this->ind->setPaths(['tmp']);
        $this->ind->setFilter('/^' . $fileName . '$/');
        $this->ind->setMetaLookup(new MetaLookupFile(['.'], '.ttl'));
        $indRes = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        // indexed resource should match manually created one
        $this->assertEquals(1, count($indRes));
        $this->assertEquals($res4->getUri(), array_pop($indRes)->getUri());
    }

    /**
     * @group indexer
     */
    public function testAutocommit(): void {
        self::$repo->begin();
        $this->ind->setFilter('/txt|xml/');
        $this->ind->setAutoCommit(2);
        $indRes = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();
        $this->assertEquals(7, count($indRes));
    }

    /**
     * @group indexer
     */
    public function testNewVersionCreation(): void {
        $pidProp = RC::get('epicPidProp');
        $pid     = 'https://sample.pid/' . rand();

        $indRes1 = $indRes2 = $indRes3 = [];
        $this->ind->setFilter('/^sample.xml$/', Indexer::MATCH);
        $this->ind->setFlatStructure(true);

        self::$repo->begin();
        $indRes1 = $this->ind->index();
        $this->noteResources($indRes1);
        $initRes = array_pop($indRes1);
        $meta    = $initRes->getMetadata();
        $meta->addResource($pidProp, $pid);
        $initRes->setMetadata($meta);
        $initRes->updateMetadata();
        self::$repo->commit();

        file_put_contents(__DIR__ . '/data/sample.xml', random_int(0, 123456));

        self::$repo->begin();
        $this->ind->setVersioning(Indexer::VERSIONING_DIGEST, Indexer::PID_PASS);
        $indRes2 = $this->ind->index();
        $this->noteResources($indRes2);
        self::$repo->commit();

        $this->assertEquals(1, count($indRes2));
        $newRes    = array_pop($indRes2);
        $meta      = $newRes->getMetadata();
        $this->assertEquals($pid, (string) $meta->getResource($pidProp)); // PID copied to the new resource
        $this->assertTrue(in_array($pid, $newRes->getIds())); // depends on PID being copied to id (which is NOT the default repository setup cause the repository doesn't know the PID concept)
        $prevResId = (string) $meta->getResource(RC::get('fedoraIsNewVersionProp'));
        $this->assertTrue(!empty($prevResId));
        $prevRes   = self::$repo->getResourceById($prevResId);
        $prevMeta  = $prevRes->getMetadata(true);
        $this->assertNull($prevMeta->getResource($pidProp)); // PID not present in the old resource
        $newResId  = (string) $prevMeta->getResource(RC::get('fedoraIsPrevVersionProp'));
        $this->assertTrue(!empty($newResId));
        $newRes2   = self::$repo->getResourceById($newResId);
        $this->assertEquals($newRes2->getUri(), $newRes->getUri());

        file_put_contents(__DIR__ . '/data/sample.xml', random_int(0, 123456));

        self::$repo->begin();
        $this->ind->setVersioning(Indexer::VERSIONING_DIGEST, Indexer::PID_KEEP);
        $indRes3 = $this->ind->index();
        $this->noteResources($indRes3);
        self::$repo->commit();

        $this->assertEquals(1, count($indRes3));
        $newestRes  = array_pop($indRes3);
        $newestMeta = $newestRes->getMetadata();
        $this->assertNull($newestMeta->getResource($pidProp));
        $newMeta    = $newRes->getMetadata(true);
        $this->assertEquals($pid, (string) $newMeta->getResource($pidProp));
        $this->assertTrue(in_array($pid, $newRes->getIds()));
    }

    /**
     * 
     * @large
     * @group largeIndexer
     */
    public function testRealWorldData(): void {
        $this->ind->setFilter('/.*/');
        $this->ind->setDepth(100);
        self::$repo->begin();
        $indRes = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();
        $this->assertEquals(77, count($indRes));
    }

    /**
     * 
     * @large
     */
    public function testBigFile(): void {
        $bufLen = 1024 * 1024;
        $buf    = str_repeat('a', $bufLen); // 1 MB
        $count  = 1024; // 1 GB

        mkdir($this->tmpDir);
        $f = fopen($this->tmpDir . '/test', 'wb');
        for ($i = 0; $i < $count; $i++) {
            fwrite($f, $buf);
        }
        fclose($f);
        unset($buf);

        $this->ind->setPaths(['tmp']);
        $this->ind->setUploadSizeLimit($count * $bufLen);
        self::$repo->begin();
        $indRes = $this->ind->index();
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(1, count($indRes));
        $this->assertEquals($count * $bufLen, (int) array_pop($indRes)->getMetadata()->getLiteral(RC::get('fedoraSizeProp'))->getValue());
    }

}
