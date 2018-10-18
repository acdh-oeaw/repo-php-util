<?php

/*
 * The MIT License
 *
 * Copyright 2018 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\util;

use RuntimeException;

/**
 * A wrapper for bin/keepTransactionAlive.php script allowing to keep a Fedora
 * transaction alive on platforms which don't provide pcntl_fork() (like Windows)
 * 
 * The spawned bin/keepTransactionAlive.php is automatically ended when corresponding
 * KeepTransactionAlive class object is deleted.
 *
 * @author zozlak
 */
class KeepTransactionAlive {

    private $pipes = [];
    private $process;

    /**
     * Spawns a process keeping transaction alive.
     * @param string $txUrl Fedora transaction URL
     * @param string $login login to be used
     * @param string $password password to be used
     * @param int $interval default refresh interval
     * @throws RuntimeException
     */
    public function __construct(string $txUrl, string $login, string $password,
                                int $interval = 90) {
        // Black Magic - find Composer object and use it to find repo-php-util location
        foreach (get_declared_classes() as $class) {
            if (strpos($class, 'ComposerAutoloaderInit') === 0) {
                $composer        = $class::getLoader();
                $classMap        = $composer->getPrefixesPsr4();
                $repoPhpUtilPath = $classMap['acdhOeaw\\'][0] . '/../../';
                break;
            }
        }
        if (!isset($repoPhpUtilPath)) {
            throw new RuntimeException('Composer class not found');
        }
        $command       = PHP_BINARY . ' ' .
            escapeshellarg($repoPhpUtilPath . 'bin/keepTransactionAlive.php') . ' ' .
            escapeshellarg($txUrl) . ' ' .
            escapeshellarg($login) . ' ' .
            escapeshellarg($password) . ' ' .
            escapeshellarg($interval);
        $pipesDef      = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $this->process = proc_open($command, $pipesDef, $this->pipes);
    }

    /**
     * Ends corresponding bin/keepTransactionAlive.php script
     */
    public function __destruct() {
        fwrite($this->pipes[0], "end\n");
        echo stream_get_contents($this->pipes[1]) . "\n";
        echo stream_get_contents($this->pipes[2]) . "\n";
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $ret = proc_close($this->process);
        echo "KeepTransactionAlive ended with code $ret \n";
    }

}
