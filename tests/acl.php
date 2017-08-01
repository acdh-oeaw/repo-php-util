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
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\acl\WebAcl;
use acdhOeaw\fedora\acl\WebAclRule as AR;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;

require_once 'init.php';
$fedora        = new Fedora();
$idBase        = 'https://id.acdh.oeaw.ac.at/';
$testClass1    = 'http://my.acl/test#class1';
$testClass2    = 'http://my.acl/test#class2';

// Prepare test resources
$fedora->begin();
$resources = array('c1', 'c1/c2', 'c1/r1', 'c1/r2', 'c1/c2/r1', 'c1/c2/r2');
$resources = array_combine($resources, $resources);
$n = 0;
foreach (array_keys($resources) as $i) {
    try {
        $resources[$i] = $fedora->getResourceById($idBase . $i);
    } catch (NotFound $e) {
        $meta = (new Graph())->resource('.');
        $meta->addResource(RC::idProp(), $idBase . $i);
        $meta->addLiteral(RC::titleProp(), $i);
        if (preg_match('|c1/c2/|', $i)) {
            $meta->addResource(RC::relProp(), $resources['c1/c2']->getId());
            $meta->addType($testClass2);
        } else if (preg_match('|c1/|', $i)) {
            $meta->addResource(RC::relProp(), $resources['c1']->getId());
            $meta->addType($testClass1);
        }
        $resources[$i] = $fedora->createResource($meta, '', $i, 'PUT');
        $n++;
    }
}
$fedora->commit();
if ($n) {
    sleep(2);
}


echo "\n-------------------------------------------------------------------\n";
echo "grants read access to public\n";
$res = $fedora->getResourceById($idBase . 'c1/r1');
try {
    $fedora->begin();
    $acl = $res->getAcl();

    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER) && AR::NONE === $acl->getMode(AR::USER, 'user1') && AR::NONE === $acl->getMode(AR::GROUP, 'group1'));
    $acl->grant(AR::USER, AR::PUBLIC_USER, AR::READ);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::READ === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));

    $fedora->commit();
    sleep(1);

    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::READ === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));
} catch (Throwable $e) {
    $fedora->rollback();
    throw $e;
} finally {
    $fedora->begin();
    $acl->reload()->revokeAll();
    $fedora->commit();
}


echo "\n-------------------------------------------------------------------\n";
echo "grants different rights to different users/groups\n";
$res = $fedora->getResourceById($idBase . 'c1/r2');
try {
    $fedora->begin();
    $acl = $res->getAcl();

    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER) && AR::NONE === $acl->getMode(AR::USER, 'user1') && AR::NONE === $acl->getMode(AR::GROUP, 'group1'));

    $acl->grant(AR::USER, 'user1', AR::WRITE);
    $acl->grant(AR::GROUP, 'group1', AR::READ);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::WRITE === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));

    $fedora->commit();
    sleep(1);
    $acl = $res->getAcl();
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::WRITE === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));
} catch (Throwable $e) {
    $fedora->rollback();
    throw $e;
} finally {
    $fedora->begin();
    $acl->reload()->revokeAll();
    $fedora->commit();
}


echo "\n-------------------------------------------------------------------\n";
echo "manual save mode works\n";
$res = $fedora->getResourceById($idBase . 'c1/r1');
try {
    $fedora->begin();
    $acl = $res->getAcl();
    WebAcl::setAutosave(false);

    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));

    $acl->grant(AR::USER, AR::PUBLIC_USER, AR::READ);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));

    $fedora->commit();
    sleep(1);

    $res = $fedora->getResourceById($idBase . 'c1/r1');
    $acl->reload();
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
} catch (Throwable $e) {
    $fedora->rollback();
    throw $e;
} finally {
    $fedora->begin();
    $acl->reload()->revokeAll();
    $fedora->commit();
    WebAcl::setAutosave(true);
}


echo "\n-------------------------------------------------------------------\n";
echo "revoke works\n";
$res = $fedora->getResourceById($idBase . 'c1/r1');
try {
    $fedora->begin();
    $acl = $res->getAcl();
    $acl->grant(AR::USER, 'user1', AR::READ);
    $acl->grant(AR::GROUP, 'group1', AR::WRITE);
    $fedora->commit();sleep(1);$fedora->begin();
    $acl->reload();
    assert(AR::READ === $acl->getMode(AR::USER, 'user1'));
    assert(AR::WRITE === $acl->getMode(AR::GROUP, 'group1'));
    
    $acl->revoke(AR::USER, 'user1', AR::READ);
    assert(AR::NONE === $acl->getMode(AR::USER, 'user1'));
    $acl->revoke(AR::GROUP, 'group1', AR::WRITE);
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));
    
    $fedora->commit();sleep(1);
    $acl->reload();
    assert(AR::NONE === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));    
} catch (Throwable $e) {
    $fedora->rollback();
    throw $e;
} finally {
    $fedora->begin();
    $acl->reload()->revokeAll();
    $fedora->commit();
}


