<?php

namespace Buuum;

class StructDiff
{
    /**
     * @var string
     */
    protected $struct;
    /**
     * @var string
     */
    protected $struct2;

    /**
     * StructDiff constructor.
     * @param $struct
     * @param $struct2
     */
    public function __construct($struct, $struct2)
    {
        $this->struct = $struct;
        $this->struct2 = $struct2;
    }

    /**
     * @param bool $reverse
     * @return array
     */
    public function getUpdates($reverse = false)
    {
        $differences = [];

        $struct = $this->parseSql($this->struct);
        $struct2 = $this->parseSql($this->struct2);

        if ($reverse) {
            $struct_ = $struct;
            $struct = $struct2;
            $struct2 = $struct_;
        }

        $common = array_intersect($struct['list_tables'], $struct2['list_tables']);
        $create_tables = array_diff($struct['list_tables'], $common);
        $remove_tables = array_diff($struct2['list_tables'], $common);

        if (!empty($create_tables)) {
            foreach ($create_tables as $create_table) {
                $differences[] = $struct['tables'][$create_table]['table'][0];
                if (!empty($struct['tables'][$create_table]['indexes'])) {
                    $differences = array_merge($differences, $struct['tables'][$create_table]['indexes']);
                }
            }
        }

        if (!empty($remove_tables)) {
            foreach ($remove_tables as $remove_table) {
                $differences[] = 'DROP TABLE `' . $remove_table . '`';
            }
        }

        foreach ($common as $comm) {
            $diffs = $this->compareTables($struct['tables'][$comm], $struct2['tables'][$comm], $comm);
            $differences = array_merge($differences, $diffs);
        }

        return $differences;
    }

    /**
     * @param $table1
     * @param $table2
     * @param $table_name
     * @return array
     */
    protected function compareTables($table1, $table2, $table_name)
    {
        $table1_parts = $this->splitTableSql($table1['table'][0]);
        $table2_parts = $this->splitTableSql($table2['table'][0]);

        $checks_table1 = [];
        foreach ($table1_parts as $part) {
            if (!$name = $this->processLine($part)) {
                continue;
            }
            $checks_table1[$name] = $part;
        }

        $checks_table2 = [];
        foreach ($table2_parts as $part) {
            if (!$name = $this->processLine($part)) {
                continue;
            }
            $checks_table2[$name] = $part;
        }

        $table1_keys = array_keys($checks_table1);
        $table2_keys = array_keys($checks_table2);
        $all = array_unique(array_merge($table1_keys, $table2_keys));
        sort($all);

        $diffs = [];
        foreach ($all as $key) {
            $in_table1 = in_array($key, $table1_keys);
            $in_table2 = in_array($key, $table2_keys);
            if (!$in_table2) {
                $diffs[] = $this->getSqlAdd($table_name, $checks_table1[$key]);
            } elseif (!$in_table1) {
                $diffs[] = $this->getSqlDrop($table_name, $checks_table2[$key]);
            } elseif (strcasecmp($this->normalizeString($checks_table1[$key]),
                    $this->normalizeString($checks_table2[$key])) != 0
            ) {
                $diffs[] = $this->getSqlModify($table_name, $checks_table1[$key], $table2['foreigns']);
            }
        }

        return $diffs;
    }

    /**
     * @param $table_name
     * @param $line1
     * @return string
     */
    protected function getSqlDrop($table_name, $line1)
    {
        $result = 'ALTER TABLE `' . $table_name . '` ';
        $keyField = '`?\w`?(?:\(\d+\))?';
        $keyFieldList = '(?:' . $keyField . '(?:,\s?)?)+';
        if (preg_match('/((?:PRIMARY )|(?:UNIQUE )|(?:FULLTEXT ))?KEY `?(\w+)?`?\s(\(' . $keyFieldList . '\))/i',
            $line1,
            $m)) {
            $type = strtolower(trim($m[1]));
            $name = trim($m[2]);

            if ($type == 'primary') {
                $result .= 'DROP PRIMARY KEY';
            } else {
                $result .= 'DROP INDEX `' . $name . '`';
            }

        } elseif (preg_match('/^CONSTRAINT\s`?(\w+)`?/', $line1, $m)) {
            $name = trim($m[1]);
            $result .= 'DROP FOREIGN KEY `' . $name . '`';
        } else {
            $sql = rtrim(trim($line1), ',');
            $result .= 'DROP';
            $result .= ' ' . $sql;
        }
        return $result;
    }

