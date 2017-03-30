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
use acdhOeaw\storage\Indexer;
use acdhOeaw\schema\MetadataCollection;

require_once 'init.php';

$graph = new MetadataCollection($fedora, '/home/zozlak/roboty/ACDH/repo/userstories/troesmis_6665/metadata/actors/O06_Ersteller_google-csv_openrefine.ttl');
$graph->import();
exit();

$fedora->begin();

$id = $conf->get('fedoraIdNamespace') . 'test1';
try{
    $res = $fedora->getResourceById($id);
} catch (Exception $ex) {
    $meta = (new Graph())->resource('.');
    $meta->addLiteral($conf->get('fedoraTitleProp'), 'test parent');
    $meta->addLiteral($conf->get('fedoraLocProp'), '/some/path');
    $meta->addResource($conf->get('fedoraIdProp'), $id);
    $res = $fedora->createResource($meta);
}
$ind = new Indexer($res);
//$ind->setFilter('|[.]wav$|i');
$ind->setPaths(array(''));
$ind->setUploadSizeLimit(10000000);
$ind->setDepth(10);
$ind->setFlatStructure(false);
$indRes = $ind->index(true);
foreach ($indRes as $i) {
    echo $i->getUri() . "\n";
}
$fedora->commit();