echo "\n-------------------------------------------------------------------\n";
echo "recursive grant/revoke works\n";
$res = array(
    'c1'       => $fedora->getResourceById($idBase . 'c1'),
    'c1/r1'    => $fedora->getResourceById($idBase . 'c1/r1'),
    'c1/r2'    => $fedora->getResourceById($idBase . 'c1/r2'),
    'c1/c2'    => $fedora->getResourceById($idBase . 'c1/c2'),
    'c1/c2/r1' => $fedora->getResourceById($idBase . 'c1/c2/r1'),
    'c1/c2/r2' => $fedora->getResourceById($idBase . 'c1/c2/r2')
);
try {
    $fedora->begin();
    $acl = array();
    foreach ($res as $k => $v) {
        $acl[$k] = $v->getAcl();
    }
    foreach ($acl as $i) {
        assert(AR::NONE === $i->getMode(AR::USER, 'user1') && AR::NONE === $i->getMode(AR::GROUP, 'group1') && AR::NONE === $i->getMode(AR::USER, AR::PUBLIC_USER));
    }

    // grant rights
    $acl['c1']->grant(AR::USER, 'user1', AR::READ, 1);
    $acl['c1']->grant(AR::GROUP, 'group1', AR::WRITE, 10);
    foreach (array('c1', 'c1/r1', 'c1/r2', 'c1/c2') as $i) {
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, AR::PUBLIC_USER));
        assert(AR::READ === $acl[$i]->getMode(AR::USER, 'user1'));
        assert(AR::WRITE === $acl[$i]->getMode(AR::GROUP, 'group1'));
    }
    foreach (array('c1/c2/r1', 'c1/c2/r2') as $i) {
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, AR::PUBLIC_USER));
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, 'user1'));
        assert(AR::WRITE === $acl[$i]->getMode(AR::GROUP, 'group1'));
    }

    // check if they were properly populated to the Fedora
    $fedora->commit(); sleep(2);   
    foreach ($acl as $i) {
        $i->reload();
    }
    foreach (array('c1', 'c1/r1', 'c1/r2', 'c1/c2') as $i) {
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, AR::PUBLIC_USER));
        assert(AR::READ === $acl[$i]->getMode(AR::USER, 'user1'));
        assert(AR::WRITE === $acl[$i]->getMode(AR::GROUP, 'group1'));
    }
    foreach (array('c1/c2/r1', 'c1/c2/r2') as $i) {
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, AR::PUBLIC_USER));
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, 'user1'));
        assert(AR::WRITE === $acl[$i]->getMode(AR::GROUP, 'group1'));
    }

    // revoke
    $fedora->begin();
    $acl['c1']->revoke(AR::GROUP, 'group1', AR::WRITE, 1);
    foreach (array('c1', 'c1/r1', 'c1/r2', 'c1/c2') as $i) {
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, AR::PUBLIC_USER));
        assert(AR::READ === $acl[$i]->getMode(AR::USER, 'user1'));
        assert(AR::READ === $acl[$i]->getMode(AR::GROUP, 'group1'));
    }
    foreach (array('c1/c2/r1', 'c1/c2/r2') as $i) {
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, AR::PUBLIC_USER));
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, 'user1'));
        assert(AR::WRITE === $acl[$i]->getMode(AR::GROUP, 'group1'));
    }

    // check it they were properly populated to the Fedora
    $fedora->commit();sleep(2);
    $fedora->begin();
    foreach ($acl as $i) {
        $i->reload();
    }
    foreach (array('c1', 'c1/r1', 'c1/r2', 'c1/c2') as $i) {
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, AR::PUBLIC_USER));
        assert(AR::READ === $acl[$i]->getMode(AR::USER, 'user1'));
        assert(AR::READ === $acl[$i]->getMode(AR::GROUP, 'group1'));
    }
    foreach (array('c1/c2/r1', 'c1/c2/r2') as $i) {
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, AR::PUBLIC_USER));
        assert(AR::NONE === $acl[$i]->getMode(AR::USER, 'user1'));
        assert(AR::WRITE === $acl[$i]->getMode(AR::GROUP, 'group1'));
    }
} catch (Throwable $e) {
    $fedora->rollback();
    throw $e;
} finally {
    $fedora->begin();
    foreach($acl as $i) {
        $i->reload()->revokeAll();
    }
    $fedora->commit();
}


