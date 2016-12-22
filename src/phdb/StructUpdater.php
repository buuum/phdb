<?php

namespace Buuum;


class StructUpdater
{

    protected $config = [];

    public function __construct($options = [])
    {
        $default_options = [
            'updateTypes'          => 'create, drop, add, remove, modify',
            'varcharDefaultIgnore' => true,
            'intDefaultIgnore'     => true,
            'ignoreIncrement'      => true,
            'forceIfNotExists'     => true,
            'ingoreIfNotExists'    => false
        ];

        $this->config = array_merge($default_options, $options);
    }

    public function getUpdates($last_save_sql, $new_sql)
    {
        $result = [];
        $compRes = $this->compare($last_save_sql, $new_sql);

        if (empty($compRes)) {
            return $result;
        }

        $compRes = $this->filterDiffs($compRes);
        if (empty($compRes)) {
            return $result;
        }
        $result = $this->getDiffSql($compRes, $new_sql);
        return $result;
    }

    protected function compare($last_save_sql, $new_sql)
    {
        $result = [];

        $last_tables = $this->getTableList($last_save_sql);
        $new_tables = $this->getTableList($new_sql);

        $common = array_intersect($new_tables, $last_tables);
        $create_tables = array_diff($new_tables, $common);
        $remove_tables = array_diff($last_tables, $common);
        $all = array_unique(array_merge($new_tables, $last_tables));
        sort($all);
        foreach ($all as $table_name) {

            $info = [
                'create_tables' => false,
                'remove_tables' => false,
                'differs'       => false
            ];

            if (in_array($table_name, $create_tables)) {
                $info['create_tables'] = true;
            } elseif (in_array($table_name, $remove_tables)) {
                $info['remove_tables'] = true;
            } else {
                $new_sql2 = $this->getTableSql($new_sql, $table_name, true);
                $last_save_sql2 = $this->getTableSql($last_save_sql, $table_name, true);

                $diffs = $this->compareSql($last_save_sql2, $new_sql2);

                if ($diffs === false) {
                    trigger_error('[WARNING] error parsing definition of table "' . $table_name . '" - skipped');
                    continue;
                } elseif (!empty($diffs)) {
                    $info['differs'] = $diffs;
                } else {
                    continue;
                }
            }
            $result[$table_name] = $info;
        }
        return $result;
    }


    protected function getTableList($struct)
    {
        $result = [];
        if (preg_match_all('/CREATE(?:\s*TEMPORARY)?\s*TABLE\s*(?:IF NOT EXISTS\s*)?(?:`?(\w+)`?\.)?`?(\w+)`?/i',
            $struct, $m)) {
            foreach ($m[2] as $match) {
                $result[] = $match;
            }
        }
        return $result;
    }

    protected function getTableSql($struct, $table_name, $removeDatabase = true)
    {
        $re = '/CREATE(?:[^\(]*)' . $table_name . '(?:[^\(]*)(?:[^;]*)/si';
        preg_match($re, $struct, $matches);
        return trim($matches[0]);
    }

    //protected function getTableSqlOld($struct, $table_name, $removeDatabase = true)
    //{
    //    $result = '';
    //    $tableDef = '';
    //    $database = false;
    //
    //    if (preg_match('/(CREATE(?:\s*TEMPORARY)?\s*TABLE\s*(?:IF NOT EXISTS\s*)?)(?:`?(\w+)`?\.)?(`?(' . $table_name . ')`?(\W|$))/i',
    //        $struct, $m, PREG_OFFSET_CAPTURE)) {
    //        $tableDef = $m[0][0];
    //        $start = $m[0][1];
    //        $database = $m[2][0];
    //        $offset = $start + strlen($m[0][0]);
    //        $end = $this->getDelimiterPos($struct, $offset);
    //        if ($end === false) {
    //            $result = substr($struct, $start);
    //        } else {
    //            $result = substr($struct, $start, $end - $start);
    //        }
    //    }
    //    $result = trim($result);
    //    if ($database && $removeDatabase) {
    //        $result = str_replace($tableDef, $m[1][0] . $m[3][0], $result);
    //    }
    //    return $result;
    //}

