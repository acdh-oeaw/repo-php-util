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

use EasyRdf\Graph;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\fedora\Fedora;
require_once 'init.php';
$fedora = new Fedora();

echo "\n-------------------------------------------------------------------\n";
echo "handles thumbstone resources\n";
$fedora->begin();
$uri = 'test' . rand();
try {
    $meta = (new Graph())->resource('.');
    $meta->addLiteral(RC::titleProp(), 'test parent');
    $meta->addResource(RC::idProp(), 'https://some.id/#' . rand());
    $res = $fedora->createResource($meta, '', $uri, 'PUT');
    $res->delete();
    try {
        $res = $fedora->createResource($meta, '', $uri, 'PUT');
        throw new Exception('no exception');
    } catch (Deleted $e) {
        
    }
} finally {
    $fedora->rollback();
}

echo "\n-------------------------------------------------------------------\n";
echo "keeps transaction alive\n";
$fedora->begin();
sleep(200);
$fedora->commit();