echo "\n-------------------------------------------------------------------\n";
echo "rights on classes work\n";
$res1 = $fedora->getResourceById($idBase . 'c1/r1');
$res2 = $fedora->getResourceById($idBase . 'c1/c2/r1');
try {
    $fedora->begin();
    $acl1 = $res1->getAcl();
    $acl2 = $res2->getAcl();
    
    assert(AR::NONE == WebAcl::getClassMode($fedora, $testClass1) && AR::NONE == WebAcl::getClassMode($fedora, $testClass2));
    assert(AR::NONE == $acl1->getMode(AR::USER, AR::PUBLIC_USER) && AR::NONE == $acl2->getMode(AR::USER, AR::PUBLIC_USER));
    
    WebAcl::grantClass($fedora, $testClass1, AR::READ);
    WebAcl::grantClass($fedora, $testClass2, AR::WRITE);
    $fedora->commit(); sleep(2);
    
    assert(AR::READ === WebAcl::getClassMode($fedora, $testClass1));
    assert(AR::WRITE === WebAcl::getClassMode($fedora, $testClass2));
    $acl1->reload();
    $acl2->reload();
    assert(AR::READ === $acl1->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::WRITE === $acl2->getMode(AR::USER, AR::PUBLIC_USER));
    
    $fedora->begin();
    WebAcl::revokeClass($fedora, $testClass1);
    assert(AR::NONE === WebAcl::getClassMode($fedora, $testClass1));
    WebAcl::revokeClass($fedora, $testClass2, AR::WRITE);
    assert(AR::READ === WebAcl::getClassMode($fedora, $testClass2));
    
    $fedora->commit(); sleep(2);
    $acl1->reload();
    $acl2->reload();
    assert(AR::NONE === $acl1->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::READ === $acl2->getMode(AR::USER, AR::PUBLIC_USER));
} catch (Throwable $e) {
    $fedora->rollback();
    throw $e;
} finally {
    $fedora->begin();
    $query = new SimpleQuery("SELECT ?class WHERE {?a <http://www.w3.org/ns/auth/acl#accessToClass> ?class . ?a a <http://www.w3.org/ns/auth/acl#Authorization>}");
    foreach($fedora->runQuery($query) as $i){
        WebAcl::revokeClass($fedora, $i->class->getUri());
    }
    $fedora->commit();
}


echo "\n-------------------------------------------------------------------\n";
echo "simplification of compound rules works\n";
$res1 = $fedora->getResourceById($idBase . 'c1');
$res2 = $fedora->getResourceById($idBase . 'c1/r1');
$res3 = $fedora->getResourceById($idBase . 'c1/c2/r1');
$query = new SimpleQuery("SELECT * WHERE {?a a <http://www.w3.org/ns/auth/acl#Authorization>}");
try {
    $acl = array(
        $res1->getAcl(),
        $res2->getAcl(),
        $res3->getAcl()
    );
    
    foreach ($acl as $i) {
        assert(AR::NONE == $i->getMode(AR::USER, 'user1'));
        assert(AR::NONE == $i->getMode(AR::USER, AR::PUBLIC_USER));
    }
    
    $fedora->begin();
    $rule = new AR($fedora);
    $rule->setMode(AR::READ);
    $rule->addResource($res1);
    $rule->addResource($res2);
    $rule->addClass($testClass2); // c1/c2/r1
    $rule->addRole(AR::USER, 'user1');
    $rule->save($fedora);
    
    $fedora->commit(); sleep(2);
    assert(1 === count($fedora->runQuery($query)));
    
    // tricky part - the first reload() will split the compound ACL rule 
    // but an immediate commit() is required for other resources to be able to see the change
    $fedora->begin();
    $acl[0]->reload();
    $fedora->commit(); sleep(2);
    assert(3 === count($fedora->runQuery($query)));
    
    foreach ($acl as $i) {
        $i->reload();
    }
    foreach ($acl as $k => $i) {
        assert(AR::READ === $i->getMode(AR::USER, 'user1'));
        $mode = $k < 2 ? AR::NONE : AR::READ;
        assert($mode === $i->getMode(AR::USER, AR::PUBLIC_USER));
    }
} catch (Throwable $e) {
    $fedora->rollback();
    throw $e;
} finally {
    $fedora->begin();
    foreach ($acl as $i) {
        $i->reload()->revokeAll();
    }
    $query = new SimpleQuery("SELECT ?class WHERE {?a <http://www.w3.org/ns/auth/acl#accessToClass> ?class . ?a a <http://www.w3.org/ns/auth/acl#Authorization>}");
    WebAcl::revokeClass($fedora, $testClass2);
    $fedora->commit();
}