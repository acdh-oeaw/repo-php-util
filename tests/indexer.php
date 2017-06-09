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
use acdhOeaw\util\Indexer;
use acdhOeaw\util\metaLookup\MetaLookupFile;
use acdhOeaw\util\RepoConfig as RC;

require_once 'init.php';

$fedora->begin();
$id = 'http://my.test/id';
try{
    $res = $fedora->getResourceById($id);
} catch (Exception $ex) {
    $meta = (new Graph())->resource('.');
    $meta->addLiteral(RC::titleProp(), 'test parent');
    $meta->addLiteral(RC::locProp(), 'aaa');
    $meta->addResource(RC::idProp(), $id);
    $res = $fedora->createResource($meta);
}
$fedora->commit();
$res = $fedora->getResourceByUri($res->getUri(true));

echo "simple indexing\n";
$fedora->begin();

$ind = new Indexer($res);
$ind->setUploadSizeLimit(10000000);
$ind->setDepth(10);
$ind->setFlatStructure(false);
$indRes = $ind->index(true);
foreach ($indRes as $i) {
    echo $i->getUri(true) . "\n";
}
$fedora->commit();

echo "\n-----\n";

echo "automatic metadata fetching\n";
MetaLookupFile::$debug = true;
$metaLookup = new MetaLookupFile(array('.'), '.ttl');
$fedora->begin();
$ind = new Indexer($res);
$ind->setPaths(array('/'));
$ind->setMetaLookup($metaLookup);
$ind->setFilter('/xml$/');
$indRes = $ind->index(true);
foreach ($indRes as $i) {
    echo $i->getUri(true) . "\n";
}
$fedora->commit();