    //protected function getDelimiterPos($string, $offset = 0, $delim = ';', $skipInBrackets = false)
    //{
    //    $stack = [];
    //    $rbs = '\\\\';
    //    $regPrefix = "(?<!$rbs)(?:$rbs{2})*";
    //    $reg = $regPrefix . '("|\')|(/\\*)|(\\*/)|(-- )|(\r\n|\r|\n)|';
    //    if ($skipInBrackets) {
    //        $reg .= '(\(|\))|';
    //    } else {
    //        $reg .= '()';
    //    }
    //    $reg .= '(' . preg_quote($delim) . ')';
    //    while (preg_match('%' . $reg . '%', $string, $m, PREG_OFFSET_CAPTURE, $offset)) {
    //        $offset = $m[0][1] + strlen($m[0][0]);
    //        if (end($stack) == '/*') {
    //            if (!empty($m[3][0])) {
    //                array_pop($stack);
    //            }
    //            continue;
    //        }
    //        if (end($stack) == '-- ') {
    //            if (!empty($m[5][0])) {
    //                array_pop($stack);
    //            }
    //            continue;
    //        }
    //
    //        if (!empty($m[7][0])) {
    //            if (empty($stack)) {
    //                return $m[7][1];
    //            }
    //        }
    //        if (!empty($m[6][0])) {
    //            if (empty($stack) && $m[6][0] == '(') {
    //                array_push($stack, $m[6][0]);
    //            } elseif ($m[6][0] == ')' && end($stack) == '(') {
    //                array_pop($stack);
    //            }
    //        } elseif (!empty($m[1][0])) {
    //            if (end($stack) == $m[1][0]) {
    //                array_pop($stack);
    //            } else {
    //                array_push($stack, $m[1][0]);
    //            }
    //        } elseif (!empty($m[2][0])) {
    //            array_push($stack, $m[2][0]);
    //        } elseif (!empty($m[4][0])) {
    //            array_push($stack, $m[4][0]);
    //        }
    //    }
    //    return false;
    //}

    protected function compareSql($last_save_sql, $new_sql)
    {
        $result = [];

        $sourceParts = $this->splitTableSql($last_save_sql);
        if ($sourceParts === false) {
            trigger_error('[WARNING] error parsing source sql');
            return false;
        }

        $destParts = $this->splitTableSql($new_sql);
        if ($destParts === false) {
            trigger_error('[WARNING] error parsing destination sql');
            return false;
        }

        $sourcePartsIndexed = [];
        $destPartsIndexed = [];
        foreach ($sourceParts as $line) {
            $lineInfo = $this->processLine($line);
            if (!$lineInfo) {
                continue;
            }
            $sourcePartsIndexed[$lineInfo['key']] = $lineInfo['line'];
        }
        foreach ($destParts as $line) {
            $lineInfo = $this->processLine($line);
            if (!$lineInfo) {
                continue;
            }
            $destPartsIndexed[$lineInfo['key']] = $lineInfo['line'];
        }
        $sourceKeys = array_keys($sourcePartsIndexed);
        $destKeys = array_keys($destPartsIndexed);
        $all = array_unique(array_merge($sourceKeys, $destKeys));
        sort($all);//fields first, then indexes - because fields are prefixed with '!'

        foreach ($all as $key) {
            $info = [
                'source' => '',
                'dest'   => ''
            ];
            $inSource = in_array($key, $sourceKeys);
            $inDest = in_array($key, $destKeys);
            $sourceOrphan = $inSource && !$inDest;
            $destOrphan = $inDest && !$inSource;
            $different = $inSource && $inDest &&
                strcasecmp($this->normalizeString($destPartsIndexed[$key]),
                    $this->normalizeString($sourcePartsIndexed[$key]));
            if ($sourceOrphan) {
                $info['source'] = $sourcePartsIndexed[$key];
            } elseif ($destOrphan) {
                $info['dest'] = $destPartsIndexed[$key];
            } elseif ($different) {
                $info['source'] = $sourcePartsIndexed[$key];
                $info['dest'] = $destPartsIndexed[$key];
            } else {
                continue;
            }
            $result[] = $info;
        }
        return $result;
    }

    protected function splitTableSql($sql)
    {
        $re = '/CREATE(?:[^\(]*)\((.*)\)/si';
        preg_match($re, $sql, $matches);

        $result = $matches[1];
        $result = explode(',', $result);
        $result = array_map('trim', $result);

        return $result;
    }

