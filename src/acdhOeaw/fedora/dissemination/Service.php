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

namespace acdhOeaw\fedora\dissemination;

use RuntimeException;
use GuzzleHttp\Psr7\Request;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\exceptions\NotInCache;
use acdhOeaw\util\RepoConfig as RC;

/**
 * Description of Service
 *
 * @author zozlak
 */
class Service extends FedoraResource {

    private $param = array();
    
    public function __construct(Fedora $fedora, string $uri = '') {
        try {
            $fedora->getCache()->deleteByUri($fedora->standardizeUri($uri));
        } catch (NotInCache $ex) {

        }
        parent::__construct($fedora, $uri);

        foreach ($this->getChildren() as $i) {
            $param = new Parameter($fedora, $i->getUri(true));
            $this->param[$param->getName()] = $param;
        }
    }

    public function getFormats(): array {
        $meta = $this->getMetadata();
        $formats = array();
        foreach ($meta->all(RC::get('fedoraServiceRetFormatProp')) as $i) {
            $formats[] = (string) $i;
        }
        return $formats;
    }
    
    public function getRequest(FedoraResource $res): Request {
        $meta = $this->getMetadata();
        
        $uri = $meta->getLiteral(RC::get('fedoraServiceLocProp'));
        
        $param = array();
        preg_match_all('#{[-A-Za-z_0-9]+([|][a-zA-Z0-9]+)?}#', $uri, $param);
        $param = $param[0];
        foreach ($param as $i) {
            $ii = explode('|', substr($i, 1, -1));
            if (count($ii) === 1) {
                $ii[1] = '';
            }
            
            if ($ii[0] === 'RES_URI') {
                $value = Parameter::value($res, '.', '', $ii[1]);
            } else if (isset($this->param[$ii[0]])) {
                $value = $this->param[$ii[0]]->getValue($res, $ii[1]);
            } else {
                throw new RuntimeException('unknown parameter ' . $i);
            }
            $uri = str_replace($i, $value, $uri);
        }
        
        return new Request('GET', $uri);
    }
    
}
