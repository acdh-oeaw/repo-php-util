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

use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\acl\WebAcl;
use acdhOeaw\fedora\acl\WebAclRule as AR;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\util\ResourceFactory as RF;

require_once 'init.php';
Fedora::$debug = true;
$fedora        = new Fedora();
RF::init($fedora);
$idBase        = 'https://id.acdh.oeaw.ac.at/';

// Prepare test resources
$fedora->begin();
$r = [];
foreach (['c1', 'c1/c2', 'c1/r1', 'c1/r2', 'c1/c2/r1', 'c1/c2/r2'] as $i) {
    $r[$i] = RF::create(['id' => $idBase . $i], $i, 'PUT');
}
$fedora->commit();
RF::removeAcl($fedora);


echo "\n-------------------------------------------------------------------\n";
echo "grants read access to public\n";
try {
    $fedora->begin();
    $acl = $r['c1/r1']->getAcl();

    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER) && AR::NONE === $acl->getMode(AR::USER, 'user1') && AR::NONE === $acl->getMode(AR::GROUP, 'group1'));
    $acl->createAcl();
    $acl->grant(AR::USER, AR::PUBLIC_USER, AR::READ);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::READ === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));

    $fedora->commit();

    $acl = $r['c1/r1']->getAcl(true);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::READ === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));
} finally {
    RF::removeAcl($fedora, false);
}


echo "\n-------------------------------------------------------------------\n";
echo "grants different rights to different users/groups\n";
try {
    $fedora->begin();
    $acl = $r['c1/r2']->getAcl();

    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER) && AR::NONE === $acl->getMode(AR::USER, 'user1') && AR::NONE === $acl->getMode(AR::GROUP, 'group1'));

    $acl->createAcl();
    $acl->grant(AR::USER, 'user1', AR::WRITE);
    $acl->grant(AR::GROUP, 'group1', AR::READ);
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::WRITE === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));

    $fedora->commit();

    $acl = $r['c1/r2']->getAcl();
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::WRITE === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));
} finally {
    RF::removeAcl($fedora, false);
}


echo "\n-------------------------------------------------------------------\n";
echo "inheritance works\n";
try {
    $fedora->begin();

    $aclP  = $r['c1']->getAcl();
    $aclCh = $r['c1/c2/r2']->getAcl();
    assert(AR::NONE === $aclP->getMode(AR::USER, AR::PUBLIC_USER) && AR::NONE === $aclP->getMode(AR::USER, 'user1') && AR::NONE === $aclP->getMode(AR::GROUP, 'group1'));
    assert(AR::NONE === $aclCh->getMode(AR::USER, AR::PUBLIC_USER) && AR::NONE === $aclCh->getMode(AR::USER, 'user1') && AR::NONE === $aclCh->getMode(AR::GROUP, 'group1'));

    $aclP->createAcl();
    $aclP->grant(AR::USER, 'user1', AR::READ);
    $aclP->grant(AR::GROUP, 'group1', AR::WRITE);
    $aclCh = $r['c1/c2/r2']->getAcl(true);
    $aclCh->grant(AR::USER, 'user1', AR::WRITE);
    $aclCh->grant(AR::GROUP, 'group1', AR::READ);

    assert(AR::NONE === $aclCh->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::WRITE === $aclCh->getMode(AR::USER, 'user1'));
    // information on external rules is fetched using a SPARQL query so it isn't available before a commit
    assert(AR::READ === $aclCh->getMode(AR::GROUP, 'group1'));

    $fedora->commit();

    $aclCh = $r['c1/c2/r2']->getAcl(true);
    assert(AR::NONE === $aclCh->getMode(AR::USER, AR::PUBLIC_USER));
    assert(AR::WRITE === $aclCh->getMode(AR::USER, 'user1'));
    assert(AR::WRITE === $aclCh->getMode(AR::GROUP, 'group1'));
} finally {
    RF::removeAcl($fedora, false);
}


