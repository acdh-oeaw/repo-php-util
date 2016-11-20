<?php

/*
 * The MIT License
 *
 * Copyright 2016 zozlak.
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

require_once './vendor/autoload.php';

use acdhOeaw\redmine\Redmine;
use acdhOeaw\util\SparqlEndpoint;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\storage\Indexer;
use zozlak\util\ClassLoader;
use zozlak\util\Config;
use zozlak\util\ProgressBar;

$loader = new ClassLoader();

$conf = new Config('config.ini');

Redmine::init($conf);
SparqlEndpoint::init($conf->get('sparqlUrl'));
FedoraResource::init($conf);
Indexer::init($conf);

FedoraResource::begin();

echo "\nProjects:\n";
$projects = Redmine::fetchAllProjects(true);
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

echo "\nUsers:\n";
$users = Redmine::fetchAllUsers(true);
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

echo "\nIssues:\n";
$issues = Redmine::fetchAllIssues(true, ['tracker_id' => 5]);
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

FedoraResource::commit();

/*
FedoraResource::begin();

$res = new FedoraResource('http://fedora.localhost/rest/0c/c3/d0/ba/0cc3d0ba-2836-41d2-aa97-9c1d56907068'); // SELECT ?id WHERE {?uri <https://vocabs.acdh.oeaw.ac.at/#locationpath> "R_durmlemmatizer_4745"^^xsd:string . }
$ind = new Indexer($res);
$ind->index(1000, 2, false, true);

FedoraResource::commit();
*/
/*
$res = new FedoraResource('http://fedora.localhost/rest/0c/c3/d0/ba/0cc3d0ba-2836-41d2-aa97-9c1d56907068');
$ind = new Indexer($res);
print_r($ind->getMissingLocations());
*/
