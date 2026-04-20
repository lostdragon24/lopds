<?php

class EmptyPDOStatement
{
    private $data = [];
    private $fetchMode = PDO::FETCH_ASSOC;

    public function __construct($data = [])
    {
        $this->data = $data;
    }

    public function fetch($mode = null)
    {
        if (empty($this->data)) {
            return false;
        }
        $result = current($this->data);
        next($this->data);

        return $result;
    }

    public function fetchAll($mode = null)
    {
        return $this->data;
    }

    public function fetchColumn($column = 0)
    {
        if (empty($this->data)) {
            return 0;
        }
        $row = current($this->data);
        if (is_array($row) && isset($row[$column])) {
            return $row[$column];
        }

        return 0;
    }

    public function rowCount()
    {
        return count($this->data);
    }

    public function setFetchMode($mode)
    {
        $this->fetchMode = $mode;

        return true;
    }

    public function execute($params = [])
    {
        return true;
    }

    public function bindValue($param, $value, $type = PDO::PARAM_STR)
    {
        return true;
    }
}
