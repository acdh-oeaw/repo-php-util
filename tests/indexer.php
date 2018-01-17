<?php

/*
 * The MIT License
 *
 * Copyright 2017 zozlak.
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

use EasyRdf\Graph;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\util\Indexer;
use acdhOeaw\util\metaLookup\MetaLookupFile;
use acdhOeaw\util\metaLookup\MetaLookupGraph;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\fedora\Fedora;

require_once 'init.php';
$fedora = new Fedora();

acdhOeaw\schema\Object::$debug       = true;
acdhOeaw\fedora\Fedora::$debug = true;
MetaLookupFile::$debug               = true;
MetaLookupGraph::$debug              = true;
Indexer::$debug = true;

$fedora->begin();
$id = 'http://my.test/id';
try {
    $res = $fedora->getResourceById($id);
} catch (NotFound $ex) {
    $meta = (new Graph())->resource('.');
    $meta->addLiteral(RC::titleProp(), 'test parent');
    $meta->addLiteral(RC::locProp(), 'data');
    $meta->addResource(RC::idProp(), $id);
    $res  = $fedora->createResource($meta, '', '/test', 'PUT');
}
$fedora->commit();
$res = $fedora->getResourceByUri($res->getUri(true));

echo "\n-------------------------------------------------------------------\n";
echo "simple indexing into a given location\n";
try {
    $fedora->begin();
    $ind    = new Indexer($res);
    $ind->setUploadSizeLimit(10000000);
    $ind->setFilter('/txt|xml/');
    $ind->setFedoraLocation('/test/');
    $indRes = $ind->index();

    assert(count($indRes) === 5, new Exception("resources count doesn't match " . count($indRes)));
    foreach ($indRes as $i) {
        assert(preg_match('|/rest/test/|', $i->getUri(true)), new Exception('Resource created at wrong location: ' . $i->getUri(true)));
    }
    $fedora->commit();
} finally {
    $fedora->rollback();
}

echo "\n-------------------------------------------------------------------\n";
echo "simple reindexing\n";
try {
    $fedora->begin();
    $indRes = $ind->index();
    assert(count($indRes) === 5, new Exception("resources count doesn't match " . count($indRes)));
    foreach ($indRes as $i) {
        assert(preg_match('|/rest/test/|', $i->getUri(true)), new Exception('Resource created at wrong location: ' . $i->getUri(true)));
    }
} finally {
    foreach ($indRes as $i) {
        $i->delete(true, false, true);
    }
    $fedora->commit();
}

echo "\n-------------------------------------------------------------------\n";
echo "automatic metadata fetching from file\n";
try {
    $fedora->__clearCache();
    $fedora->begin();
    $metaLookup = new MetaLookupFile(array('.'), '.ttl');
    $fedora->begin();
    $ind        = new Indexer($res);
    $ind->setDepth(0);
    $ind->setPaths(array('data'));
    $ind->setMetaLookup($metaLookup);
    $ind->setFilter('/sample.xml$/');
    $indRes     = $ind->index();
    assert(count($indRes) === 1, new Exception("wrong indexed resources count " . count($indRes)));
    $indRes     = array_pop($indRes);
    $meta       = $indRes->getMetadata();
    if ($meta->getLiteral('https://some.sample/property') != 'sample value') {
        echo $indRes->__metaToString();
        throw new Exception('wrong metadata "' . $meta->getLiteral('https://some.sample/property') . '"');
    }
    $indRes->delete(true, true, true);
    $fedora->commit();
} finally {
    $fedora->rollback();
}

echo "\n-------------------------------------------------------------------\n";
echo "automatic metadata fetching from graph\n";
try {
    $fedora->__clearCache();
    $fedora->begin();
    $metaLookup = new MetaLookupFile(array('.'), '.ttl');
    $fedora->begin();
    $ind        = new Indexer($res);
    $ind->setDepth(0);
    $ind->setPaths(array('data'));
    $ind->setMetaLookup($metaLookup);
    $ind->setFilter('/sample.xml$/');
    $indRes     = $ind->index();
    assert(count($indRes) === 1, new Exception("wrong indexed resources count " . count($indRes)));
    $indRes     = array_pop($indRes);
    $meta       = $indRes->getMetadata();
    if ($meta->getLiteral('https://some.sample/property') != 'sample value') {
        echo $indRes->__metaToString();
        throw new Exception('wrong metadata "' . $meta->getLiteral('https://some.sample/property') . '"');
    }
    $indRes->delete(true, true, true);
    $fedora->commit();
} finally {
    $fedora->rollback();
}

echo "\n-------------------------------------------------------------------\n";
echo "skipping resources without external metadata from file\n";
try {
    $fedora->__clearCache();
    $fedora->begin();
    $metaLookup = new MetaLookupFile(array('.'), '.ttl');
    $fedora->begin();
    $ind        = new Indexer($res);
    $ind->setDepth(0);
    $ind->setPaths(array('data'));
    $ind->setMetaLookup($metaLookup, true);
    $ind->setFilter('/xml$/');
    $indRes     = $ind->index();
    assert(count($indRes) === 1, new Exception("wrong indexed resources count " . count($indRes)));
    $indRes     = array_pop($indRes);
    $meta       = $indRes->getMetadata();
    if ($meta->getLiteral('https://some.sample/property') != 'sample value') {
        echo $indRes->__metaToString();
        throw new Exception('wrong metadata "' . $meta->getLiteral('https://some.sample/property') . '"');
    }
    $indRes->delete(true, true, true);
    $fedora->commit();
} finally {
    $fedora->rollback();
}

echo "\n-------------------------------------------------------------------\n";
echo "skipping resources without external metadata from graph\n";
try {
    $fedora->__clearCache();
    $fedora->begin();
    $metaLookup = new MetaLookupFile(array('.'), '.ttl');
    $fedora->begin();
    $ind        = new Indexer($res);
    $ind->setDepth(0);
    $ind->setPaths(array('data'));
    $ind->setMetaLookup($metaLookup, true);
    $ind->setFilter('/sample.xml$/');
    $indRes     = $ind->index();
    assert(count($indRes) === 1, new Exception("wrong indexed resources count " . count($indRes)));
    $indRes     = array_pop($indRes);
    $meta       = $indRes->getMetadata();
    if ($meta->getLiteral('https://some.sample/property') != 'sample value') {
        echo $indRes->__metaToString();
        throw new Exception('wrong metadata "' . $meta->getLiteral('https://some.sample/property') . '"');
    }
    $indRes->delete(true, true, true);
    $fedora->commit();
} finally {
    $fedora->rollback();
}

echo "\n-------------------------------------------------------------------\n";
echo "it is possible to merge resource on externally provided metadata\n";
$commonId = 'https://my.id.nmsp/' . rand();
$fileName = rand();
try {
    $fedora->__clearCache();
    $fedora->begin();
    $meta = (new Graph())->resource('.');
    $meta->addResource(RC::idProp(), $commonId);
    $meta->addLiteral(RC::titleProp(), 'sample title');
    $res2 = $fedora->createResource($meta);
    $meta->delete(RC::titleProp());
    if (file_exists('tests/tmp')) {
        system('rm -fr tests/tmp');
    }
    mkdir('tests/tmp');
    file_put_contents('tests/tmp/' . $fileName . '.ttl', $meta->getGraph()->serialise('turtle'));
    file_put_contents('tests/tmp/' . $fileName, 'sample content');
    $ind    = new Indexer($res);
    $ind->setPaths(array('tmp'));
    $ind->setFilter('/^' . $fileName . '$/');
    $ind->setMetaLookup(new MetaLookupFile(array('.'), '.ttl'));
    $indRes = $ind->index();
    assert(count($indRes) === 1, new Exception("resource wasn't indexed"));
    $indRes = array_pop($indRes);
    if ($indRes->getUri(true) !== $res2->getUri(true)) {
        $fedora->rollback();
        throw new Exception("URIs don't match");
    }
    $fedora->commit();
} finally {
    $fedora->rollback();
}

echo "\n-------------------------------------------------------------------\n";
echo "it is possible to merge resource on externally provided metadata and deleted resources are resolved correctly\n";
try {
    $fedora->__clearCache();
    $commonId = 'https://my.id.nmsp/' . rand();
    $fileName = rand();
    //first instance of a resource created in a separate transaction
    $fedora->begin();
    $meta     = (new Graph())->resource('.');
    $meta->addResource(RC::idProp(), $commonId);
    $meta->addLiteral(RC::titleProp(), 'sample title');
    $res2     = $fedora->createResource($meta);
    $fedora->commit();
    // main transaction
    $fedora->begin();
    $res2->delete();
    $res3     = $fedora->createResource($meta);
    $res3->delete();
    $res4     = $fedora->createResource($meta);
    // preparare files on a disk
    $meta->delete(RC::titleProp());
    if (file_exists('tests/tmp')) {
        system('rm -fr tests/tmp');
    }
    mkdir('tests/tmp');
    file_put_contents('tests/tmp/' . $fileName . '.ttl', $meta->getGraph()->serialise('turtle'));
    file_put_contents('tests/tmp/' . $fileName, 'sample content');
    // index
    $ind    = new Indexer($res);
    $ind->setPaths(array('tmp'));
    $ind->setFilter('/^' . $fileName . '$/');
    $ind->setMetaLookup(new MetaLookupFile(array('.'), '.ttl'));
    $indRes = $ind->index();
    // indexed resource should match manually created one
    assert(count($indRes) === 1, new Exception("resource wasn't indexed"));
    $indRes = array_pop($indRes);
    if ($indRes->getUri(true) !== $res4->getUri(true)) {
        $fedora->rollback();
        throw new Exception("URIs don't match");
    }
    $fedora->commit();
} finally {
    $fedora->rollback();
}


echo "\n-------------------------------------------------------------------\n";
echo "autocommit works\n";
try {
    $indRes = array();
    $fedora->begin();
    $ind    = new Indexer($res);
    $ind->setUploadSizeLimit(10000000);
    $ind->setFilter('/txt|xml/');
    $ind->setFedoraLocation('/test/');
    $ind->setAutoCommit(2);
    $indRes = $ind->index();
    assert(count($indRes) === 5, new Exception("resources count doesn't match " . count($indRes)));
    $fedora->commit();
} finally {
    $fedora->rollback();
    $fedora->begin();
    foreach ($indRes as $res) {
        $res->delete(true, false, true);
    }
    $fedora->commit();
}
