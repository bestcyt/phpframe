<?php

namespace Fw\Db;

use Fw\Db;
use Fw\Exception;
use Fw\LogType;

/**
 * Class Mysql
 * 只支持1主N从，读（从）写（主）分离，事务需要在主库上执行
 *
 * @package Mt\Db
 */
class Mysql implements Db
{
    private static $instances = [];
    private $masterConnectKey;
    private $slaveConnectKey;
    /** @var \PDOStatement $stmt */
    private $stmt;

    private $inTrans = false;
    private $transDepth = 0;
    private $dbConfig = [];

    private $lastErrorCode = 0;
    const ERROR_CODE_DUPLICATE_ENTRY = 1062;

    const SQL_TYPE_SELECT = 1;
    const SQL_TYPE_INSERT = 2;
    const SQL_TYPE_UPDATE = 3;
    const SQL_TYPE_REPLACE = 4;
    const SQL_TYPE_DELETE = 5;
    const SQL_TYPE_INSERT_BATCH = 6;
    const SQL_TYPE_UPDATE_BATCH = 7;
    const SQL_TYPE_REPLACE_BATCH = 8;

    //allowed condition op
    //['=', '>', '>=', '<', '<=', '!=', '<>', 'IN', 'NOT IN', 'LIKE', 'NOT LIKE', 'BETWEEN', 'NOT BETWEEN', 'IS NULL', 'IS NOT NULL'];
    protected $sqlTypesByBatch = [self::SQL_TYPE_INSERT_BATCH, self::SQL_TYPE_UPDATE_BATCH, self::SQL_TYPE_REPLACE_BATCH];

    //↓↓↓↓↓↓每次SQL拼接前都需要reset的属性↓↓↓↓↓↓//
    protected $sqlType = 0;
    private $useDistinct = false;
    private $useIgnore = false;
    //select for update
    private $useForUpdate = false;
    //select count
    private $selectCountSql = '';
    //field
    private $fieldSql = '';
    //table
    private $tableSql = '';
    //join
    private $joinSql = '';
    //where
    private $whereSql = '';
    private $whereParams = [];
    //group by
    private $groupBySql = '';
    //having
    private $havingSql = '';
    private $havingParams = [];
    //order by
    private $orderBySql = '';
    //limit
    private $limit = null;
    private $offset = null;
    private $page = null;
    private $count = null;
    //insert
    private $valuesSql = '';
    //insert batch
    private $valuesSqlArr = [];
    //on duplicate key update
    private $onDuplicateKeyUpdateSql = '';
    private $onDuplicateKeyUpdateParams = [];
    //update
    private $updateSql = '';
    //update batch
    private $updateSqlArr = [];
    private $updateWhereSqlArr = [];
    private $updateWhereParamsArr = [];
    //insert,update
    private $params = [];
    //insert batch,update batch
    private $paramsArr = [];
    //update batch
    private $updateParamsArr = [];

    //上一次的SQL语句(参数)
    private $lastPreSql = null;
    private $lastParams = null;
    //SQL执行后的影响行数
    private $affectedRows = null;
    private $affectedRowsOnce = null;
    //上一次执行插入语句后返回的insert_id
    private $lastInsertId = null;
    //是否强制使用主库
    private $forceMaster = false;
    //↑↑↑↑↑↑每次SQL拼接前都需要reset的属性↑↑↑↑↑↑//


    //连接闲置时间超时重连
    private $connectWaitTimeout = 0; //连接超时时间,单位:秒
    private $connectTimeout = 0; //连接超时时间,单位:秒

    private static $errorLogCallback;
    private $rwType = null; //当前操作的读写类型
    //把要连接的host转成ip后再连接,默认转成ip,在配置中设置host_to_ip为false才不转
    private $hostToIp = true;
    //PDO连接时是否不指定dbname，以便更好的复用，默认指定，在配置中设置dsn_without_dbname为true则开启
    private $dsnWithoutDbname = false;
    //是否开启PDO持久连接
    private $isPersistent = false;

    private static $beforeExecuteCallback = null;
    private static $afterExecuteCallback = null;

    private function callBeforeExecuteCallback()
    {
        if (self::$beforeExecuteCallback && is_callable(self::$beforeExecuteCallback)) {
            call_user_func_array(self::$beforeExecuteCallback, [$this]);
        }
    }

    private function callAfterExecuteCallback()
    {
        if (self::$afterExecuteCallback && is_callable(self::$afterExecuteCallback)) {
            call_user_func_array(self::$afterExecuteCallback, [$this]);
        }
    }

    private function __construct($config = [])
    {
        if ($config) {
            $this->dbConfig = $config;

            if (isset($config['connect_wait_timeout'])) {
                $this->connectWaitTimeout = intval($config['connect_wait_timeout']);
            } elseif (isset($config['connect_max_time'])) {
                //兼容旧版本的配置项
                $this->connectWaitTimeout = intval($config['connect_max_time']);
            }
            if (isset($config['connect_timeout']) && $config['connect_timeout'] > 0) {
                $this->connectTimeout = (int)$config['connect_timeout'];
            }
            if (isset($config['host_to_ip']) && $config['host_to_ip'] == false) {
                $this->hostToIp = false;
            }
            if (isset($config['dsn_without_dbname']) && $config['dsn_without_dbname'] == true) {
                $this->dsnWithoutDbname = true;
            }
            if (isset($config['is_persistent']) && $config['is_persistent'] == true) {
                $this->isPersistent = true;
            }
        }
    }

    private function __clone()
    {
    }

