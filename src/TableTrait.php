<?php

namespace Fw;

use Fw\Db\Mysql;

trait TableTrait
{
    private $_dbInstances;

    protected $dbGroup = '';
    protected $primaryKey = 'id';
    protected $isAutoIncr = true;
    protected $table = '';

    /**
     * @param string $dbGroup
     * @return Mysql
     */
    public function db($dbGroup = '')
    {
        if (!$dbGroup) {
            $dbGroup = $this->dbGroup;
        }
        if (empty($this->_dbInstances[$dbGroup])) {
            $this->_dbInstances[$dbGroup] = Mysql::getInstance(App::getInstance()->env($dbGroup));
        }
        return $this->_dbInstances[$dbGroup];
    }

    public function getTable()
    {
        return $this->table;
    }

    public function insert(array $info)
    {
        if ($this->isAutoIncr) {
            $id = $this->db()->insert($this->table, $info)->getLastInsertId();
            if ($id) {
                return isset($info[$this->primaryKey]) ? $info[$this->primaryKey] : (int)$id;
            }
        } else {
            if ($this->db()->insert($this->table, $info)->exec()) {
                return isset($info[$this->primaryKey]) ? $info[$this->primaryKey] : 0;
            }
        }
        return 0;
    }

    public function update($id, array $info)
    {
        return $this->db()->update($this->table, $info)->where($this->primaryKey, $id)->exec();
    }

    public function delete($id)
    {
        return $this->db()->delete($this->table)->where($this->primaryKey, $id)->exec();
    }

    public function getOne($id, $forceMaster = false)
    {
        if ($forceMaster === true) {
            $this->db()->forceMaster();
        }
        return $this->db()->select()->from($this->table)->where($this->primaryKey, $id)->fetch();
    }

    public function getMulti(array $idArr, $forceMaster = false)
    {
        if ($forceMaster === true) {
            $this->db()->forceMaster();
        }
        return $this->db()->select()->from($this->table)->where($this->primaryKey, $idArr)->fetchAll();
    }

    public function getPageList($where, $page, $count, $orderBy = '', $field = '*', $forceMaster = false)
    {
        if ($forceMaster === true) {
            $this->db()->forceMaster();
        }
        $db = $this->db()->select($field)->from($this->table)->multiWhere($where);
        if ($orderBy) {
            $db = $db->orderBy($orderBy);
        }
        return $db->page($page)->count($count)->fetchAll();
    }

    public function getTotal($where, $field = "*", $forceMaster = false)
    {
        if ($forceMaster === true) {
            $this->db()->forceMaster();
        }
        $one = $this->db()->selectCount($field)->from($this->table)->multiWhere($where)->fetch();
        return $one ? current($one) : 0;
    }

    public function getAll($where, $orderBy = '', $field = '*', $forceMaster = false)
    {
        if ($forceMaster === true) {
            $this->db()->forceMaster();
        }
        $db = $this->db()->select($field)->from($this->table)->multiWhere($where);
        if ($orderBy) {
            $db = $db->orderBy($orderBy);
        }
        return $db->fetchAll();
    }

    public function getRow($where, $orderBy = '', $field = '*', $forceMaster = false)
    {
        if ($forceMaster === true) {
            $this->db()->forceMaster();
        }
        $db = $this->db()->select($field)->from($this->table)->multiWhere($where);
        if ($orderBy) {
            $db = $db->orderBy($orderBy);
        }
        return $db->limit(1)->fetch();
    }

    public function affectedRows()
    {
        return $this->db()->affectedRows();
    }

    public function updateWhere(array $where, array $update)
    {
        return $this->db()->multiWhere($where)->update($this->table, $update)->exec();
    }

    public function insertBatch(array $data)
    {
        return $this->db()->insertBatch($this->table, $data)->exec();
    }

    public function deleteBatch(array $where)
    {
        return $this->db()->multiWhere($where)->delete($this->table)->exec();
    }

    public function updateBatch($data = [], $index="id", $onceMaxCount = 100, $forceString = false)
    {
        return $this->db()->updateBatch($this->table, $data, $index, $onceMaxCount, $forceString)->exec();
    }

    public function beginTrans()
    {
        return $this->db()->beginTrans();
    }

    public function commitTrans()
    {
        return $this->db()->commitTrans();
    }

    public function rollbackTrans()
    {
        return $this->db()->rollbackTrans();
    }
}