<?php
namespace Fw;

trait CounterTrait
{
    use InstanceTrait;

    private $counterInstances;

    //需要在具体的类中赋值的属性
    protected $counterType;
    protected $counterConfig;
    protected $ttl = 0;
    protected $prefixKey;
    protected $fields = [];
    protected $fieldsAbbreviation = []; // [field => field_abbreviation] 定义字段名对应的缩写，减少计数器key的长度

    /**
     * @param string $counterType
     * @param array $config
     * @return Counter\CounterInterface
     */
    protected function getCounter($counterType = '', $config = [])
    {
        if (!$counterType) {
            $counterType = $this->counterType;
        }
        if (!$config) {
            $config = $this->counterConfig;
        }
        $key = md5($counterType . serialize($config));
        if (!isset($this->counterInstances[$key])) {
            if (!$config) {
                $config = [];
            }
            $this->counterInstances[$key] = Counter::getProvider($counterType, $config);
        }
        return $this->counterInstances[$key];
    }

    public function key($id, $field = '')
    {
        $key = $this->prefixKey . $id;
        if ($field) {
            if ($this->fieldsAbbreviation && !empty($this->fieldsAbbreviation[$field])) {
                $field = $this->fieldsAbbreviation[$field];
            }
            $key .= ':' . $field;
        }
        return $key;
    }

    public function get($id, $field = '')
    {
        return $this->getCounter()->get($this->key($id, $field));
    }

    public function set($id, $count, $field = '', $ttl = 0)
    {
        if ($ttl == 0) {
            $ttl = $this->ttl;
        }
        return $this->getCounter()->set($this->key($id, $field), $count, $ttl);
    }

    public function delete($id, $field = '')
    {
        return $this->getCounter()->delete($this->key($id, $field));
    }

    public function getMulti(array $idArr)
    {
        $idArr = array_values($idArr);
        $keys = [];
        foreach ($idArr as $id) {
            $keys[] = $this->key($id);
        }
        $counts = $this->getCounter()->getMulti($keys);
        $result = [];
        if ($counts) {
            $idx = 0;
            foreach ($counts as $count) {
                if (isset($idArr[$idx])) {
                    $result[$idArr[$idx]] = $count;
                }
                $idx++;
            }
        }
        return $result;
    }

    public function deleteMulti(array $idArr)
    {
        $keys = [];
        foreach ($idArr as $id) {
            $keys[] = $this->key($id);
        }
        return $this->getCounter()->deleteMulti($keys);
    }

    public function incr($id, $step = 1, $field = '', $ttl = 0)
    {
        if ($ttl == 0) {
            $ttl = $this->ttl;
        }
        $key = $this->key($id, $field);
        return $this->getCounter()->incr($key, $step, $ttl);
    }

    public function decr($id, $step = 1, $field = '', $ttl = 0)
    {
        if ($ttl == 0) {
            $ttl = $this->ttl;
        }
        $key = $this->key($id, $field);
        return $this->getCounter()->decr($key, $step, $ttl);
    }

    public function getOneCounts($id, $fields = [])
    {
        $fields = array_values($fields);
        if (!$fields) {
            $fields = $this->fields;
        }
        $keys = [];
        foreach ($fields as $field) {
            $keys[] = $this->key($id, $field);
        }
        $counts = $this->getCounter()->getMulti($keys);
        $result = [];
        if ($counts) {
            $idx = 0;
            foreach ($counts as $count) {
                $field = isset($fields[$idx]) ? $fields[$idx] : '';
                if ($field) {
                    $result[$field] = $count;
                }
                $idx++;
            }
        }
        return $result;
    }

    public function getMultiCounts(array $idArr, $fields = [])
    {
        $idArr = array_values($idArr);
        $fields = array_values($fields);
        if (!$fields) {
            $fields = $this->fields;
        }
        $keys = [];
        foreach ($idArr as $id) {
            foreach ($fields as $field) {
                $keys[] = $this->key($id, $field);
            }
        }
        $counts = $this->getCounter()->getMulti($keys);
        $result = [];
        if ($counts) {
            $fieldCount = count($fields);
            $idIndex = 0;
            $fieldIndex = 0;
            $idx = 0;
            foreach ($counts as $count) {
                $idKey = isset($idArr[$idIndex]) ? $idArr[$idIndex] : '';
                $fieldKey = isset($fields[$fieldIndex]) ? $fields[$fieldIndex] : '';
                if ($idKey && $fieldKey) {
                    $result[$idKey][$fieldKey] = $count;
                }
                if (($idx + 1) % $fieldCount == 0) {
                    $idIndex++;
                    $fieldIndex = 0;
                } else {
                    $fieldIndex++;
                }
                $idx++;
            }
        }
        return $result;
    }

    public function deleteOneCounts($id, $fields = [])
    {
        if (!$fields) {
            $fields = $this->fields;
        }
        $keys = [];
        foreach ($fields as $field) {
            $keys[] = $this->key($id, $field);
        }
        return $this->getCounter()->deleteMulti($keys);
    }

    public function deleteMultiCounts(array $idArr, $fields = [])
    {
        if (!$fields) {
            $fields = $this->fields;
        }
        $keys = [];
        foreach ($idArr as $id) {
            foreach ($fields as $field) {
                $keys[] = $this->key($id, $field);
            }
        }
        return $this->getCounter()->deleteMulti($keys);
    }
}