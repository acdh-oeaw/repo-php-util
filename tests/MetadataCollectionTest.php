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

use acdhOeaw\util\MetadataCollection;

/**
 * Description of MetadataCollectionTest
 *
 * @author zozlak
 */
class MetadataCollectionTest extends TestBase {

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        //MetadataCollection::$debug = true;
    }

    /**
     * @group metadataCollection
     */
    public function testSimple(): void {
        $graph  = new MetadataCollection(self::$repo, __DIR__ . '/data/graph-small.ttl');
        self::$repo->begin();
        $indRes = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP);
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(4, count($indRes));
    }

    /**
     * @group metadataCollection
     */
    public function testSimpleDouble(): void {
        $graph   = new MetadataCollection(self::$repo, __DIR__ . '/data/graph-small.ttl');
        self::$repo->begin();
        $indRes1 = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP);
        $this->noteResources($indRes1);
        self::$repo->commit();

        $this->assertEquals(4, count($indRes1));

        $graph   = new MetadataCollection(self::$repo, __DIR__ . '/data/graph-small.ttl');
        self::$repo->begin();
        $indRes2 = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP);
        $this->noteResources($indRes2);
        self::$repo->commit();

        $this->assertEquals(4, count($indRes2));
        $r1u = $r2u = [];
        foreach ($indRes1 as $i) {
            $r1u[] = $i->getUri();
        }
        foreach ($indRes2 as $i) {
            $r2u[] = $i->getUri();
        }
        $this->assertEquals(count($r1u), count($r2u));
        $this->assertEquals([], array_diff($r2u, $r1u));
    }

    /**
     * @group metadataCollection
     */
    public function testLarge(): void {
        $graph  = new MetadataCollection(self::$repo, __DIR__ . '/data/graph-large.ttl');
        self::$repo->begin();
        $indRes = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP);
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(19, count($indRes));
    }

    /**
     * @group metadataCollection
     */
    public function testCycle(): void {
        $graph  = new MetadataCollection(self::$repo, __DIR__ . '/data/graph-cycle.ttl');
        self::$repo->begin();
        $indRes = $graph->import('http://some.id', MetadataCollection::SKIP);
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(2, count($indRes));
    }

    /**
     * @large
     * @group largeMetadataCollection
     */
    public function testBig(): void {
        $graph           = new MetadataCollection(self::$repo, __DIR__ . '/data/schnitzler-diaries.rdf');
        self::$repo->begin();
        $indRes = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP);
        $this->noteResources($indRes);
        self::$repo->commit();

        $this->assertEquals(75, count($indRes));
    }

}