echo "\n-------------------------------------------------------------------\n";
echo "manual save mode works\n";
try {
    $acl = $r['c1/r1']->getAcl();
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));

    WebAcl::setAutosave(false);
    $fedora->begin();
    $acl->createAcl();
    $acl->grant(AR::USER, AR::PUBLIC_USER, AR::READ);
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    $fedora->commit();

    $acl->reload();
    assert(AR::NONE === $acl->getMode(AR::USER, AR::PUBLIC_USER));

    $fedora->begin();
    $acl->grant(AR::USER, AR::PUBLIC_USER, AR::READ);
    $acl->save();
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
    $fedora->commit();
    $acl->reload();
    assert(AR::READ === $acl->getMode(AR::USER, AR::PUBLIC_USER));
} finally {
    RF::removeAcl($fedora, false);
    WebAcl::setAutosave(true);
}


echo "\n-------------------------------------------------------------------\n";
echo "revoke works\n";
try {
    $fedora->begin();
    $acl = $r['c1/r1']->getAcl();
    $acl->createAcl();
    $acl->grant(AR::USER, 'user1', AR::READ);
    $acl->grant(AR::GROUP, 'group1', AR::WRITE);
    $fedora->commit();

    $fedora->begin();
    $acl->reload();
    assert(AR::READ === $acl->getMode(AR::USER, 'user1'));
    assert(AR::WRITE === $acl->getMode(AR::GROUP, 'group1'));

    $acl->revoke(AR::USER, 'user1', AR::READ);
    assert(AR::NONE === $acl->getMode(AR::USER, 'user1'));
    $acl->revoke(AR::GROUP, 'group1', AR::WRITE);
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));

    $fedora->commit();
    $acl->reload();
    assert(AR::NONE === $acl->getMode(AR::USER, 'user1'));
    assert(AR::READ === $acl->getMode(AR::GROUP, 'group1'));
} finally {
    RF::removeAcl($fedora, false);
}


echo "\n-------------------------------------------------------------------\n";
echo "revokeAll works\n";
try {
    $fedora->begin();
    $aclP  = $r['c1']->getAcl();
    $aclP->createAcl();
    $aclP->grant(AR::USER, 'user1', AR::READ);
    $aclCh = $r['c1/r1']->getAcl();
    $aclCh->grant(AR::USER, 'user1', AR::WRITE);
    $aclCh->grant(AR::GROUP, 'group1', AR::WRITE);

    $fedora->commit();

    $fedora->begin();
    $aclCh->reload();
    assert(AR::WRITE === $aclCh->getMode(AR::USER, 'user1'));
    assert(AR::WRITE === $aclCh->getMode(AR::GROUP, 'group1'));
    $aclCh->revokeAll();
    assert(AR::READ === $aclCh->getMode(AR::USER, 'user1'));
    assert(AR::NONE === $aclCh->getMode(AR::GROUP, 'group1'));
    $fedora->commit();

    $fedora->begin();
    $aclCh->reload();
    assert(AR::READ === $aclCh->getMode(AR::USER, 'user1'));
    assert(AR::NONE === $aclCh->getMode(AR::GROUP, 'group1'));
    $aclP->revokeAll();
    $aclCh->reload();
    assert(AR::NONE === $aclCh->getMode(AR::USER, 'user1'));
    assert(AR::NONE === $aclCh->getMode(AR::GROUP, 'group1'));
    $fedora->commit();

    $aclCh->reload();
    assert(AR::NONE === $aclCh->getMode(AR::USER, 'user1'));
    assert(AR::NONE === $aclCh->getMode(AR::GROUP, 'group1'));
} finally {
    RF::removeAcl($fedora, false);
}


echo "\n-------------------------------------------------------------------\n";
echo "delete ACL works\n";
try {
    $fedora->begin();
    $acl = $r['c1/c2']->getAcl();
    assert(AR::NONE === $acl->getMode(AR::USER, 'user1'));
    $acl->createAcl();
    $acl->grant(AR::USER, 'user1', AR::READ);
    $fedora->commit();

    $fedora->begin();
    $acl = $r['c1/c2/r1']->getAcl();
    assert(AR::READ === $acl->getMode(AR::USER, 'user1'));
    try {
        // ACL can be deleted only from the resource to which it is directly attached
        $acl->deleteAcl();
        throw new Exception('NotFound was expected');
    } catch (NotFound $ex) {
        
    }
    $acl = $r['c1/c2']->getAcl();
    assert(AR::READ === $acl->getMode(AR::USER, 'user1'));
    $acl->deleteAcl();
    assert(AR::NONE === $acl->getMode(AR::USER, 'user1'));

    $acl->reload();
    assert(AR::NONE === $acl->getMode(AR::USER, 'user1'));
    $acl = $r['c1/c2']->getAcl(true);
    assert(AR::NONE === $acl->getMode(AR::USER, 'user1'));
} finally {
    RF::removeAcl($fedora, false);
}


