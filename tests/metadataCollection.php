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

require_once 'init.php';

use acdhOeaw\schema\MetadataCollection;
use EasyRdf\Resource;

$verbose = true;

echo "\n######################################################\n\n";

$graph = new MetadataCollection($conf, $fedora, 'tests/graph-small.ttl');
$toDel = new Resource('https://id.acdh.oeaw.ac.at/tunico/someId', $graph);
$res   = $graph->resourcesMatching('http://purl.org/dc/terms/isPartOf', $toDel)[0];
$res->delete('http://purl.org/dc/terms/isPartOf', $toDel);

$fedora->begin();
$resources = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP, true, $verbose);
$fedora->commit();

echo "\n######################################################\n\n";
sleep(5);

$graph = new MetadataCollection($conf, $fedora, 'tests/graph-small.ttl');

$fedora->begin();
$resources = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP, true, $verbose);
$fedora->commit();

echo "\n######################################################\n\n";
sleep(5);

$graph = new MetadataCollection($conf, $fedora, 'tests/graph-large.ttl');

$fedora->begin();
$resources = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP, true, $verbose);
$fedora->commit();

echo "\n######################################################\n\n";
sleep(5);

$graph = new MetadataCollection($conf, $fedora, 'tests/graph-cycle.ttl');

$fedora->begin();
try {
    $resources = $graph->import('https://id.acdh.oeaw.ac.at/', MetadataCollection::SKIP, true, $verbose);
    throw new RuntimeException('no error');
} catch (RuntimeException $e) {
    
}
$fedora->rollback();
