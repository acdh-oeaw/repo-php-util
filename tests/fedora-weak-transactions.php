<?php

/*
 * The MIT License
 *
 * Copyright 2017 Austrian Centre for Digital Humanities.
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

/*
 * Demonstrates how weak Fedora transactions are:
 * - they only assure your changes will be applied to the repository at the
 *   same time (at the transaction commit)
 * - but state of the resources can be changed during your transaction by any
 *   other client, even causing your transaction to fail
 */

use EasyRdf\Graph;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\fedora\Fedora;
require_once 'init.php';

$p1 = 'http://some.property/#p1';
$id = 'https://some.random/id/' . rand();

// create a sample resource
$fedora = new Fedora();
$fedora->begin();
$meta = (new Graph())->resource('.');
$meta->addLiteral(RC::titleProp(), 'sample title');
$meta->addResource(RC::idProp(), $id);
$res = $fedora->createResource($meta);
$fedora->commit();

// establish two connections in parallel and get the sample resource
$fedora1 = new Fedora();
$fedora2 = new Fedora();
$fedora1->begin();
$fedora2->begin();
$res1 = $fedora1->getResourceByUri($res->getUri(true));
$res2 = $fedora2->getResourceByUri($res->getUri(true));
// update its metadata in the first connection
$meta1 = $res1->getMetadata();
$meta1->addLiteral($p1, 'v1');
$res1->setMetadata($meta1);
$res1->updateMetadata();
// delete it in the second connection
$res2->delete();
// try to commit
$fedora2->commit();
$fedora1->commit(); // fails - resource was deleted by $fedora2 connection which was commited just before 
                    // (but would work if $fedora1 was commited before $fedora2)
