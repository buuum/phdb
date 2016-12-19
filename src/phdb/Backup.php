<?php

namespace Buuum;

class Backup
{

    private $host,
        $user,
        $pass,
        $name,
        $link,
        $tables,
        $output = '';

    public function __construct($host, $user, $pass, $name)
    {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->name = $name;
    }

    private function connect()
    {
        $this->link = mysqli_connect($this->host, $this->user, $this->pass);
        mysqli_select_db($this->link, $this->name);
    }

    public function backup($tables = '*', $schema = true, $datos = true)
    {
        $this->connect();
        $this->tables = array();

        if ($tables == '*') {
            $result = mysqli_query($this->link, 'SHOW TABLES');
            while ($row = mysqli_fetch_row($result)) {
                $this->tables[] = $row[0];
            }
        } else {
            $this->tables = is_array($tables) ? $tables : explode(',', $tables);
        }

        foreach ($this->tables as $table) {
            $result = mysqli_query($this->link, "SELECT * FROM $table");
            $num_fields = mysqli_num_fields($result);

            if ($schema) {
                // $this->output .= 'DROP TABLE ' . $table . ';';
                $row2 = mysqli_fetch_row(mysqli_query($this->link, 'SHOW CREATE TABLE ' . $table));
                // $this->output .= "\n\n" . $row2[1] . ";\n\n";

                $tbl = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $row2[1]);
                $this->output .= "\n\n" . $tbl . ";\n\n";
            }

            if ($datos) {
                for ($i = 0; $i < $num_fields; $i++) {
                    while ($row = mysqli_fetch_row($result)) {
                        $this->output .= 'INSERT INTO ' . $table . ' VALUES(';
                        for ($j = 0; $j < $num_fields; $j++) {
                            $row[$j] = addslashes($row[$j]);
                            $row[$j] = str_replace("\n", "\\n", $row[$j]);

                            if (isset($row[$j])) {
                                $this->output .= '"' . $row[$j] . '"';
                            } else {
                                $this->output .= '""';
                            }
                            if ($j < ($num_fields - 1)) {
                                $this->output .= ',';
                            }
                        }
                        $this->output .= ");\n";
                    }
                }
            }

            if ($schema) {
                $this->output .= "\n\n\n";
            }

        }
    }

    public function executeSql($sql)
    {
        return mysqli_multi_query($this->link, $sql);
    }

    public function getSql()
    {
        return $this->output;
    }

    public function save($name, $dir)
    {
        file_put_contents($dir . '/' . $name . '.sql', $this->output);
    }

}