    /**
     * @param $table_name
     * @param $line1
     * @return string
     */
    protected function getSqlAdd($table_name, $line1)
    {
        $result = 'ALTER TABLE `' . $table_name . '` ';
        $keyField = '`?\w`?(?:\(\d+\))?';
        $keyFieldList = '(?:' . $keyField . '(?:,\s?)?)+';
        if (preg_match('/((?:PRIMARY )|(?:UNIQUE )|(?:FULLTEXT ))?KEY `?(\w+)?`?\s(\(' . $keyFieldList . '\))/i',
            $line1,
            $m)) {
            $type = strtolower(trim($m[1]));
            $name = trim($m[2]);
            $fields = trim($m[3]);

            if ($type == 'primary') {
                $result .= 'ADD PRIMARY KEY ' . $fields;
            } elseif ($type == '') {
                $result .= 'ADD INDEX `' . $name . '` ' . $fields;
            } else {
                $result .= 'ADD ' . strtoupper($type) . ' `' . $name . '` ' . $fields;
            }

        } elseif (preg_match('/^CONSTRAINT\s`?(\w+)`?/', $line1, $m)) {
            $result .= 'ADD ' . $line1;
        } else {
            $sql = rtrim(trim($line1), ',');
            $result .= 'ADD';
            $result .= ' ' . $sql;
        }
        return $result;
    }

    /**
     * @param $table_name
     * @param $line1
     * @param $foreigns
     * @return string
     */
    protected function getSqlModify($table_name, $line1, $foreigns)
    {
        $result = 'ALTER TABLE `' . $table_name . '` ';
        $keyField = '`?\w`?(?:\(\d+\))?';
        $keyFieldList = '(?:' . $keyField . '(?:,\s?)?)+';
        if (preg_match('/((?:PRIMARY )|(?:UNIQUE )|(?:FULLTEXT ))?KEY `?(\w+)?`?\s(\(' . $keyFieldList . '\))/i',
            $line1,
            $m)) {
            $type = strtolower(trim($m[1]));
            $name = trim($m[2]);
            $fields = trim($m[3]);

            if ($type == 'primary') {
                $result .= 'DROP PRIMARY KEY, ADD PRIMARY KEY ' . $fields;
            } elseif ($type == '') {
                $result .= 'DROP INDEX `' . $name . '`, ADD INDEX `' . $name . '` ' . $fields;
            } else {
                $result .= 'DROP INDEX `' . $name . '`, ADD ' . strtoupper($type) . ' `' . $name . '` ' . $fields;//fulltext or unique
            }

        } elseif (preg_match('/^CONSTRAINT\s`?(\w+)`?/', $line1, $m)) {
            $name = trim($m[1]);
            $result .= "DROP FOREIGN KEY `{$name}`;\n$result ADD {$line1}";
        } else {
            $sql = rtrim(trim($line1), ',');
            $field = $this->getFieldNameFromLine($line1);
            $foreigns_keys = array_keys($foreigns);
            if (in_array($field, $foreigns_keys)) {
                $result = $this->changeForeign($table_name, $field, $line1, $foreigns[$field]['constraint'],
                    $foreigns[$field]['foreign']);
            } else {
                $result .= 'MODIFY';
                $result .= ' ' . $sql;
            }
        }
        return $result;
    }

