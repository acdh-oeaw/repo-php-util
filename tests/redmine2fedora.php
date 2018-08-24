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
use acdhOeaw\schema\redmine\Issue;
use acdhOeaw\schema\redmine\Project;
use acdhOeaw\schema\redmine\User;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\util\RepoConfig as RC;
use zozlak\util\ProgressBar;
require_once 'init.php';
$fedora = new Fedora();
//\acdhOeaw\schema\Object::$debug = true;

$fedora->begin();

// create a collection for users
try {
    $fedora->getResourceByUri('/agent')->getMetadata();
} catch (NotFound $e) {
    $meta = (new Graph())->resource('.');
    $meta->addLiteral(RC::titleProp(), 'Collection of users, groups and institutions');
    $meta->addResource(RC::idProp(), 'http://id.acdh.oeaw.ac.at/agentsCollection');
    $fedora->createResource($meta, '', '/agent', 'PUT');
}

echo "\nUsers:\n";
$users = User::fetchAll($fedora, true);
echo "\n\tsaving:\n";
$pb = new ProgressBar(count($users), 5);
foreach ($users as $i) {
    $pb->next();
    try {
        $i->updateRms();
    } catch (Exception $e) {
        $fedora->rollback();
        throw $e;
    }
}
$pb->finish();

echo "\nProjects:\n";
$projects = Project::fetchAll($fedora, true);
echo "\n\tsaving:\n";
$pb = new ProgressBar(count($projects), 5);
foreach ($projects as $i) {
    $pb->next();
    try {
        $i->updateRms();
    } catch (Exception $e) {
        $fedora->rollback();
        throw $e;
    }
}
$pb->finish();

echo "\nIssues:\n";
$issues = Issue::fetchAll($fedora, true, ['tracker_id' => 5]);
echo "\n\tsaving:\n";
$pb = new ProgressBar(count($issues), 5);
foreach ($issues as $i) {
    $pb->next();
    try {
        $i->updateRms();
    } catch (Exception $e) {
        $fedora->rollback();
        throw $e;
    }
}
$pb->finish();

$fedora->commit();
