<?php

/**
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
 * 
 * @package repo-php-util
 * @copyright (c) 2017, Austrian Centre for Digital Humanities
 * @license https://opensource.org/licenses/MIT
 */

namespace acdhOeaw\schema;

use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\fedora\exceptions\NotInCache;
use acdhOeaw\util\RepoConfig as RC;
use acdhOeaw\util\UriNorm;
use zozlak\util\UUID;

/**
 * Basic class for representing real-world entities to be imported into 
 * the repository.
 *
 * @author zozlak
 */
abstract class SchemaObject {

    /**
     * Debug mode switch.
     * @var boolean 
     */
    static public $debug = false;

    /**
     * Repository resource representing given entity.
     * @var \acdhOeaw\fedora\FedoraResource
     */
    private $res;

    /**
     * Entity id.
     * @var string
     */
    private $id;

    /**
     * External metadata to be merged with automatically generated one.
     * @var \EasyRdf\Resource
     */
    private $metadata;

    /**
     * List of automaticaly generated metadata properties to be preserved while
     * merging with external metadata.
     * @var array
     * @see $metadata
     */
    private $metadataPreserve = array();

    /**
     * Allows to keep track of the corresponding repository resource state:
     * - null - unknown
     * - true - recent call to updateRms() created the repository resource
     * - false - repository resource already existed uppon last updateRms() call
     * @var bool
     */
    protected $created;

    /**
     * Fedora connection object.
     * @var \acdhOeaw\fedora\Fedora 
     */
    protected $fedora;

    /**
     * Creates an object representing a real-world entity.
     * 
     * @param Fedora $fedora repository connection object
     * @param string $id entity identifier (derived class-specific)
     */
    public function __construct(Fedora $fedora, string $id) {
        $this->fedora = $fedora;
        $this->id     = $id;

        try {
            // not so elegant but saves expensive findResource() call
            $this->res = $fedora->getCache()->getById($this->id);
        } catch (NotInCache $e) {
            
        }
    }

    /**
     * Creates RDF metadata from the real-world entity stored in this object.
     */
    abstract public function getMetadata(): Resource;

    /**
     * Returns repository resource representing given real-world entity.
     * 
     * If it does not exist, it can be created.
     * 
     * @param bool $create should repository resource be created if it does not
     *   exist?
     * @param bool $uploadBinary should binary data of the real-world entity
     *   be uploaded uppon repository resource creation?
     * @return FedoraResource
     */
    public function getResource(bool $create = true, bool $uploadBinary = true): FedoraResource {
        if ($this->res === null) {
            try {
                $this->findResource(false, false);
            } catch (NotFound $e) {
                $this->updateRms($create, $uploadBinary);
            }
        }
        return $this->res;
    }

    /**
     * Returns primary id of the real-world entity stored in this object
     * (as it was set up in the object contructor).
     * 
     * Please do not confuse this id with the random internal ACDH repo id.
     * 
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Returns all known ids
     * 
     * @return array list of all ids
     */
    public function getIds(): array {
        $ids  = array($this->id);
        $meta = $this->getMetadata();
        foreach ($meta->allResources(RC::idProp()) as $id) {
            $ids[] = $id->getUri();
        }
        $ids = array_unique($ids);
        return $ids;
    }

    /**
     * Updates repository resource representing a real-world entity stored in
     * this object.
     * 
     * @param bool $create should repository resource be created if it does not
     *   exist?
     * @param bool $uploadBinary should binary data of the real-world entity
     *   be uploaded uppon repository resource creation?
     * @param string $path where to create a resource (if it does not exist).
     *   If it it ends with a "/", the resource will be created as a child of
     *   a given collection). All the parents in the Fedora resource tree have
     *   to exist (you can not create "/foo/bar" if "/foo" does not exist already).
     * @return FedoraResource
     */
    public function updateRms(bool $create = true, bool $uploadBinary = true,
                              string $path = '/'): FedoraResource {
        $this->created = $this->findResource($create, $uploadBinary, $path);

        // if it has just been created it would be a waste of time to update it
        if (!$this->created) {
            $meta = $this->getMetadata();
            if ($this->metadata) {
                $meta->merge($this->metadata, $this->metadataPreserve);
            }
            $this->fedora->fixMetadataReferences($meta);
            $meta = $this->mergeMetadata($this->res->getMetadata(), $meta);
            $this->res->setMetadata($meta);
            $this->res->updateMetadata();

            $binaryContent = $this->getBinaryData();
            if ($uploadBinary && $binaryContent !== '') {
                $this->res->updateContent($binaryContent, true);
            }
        }

        return $this->res;
    }

    /**
     * Informs about the corresponding repository resource state uppon last call
     * to the `updateRms()` method:
     * - null - the updateRms() was not called yet
     * - true - repository resource was created by last call to the updateRms()
     * - false - repository resource already existed uppoin last call to the
     *   updateRms()
     * @return bool
     */
    public function getCreated(): bool {
        return $this->created;
    }

    /**
     * Sets an external metadata to be appended to automatically generated ones.
     * 
     * If a given metatada property exists both in automatically generated and
     * provided metadata, then the final result depends on the $preserve parameter:
     * - if the property is listed in the $preserve array, both automatically
     *   generated and provided values will be kept
     * - if not, only values from provided metadata will be kept and automatically
     *   generated ones will be skipped
     * 
     * @param Resource $meta external metadata
     * @param array $preserve list of metadata properties to be kept - see above
     */
    public function setMetadata(Resource $meta, array $preserve = array()) {
        $this->metadata         = $meta;
        $this->metadataPreserve = $preserve;
    }