    /**
     * @param $line
     * @return bool|mixed|string
     */
    protected function processLine($line)
    {
        if (preg_match('/^(PRIMARY\s+KEY)|(((UNIQUE\s+)|(FULLTEXT\s+))?KEY\s+`?\w+`?)/i', $line, $m)) {
            $key = $m[0];
        } elseif (preg_match('/^CONSTRAINT\s+`?\w+`?/i', $line, $m)) {
            $key = $m[0];
        } elseif (preg_match('/^`?\w+`?/i', $line, $m)) {
            $key = '!' . $m[0];
        } else {
            return false;
        }

        return $this->normalizeString($key);
    }

    /**
     * @param $str
     * @return mixed|string
     */
    protected function normalizeString($str)
    {
        $str = strtolower($str);
        $str = preg_replace('/\s+/', ' ', $str);
        return $str;
    }

    /**
     * @param $sql
     * @return array
     */
    protected function splitTableSql($sql)
    {
        $re = '/CREATE(?:[^\(]*)\((.*)\)/si';
        preg_match($re, $sql, $matches);

        $result = $matches[1];
        $result = explode(',', $result);
        $result = array_map('trim', $result);

        return $result;
    }

    /**
     * @param $struct
     * @return array
     */
    protected function parseSql($struct)
    {
        $info = [];
        $info['list_tables'] = $this->getTableNames($struct);
        $info['tables'] = $this->getTables($struct);

        return $info;
    }

    /**
     * @param $struct
     * @return array
     */
    protected function getTables($struct)
    {
        $re = '/CREATE(?:\s*TEMPORARY)?\s*TABLE\s*(?:IF NOT EXISTS\s*)?(?:`?(\w+)`?\.)?`?(\w+)`?\s*\((?:[^\(]*)(?:[^;]*)\)/si';
        preg_match_all($re, $struct, $matches);
        $regex = '/CREATE\sINDEX\s(.*)/si';
        $tables = [];
        foreach ($matches[0] as $n => $match) {
            preg_match($regex, $match, $index);
            if (!empty($index)) {
                $tables[$matches[2][$n]]['indexes'][] = $match;
            } else {
                $tables[$matches[2][$n]]['table'][] = $match;
                $tables[$matches[2][$n]]['foreigns'] = $this->getForeigns($match);
            }
        }
        return $tables;
    }

    /**
     * @param $table
     * @return array
     */
    protected function getForeigns($table)
    {
        $re = '/CONSTRAINT\s\`?(\w+)\`?\s*FOREIGN\sKEY\s\(\`?([^,]\w+)\`?\)\sREFERENCES\s\`?(\w*)\`?\s*\(\`?(?:\w+)\`?\)\s*(?:[^)]+)/ims';
        preg_match_all($re, $table, $matches);
        if (empty($matches)) {
            return [];
        }
        $foreigns = [];
        foreach ($matches[2] as $n => $match) {
            $constraint = $matches[0][$n];
            if (substr($constraint, -1) == ')') {
                $constraint = substr($constraint, 0, -1);
            }
            $foreigns[$match] = [
                'constraint' => $constraint,
                'foreign'    => $matches[1][$n]
            ];
        }
        return $foreigns;
    }

    /**
     * @param $struct
     * @return array
     */
    protected function getTableNames($struct)
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

    /**
     * @param $line
     * @return bool|string
     */
    protected function getFieldNameFromLine($line)
    {
        $re = '/\`?(\w+)\`?\s/s';
        preg_match($re, $line, $matches);
        if (!empty($matches)) {
            return trim($matches[1]);
        }
        return false;
    }

    /**
     * @param $table
     * @param $field
     * @param $newfield
     * @param $constraint
     * @param $foreign_key
     * @return string
     */
    protected function changeForeign($table, $field, $newfield, $constraint, $foreign_key)
    {
        $sql = "ALTER TABLE `$table` DROP FOREIGN KEY `$foreign_key`;";
        $sql .= "ALTER TABLE `$table` CHANGE COLUMN `$field` $newfield;";
        $sql .= "ALTER TABLE `$table` ADD $constraint";
        return $sql;
    }
}