    //protected function splitTableSqlOld($sql)
    //{
    //    $result = [];
    //
    //    $openBracketPos = $this->getDelimiterPos($sql, 0, '(');
    //    if ($openBracketPos === false) {
    //        trigger_error('[WARNING] can not find opening bracket in table definition');
    //        return false;
    //    }
    //    $prefix = substr($sql, 0, $openBracketPos + 1);
    //    $result[] = trim($prefix);
    //    $body = substr($sql, strlen($prefix));
    //
    //    while (($commaPos = $this->getDelimiterPos($body, 0, ',', true)) !== false) {
    //        $part = trim(substr($body, 0, $commaPos + 1));//read another part and shorten $body
    //        if ($part) {
    //            $result[] = $part;
    //        }
    //        $body = substr($body, $commaPos + 1);
    //    }
    //
    //    $closeBracketPos = $this->getDelimiterPos($body, 0, ')');
    //    if ($closeBracketPos === false) {
    //        trigger_error('[WARNING] can not find closing bracket in table definition');
    //        return false;
    //    }
    //
    //    $part = substr($body, 0, $closeBracketPos);
    //    $result[] = trim($part);
    //
    //    $suffix = substr($body, $closeBracketPos);
    //    $suffix = trim($suffix);
    //    if ($suffix) {
    //        $result[] = $suffix;
    //    }
    //    return $result;
    //}

    protected function processLine($line)
    {
        $options = $this->config;
        $result = [
            'key'  => '',
            'line' => ''
        ];
        $line = rtrim(trim($line), ',');
        if (preg_match('/^(CREATE\s+TABLE)|(\) ENGINE=)/i', $line)) {
            return false;
        }

        if (preg_match('/^(PRIMARY\s+KEY)|(((UNIQUE\s+)|(FULLTEXT\s+))?KEY\s+`?\w+`?)/i', $line, $m))//key definition
        {
            $key = $m[0];
        } elseif (preg_match('/^CONSTRAINT\s+`?\w+`?/i', $line, $m)) {
            $key = $m[0];
        } elseif (preg_match('/^`?\w+`?/i', $line, $m)) {
            $key = '!' . $m[0];
        } else {
            return false;
        }

        if (!empty($options['varcharDefaultIgnore'])) {
            $line = preg_replace("/(var)?char\(([0-9]+)\)\s+NOT\s+NULL\s+default\s+''/i", '$1char($2) NOT NULL', $line);
        }
        if (!empty($options['intDefaultIgnore'])) {
            $line = preg_replace("/((?:big)|(?:tiny))?int\(([0-9]+)\)\s+NOT\s+NULL\s+default\s+'0'/i",
                '$1int($2) NOT NULL', $line);
        }
        if (!empty($options['ignoreIncrement'])) {
            $line = preg_replace("/ AUTO_INCREMENT=[0-9]+/i", '', $line);
        }
        $result['key'] = $this->normalizeString($key);
        $result['line'] = $line;

        return $result;
    }

    protected function normalizeString($str)
    {
        $str = strtolower($str);
        $str = preg_replace('/\s+/', ' ', $str);
        return $str;
    }

    protected function filterDiffs($compRes)
    {
        $result = [];
        if (is_array($this->config['updateTypes'])) {
            $updateActions = $this->config['updateTypes'];
        } else {
            $updateActions = array_map('trim', explode(',', $this->config['updateTypes']));
        }
        $allowedActions = ['create', 'drop', 'add', 'remove', 'modify'];
        $updateActions = array_intersect($updateActions, $allowedActions);
        foreach ($compRes as $table => $info) {
            if ($info['create_tables']) {
                if (in_array('create', $updateActions)) {
                    $result[$table] = $info;
                }
            } elseif ($info['remove_tables']) {
                if (in_array('drop', $updateActions)) {
                    $result[$table] = $info;
                }
            } elseif ($info['differs']) {
                $resultInfo = $info;
                unset($resultInfo['differs']);
                foreach ($info['differs'] as $diff) {
                    if (empty($diff['dest']) && in_array('add', $updateActions)) {
                        $resultInfo['differs'][] = $diff;
                    } elseif (empty($diff['source']) && in_array('remove', $updateActions)) {
                        $resultInfo['differs'][] = $diff;
                    } elseif (in_array('modify', $updateActions)) {
                        $resultInfo['differs'][] = $diff;
                    }
                }
                if (!empty($resultInfo['differs'])) {
                    $result[$table] = $resultInfo;
                }
            }
        }
        return $result;
    }

