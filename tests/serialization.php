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
use acdhOeaw\util\EasyRdfUtil;

require_once 'init.php';

$graph = new Graph();
$resource = $graph->resource('https://my.domain/my/uri');
$resource->addLiteral('https://my.domain/my/prop', "\\zażółć\n\"kota'");
echo $graph->serialise('ntriples');
echo EasyRdfUtil::serialiseResource($resource);

$fedora->begin();
$meta = (new EasyRdf_Graph())->resource('.');
$meta->addLiteral('aaa', 'ala \ ma " kota \' żółć');
$res = $fedora->createResource($meta);
echo $res->getUri();
$fedora->commit();

$fedora->begin();
$graph = new EasyRdf_Graph();
$meta = $graph->resource('.');
$meta->addLiteral('http://my.prop/#erty', 'myValue');
$meta->addLiteral('http://www.ebu.ch/metadata/ontologies/ebucore/ebucore#filename', 'sampleName.html');
$res = $fedora->createResource($meta, 'https://zozlak.org', 'test31', 'PUT');
print_r($res->__getSparqlTriples());
$fedora->rollback();