    /**
     * Creates a new version of the resource. The new version inherits all IDs but
     * the UUID and epic PIDs. The old version looses all IDs but the UUID and
     * spic PIDs. It also looses all RC::relProp() connections with collections.
     * The old and the new resource are linked with `cfg:fedoraIsNewVersionProp`
     * and `cfg:fedoraIsOldVersionProp`.
     * 
     * @param bool $uploadBinary should binary data of the real-world entity
     *   be uploaded uppon repository resource creation?
     * @param string $path where to create a resource (if it does not exist).
     *   If it it ends with a "/", the resource will be created as a child of
     *   a given collection). All the parents in the Fedora resource tree have
     *   to exist (you can not create "/foo/bar" if "/foo" does not exist already).
     * @param bool $pidPass should PIDs (epic handles) be migrated to the new
     *   version (`true`) or kept by the old one (`false`)
     * @return FedoraResource old version resource
     */
    public function createNewVersion(bool $uploadBinary = true,
                                     string $path = '/', bool $pidPass = false): FedoraResource {
        $pidProp  = RC::get('epicPidProp');
        $idProp   = RC::idProp();
        $uuidNmsp = RC::get('fedoraUuidNamespace');
        $skipProp = [RC::idProp()];
        if (!$pidPass) {
            $skipProp[] = $pidProp;
        }

        $this->findResource(false, $uploadBinary, $path);
        $oldMeta = $this->res->getMetadata(true);
        $newMeta = $oldMeta->copy($skipProp);
        $newMeta->addResource(RC::get('fedoraIsNewVersionProp'), $this->res->getId());
        if ($pidPass) {
            $oldMeta->deleteResource($pidProp);
        }

        $idSkip = [];
        if (!$pidPass) {
            foreach ($oldMeta->allResources($pidProp) as $pid) {
                $idSkip[] = (string) $pid;
            }
        }
        foreach ($oldMeta->allResources($idProp) as $id) {
            $id = (string) $id;
            if (!in_array($id, $idSkip) && strpos($id, $uuidNmsp) !== 0) {
                $newMeta->addResource($idProp, $id);
                $oldMeta->deleteResource($idProp, $id);
            }
        }
        $oldMeta->deleteResource(RC::relProp());
        // there is at least one non-UUID ID required; as all are being passed to the new resource, let's create a dummy one
        $oldMeta->addResource($idProp, RC::get('fedoraVidNamespace') . UUID::v4());

        $oldRes  = $this->fedora->getResourceByUri($this->res->getUri(true));
        $oldRes->setMetadata($oldMeta);
        $oldRes->updateMetadata();
        $oldMeta = $oldRes->getMetadata();

        $oldRes = $this->res;

        $this->createResource($newMeta, $uploadBinary, $path);

        $oldMeta->addResource(RC::get('fedoraIsPrevVersionProp'), $this->res->getId());
        $oldRes->setMetadata($oldMeta);
        $oldRes->updateMetadata();

        return $oldRes;
    }

    /**
     * Tries to find a repository resource representing a given object.
     * 
     * @param bool $create should repository resource be created if it was not
     *   found?
     * @param bool $uploadBinary should binary data of the real-world entity
     *   be uploaded uppon repository resource creation?
     * @param string $path where to create a resource (if it does not exist).
     *   If it it ends with a "/", the resource will be created as a child of
     *   a given collection). All the parents in the Fedora resource tree have
     *   to exist (you can not create "/foo/bar" if "/foo" does not exist already).
     * @return boolean if a repository resource was found
     */
    protected function findResource(bool $create = true,
                                    bool $uploadBinary = true, string $path = ''): bool {
        $ids    = $this->getIds();
        echo self::$debug ? "searching for " . implode(', ', $ids) . "\n" : "";
        $result = '';

        try {
            $this->res = $this->fedora->getResourceByIds($ids);
            $result    = 'found in cache';
        } catch (NotFound $e) {
            if (!$create) {
                throw $e;
            }

            $meta   = $this->getMetadata();
            $this->createResource($meta, $uploadBinary, $path);
            $result = 'not found - created';
        }

        echo self::$debug ? "\t" . $result . " - " . $this->res->getUri(true) . "\n" : "";
        return $result == 'not found - created';
    }

    /**
     * Creates a Fedora resource
     * @param Resource $meta
     * @param bool $uploadBinary
     * @param string $path
     */
    protected function createResource(Resource $meta, bool $uploadBinary,
                                      string $path) {
        $this->fedora->fixMetadataReferences($meta, [RC::get('epicPidProp')]);
        UriNorm::standardizeMeta($meta);
        $binary    = $uploadBinary ? $this->getBinaryData() : '';
        $method    = substr($path, -1) == '/' || $path === '' ? 'POST' : 'PUT';
        $this->res = $this->fedora->createResource($meta, $binary, $path, $method);
    }

    /**
     * Provides entity binary data.
     * @return value accepted as the \acdhOeaw\fedora\Fedora::attachData() $body parameter
     */
    protected function getBinaryData() {
        return '';
    }

    /**
     * Merges metadata coming from the Fedora and generated by the class.
     * @param Resource $current current Fedora resource metadata
     * @param Resource $new metadata generated by the class
     * @return Resource final metadata
     */
    protected function mergeMetadata(Resource $current, Resource $new): Resource {
        $meta = $current->merge($new, array(RC::idProp()));
        UriNorm::standardizeMeta($meta);
        return $meta;
    }

}
