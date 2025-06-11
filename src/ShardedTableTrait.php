<?php
namespace Fw;

use Fw\Db\Mysql;

trait ShardedTableTrait
{
    private $_dbInstances;

    protected $dbGroup = '';
    protected $primaryKey = 'id';
    protected $isAutoIncr = true;
    protected $table = '';
    protected $shardedCount = 100;
    protected $shardedCountLen = 0;

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

    public function getTable($shardedId)
    {
        if ($this->shardedCountLen <= 0) {
            $this->shardedCountLen = strlen($this->shardedCount);
        }
        return $this->table . '_' . str_pad(crc32($shardedId) % $this->shardedCount, $this->shardedCountLen, '0', STR_PAD_LEFT);
    }

    public function insert($shardedId, array $info)
    {
        if ($this->isAutoIncr) {
            $id = $this->db()->insert($this->getTable($shardedId), $info)->getLastInsertId();
            if ($id) {
                return isset($info[$this->primaryKey]) ? $info[$this->primaryKey] : (int)$id;
            }
        } else {
            if ($this->db()->insert($this->getTable($shardedId), $info)->exec()) {
                return isset($info[$this->primaryKey]) ? $info[$this->primaryKey] : 0;
            }
        }
        return 0;
    }

    public function update($shardedId, $id, array $info)
    {
        return $this->db()->update($this->getTable($shardedId), $info)->where($this->primaryKey, $id)->exec();
    }

    public function delete($shardedId, $id)
    {
        return $this->db()->delete($this->getTable($shardedId))->where($this->primaryKey, $id)->exec();
    }

    public function getOne($shardedId, $id)
    {
        return $this->db()->select()->from($this->getTable($shardedId))->where($this->primaryKey, $id)->fetch();
    }

    public function getMulti($shardedId, array $idArr)
    {
        return $this->db()->select()->from($this->getTable($shardedId))->where($this->primaryKey, $idArr)->fetchAll();
    }
}