    protected function getDiffSql($diff, $new_sql)
    {
        $sqls = [];
        if (!is_array($diff) || empty($diff)) {
            return $sqls;
        }
        foreach ($diff as $tab => $info) {
            if ($info['remove_tables']) {
                $sqls[] = 'DROP TABLE `' . $tab . '`';
            } elseif ($info['create_tables']) {
                $database = '';
                $destSql = $this->getTableSql($new_sql, $tab, $database);
                if (!empty($this->config['ignoreIncrement'])) {
                    $destSql = preg_replace("/\s*AUTO_INCREMENT=[0-9]+/i", '', $destSql);
                }
                if (!empty($this->config['ingoreIfNotExists'])) {
                    $destSql = preg_replace("/IF NOT EXISTS\s*/i", '', $destSql);
                }
                if (!empty($this->config['forceIfNotExists'])) {
                    $destSql = preg_replace('/(CREATE(?:\s*TEMPORARY)?\s*TABLE\s*)(?:IF\sNOT\sEXISTS\s*)?(`?\w+`?)/i',
                        '$1IF NOT EXISTS $2', $destSql);
                }
                $sqls[] = $destSql;
            } else {
                foreach ($info['differs'] as $finfo) {
                    $inDest = !empty($finfo['dest']);
                    $inSource = !empty($finfo['source']);
                    if ($inSource && !$inDest) {
                        $sql = $finfo['source'];
                        $action = 'drop';
                    } elseif ($inDest && !$inSource) {
                        $sql = $finfo['dest'];
                        $action = 'add';
                    } else {
                        $sql = $finfo['dest'];
                        $action = 'modify';
                    }
                    $sql = $this->getActionSql($action, $tab, $sql);
                    $sqls[] = $sql;
                }
            }
        }
        return $sqls;
    }

    protected function getActionSql($action, $tab, $sql)
    {
        $result = 'ALTER TABLE `' . $tab . '` ';
        $action = strtolower($action);
        $keyField = '`?\w`?(?:\(\d+\))?';
        $keyFieldList = '(?:' . $keyField . '(?:,\s?)?)+';
        if (preg_match('/((?:PRIMARY )|(?:UNIQUE )|(?:FULLTEXT ))?KEY `?(\w+)?`?\s(\(' . $keyFieldList . '\))/i', $sql,
            $m)) {
            $type = strtolower(trim($m[1]));
            $name = trim($m[2]);
            $fields = trim($m[3]);
            switch ($action) {
                case 'drop':
                    if ($type == 'primary') {
                        $result .= 'DROP PRIMARY KEY';
                    } else {
                        $result .= 'DROP INDEX `' . $name . '`';
                    }
                    break;
                case 'add':
                    if ($type == 'primary') {
                        $result .= 'ADD PRIMARY KEY ' . $fields;
                    } elseif ($type == '') {
                        $result .= 'ADD INDEX `' . $name . '` ' . $fields;
                    } else {
                        $result .= 'ADD ' . strtoupper($type) . ' `' . $name . '` ' . $fields;
                    }
                    break;
                case 'modify':
                    if ($type == 'primary') {
                        $result .= 'DROP PRIMARY KEY, ADD PRIMARY KEY ' . $fields;
                    } elseif ($type == '') {
                        $result .= 'DROP INDEX `' . $name . '`, ADD INDEX `' . $name . '` ' . $fields;
                    } else {
                        $result .= 'DROP INDEX `' . $name . '`, ADD ' . strtoupper($type) . ' `' . $name . '` ' . $fields;//fulltext or unique
                    }
                    break;

            }
        } elseif (preg_match('/^CONSTRAINT\s`?(\w+)`?/', $sql, $m)) {

            $name = trim($m[1]);
            switch ($action) {
                case 'drop':
                    $result .= 'DROP FOREIGN KEY `' . $name . '`';
                    break;
                case 'add':
                    $result .= 'ADD ' . $sql;
                    break;
                case 'modify':
                    $result .= "DROP FOREIGN KEY `{$name}`;\n$result ADD {$sql}";
                    break;
            }
        } else {
            $sql = rtrim(trim($sql), ',');
            $result .= strtoupper($action);
            if ($action == 'drop') {
                $spacePos = strpos($sql, ' ');
                $result .= ' ' . substr($sql, 0, $spacePos);
            } else {
                $result .= ' ' . $sql;
            }
        }

        return $result;
    }
}