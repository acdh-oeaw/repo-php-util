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

namespace acdhOeaw\fedora\metadataQuery;

/**
 * Description of Query
 *
 * @author zozlak
 */
class Query {

    /**
     *
     * @var \acdhOeaw\fedora\metadataQuery\QueryParameter
     */
    private $param = array();
    private $distinct;
    private $orderBy = array();
    private $joinClause = '';

    public function __construct() {
        
    }

    public function addParameter(QueryParameter $p) {
        $this->param[] = $p;
    }

    public function addSubquery(Query $q) {
        $this->param[] = $q;
    }

    public function setDistinct(bool $distinct) {
        $this->distinct = $distinct;
    }

    public function setOrderBy(array $orderBy) {
        $this->orderBy = $orderBy;
    }

    public function setJoinClause(string $joinClause) {
        $clauses = array('optional', 'minus', 'filter exists', 'filter not exists');
        if (!in_array(strtolower($joinClause), $clauses)) {
            throw new \BadMethodCallException('wrong join clause');
        }
        $this->joinClause = $joinClause;
    }

    public function getJoinClause(): string {
        return $this->joinClause;
    }

    public function getQuery() {
        $query = '';

        $query .= 'SELECT ' . implode(' ', $this->getSubVars()) . "\n";

        $where = $this->getWhere();
        $filter = $this->getFilter();
        $query .= "WHERE {\n" . $where . $filter . "}\n";

        $query .= $this->getOrderBy();

        return $query;
    }

    private function getSubVars() {
        $subVars = array();
        foreach ($this->param as $p) {
            $subVars[] .= $p->getSubVar();
        }
        return array_unique($subVars);
    }

    private function getWhere() {
        $where = array();
        foreach ($this->param as $p) {
            if (method_exists($p, 'getWhere')) {
                $where[] = $p->getWhere() . "\n";
            } else {
                $where[] = $p->getJoinClause() . '{ ' . $p->getQuery() . " }\n";
            }
        }
        return implode('', $where);
    }

    private function getFilter() {
        $filter = array();
        foreach ($this->param as $p) {
            if (method_exists($p, 'getFilter')) {
                $f = $p->getFilter();
                if ($f != '') {
                    $filter[] = $f;
                }
            }
        }
        if (count($filter) == 0) {
            return '';
        }
        return 'FILTER (' . implode(' && ', $filter) . ')';
    }

    private function getOrderBy() {
        if (count($this->orderBy) == 0) {
            return '';
        }
        return 'ORDER BY ' . implode($this->orderBy) . "\n";
    }

}
