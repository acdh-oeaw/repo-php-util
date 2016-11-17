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
use acdhOeaw\rms\SparqlEndpoint;
use acdhOeaw\rms\Fedora;
use zozlak\util\ClassLoader;
use zozlak\util\Config;

$loader = new ClassLoader();

$conf = new Config('config.ini');

Redmine::init($conf->get('mappingsFile'), $conf->get('redmineUrl'), $conf->get('redmineKey'));
SparqlEndpoint::init($conf->get('sparqlUrl'));
Fedora::init($conf->get('fedoraUrl'), $conf->get('fedoraUser'), $conf->get('fedoraPswd'));
Fedora::begin();

echo "\nProjects:\n";
$projects = Redmine::fetchAllProjects();
echo "\tsaving: ";
foreach ($projects as $i) {
    echo '#';
    try {
        $i->updateRms();
    } catch (Exception $ex) {
        
    }
}

echo "\nUsers:\n";
$users = Redmine::fetchAllUsers();
echo "\tsaving: ";
foreach ($users as $i) {
    echo '#';
    try {
        $i->updateRms();
    } catch (Exception $e) {
        
    }
}

echo "\nIssues:\n";
$issues = Redmine::fetchAllIssues(['tracker_id' => 5]);
echo "\tsaving: ";
//file_put_contents('data', serialize($issues));
//$issues = unserialize(file_get_contents('data'));
foreach ($issues as $i) {
    echo '#';
    try {
        $i->updateRms();
    } catch (Exception $e) {
        
    }
}
Fedora::commit();