echo "\n-------------------------------------------------------------------\n";
echo "simplification of compound rules works\n";
$query = new SimpleQuery("SELECT * WHERE {?a a <http://www.w3.org/ns/auth/acl#Authorization>}");
try {
    $r['c1']->getAcl()->createAcl();
    $acl = array(
        $r['c1']->getAcl(),
        $r['c1/r1']->getAcl(),
        $r['c1/c2/r1']->getAcl()
    );

    foreach ($acl as $i) {
        assert(AR::NONE == $i->getMode(AR::USER, 'user1'));
        assert(AR::NONE == $i->getMode(AR::USER, AR::PUBLIC_USER));
    }

    $fedora->begin();
    $rule = new AR($fedora);
    $rule->setMode(AR::READ);
    $rule->addResource($r['c1']);
    $rule->addResource($r['c1/r1']);
    $rule->addResource($r['c1/c2/r1']);
    $rule->addRole(AR::USER, 'user1');
    $rule->addRole(AR::USER, 'user2');
    $rule->save($r['c1']->getAclUrl());
    $fedora->commit();
    assert(1 === count($fedora->runQuery($query)));

    // tricky part - the first reload() will split the compound ACL rule 
    // but an immediate commit() is required for other resources to be able to see the change
    $fedora->begin();
    $acl[0]->reload();
    $fedora->commit();
    assert(3 === count($fedora->runQuery($query)));

    foreach ($acl as $k => $i) {
        $i->reload();
        assert(AR::READ === $i->getMode(AR::USER, 'user1'));
        assert(AR::READ === $i->getMode(AR::USER, 'user2'));
    }
} finally {
    RF::removeAcl($fedora, false);
}


echo "\n-------------------------------------------------------------------\n";
echo "moving existing rules uppon ACL creation works\n";
try {
    $aclP  = $r['c1']->getAcl();
    $aclP->createAcl();
    $aclCh = $r['c1/r1']->getAcl();
    assert(AR::NONE === $aclCh->getMode(AR::USER, 'user1'));

    $fedora->begin();
    $rule = new AR($fedora);
    $rule->setMode(AR::READ);
    $rule->addResource($r['c1/r1']);
    $rule->addRole(AR::USER, 'user1');
    $rule->save($r['c1']->getAclUrl());
    $fedora->commit();

    $aclCh->reload();
    assert(AR::READ === $aclCh->getMode(AR::USER, 'user1'));
    
    $fedora->begin();
    $aclCh->createAcl();
    $aclP->revokeAll();
    $aclCh->reload();
    assert(AR::READ === $aclCh->getMode(AR::USER, 'user1'));
    $fedora->commit();

    $aclCh = $r['c1/r1']->getAcl(true);
    assert(AR::READ === $aclCh->getMode(AR::USER, 'user1'));
} finally {
    RF::removeAcl($fedora, false);
}


echo "\n-------------------------------------------------------------------\n";
echo "requires ACL to exist\n";
try {
    $fedora->begin();

    try {
        $acl = $r['c1/r1']->getAcl();
        $acl->grant(AR::USER, AR::PUBLIC_USER, AR::READ);
        throw new Exception('NotFound exception was expected');
    } catch (NotFound $e) {
        
    }

    // it's valid if an ACL belongs to parent
    $r['c1']->getAcl()->createAcl();
    $r['c1/r1']->getAcl()->grant(AR::USER, AR::PUBLIC_USER, AR::READ);
} finally {
    RF::removeAcl($fedora, false);
}
