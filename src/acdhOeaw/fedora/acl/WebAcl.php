<?php

/**
 * The MIT License
 *
 * Copyright 2016 Austrian Centre for Digital Humanities.
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
 * 
 * @package repo-php-util
 * @copyright (c) 2017, Austrian Centre for Digital Humanities
 * @license https://opensource.org/licenses/MIT
 */

namespace acdhOeaw\fedora\acl;

use InvalidArgumentException;
use RuntimeException;
use EasyRdf\Graph;
use EasyRdf\Sparql\Result;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;
use acdhOeaw\fedora\exceptions\AlreadyInCache;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Provides ACL management.
 * 
 * A WebAcl object stores access control rules for a given FedoraResource
 * object and provides methods to manipulate them.
 * 
 * Static methods provide a way to adjust class-oriented access control rules.
 * 
 * Keeping ACL rules in order is very troublesome in general.
 * 
 * The first problem is that every ACL resource can contain rules for any 
 * combination of users, groups, classes, resources and access modes.
 * While the repo-php-util can follow some guidelines on write (e.g. "only one 
 * resource/class described in a single acl resource"), it must be able to cope 
 * with any mess on read.
 *
 * Another big problem is tracking the current set of all rules connected with
 * a given resource. It requires either inspecting the whole acl collection 
 * (which is terribly slow but reliable) or quering a triplestore (which is
 * fast but unreliable as the triplestore is synchronized only at the 
 * transaction commit).
 * This problem is not fully addressed by this class. During a transaction it 
 * provides proper tracking for the rules describing user/group access rights
 * to particular resources. Tracking is not provided for rules describing access
 * rights to classes and for rules specyfying access rights to more then one
 * resource and/or class.
 * This means you must commit a transaction and call reload() method on all
 * WebAcl objects after every change made to class ACL rules to keep your
 * WebAcl objects up to date.
 * 
 * When a compound (describing more then one class and/or resource) rule is
 * encountered, it is automatically splitted into "simple" rules (each
 * descrining exactly one resource/class). Similarly to class rules,
 * a transaction commit and reload() calls on WebAcl objects is required to
 * make WebAcl objects aware of this transformation.
 * 
 * @author zozlak
 */
class WebAcl {

    const ACL_LINK_PROP     = 'http://www.w3.org/ns/auth/acl#accessControl';
    const ACL_CHILDREN_PROP = 'http://www.w3.org/ns/ldp#contains';
    const ACL_CLASS         = 'http://fedora.info/definitions/v4/webac#Acl';
    const QUERY             = "
        SELECT DISTINCT ?rule
        WHERE {
            ?@ <http://fedora.info/definitions/v4/repository#hasParent>* / ^<http://www.w3.org/ns/auth/acl#accessTo> ?rule .
            ?rule a <http://www.w3.org/ns/auth/acl#Authorization> .
            ?rule <http://fedora.info/definitions/v4/repository#hasParent>+ ?@ .
        }
    ";

    /**
     * Should debug information be displayed
     * @var bool 
     */
    static public $debug = false;

    /**
     * Should `WebAclRule` resources be synced with the Fedora immediately
     * @var bool
     * @see setAutosave()
     */
    static private $autosave = true;

    /**
     * When autosave is turned on, all changes to `WebAclRule` objects are
     * immediately synced with the Fedora.
     * It is convenient but may be slower if you apply many rules to a single
     * resource (e.g. when you grant the same access rights to many users).
     * 
     * When autosave is off, you must call a `save()` method for the changes
     * to be populated to the Fedora (of course you must also commit
     * a transaction separately to make them persistent).
     * @param bool $autosave
     */
    static public function setAutosave(bool $autosave) {
        self::$autosave = $autosave;
    }

    /**
     * Preprocesses rules fetched from the SPARQL query:
     * - skips rules which no longer exist in the Fedora
     * - splits compound rules into simple ones
     * @param Result $results SPARQL result set containg a `rule` variable
     *   with a Fedora URIs of ACL Fedora resources
     * @param Fedora $fedora Fedora connection object
     * @param string $aclUrl URL of the ACL where splitted rules (if there will
     *   be such) should be saved
     * @return array collection of `WebAclRule` objects
     */
    static private function initRules(Result $results, Fedora $fedora,
                                      string $aclUrl): array {
        $rules = array();
        foreach ($results as $i) {
            $rule = (string) $i->rule;
            if (!isset($rules[$rule])) {
                try {
                    $rules[$rule] = new WebAclRule($fedora, $rule);
                } catch (AlreadyInCache $e) {
                    $rules[$rule] = $fedora->getResourceByUri($rule);
                } catch (Deleted $e) {
                    
                } catch (NotFound $e) {
                    
                }
            }
        }

        $validRules = array();
        foreach ($rules as $r) {
            $tmp = $r->makeValid();
            if (count($tmp) > 1) {
                foreach ($tmp as $i) {
                    $i->save($aclUrl);
                    $validRules[] = $i;
                }
                $r->delete(true);
            } else {
                $validRules[] = $r;
            }
        }

        return $validRules;
    }

    /**
     * FedoraResource for which ACL rules are stored.
     * @var \acdhOeaw\fedora\FedoraResource 
     */
    private $res;

    /**
     * Collection of rules inherited from parent (in Fedora terms) resources.
     * @var array 
     */
    private $extRules = array();

    /**
     * Collection of rules applied dirtectly to the resource or its classes.
     * @var array 
     */
    private $resRules = array();

    /**
     * Creates a WebAcl object
     * @param FedoraResource $res corresponding Fedora resource
     */
    public function __construct(FedoraResource $res) {
        $this->res = $res;
        $this->reload();
    }

    /**
     * Manually triggers synchronization of all stored `WebAclRule` objects
     * with the Fedora.
     * @see setAutocommit()
     */
    public function save() {
        foreach ($this->resRules as $i) {
            $i->save($this->res->getAclUrl());
        }
    }

    /**
     * Revokes privileges from all users, groups and classes for a given 
     * Fedora resource.
     * 
     * Only rules directly targeting the given resource are removed.
     * Rules inherited from Fedora parents sharing the same ACL are not affected.
     * 
     * If `$mode` equals to `WebAclRule::WRITE` all privileges are limited to
     * `WebAclRule::READ`.
     * 
     * If `$mode` equals to `WebAclRule::READ` all privileges are revoked.
     * @param int $mode WebAclRule::READ or WebAclRule::WRITE
     * @return \acdhOeaw\fedora\acl\WebAcl
     */
    public function revokeAll(int $mode = WebAclRule::READ): WebAcl {
        WebAclRule::checkMode($mode);

        if ($mode == WebAclRule::WRITE) {
            foreach ($this->resRules as $i) {
                $i->setMode(WebAclRule::READ);
                if (self::$autosave) {
                    $i->save($this->res->getAclUrl());
                }
            }
        } else {
            foreach ($this->resRules as $i) {
                $i->delete(true);
            }
            $this->resRules = array();
        }

        return $this;
    }

    /**
     * Returns effective access rights for a given user/group.
     * @param string $type WebAclRule::USER or WebAclRule::Group
     * @param string $name user/group name
     * @param bool $inherited should rules inherited from parents (in Fedora 
     *   terms) resources be taken into account?
     * @return int (WebAclRule::READ or WebAclRule::WRITE)
     */
    public function getMode(string $type, string $name = null,
                            bool $inherited = true): int {
        WebAclRule::checkRoleType($type);

        $modes = array(WebAclRule::NONE);

        if ($type !== WebAclRule::USER || $name !== WebAclRule::PUBLIC_USER) {
            $modes[] = $this->getMode(WebAclRule::USER, WebAclRule::PUBLIC_USER, $inherited);
        } else {
            $classes = $this->res->getClasses();
            $rules   = array_merge($this->resRules, $this->extRules);
            foreach ($rules as $r) {
                foreach ($classes as $c) {
                    if ($r->hasClass($c)) {
                        $modes[] = $r->getMode();
                    }
                }
            }
        }

        $rules = $this->resRules;
        if ($inherited) {
            $rules = array_merge($rules, $this->extRules);
        }
        foreach ($rules as $i) {
            if ($i->hasRole($type, $name)) {
                $modes[] = $i->getMode();
            }
        }
        return max($modes);
    }

    /**
     * Grants give rights to a given user/group.
     * @param string $type WebAclRule::USER or WebAclRule::GROUP
     * @param string $name user/group name
     * @param int $mode WebAclRule::READ or WebAclRule::WRITE
     */
    public function grant(string $type, string $name,
                          int $mode = WebAclRule::READ) {
        $this->checkParam($type, $mode);
        $fedora = $this->res->getFedora();

        $curMode   = array(WebAclRule::NONE);
        $matches   = array();
        $modeMatch = null;
        foreach ($this->resRules as $i) {
            $iMode = $i->getMode();
            if ($i->hasRole($type, $name)) {
                $matches[] = $i;
                $curMode[] = $iMode;
            }
            if ($iMode === $mode) {
                $modeMatch = $i;
            }
        }
        $curMode = max($curMode);

        if ($mode > $curMode) {
            foreach ($matches as $i) {
                $i->deleteRole($type, $name);
            }
            if ($modeMatch) {
                $modeMatch->addRole($type, $name);
            } else {
                $rule = new WebAclRule($fedora);
                $rule->setMode($mode);
                $rule->addResource($this->res);
                $rule->addRole($type, $name);
                if (self::$autosave) {
                    $rule->save($this->res->getAclUrl());
                }
                $this->resRules[] = $rule;
            }
            if (self::$autosave) {
                foreach ($matches as $i) {
                    $rule->save($this->res->getAclUrl());
                }
            }
        }
    }

    /**
     * Revokes access rights from a given user/group.
     * @param string $type WebAclRule::USER or WebAclRule::GROUP
     * @param string $name user/group name
     * @param int $mode WebAclRule::READ or WebAclRule::WRITE
     */
    public function revoke(string $type, string $name,
                           int $mode = WebAclRule::READ) {
        $this->checkParam($type, $mode);

        $toDel = array();
        $hasSomeRights = false;
        foreach ($this->resRules as $i) {
            if ($i->hasRole($type, $name) && $i->getMode() >= $mode) {
                $hasSomeRights = true;
                if ($i->getRolesCount() == 1) {
                    $toDel[] = $i;
                } else {
                    $i->deleteRole($type, $name);
                    if (self::$autosave) {
                        $i->save($this->res->getAclUrl());
                    }
                }
            }
        }
        $this->resRules = array_diff($this->resRules, $toDel);
        $this->extRules = array_diff($this->extRules, $toDel);
        foreach ($toDel as $i) {
            $i->delete(true);
        }

        if ($mode === WebAclRule::WRITE && $hasSomeRights) {
            $this->grant($type, $name, WebAclRule::READ, 0);
        }
    }

    /**
     * Fetches an array of `WebAclRule` objects containing access rules for
     * a corresponding Fedora resource.
     * @param bool $inherited should rules inherited from parent (in Fedora 
     *   terms) resources be taken into account?
     * @return array
     */
    public function getRules(bool $inherited = true): array {
        $ret = array();
        foreach ($this->resRules as $i) {
            $ret[] = $i->getData();
        }
        if ($inherited) {
            foreach ($this->extRules as $i) {
                $ret[] = $i->getData();
            }
        }
        return $ret;
    }

    /**
     * Reloads access rules by quering a triplestore.
     * See class description for use cases.
     */
    public function reload(): WebAcl {
        $fedora         = $this->res->getFedora();
        $resUri         = $this->res->getUri(true);
        $aclUri         = $this->res->getAclUrl();
        $this->resRules = [];
        $this->extRules = [];

        if ($aclUri) {
            $query   = new SimpleQuery(self::QUERY, [$resUri, $aclUri]);
            $results = $fedora->runQuery($query);
            $rules   = self::initRules($results, $fedora, $aclUri);
            foreach ($rules as $r) {
                if ($r->hasResource($resUri)) {
                    $this->resRules[] = $r;
                } else {
                    $this->extRules[] = $r;
                }
            }
        }
        return $this;
    }

    /**
     * Creates an ACL attached directly to a given resource.
     * All rules describing resource from ACL currently in effect are 
     * automatically moved to the newly created ACL.
     * 
     * Resource has to permanently exist in the repository for operation to
     * succeed (you can not create a resource's ACL within the same transaction
     * a resource was created). If it is not a case, a `NotFound` exception is
     * rised.
     * 
     * If an ACL attached directly to the resource already exists, nothing 
     * happens.
     * @throws \RuntimeException
     */
    public function createAcl(): WebAcl {
        $resMeta = $this->res->getMetadata();
        $acls    = $resMeta->allResources(self::ACL_LINK_PROP);
        if (count($acls) > 1) {
            throw new RuntimeException('Resource has many ACLs');
        } else if (count($acls) > 0) {
            return $this;
        }

        try {
            $location = RC::get('fedoraAclUri');
        } catch (InvalidArgumentException $ex) {
            $location = '/';
        }

        $aclMeta = (new Graph())->resource('.');
        $aclMeta->addType(self::ACL_CLASS);
        $aclMeta->addLiteral(RC::titleProp(), 'ACL');
        $id      = preg_replace('|^[^:]+|', 'acl', $this->res->getId());
        $aclMeta->addResource(RC::idProp(), $id);

        // Link to ACL is applied after the transaction commit, so we need 
        // a separate transaction not to affect the current one
        // As a side effect the resource for which an ACL is created has to 
        // persistently exist in the repository already!
        $fedoraTmp = clone($this->res->getFedora());
        $fedoraTmp->__clearCache();
        $fedoraTmp->begin();
        $aclRes    = $fedoraTmp->createResource($aclMeta, '', $location, 'POST');
        $resMeta->addResource(self::ACL_LINK_PROP, $aclRes->getUri(true));
        try {
            $resTmp = $fedoraTmp->getResourceByUri($this->res->getUri());
            $resTmp->setMetadata($resMeta);
            $resTmp->updateMetadata();
            $fedoraTmp->commit();
        } catch (NotFound $e) {
            $fedoraTmp->rollback();
        }

        $this->res->getMetadata(true);
        if ($this->res->getAclUrl() !== $aclRes->getUri(true)) {
            // second try for binary resources which are not handled properly by Fedora
            if ($this->res->getAclUrl(true) !== $aclRes->getUri(true)) {
                throw new RuntimeException('ACL creation failed');
            }
        }

        foreach ($this->resRules as $h => $i) {
            $i->move($aclRes->getUri(true) . '/' . $h);
        }

        return $this;
    }

    /**
     * Removes ACL directly attached to this resource.
     * If there is no such ACL, error is thrown.
     * @return \acdhOeaw\fedora\acl\WebAcl
     * @throws NotFound
     */
    public function deleteAcl(): WebAcl {
        $fedora  = $this->res->getFedora();
        $resMeta = $this->res->getMetadata();
        $acls    = $resMeta->allResources(self::ACL_LINK_PROP);
        if (count($acls) == 0) {
            throw new NotFound();
        }
        foreach ($acls as $i) {
            $aclRes  = $fedora->getResourceByUri($i);
            $aclMeta = $aclRes->getMetadata();
            foreach ($aclMeta->allResources(self::ACL_CHILDREN_PROP) as $j) {
                $fedora->getResourceByUri($j->getUri())->delete();
            }
            $aclRes->getMetadata(true); // notify children were deleted
            $aclRes->delete(true, false);
        }
        $resMeta->deleteResource(self::ACL_LINK_PROP);
        $this->res->setMetadata($resMeta);
        $this->res->updateMetadata();

        $this->res->getAclUrl(true);
        $this->resRules = $this->extRules = [];

        return $this;
    }

    /**
     * Checks the `grant()` and `revoke()` method parameters.
     * @param string $type
     * @param int $mode
     * @throws NotFound
     */
    private function checkParam(string $type, int $mode) {
        WebAclRule::checkRoleType($type);
        WebAclRule::checkMode($mode);
        if ($this->res->getAclUrl() === '') {
            throw new NotFound();
        }
    }

    /**
     * Provides a nice printable representation.
     * @return string
     */
    public function __toString() {
        $ret = 'ACL rules for ' . $this->res->getUri() . ":\n";
        $ret .= "  Resource rules:\n";
        foreach ($this->resRules as $i) {
            $ret .= '    ' . (string) $i;
        }
        $ret .= "  External rules:\n";
        foreach ($this->extRules as $i) {
            $ret .= '    ' . (string) $i;
        }
        return $ret;
    }

}
