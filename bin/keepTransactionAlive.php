#!/usr/bin/php
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

if ($argc < 4) {
    echo $argv[0] . " transactionUrl user pswd [refreshTime=90]\n\n";
    echo "    Keeps alive a given Fedora transaction.\n";
    echo "    Any data on input ends script execution.\n\n";
    exit();
}

$refreshTime = (int) $argv[4] ?? 90;
$input       = fopen('php://stdin', 'r');
$nonBlocking = stream_set_blocking($input, false);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $argv[1] . '/fcr:tx',
    CURLOPT_POST           => 1,
    CURLOPT_FAILONERROR    => 1,
    CURLOPT_SSL_VERIFYPEER => 0,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    CURLOPT_USERPWD        => $argv[2] . ':' . $argv[3],
]);

$t = 0;
while (!$nonBlocking || fgetc($input) === false) {
    sleep(1);
    $t++;
    if ($t >= $refreshTime) {
        $t    = 0;
        echo "Extending transaction... ";
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        echo $code . "\n";
        if ($code === 410 || $code === 500) {
            break;
        }
    }
}

curl_close($ch);