    /**
     * @param array $config
     * @param bool $useBackup
     * @return Mysql
     */
    public static function getInstance($config, $useBackup = false)
    {
        if ($useBackup) {
            if (isset($config['backups'])) {
                $config['slaves'] = $config['backups'];
                unset($config['backups']);
            } elseif (isset($config['backup'])) {
                $config['slaves'][] = $config['backup'];
                unset($config['backup']);
            }
        }
        $key = md5(get_called_class() . ':' . serialize($config));
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new static($config);
        }
        return self::$instances[$key];
    }

    public static function setErrorLog(callable $callback)
    {
        self::$errorLogCallback = $callback;
    }

    private function errorLog(Exception\DbException $e)
    {
        if (!self::$errorLogCallback || !is_callable(self::$errorLogCallback)) {
            $logInfo = [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'host' => isset($e->config['host']) ? $e->config['host'] : '',
                'host_ip' => isset($e->config['host_ip']) ? $e->config['host_ip'] : '',
                'port' => isset($e->config['port']) ? $e->config['port'] : '',
                'dbname' => isset($e->config['dbname']) ? $e->config['dbname'] : '',
                'rw_type' => $e->rwType,
                'pre_sql' => $e->preSql,
            ];
            if ($e->sqlCode != self::ERROR_CODE_DUPLICATE_ENTRY) {
                $logInfo['trace'] = $e->getTraceAsString();
                app_logger()->error($logInfo, $e->logType);
            } else {
                app_logger()->warn($logInfo, $e->logType);
            }
        } else {
            call_user_func_array(self::$errorLogCallback, [$e]);
        }
    }

    private function dealError(\PDOException $pdoException, $logType, $preSql = '', $params = [], $config = [])
    {
        $sqlCode = $pdoException->errorInfo[1];
        $e = new Exception\DbException($pdoException->getMessage(), $sqlCode);
        $e->logType = $logType;
        $e->sqlCode = $sqlCode;
        $e->preSql = $preSql;
        $e->params = $params;
        $e->rwType = $this->rwType;
        if ($config) {
            $e->config = $config;
        }
        $this->errorLog($e);
    }

    private function connect($config)
    {
        $connectKeyParams = [];
        $host = empty($config['host']) ? '' : $config['host'];
        $connectKeyParams['host'] = $host;
        if ($this->hostToIp && empty($config['host_ip'])) {
            $host = gethostbyname($host);
            $config['host_ip'] = $host;
        }
        $port = empty($config['port']) ? 3306 : $config['port'];
        $dbname = empty($config['dbname']) ? '' : $config['dbname'];
        $username = empty($config['username']) ? '' : $config['username'];
        $password = empty($config['password']) ? '' : $config['password'];
        $charset = empty($config['charset']) ? 'utf8' : $config['charset'];
        $connectKeyParams['port'] = $port;
        $connectKeyParams['username'] = $username;
        $connectKeyParams['password'] = $password;
        $connectKeyParams['charset'] = $charset;
        if ($this->dsnWithoutDbname) {
            $dsn = "mysql:host={$host};port={$port}";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname}";
            $connectKeyParams['dbname'] = $dbname;
        }
        $options = array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION);
        if ($this->connectTimeout) {
            $options[\PDO::ATTR_TIMEOUT] = $this->connectTimeout;
        }
        if ($this->isPersistent) {
            $options[\PDO::ATTR_PERSISTENT] = true;
        }
        $connectKeyParams['options'] = $options;
        $connectKey = md5(serialize($connectKeyParams));
        $this->lastErrorCode = 0;
        if (!Pool::getConnectInfo($connectKey)) {
            $pdo = null;
            try {
                $pdo = new \PDO($dsn, $username, $password, $options);
                //fix:mysql5.5 dsn charset不支持utf8mb4
                $pdo->query("SET NAMES '$charset'");
                $connectInfo = new ConnectInfo($pdo, $config);
                if ($this->dsnWithoutDbname) {
                    $connectInfo->setDbname('');
                } else {
                    $connectInfo->setDbname($dbname);
                }
                Pool::setConnectInfo($connectKey, $connectInfo);
            } catch (\PDOException $e) {
                $this->lastErrorCode = $e->errorInfo[1];
                $this->dealError($e, LogType::MYSQL_CONNECT, '', [], $config);
            }
            return $pdo ? $connectKey : '';
        }
        return $connectKey;
    }

    private function reconnect($connectKey)
    {
        //在开启事务时不重连，而是采用旧连接，防止出现同一个事务使用两个不同的连接的情况
        if ($this->inTrans) {
            return true;
        }
        $config = Pool::getConnectInfo($connectKey)->getConfig();
        Pool::deleteConnectInfo($connectKey);
        $this->connect($config);
        $this->selectDb($connectKey);
        return true;
    }

    private function selectDb($connectKey)
    {
        $connectInfo = Pool::getConnectInfo($connectKey);
        $config = $connectInfo->getConfig();
        if ($this->dsnWithoutDbname && isset($config['dbname'])) {
            if (!$connectInfo->getDbname() || $connectInfo->getDbname() != $config['dbname']) {
                try {
                    $sql = 'USE ' . $config['dbname'];
                    $connectInfo->getConn()->query($sql);
                } catch (\PDOException $e) {
                    $this->lastErrorCode = $e->errorInfo[1];
                    $this->dealError($e, LogType::MYSQL_EXEC, $sql, [], $config);
                }
                $connectInfo->setDbname($config['dbname']);
                $connectInfo->keepAlive();
            }
        }
    }

    private function getMaster()
    {
        if (!$this->masterConnectKey) {
            $config = isset($this->dbConfig['master']) ? $this->dbConfig['master'] : [];
            $connectKey = $this->connect($config);
            if ($connectKey) {
                $this->masterConnectKey = $connectKey;
            }
        }
        if ($this->masterConnectKey) {
            $this->selectDb($this->masterConnectKey);
        }
        return $this->masterConnectKey;
    }

    private function getSlave()
    {
        if (!$this->slaveConnectKey) {
            $configArr = isset($this->dbConfig['slaves']) ? $this->dbConfig['slaves'] : [];
            $randKey = array_rand($configArr);
            $config = $configArr[$randKey];
            $connectKey = $this->connect($config);
            if ($connectKey) {
                $this->slaveConnectKey = $connectKey;
            }
        }
        if ($this->slaveConnectKey) {
            $this->selectDb($this->slaveConnectKey);
        }
        return $this->slaveConnectKey;
    }

    private function getConnectKey($rwType = null)
    {
        $connectKey = null;
        if ($this->inTrans || $this->forceMaster || $rwType == self::RW_TYPE_MASTER) {
            $connectKey = $this->getMaster();
            $this->rwType = self::RW_TYPE_MASTER;
        } elseif ($rwType == self::RW_TYPE_SLAVE) {
            $connectKey = $this->getSlave();
            $this->rwType = self::RW_TYPE_SLAVE;
        } else {
            if ($this->slaveConnectKey && Pool::getConnectInfo($this->slaveConnectKey)) {
                $connectKey = $this->slaveConnectKey;
                $this->selectDb($connectKey);
                $this->rwType = self::RW_TYPE_SLAVE;
            } elseif ($this->masterConnectKey && Pool::getConnectInfo($this->masterConnectKey)) {
                $connectKey = $this->masterConnectKey;
                $this->selectDb($connectKey);
                $this->rwType = self::RW_TYPE_MASTER;
            } else {
                $connectKey = $this->getSlave();
                $this->rwType = self::RW_TYPE_SLAVE;
            }
        }
        $connectInfo = null;
        if ($connectKey) {
            $connectInfo = Pool::getConnectInfo($connectKey);
        }
        if ($connectInfo && $this->connectWaitTimeout) {
            $connectAliveTime = $connectInfo->getAliveTime();
            if ($connectAliveTime) {
                if ($connectAliveTime + $this->connectWaitTimeout <= time()) {
                    $this->reconnect($connectKey);
                }
            }
        }
        return $connectKey;
    }

    public function getConnect($rwType = null)
    {
        $connectKey = $this->getConnectKey($rwType);
        $connectInfo = Pool::getConnectInfo($connectKey);
        return $connectInfo ? $connectInfo->getConn() : null;
    }

    private function closeConnects()
    {
        if ($this->stmt instanceof \PDOStatement) {
            $this->stmt->closeCursor();
        }
        if ($this->masterConnectKey) {
            Pool::deleteConnectInfo($this->masterConnectKey);
            $this->masterConnectKey = null;
        }
        if ($this->slaveConnectKey) {
            Pool::deleteConnectInfo($this->slaveConnectKey);
            $this->slaveConnectKey = null;
        }
    }

    private function escapeField($fieldName)
    {
        //`field`   `table`.`field`
        $fieldName = str_replace('`', '', trim($fieldName));
        $pos = strpos($fieldName, '.');
        if ($pos > 0) {
            $table = substr($fieldName, 0, $pos);
            $field = substr($fieldName, $pos + 1);
            return $field == '*' ? '`' . $table . '`.' . $field : '`' . $table . '`.`' . $field . '`';
        } else {
            return $fieldName == '*' ? '*' : '`' . $fieldName . '`';
        }
    }

    private function escapeTable($tableName)
    {
        return '`' . str_replace('`', '', trim($tableName)) . '`';
    }

    private function addSlashesParam($var)
    {
        $str = '';
        if (is_null($var)) {
            $str = '';
        } elseif (is_bool($var)) {
            $str = $var === false ? 0 : 1;
        } elseif (is_int($var) || is_float($var)) {
            $str = $var;
        } elseif (is_array($var)) {
            //用于WHERE IN(),该数组的元素只能是字符串类型或数值类型
            $strIn = '';
            foreach ($var as $item) {
                if (is_int($item) || is_float($item)) {
                    $strIn .= $item . ',';
                } else {
                    $strIn .= '\'' . addslashes($item) . '\',';
                }
            }
            $str = rtrim($strIn, ',');
        } else {
            $str = '\'' . addslashes($var) . '\'';
        }
        return $str;
    }

    protected function isReadSqlType($sql = '')
    {
        if (!$sql && strtoupper(substr(trim($sql), 0, 7)) == 'SELECT ') {
            return true;
        }
        return $this->sqlType == self::SQL_TYPE_SELECT;
    }

    private function resetBefore()
    {
        $this->affectedRows = null;
        $this->affectedRowsOnce = null;
        $this->lastInsertId = null;
    }

    private function resetAfter()
    {
        $this->sqlType = 0;
        $this->useDistinct = false;
        $this->useIgnore = false;
        $this->useForUpdate = false;
        $this->selectCountSql = '';
        $this->fieldSql = '';
        $this->tableSql = '';
        $this->joinSql = '';
        $this->whereSql = '';
        $this->whereParams = [];
        $this->groupBySql = '';
        $this->havingSql = '';
        $this->havingParams = [];
        $this->orderBySql = '';
        $this->offset = null;
        $this->limit = null;
        $this->page = null;
        $this->count = null;
        $this->valuesSql = '';
        $this->valuesSqlArr = [];
        $this->onDuplicateKeyUpdateSql = '';
        $this->onDuplicateKeyUpdateParams = [];
        $this->updateSql = '';
        $this->updateSqlArr = [];
        $this->updateWhereSqlArr = [];
        $this->updateWhereParamsArr = [];
        $this->params = [];
        $this->paramsArr = [];
        $this->updateParamsArr = [];
        $this->forceMaster = false;
        $this->lastPreSql = null;
        $this->lastParams = null;
    }

    public function reset()
    {
        $this->resetBefore();
        $this->resetAfter();
    }


    /**
     * @param string $field 字段名
     *      所有字段:   '*'
     *      某个字段:   'field1'
     *      某些字段:   'field1,field2'
     *      某些字段、表达式、聚合函数:  'field1,count(1)'
     *      通过数组来传递多个字段:    ['field1','field2'] ['field1','count(1) as total']
     * @return $this
     */
    public function select($field = '*')
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_SELECT;
        $fields = [];
        if ($field != '' && $field != '*') {
            if (!is_array($field)) {
                $field = explode(',', $field);
            }
            foreach ($field as $value) {
                $value = trim($value);
                if (strpos($value, ' ') > 0 || strpos($value, '(') !== false || strpos($value, ')') !== false
                    || strpos($value, '->') !== false || strpos($value, '\'') !== false || strpos($value, '"') !== false
                    || strpos($value, '`') !== false) {
                    //不是直接指定表字段名,则不对该字段做过滤处理
                    $fields[] = $value;
                } else {
                    $fields[] = $this->escapeField($value);
                }
            }
        }
        $this->fieldSql = $fields ? implode(',', $fields) : '*';
        return $this;
    }

    public function selectCount($field = '*', $alias = 'total')
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_SELECT;
        //*
        //field
        //field, alias
        if ($field != '*' && strpos(strtolower(trim($field)), "distinct ") !== 0) {
            $field = $this->escapeField($field);
        }
        $this->selectCountSql = 'SELECT COUNT(' . $field . ') `' . $alias . '`';
        return $this;
    }

    public function insert($table, array $info)
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_INSERT;
        $this->tableSql = $this->escapeTable($table);
        $fields = [];
        foreach ($info as $key => $value) {
            $fields[] = $this->escapeField($key);
        }
        $this->fieldSql = implode(',', $fields);
        $this->valuesSql = '(' . rtrim(str_repeat('?,', count($info)), ',') . ')';
        $this->params = array_values($info);
        return $this;
    }

    public function insertBatch($table, array $data, $onceMaxCount = 100)
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_INSERT_BATCH;
        if (!$data) {
            return $this;
        }
        $this->tableSql = $this->escapeTable($table);
        $firstItem = current($data);
        $fields = [];
        $keys = []; //用于保存第一个元素中字段的原始key值
        foreach ($firstItem as $key => $value) {
            $fields[] = $this->escapeField($key);
            $keys[] = $key;
        }
        $this->fieldSql = implode(',', $fields);
        $columnCount = count($firstItem);
        $valuesSegment = '(' . rtrim(str_repeat('?,', $columnCount), ',') . '),';
        $onceMaxCount = $onceMaxCount > 0 ? $onceMaxCount : 500;
        $insertDataOnceArr = array_chunk($data, $onceMaxCount);
        foreach ($insertDataOnceArr as $insertDataOnce) {
            $insertDataCount = count($insertDataOnce);
            $this->valuesSqlArr[] = rtrim(str_repeat($valuesSegment, $insertDataCount), ',');
            $params = [];
            foreach ($insertDataOnce as $item) {
                //为了兼容不同元素的字段key顺序不同，调整为根据第一个元素的key顺序来获取各字段的值
                foreach ($keys as $key) {
                    $val = isset($item[$key]) ? $item[$key] : null;
                    $params[] = $val;
                }
            }
            $this->paramsArr[] = $params;
        }
        return $this;
    }

    public function onDuplicateKeyUpdate($expr, array $params = [])
    {
        $this->onDuplicateKeyUpdateSql = ' ON DUPLICATE KEY UPDATE ' . $expr;
        $this->onDuplicateKeyUpdateParams = $params;
        return $this;
    }

    public function update($table, array $info)
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_UPDATE;
        $this->tableSql = $this->escapeTable($table);
        $this->params = [];
        $updateSegment = [];
        foreach ($info as $key => $value) {
            if (is_int($key)) {
                //如  hitNum=hitNum+1，可以是直接的函数
                $updateSegment[] = $value;
            } else {
                $updateSegment[] = $this->escapeField($key) . ' = ?';
                $this->params[] = $value;
            }
        }
        $this->updateSql = implode(',', $updateSegment);
        return $this;
    }

    public function updateBatch($table, array $data, $index, $onceMaxCount = 100, $forceString = false)
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_UPDATE_BATCH;
        if (!$data) {
            return $this;
        }
        $this->tableSql = $this->escapeTable($table);
        $onceBatchArr = array_chunk($data, $onceMaxCount);
        foreach ($onceBatchArr as $onceBatch) {
            $updateSegment = [];
            $params = [];
            $indexValArr = [];
            $arr = [];
            foreach ($onceBatch as $item) {
                foreach ($item as $key => $val) {
                    if ($key != $index && isset($item[$index])) {
                        $arr[$key][$item[$index]] = $val;
                    }
                }
            }

            $segment = '';
            foreach ($arr as $field => $val) {
                $segment .= $this->escapeField($field) . ' = CASE ' . $this->escapeField($index) . ' ';
                foreach ($val as $indexVal => $fieldVal) {
                    $segment .= 'WHEN ? THEN ? ';
                    $params[] = $forceString ? strval($indexVal) : $indexVal;
                    $params[] = $fieldVal;
                    $indexValArr[] = $forceString ? strval($indexVal) : $indexVal;
                }
                $segment .= 'ELSE ' . $this->escapeField($field) . ' END,';
            }
            $segment = rtrim($segment, ',');
            $updateSegment[] = $segment;

            $this->updateSqlArr[] = implode(',', $updateSegment);
            $indexValArr = array_unique($indexValArr);
            $indexValCount = count($indexValArr);
            $this->updateWhereSqlArr[] = 'WHERE ' . $this->escapeField($index) . ' IN (' . rtrim(str_repeat('?,', $indexValCount), ',') . ')';
            $this->updateWhereParamsArr[] = $indexValArr;
            $this->updateParamsArr[] = $params;
        }
        return $this;
    }

    public function replace($table, array $info)
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_REPLACE;
        $this->tableSql = $this->escapeTable($table);
        $fields = [];
        foreach ($info as $key => $value) {
            $fields[] = $this->escapeField($key);
        }
        $this->fieldSql = implode(',', $fields);
        $this->valuesSql = '(' . rtrim(str_repeat('?,', count($info)), ',') . ')';
        $this->params = array_values($info);
        return $this;
    }

    public function replaceBatch($table, array $data, $onceMaxCount = 100)
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_REPLACE_BATCH;
        if (!$data) {
            return $this;
        }
        $this->tableSql = $this->escapeTable($table);
        $firstItem = current($data);
        $fields = [];
        foreach ($firstItem as $key => $value) {
            $fields[] = $this->escapeField($key);
        }
        $this->fieldSql = implode(',', $fields);
        $columnCount = count($firstItem);
        $valuesSegment = '(' . rtrim(str_repeat('?,', $columnCount), ',') . '),';
        $onceMaxCount = $onceMaxCount > 0 ? $onceMaxCount : 500;
        $insertDataOnceArr = array_chunk($data, $onceMaxCount);
        foreach ($insertDataOnceArr as $insertDataOnce) {
            $insertDataCount = count($insertDataOnce);
            $this->valuesSqlArr[] = rtrim(str_repeat($valuesSegment, $insertDataCount), ',');
            $params = [];
            foreach ($insertDataOnce as $item) {
                foreach ($item as $val) {
                    $params[] = $val;
                }
            }
            $this->paramsArr[] = $params;
        }
        return $this;
    }

    public function delete($table)
    {
        $this->resetBefore();
        $this->sqlType = self::SQL_TYPE_DELETE;
        $this->tableSql = $this->escapeTable($table);
        return $this;
    }

    public function _sql($preSql, array $params = [], $rwType = null)
    {
        $this->resetBefore();
        $this->lastPreSql = $preSql;
        $this->lastParams = $params;
        $this->rwType = $rwType;
        return $this;
    }


    public function from($table, $alias = '')
    {
        //若有多个表,则多次调用
        $table = $this->escapeTable($table);
        if ($alias) {
            $table .= ' ' . $this->escapeTable($alias);
        }
        if ($this->tableSql) {
            $this->tableSql .= ',' . $table;
        } else {
            $this->tableSql = $table;
        }
        return $this;
    }

    public function distinct()
    {
        $this->useDistinct = true;
        return $this;
    }

    public function ignore()
    {
        $this->useIgnore = true;
        return $this;
    }

    public function forceMaster()
    {
        $this->forceMaster = true;
        return $this;
    }


    public function join($table, $condition, $alias = '')
    {
        $table = $this->escapeTable($table);
        if ($alias) {
            $table .= ' ' . $this->escapeTable($alias);
        }
        if ($this->joinSql) {
            $this->joinSql .= ' JOIN ' . $table . ' ON ' . $condition;
        } else {
            $this->joinSql = 'JOIN ' . $table . ' ON ' . $condition;
        }
        return $this;
    }

    public function leftJoin($table, $condition, $alias = '')
    {
        $table = $this->escapeTable($table);
        if ($alias) {
            $table .= ' ' . $this->escapeTable($alias);
        }
        if ($this->joinSql) {
            $this->joinSql .= ' LEFT JOIN ' . $table . ' ON ' . $condition;
        } else {
            $this->joinSql = 'LEFT JOIN ' . $table . ' ON ' . $condition;
        }
        return $this;
    }

    public function rightJoin($table, $condition, $alias = '')
    {
        $table = $this->escapeTable($table);
        if ($alias) {
            $table .= ' ' . $this->escapeTable($alias);
        }
        if ($this->joinSql) {
            $this->joinSql .= ' RIGHT JOIN ' . $table . ' ON ' . $condition;
        } else {
            $this->joinSql = 'RIGHT JOIN ' . $table . ' ON ' . $condition;
        }
        return $this;
    }


    /**
     * @param $field
     * @param $value
     * @param $op
     * @return array
     * @throws Exception
     */
    private function _condition($field, $value, $op = '')
    {
        //$field '字段名' 或者 '字段名 运算符'
        $field = trim($field);
        if ($op == '' && strpos($field, ' ') > 0) {
            $arr = explode(' ', $field, 2);
            $field = isset($arr[0]) ? $arr[0] : '';
            $op = isset($arr[1]) ? strtoupper(trim($arr[1])) : '';
        }
        $field = $this->escapeField($field);
        $conditionSql = '';
        $conditionParams = [];
        if (is_array($value)) {
            if ($op == '') {
                $op = 'IN';
            }
            switch ($op) {
                case 'IN':
                    if (empty($value)) {
                        $value[] = "-99999";
                    }
                    $count = count($value);
                    $conditionSql = $field . ' IN (' . rtrim(str_repeat('?,', $count), ',') . ')';
                    foreach ($value as $item) {
                        $conditionParams[] = $item;
                    }
                    break;
                case 'NOT IN':
                    if (empty($value)) {
                        $value[] = "-99999";
                    }
                    $count = count($value);
                    $conditionSql = $field . ' NOT IN (' . rtrim(str_repeat('?,', $count), ',') . ')';
                    foreach ($value as $item) {
                        $conditionParams[] = $item;
                    }
                    break;
                case 'BETWEEN':
                    $param1 = isset($value[0]) ? $value[0] : '';
                    $param2 = isset($value[1]) ? $value[1] : '';
                    $conditionSql = $field . ' BETWEEN ? AND ?';
                    $conditionParams[] = $param1;
                    $conditionParams[] = $param2;
                    break;
                case 'NOT BETWEEN':
                    $param1 = isset($value[0]) ? $value[0] : '';
                    $param2 = isset($value[1]) ? $value[1] : '';
                    $conditionSql = $field . ' NOT BETWEEN ? AND ?';
                    $conditionParams[] = $param1;
                    $conditionParams[] = $param2;
                    break;
                default:
                    throw new Exception('this op not support an array value');
                    break;
            }
        } else {
            if ($op == '') {
                $op = '=';
            }
            switch ($op) {
                case '=':
                case '!=':
                case '<>':
                case '>':
                case '>=':
                case '<':
                case '<=':
                case 'LIKE':
                case 'NOT LIKE':
                    $conditionSql = $field . ' ' . $op . ' ?';
                    $conditionParams[] = $value;
                    break;
                case 'IS NULL':
                case 'IS NOT NULL':
                    $conditionSql = $field . ' ' . $op;
                    break;
                default:
                    throw new Exception('this op just support an array value');
                    break;
            }
        }
        return [$conditionSql, $conditionParams];
    }

    public function where($field, $value, $op = '')
    {
        //where and
        list($whereSql, $whereParams) = $this->_condition($field, $value, $op);
        if ($this->whereSql) {
            if (substr($this->whereSql, -1) == '(') {
                $this->whereSql .= $whereSql;
            } else {
                $this->whereSql .= ' AND ' . $whereSql;
            }
        } else {
            $this->whereSql = 'WHERE ' . $whereSql;
        }
        foreach ($whereParams as $whereParam) {
            $this->whereParams[] = $whereParam;
        }
        return $this;
    }

    public function multiWhere(array $conditions)
    {
        //多个where and
        foreach ($conditions as $field => $value) {
            if (is_int($field)) {
                $params = [];
                if (is_array($value)) {
                    $params = $value[1];
                    $value = $value[0];
                }
                $this->whereSql($value, $params);
            } else {
                $this->where($field, $value);
            }
        }
        return $this;
    }

    public function orWhere($field, $value, $op = '')
    {
        //where or
        list($whereSql, $whereParams) = $this->_condition($field, $value, $op);
        if ($this->whereSql) {
            if (substr($this->whereSql, -1) == '(') {
                $this->whereSql .= $whereSql;
            } else {
                $this->whereSql .= ' OR ' . $whereSql;
            }
        } else {
            $this->whereSql = 'WHERE ' . $whereSql;
        }
        foreach ($whereParams as $whereParam) {
            $this->whereParams[] = $whereParam;
        }
        return $this;
    }

    public function multiOrWhere(array $conditions)
    {
        //多个where or
        foreach ($conditions as $field => $value) {
            $this->orWhere($field, $value);
        }
        return $this;
    }

    public function whereBetween($field, $start, $end)
    {
        return $this->where($field, [$start, $end], 'BETWEEN');
    }

    public function whereLike($field, $value)
    {
        return $this->where($field, '%' . $this->escapeLike($value) . '%', 'LIKE');
    }

    public function whereLikeBefore($field, $value)
    {
        return $this->where($field, '%' . $this->escapeLike($value), 'LIKE');
    }

    public function whereLikeAfter($field, $value)
    {
        return $this->where($field, $this->escapeLike($value) . '%', 'LIKE');
    }

    public function orWhereBetween($field, $start, $end)
    {
        return $this->orWhere($field, [$start, $end], 'BETWEEN');
    }

    public function orWhereLike($field, $value)
    {
        return $this->orWhere($field, '%' . $this->escapeLike($value) . '%', 'LIKE');
    }

    public function orWhereLikeBefore($field, $value)
    {
        return $this->orWhere($field, '%' . $this->escapeLike($value), 'LIKE');
    }

    public function orWhereLikeAfter($field, $value)
    {
        return $this->orWhere($field, $this->escapeLike($value) . '%', 'LIKE');
    }

    public function whereSql($where, array $params = [])
    {
        if ($this->whereSql) {
            if (substr($this->whereSql, -1) == '(') {
                $this->whereSql .= $where;
            } else {
                $this->whereSql .= ' AND ' . $where;
            }
        } else {
            $this->whereSql = 'WHERE ' . $where;
        }
        foreach ($params as $param) {
            $this->whereParams[] = $param;
        }
        return $this;
    }

    public function having($field, $value, $op = '')
    {
        //having and
        list($havingSql, $havingParams) = $this->_condition($field, $value, $op);
        if ($this->havingSql) {
            if (substr($this->havingSql, -1) == '(') {
                $this->havingSql .= $havingSql;
            } else {
                $this->havingSql .= ' AND ' . $havingSql;
            }
        } else {
            $this->havingSql = 'HAVING ' . $havingSql;
        }
        foreach ($havingParams as $havingParam) {
            $this->havingParams[] = $havingParam;
        }
        return $this;
    }

    public function multiHaving(array $conditions)
    {
        //多个having and
        foreach ($conditions as $field => $value) {
            $this->having($field, $value);
        }
        return $this;
    }

    public function orHaving($field, $value, $op = '')
    {
        //having or
        list($havingSql, $havingParams) = $this->_condition($field, $value, $op);
        if ($this->havingSql) {
            if (substr($this->havingSql, -1) == '(') {
                $this->havingSql .= $havingSql;
            } else {
                $this->havingSql .= ' OR ' . $havingSql;
            }
        } else {
            $this->havingSql = 'HAVING ' . $havingSql;
        }
        foreach ($havingParams as $havingParam) {
            $this->havingParams[] = $havingParam;
        }
        return $this;
    }

    public function multiOrHaving(array $conditions)
    {
        //多个having or
        foreach ($conditions as $field => $value) {
            $this->orHaving($field, $value);
        }
        return $this;
    }

    public function havingBetween($field, $start, $end)
    {
        return $this->having($field, [$start, $end], 'BETWEEN');
    }

    public function havingLike($field, $value)
    {
        return $this->having($field, '%' . $this->escapeLike($value) . '%', 'LIKE');
    }

    public function havingLikeBefore($field, $value)
    {
        return $this->having($field, '%' . $this->escapeLike($value), 'LIKE');
    }

    public function havingLikeAfter($field, $value)
    {
        return $this->having($field, $this->escapeLike($value) . '%', 'LIKE');
    }

    public function orHavingBetween($field, $start, $end)
    {
        return $this->orHaving($field, [$start, $end], 'BETWEEN');
    }

    public function orHavingLike($field, $value)
    {
        return $this->orHaving($field, '%' . $this->escapeLike($value) . '%', 'LIKE');
    }

    public function orHavingLikeBefore($field, $value)
    {
        return $this->orHaving($field, '%' . $this->escapeLike($value), 'LIKE');
    }

    public function orHavingLikeAfter($field, $value)
    {
        return $this->orHaving($field, $this->escapeLike($value) . '%', 'LIKE');
    }

    public function havingSql($having, array $params = [])
    {
        if ($this->havingSql) {
            if (substr($this->havingSql, -1) == '(') {
                $this->havingSql .= $having;
            } else {
                $this->havingSql .= ' AND ' . $having;
            }
        } else {
            $this->havingSql = 'HAVING ' . $having;
        }
        foreach ($params as $param) {
            $this->havingParams[] = $param;
        }
        return $this;
    }

    public function beginWhereGroup()
    {
        if ($this->whereSql) {
            $this->whereSql .= ' AND (';
        } else {
            $this->whereSql = 'WHERE (';
        }
        return $this;
    }

    public function beginOrWhereGroup()
    {
        if ($this->whereSql) {
            $this->whereSql .= ' OR (';
        } else {
            $this->whereSql = 'WHERE (';
        }
        return $this;
    }

    public function endWhereGroup()
    {
        if ($this->whereSql) {
            $this->whereSql .= ')';
        }
        return $this;
    }

    public function beginHavingGroup()
    {
        if ($this->havingSql) {
            $this->havingSql .= ' AND (';
        } else {
            $this->havingSql = 'HAVING (';
        }
        return $this;
    }

    public function beginOrHavingGroup()
    {
        if ($this->havingSql) {
            $this->havingSql .= ' OR (';
        } else {
            $this->havingSql = 'HAVING (';
        }
        return $this;
    }

    public function endHavingGroup()
    {
        if ($this->havingSql) {
            $this->havingSql .= ')';
        }
        return $this;
    }


    public function groupBy($field)
    {
        //field1
        //field1,field2
        //[field1,field2]
        if (!is_array($field)) {
            $field = explode(',', $field);
        }
        $fields = [];
        foreach ($field as $value) {
            $fields[] = $this->escapeField($value);
        }
        if ($this->groupBySql) {
            $this->groupBySql .= ',' . implode(',', $fields);
        } else {
            $this->groupBySql = 'GROUP BY ' . implode(',', $fields);
        }
        return $this;
    }

    public function orderBy($field, $type = '')
    {
        if (!is_array($field)) {
            if ($type) {
                $field .= ' ' . $type;
            }
            $field = explode(',', $field);
        }
        $fields = [];
        foreach ($field as $key => $value) {
            if (is_int($key)) {
                //索引数组
                $arr = explode(' ', $value, 2);
                $orderField = isset($arr[0]) ? $this->escapeField($arr[0]) : '';
                $orderType = isset($arr[1]) && strtoupper($arr[1]) == 'DESC' ? ' DESC' : '';
                $fields[] = $orderField . $orderType;
            } else {
                //关联数组
                $orderField = $this->escapeField($key);
                $orderType = strtoupper($value) == 'DESC' ? ' DESC' : '';
                $fields[] = $orderField . $orderType;
            }
        }
        if ($this->orderBySql) {
            $this->orderBySql .= ',' . implode(',', $fields);
        } else {
            $this->orderBySql = 'ORDER BY ' . implode(',', $fields);
        }
        return $this;
    }

    public function limit($count)
    {
        $this->limit = $count;
        return $this;
    }

    public function offset($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    public function page($page)
    {
        $this->page = $page > 1 ? (int)$page : 1;
        return $this;
    }

    public function count($count)
    {
        $this->count = (int)$count;
        return $this;
    }

    public function forUpdate()
    {
        $this->useForUpdate = true;
        return $this;
    }


    private function getLimitSql()
    {
        $limitSql = '';
        if (!is_null($this->limit)) {
            if (!is_null($this->offset)) {
                $limitSql = 'LIMIT ?,?';
            } else {
                $limitSql = 'LIMIT ?';
            }
        } elseif (!is_null($this->page) && !is_null($this->count)) {
            $offset = $this->page > 1 ? ($this->page - 1) * $this->count : 0;
            $limitSql = 'LIMIT ?,?';
            $this->offset = $offset;
            $this->limit = $this->count;
        }
        return $limitSql;
    }

    private function getLimitParams()
    {
        $limitParams = [];
        if (!is_null($this->limit)) {
            if (!is_null($this->offset)) {
                $limitParams = [$this->offset, $this->limit];
            } else {
                $limitParams = [$this->limit];
            }
        }
        return $limitParams;
    }

    public function getSql()
    {
        $result = null;
        //问号占位符
        if (in_array($this->sqlType, [self::SQL_TYPE_INSERT_BATCH, self::SQL_TYPE_UPDATE_BATCH, self::SQL_TYPE_REPLACE_BATCH])) {
            $preSqlArr = $this->getPrepareSql();
            $paramsArr = $this->getParams();
            $sqlArr = [];
            foreach ($preSqlArr as $key => $preSql) {
                $preSqlSegments = explode('?', $preSql);
                $paramCount = count($preSqlSegments) - 1;
                $sql = $preSqlSegments[0];
                $i = 1;
                foreach ($paramsArr[$key] as $var) {
                    $sql .= $this->addSlashesParam($var) . $preSqlSegments[$i];
                    if ($i >= $paramCount) {
                        break;
                    }
                    $i++;
                }
                $sqlArr[] = $sql;
            }
            $result = $sqlArr;
        } else {
            $preSqlSegments = explode('?', $this->getPrepareSql());
            $paramCount = count($preSqlSegments) - 1;
            $sql = $preSqlSegments[0];
            $i = 1;
            foreach ($this->getParams() as $var) {
                $sql .= $this->addSlashesParam($var) . $preSqlSegments[$i];
                if ($i >= $paramCount) {
                    break;
                }
                $i++;
            }
            $result = $sql;
        }
        $this->resetAfter();
        return $result;
    }

    /**
     * @return string|array
     */
    public function getPrepareSql()
    {
        if (is_null($this->lastPreSql)) {
            switch ($this->sqlType) {
                case self::SQL_TYPE_SELECT:
                    //select ... from ... join ... on ... where ... group by ... having ... order by ... limit ... for update ...
                    if ($this->selectCountSql) {
                        $selectSql = $this->selectCountSql;
                    } else {
                        $selectSql = 'SELECT ' . ($this->useDistinct ? 'DISTINCT ' : '') . $this->fieldSql;
                    }
                    $fromSql = 'FROM ' . $this->tableSql;
                    $sql = $selectSql . ' ' . $fromSql;
                    if ($this->joinSql) {
                        $sql .= ' ' . $this->joinSql;
                    }
                    if ($this->whereSql) {
                        $sql .= ' ' . $this->whereSql;
                    }
                    if ($this->groupBySql) {
                        $sql .= ' ' . $this->groupBySql;
                    }
                    if ($this->havingSql) {
                        $sql .= ' ' . $this->havingSql;
                    }
                    if ($this->orderBySql) {
                        $sql .= ' ' . $this->orderBySql;
                    }
                    $limitSql = $this->getLimitSql();
                    if ($limitSql) {
                        $sql .= ' ' . $limitSql;
                    }
                    if ($this->useForUpdate) {
                        $sql .= ' FOR UPDATE';
                    }
                    $this->lastPreSql = $sql;
                    break;
                case self::SQL_TYPE_INSERT:
                    //insert into ...(...) values (...) on duplicate key update ...
                    $ignoreSql = $this->useIgnore ? 'IGNORE ' : '';
                    $this->lastPreSql = 'INSERT ' . $ignoreSql . 'INTO ' . $this->tableSql . ' (' . $this->fieldSql . ') VALUES ' . $this->valuesSql . $this->onDuplicateKeyUpdateSql;
                    break;
                case self::SQL_TYPE_INSERT_BATCH:
                    //多条insert语句
                    //insert into ...(...) values (...),(...) on duplicate key update ...
                    $ignoreSql = $this->useIgnore ? 'IGNORE ' : '';
                    $sqlArr = [];
                    foreach ($this->valuesSqlArr as $valuesSql) {
                        $sqlArr[] = 'INSERT ' . $ignoreSql . 'INTO ' . $this->tableSql . ' (' . $this->fieldSql . ') VALUES ' . $valuesSql . $this->onDuplicateKeyUpdateSql;
                    }
                    $this->lastPreSql = $sqlArr;
                    break;
                case self::SQL_TYPE_UPDATE:
                    //update ... join ... on ... set ... where ... order by ... limit ...
                    $sql = 'UPDATE ' . $this->tableSql;
                    if ($this->joinSql) {
                        $sql .= ' ' . $this->joinSql;
                    }
                    $sql .= ' SET ' . $this->updateSql;
                    if ($this->whereSql) {
                        $sql .= ' ' . $this->whereSql;
                    }
                    if ($this->orderBySql) {
                        $sql .= ' ' . $this->orderBySql;
                    }
                    $limitSql = $this->getLimitSql();
                    if ($limitSql) {
                        $sql .= ' ' . $limitSql;
                    }
                    $this->lastPreSql = $sql;
                    break;
                case self::SQL_TYPE_UPDATE_BATCH:
                    //update ... join ... on ... set ... = case when ... else ... end, ... where ... order by ... limit ...
                    $sqlArr = [];
                    foreach ($this->updateSqlArr as $key => $updateSql) {
                        $sql = 'UPDATE ' . $this->tableSql;
                        if ($this->joinSql) {
                            $sql .= ' ' . $this->joinSql;
                        }
                        $sql .= ' SET ' . $updateSql . ' ' . $this->updateWhereSqlArr[$key];
                        if ($this->whereSql) {
                            $sql .= ' AND (' . substr($this->whereSql, 6) . ')';
                        }
                        if ($this->orderBySql) {
                            $sql .= ' ' . $this->orderBySql;
                        }
                        $limitSql = $this->getLimitSql();
                        if ($limitSql) {
                            $sql .= ' ' . $limitSql;
                        }
                        $sqlArr[] = $sql;
                    }
                    $this->lastPreSql = $sqlArr;
                    break;
                case self::SQL_TYPE_REPLACE:
                    //replace into ...(...) values (...)
                    $this->lastPreSql = 'REPLACE INTO ' . $this->tableSql . ' (' . $this->fieldSql . ') VALUES ' . $this->valuesSql;
                    break;
                case self::SQL_TYPE_REPLACE_BATCH:
                    //replace into ...(...) values (...),(...)
                    $sqlArr = [];
                    foreach ($this->valuesSqlArr as $valuesSql) {
                        $sqlArr[] = 'REPLACE INTO ' . $this->tableSql . ' (' . $this->fieldSql . ') VALUES ' . $valuesSql;
                    }
                    $this->lastPreSql = $sqlArr;
                    break;
                case self::SQL_TYPE_DELETE:
                    //delete from ... where ... order by ... limit ...
                    $sql = 'DELETE FROM ' . $this->tableSql;
                    if ($this->whereSql) {
                        $sql .= ' ' . $this->whereSql;
                    }
                    if ($this->orderBySql) {
                        $sql .= ' ' . $this->orderBySql;
                    }
                    $limitSql = $this->getLimitSql();
                    if ($limitSql) {
                        $sql .= ' ' . $limitSql;
                    }
                    $this->lastPreSql = $sql;
                    break;
                default:
                    $this->lastPreSql = '';
                    break;
            }
        }
        return $this->lastPreSql;
    }

    public function getParams()
    {
        if (is_null($this->lastParams)) {
            switch ($this->sqlType) {
                case self::SQL_TYPE_SELECT:
                    //select ... from ... where ... group by ... having ... order by ... limit ... for update ...
                    $this->lastParams = array_merge($this->whereParams, $this->havingParams, $this->getLimitParams());
                    break;
                case self::SQL_TYPE_INSERT:
                    //insert into ...(...) values (...)
                    if ($this->onDuplicateKeyUpdateSql && $this->onDuplicateKeyUpdateParams) {
                        $this->lastParams = array_merge($this->params, $this->onDuplicateKeyUpdateParams);
                    } else {
                        $this->lastParams = $this->params;
                    }
                    break;
                case self::SQL_TYPE_INSERT_BATCH:
                    //insert into ...(...) values (...),(...)
                    if ($this->onDuplicateKeyUpdateSql && $this->onDuplicateKeyUpdateParams) {
                        $paramsArr = [];
                        foreach ($this->paramsArr as $params) {
                            $paramsArr[] = array_merge($params, $this->onDuplicateKeyUpdateParams);
                        }
                        $this->lastParams = $paramsArr;
                    } else {
                        $this->lastParams = $this->paramsArr;
                    }
                    break;
                case self::SQL_TYPE_UPDATE:
                    //update ... set ... where ... order by ... limit ...
                    $this->lastParams = array_merge($this->params, $this->whereParams, $this->getLimitParams());
                    break;
                case self::SQL_TYPE_UPDATE_BATCH:
                    //update ... set ... = case when ... else ... end, ... where ... order by ... limit ...
                    $paramsArr = [];
                    foreach ($this->updateParamsArr as $key => $updateParams) {
                        $paramsArr[] = array_merge($updateParams, $this->updateWhereParamsArr[$key], $this->whereParams, $this->getLimitParams());
                    }
                    $this->lastParams = $paramsArr;
                    break;
                case self::SQL_TYPE_REPLACE:
                    //replace into ...(...) values (...)
                    $this->lastParams = $this->params;
                    break;
                case self::SQL_TYPE_REPLACE_BATCH:
                    //replace into ...(...) values (...),(...)
                    $this->lastParams = $this->paramsArr;
                    break;
                case self::SQL_TYPE_DELETE:
                    //delete from ... where ... order by ... limit ...
                    $this->lastParams = array_merge($this->whereParams, $this->getLimitParams());
                    break;
                default:
                    $this->lastParams = [];
                    break;
            }
        }
        return $this->lastParams;
    }


    private function pdoExecute($preSql, array $params = [], $rwType = null)
    {
        $this->callBeforeExecuteCallback();
        $marker = '?';
        $preSqlSegments = explode($marker, $preSql);
        $paramCount = count($preSqlSegments) - 1;
        $actualPreSql = $preSqlSegments[0];
        $actualParams = [];
        $i = 1;
        foreach ($params as $var) {
            if (is_array($var)) {
                //WHERE IN (?)
                $actualPreSql .= rtrim(str_repeat($marker . ',', count($var)), ',') . $preSqlSegments[$i];
                foreach ($var as $_var) {
                    $actualParams[] = $_var;
                }
            } else {
                $actualPreSql .= $marker . $preSqlSegments[$i];
                $actualParams[] = $var;
            }
            if ($i >= $paramCount) {
                break;
            }
            $i++;
        }

        $this->lastErrorCode = 0;
        $connectKey = $this->getConnectKey($rwType);
        $connectInfo = Pool::getConnectInfo($connectKey);
        $conn = $connectInfo ? $connectInfo->getConn() : null;
        $result = false;
        if (!$conn) {
            return false;
        }
        try {
            $this->stmt = $conn->prepare($actualPreSql);

            foreach ($actualParams as $idx => $param) {
                if (is_int($param)) {
                    $paramType = \PDO::PARAM_INT;
                } elseif (is_bool($param)) {
                    $paramType = \PDO::PARAM_BOOL;
                } elseif (is_null($param)) {
                    $paramType = \PDO::PARAM_NULL;
                } else {
                    $paramType = \PDO::PARAM_STR;
                }
                $this->stmt->bindValue($idx + 1, $param, $paramType);
            }

            $result = $this->stmt->execute();
            $this->affectedRowsOnce = $this->stmt->rowCount();
            $connectInfo->keepAlive();
            $this->callAfterExecuteCallback();

        } catch (\PDOException $e) {
            $this->lastErrorCode = $e->errorInfo[1];
            //错误码:1317  ER_QUERY_INTERRUPTED    查询执行被中断
            //错误码:2006  CR_SERVER_GONE_ERROR    MySQL服务器不可用
            //错误码:2013  CR_SERVER_LOST    查询过程中丢失了与MySQL服务器的连接
            if (in_array($this->lastErrorCode, [1317, 2006, 2013])) {
                $this->closeConnects();
                //重连
                $this->lastErrorCode = 0;
                $connectKey = $this->getConnectKey($rwType);
                $connectInfo = Pool::getConnectInfo($connectKey);
                $conn = $connectInfo ? $connectInfo->getConn() : null;
                if (!$conn) {
                    return false;
                }
                try {
                    $this->stmt = $conn->prepare($actualPreSql);

                    foreach ($actualParams as $idx => $param) {
                        if (is_int($param)) {
                            $paramType = \PDO::PARAM_INT;
                        } elseif (is_bool($param)) {
                            $paramType = \PDO::PARAM_BOOL;
                        } elseif (is_null($param)) {
                            $paramType = \PDO::PARAM_NULL;
                        } else {
                            $paramType = \PDO::PARAM_STR;
                        }
                        $this->stmt->bindValue($idx + 1, $param, $paramType);
                    }

                    $result = $this->stmt->execute();
                    $this->affectedRowsOnce = $this->stmt->rowCount();
                    $connectInfo->keepAlive();
                    $this->callAfterExecuteCallback();

                } catch (\PDOException $e) {
                    $this->lastErrorCode = $e->errorInfo[1];
                    $this->dealError($e, LogType::MYSQL_EXEC, $preSql, $params, $connectInfo->getConfig());
                }
            } else {
                $this->dealError($e, LogType::MYSQL_EXEC, $preSql, $params, $connectInfo->getConfig());
            }

            $this->affectedRowsOnce = false;
        }

        return $result;
    }

    public function _exec($preSql, array $params = [], $rwType = null)
    {
        if (!$rwType) {
            $rwType = $this->isReadSqlType($preSql) ? self::RW_TYPE_SLAVE : self::RW_TYPE_MASTER;
        }
        return $this->pdoExecute($preSql, $params, $rwType);
    }

    public function exec()
    {
        //执行"写"的SQL语句
        $rwType = self::RW_TYPE_MASTER;
        $preSqlData = $this->getPrepareSql();
        $paramsData = $this->getParams();
        $this->affectedRows = 0;
        if (in_array($this->sqlType, $this->sqlTypesByBatch)) {
            foreach ($preSqlData as $key => $preSql) {
                if (!$this->pdoExecute($preSql, $paramsData[$key], $rwType)) {
                    $this->affectedRows = $this->affectedRowsOnce;
                    $this->resetAfter();
                    return false;
                }
                if ($this->affectedRowsOnce) {
                    $this->affectedRows += $this->affectedRowsOnce;
                }
            }
            $this->resetAfter();
            return true;
        } else {
            $result = $this->pdoExecute($preSqlData, $paramsData, $rwType);
            $this->affectedRows = $this->affectedRowsOnce;
            $this->resetAfter();
            return $result;
        }
    }

    public function fetch()
    {
        //执行"读"的SQL语句
        //执行失败返回false,查询不到结果返回空数组
        $result = false;
        $execResult = $this->pdoExecute($this->getPrepareSql(), $this->getParams(), self::RW_TYPE_SLAVE);
        if ($execResult && $this->stmt) {
            $result = $this->stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$result) {
                $result = [];
            }
        }
        $this->resetAfter();
        return $result;
    }

    public function fetchAll()
    {
        //执行"读"的SQL语句
        //执行失败返回false,查询不到结果返回空数组
        $result = false;
        $execResult = $this->pdoExecute($this->getPrepareSql(), $this->getParams(), self::RW_TYPE_SLAVE);
        if ($execResult && $this->stmt) {
            $result = $this->stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!$result) {
                $result = [];
            }
        }
        $this->resetAfter();
        return $result;
    }

    public function affectedRows()
    {
        if (is_null($this->affectedRows)) {
            if (is_null($this->affectedRowsOnce)) {
                $this->exec();
            } else {
                $this->affectedRows = $this->affectedRowsOnce;
            }
        }
        return $this->affectedRows === false ? false : (int)$this->affectedRows;
    }

    /**
     * @return string
     */
    public function getLastInsertId()
    {
        //通过判断$this->affectedRowsOnce是否为null来识别是否已执行过SQL
        if (is_null($this->affectedRowsOnce)) {
            $this->exec();
        }
        if ($this->affectedRowsOnce !== false) {
            $connectKey = $this->getConnectKey(self::RW_TYPE_MASTER);
            $connectInfo = Pool::getConnectInfo($connectKey);
            $conn = $connectInfo ? $connectInfo->getConn() : null;
            if ($conn) {
                $this->lastInsertId = $conn->lastInsertId();
            }
        }
        return $this->lastInsertId ? $this->lastInsertId : '0';
    }


    public function beginTrans()
    {
        if (!$this->inTrans) {
            $this->inTrans = true;
        }
        $this->transDepth++;
        if ($this->transDepth > 1) {
            return true;
        }
        $this->lastErrorCode = 0;
        $connectKey = $this->getConnectKey(self::RW_TYPE_MASTER);
        $connectInfo = Pool::getConnectInfo($connectKey);
        $conn = $connectInfo ? $connectInfo->getConn() : null;
        $result = false;
        try {
            if ($conn) {
                $result = $conn->beginTransaction();
                $connectInfo->keepAlive();
            }
        } catch (\PDOException $e) {
            $this->lastErrorCode = $e->errorInfo[1];
            $this->dealError($e, LogType::MYSQL_EXEC, '', [], $connectInfo->getConfig());
        }
        return $result;
    }

    public function commitTrans()
    {
        if (!$this->inTrans) {
            return true;
        }
        if ($this->transDepth > 1) {
            $this->transDepth--;
            return true;
        }
        $this->lastErrorCode = 0;
        $connectKey = $this->getConnectKey(self::RW_TYPE_MASTER);
        $connectInfo = Pool::getConnectInfo($connectKey);
        $conn = $connectInfo ? $connectInfo->getConn() : null;
        $result = false;
        try {
            if ($conn) {
                $result = $conn->commit();
                $connectInfo->keepAlive();
            }
        } catch (\PDOException $e) {
            $this->lastErrorCode = $e->errorInfo[1];
            $this->dealError($e, LogType::MYSQL_EXEC, '', [], $connectInfo->getConfig());
        }
        $this->inTrans = false;
        $this->transDepth = 0;
        return $result;
    }

    public function rollbackTrans()
    {
        if (!$this->inTrans) {
            return true;
        }
        if ($this->transDepth > 1) {
            $this->transDepth--;
            return true;
        }
        $this->lastErrorCode = 0;
        $connectKey = $this->getConnectKey(self::RW_TYPE_MASTER);
        $connectInfo = Pool::getConnectInfo($connectKey);
        $conn = $connectInfo ? $connectInfo->getConn() : null;
        $result = false;
        try {
            if ($conn) {
                $result = $conn->rollBack();
                $connectInfo->keepAlive();
            }
        } catch (\PDOException $e) {
            $this->lastErrorCode = $e->errorInfo[1];
            $this->dealError($e, LogType::MYSQL_EXEC, '', [], $connectInfo->getConfig());
        }
        $this->inTrans = false;
        $this->transDepth = 0;
        return $result;
    }


    public function escapeLike($param)
    {
        return str_replace(array('%', '_'), array('\%', '\_'), $param);
    }

    public function getLastErrorCode()
    {
        return $this->lastErrorCode;
    }

    public function close()
    {
        $this->closeConnects();
    }

    public function __destruct()
    {
        $this->closeConnects();
    }

    public static function setBeforeExecuteCallback(callable $callback)
    {
        self::$beforeExecuteCallback = $callback;
    }

    public static function setAfterExecuteCallback(callable $callback)
    {
        self::$afterExecuteCallback = $callback;
    }


}