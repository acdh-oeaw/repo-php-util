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

use zozlak\util\ProgressBar;
use acdhOeaw\redmine\Issue;
use acdhOeaw\redmine\Project;
use acdhOeaw\redmine\User;

require_once 'init.php';

$fedora->begin();
$meta = (new EasyRdf\Graph())->resource('.');
$meta->addLiteral('http://purl.org/dc/elements/1.1/title', 'sample CMDI record');
$res = $fedora->createResource($meta, '/home/zozlak/Pulpit/tmp.xml');
$id = $res->getId();
$res = $fedora->getResourceByUri('https://fedora.localhost/rest/32/53/df/e2/3253dfe2-01e3-4386-be1e-70760b3b4c4f');
$meta = $res->getMetadata();
$meta->addResource('https://vocabs.acdh.ac.at/#hasCMDIcollection', $id);
$res->setMetadata($meta);
$res->updateMetadata();
$fedora->commit();
exit();

$fedora->begin();

echo "\nUsers:\n";
$users = User::fetchAll(true);
echo "\n\tsaving:\n";
$pb = new ProgressBar(count($users), 5);
foreach ($users as $i) {
    $pb->next();
    try {
        $i->updateRms();
    } catch (Exception $e) {
        
    }
}
$pb->finish();

echo "\nProjects:\n";
$projects = Project::fetchAll(true);
echo "\n\tsaving:\n";
$pb = new ProgressBar(count($projects), 5);
foreach ($projects as $i) {
    $pb->next();
    try {
        $i->updateRms();
    } catch (Exception $ex) {
        
    }
}
$pb->finish();

echo "\nIssues:\n";
$issues = Issue::fetchAll(true, ['tracker_id' => 5]);
echo "\n\tsaving:\n";
$pb = new ProgressBar(count($issues), 5);
foreach ($issues as $i) {
    $pb->next();
    try {
        $i->updateRms();
    } catch (Exception $e) {
        
    }
}
$pb->finish();

$fedora->commit();
