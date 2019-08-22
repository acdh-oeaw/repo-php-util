<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\repoPhpUtil;

use GuzzleHttp\Exception\ClientException;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\exceptions\Deleted;
use acdhOeaw\fedora\exceptions\NotFound;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Description of HelpersTrait
 *
 * @author zozlak
 */
abstract class TestBase extends \PHPUnit\Framework\TestCase {

    /**
     *
     * @var \acdhOeaw\fedora\Fedora
     */
    static protected $repo;
    static protected $config;

    static public function setUpBeforeClass(): void {
        RC::init(__DIR__ . '/config.ini');
        self::$repo = new Fedora();
    }

    static public function tearDownAfterClass(): void {
        
    }

    private $resources;

    public function setUp(): void {
        $this->resources = [];
        self::$repo->__clearCache();
    }

    public function tearDown(): void {
        self::$repo->rollback();

        // delete resources starting with the "most metadata rich" which is a simple heuristic for avoiding 
        // unneeded resource updates when deleting one pointed by many others (such resources are typicaly 
        // "metadata poor" therefore deleting them as the last ones should do the job)
        self::$repo->begin();
        $queue = [];
        foreach ($this->resources as $n => $i) {
            /* @var $i \acdhOeaw\fedora\FedoraResource */
            $queue[$n] = count($i->getMetadata()->propertyUris());
        }
        arsort($queue);
        foreach ($queue as $n => $count) {
            try {
                $this->resources[$n]->delete(true, true, true);
            } catch (Deleted $e) {
                
            } catch (NotFound $e) {
                
            } catch (ClientException $e) {
                
            }
        }
        self::$repo->commit();
        if (is_dir(__DIR__ . '/tmp')) {
            system('rm -fR ' . __DIR__ . '/tmp');
        }
    }

    protected function noteResources(array $res): void {
        $this->resources = array_merge($this->resources, array_values($res));
    }

}
