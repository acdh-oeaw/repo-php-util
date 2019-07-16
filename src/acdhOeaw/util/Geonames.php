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

namespace acdhOeaw\util;

use acdhOeaw\util\RepoConfig as RC;
use EasyRdf\Resource;

/**
 * A simply utility class standardizing the geonames URIs
 *
 * @author zozlak
 */
class Geonames {

    /**
     * Returns a standardized geonames URI.
     * 
     * If the passed URI is not a geonames URI it is returned without any 
     * modifications.
     * @param string $uri URI to be standardized
     * @return string
     */
    static public function standardize(string $uri): string {
        $id = preg_replace('|^https?://([^.]+[.])?geonames[.]org/([0-9]+)(/.*)?$|', '\\2', $uri);
        return $id !== $uri ? 'https://www.geonames.org/' . $id : $uri;
    }

    /**
     * Performs geonames URI standardization on all id properties of a given
     * metadata resource object.
     * 
     * @param Resource $res metadata to be processed
     */
    static public function standardizeMeta(Resource $res) {
        foreach ($res->allResources(RC::idProp()) as $id) {
            $res->deleteResource(RC::idProp(), $id);
            $res->addResource(RC::idProp(), Geonames::standardize((string) $id));
        }
    }
}
