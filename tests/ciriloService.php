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

use acdhOeaw\cirilo\Service;

require_once 'init.php';

$fedora->begin();
$services = Service::fromSdepFile('/home/zozlak/roboty/ACDH/repo/userstories/reuse_cirilo_services_7991/models/sdep_tei.xml');
foreach ($services as $i) {
    $i->updateRms();
}
$fedora->commit();

$fedora->begin();
$res = $fedora->getResourceByUri('http://fedora.localhost/rest/ontology/class/f4/33/54/f1/f43354f1-db26-439f-b8fd-aee4e873470e');
$meta = $res->getMetadata();
$meta->addResource('https://vocabs.acdh.oeaw.ac.at/#hasSTYLESHEET', 'https://id.acdh.oeaw.ac.at/7c1c6b18-34e6-e36f-c51c-c841940fc803');
$res->setMetadata($meta);
$res->updateMetadata();
$fedora->commit();
