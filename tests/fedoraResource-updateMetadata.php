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
 * Tests for \acdhOeaw\fedora\FedoraResource::updateMetadata($mode)
 */

use EasyRdf\Graph;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\fedora\Fedora;
require_once 'init.php';
$fedora = new Fedora();

$p1 = 'http://some.property/#p1';
$p2 = 'http://some.property/#p2';

try {
    $fedora->begin();
    
    $meta = (new Graph())->resource('.');
    $meta->addLiteral(RC::titleProp(), 'sample title');
    $meta->addResource(RC::idProp(), 'https://some.random/id/' . rand());
    $meta->addLiteral($p1, 'v1');
    $meta->addLiteral($p2, 'v2');
    $res  = $fedora->createResource($meta);

    // ADD
    echo "\n-------------------------------------------------------------------\n";
    echo "fedoraResource::updateMetadata(ADD)\n";
    $meta->delete($p1);
    $meta->delete($p2);
    $meta->addLiteral($p2, 'v22');
    $res->setMetadata($meta);
    $res->updateMetadata('ADD');
    $meta2 = $res->getMetadata(true);
    
    $t1 = count($meta2->allLiterals($p1));
    $t2 = count($meta2->allLiterals($p2));
    $t3 = $meta2->getLiteral($p1);
    if ($t1 != 1 || $t2 != 2 || $t3 != 'v1') {
        echo $res->__metaToString();
        throw new Exception("OVERWRITE failed $t1 $t2 '$t3'");
    }

    // UPDATE
    echo "\n-------------------------------------------------------------------\n";
    echo "fedoraResource::updateMetadata(UPDATE)\n";
    $meta->delete($p2);
    $meta->addLiteral($p2, 'v222');
    $res->setMetadata($meta);
    $res->updateMetadata('UPDATE');
    $meta2 = $res->getMetadata(true);

    $t1 = count($meta2->allLiterals($p1));
    $t2 = count($meta2->allLiterals($p2));
    $t3 = $meta2->getLiteral($p1);
    $meta2 = $t4 = $meta2->getLiteral($p2);
    if ($t1 != 1 || $t2 != 1 || $t3 != 'v1' || $t4 != 'v222') {
        echo $res->__metaToString();
        throw new Exception("UPDATE failed $t1 $t2 '$t3' '$t4'");
    }
    
    // OVERWRITE
    echo "\n-------------------------------------------------------------------\n";
    echo "fedoraResource::updateMetadata(OVERWRITE)\n";
    $meta->delete($p2);
    $meta->addLiteral($p2, 'v2222');
    $res->setMetadata($meta);
    $res->updateMetadata('OVERWRITE');
    $meta2 = $res->getMetadata(true);
    
    $t1 = count($meta2->allLiterals($p1));
    $t2 = count($meta2->allLiterals($p2));
    $t4 = $meta2->getLiteral($p2);
    if ($t1 != 0 || $t2 != 1 || $t4 != 'v2222') {
        echo $res->__metaToString();
        throw new Exception("OVERWRITE failed $t1 $t2 '$t4'");
    }
} finally {
    $fedora->rollback();
}