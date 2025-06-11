<?php
namespace Fw;

interface Db
{
    const RW_TYPE_MASTER = 'm';
    const RW_TYPE_SLAVE = 's';

    public static function getInstance($config, $useBackup = false);

    /**
     * SELECT 字段
     * @param string|array $field 字段
     *      所有字段    '*'
     *      某个字段    'id'
     *      某些字段    'id,name'   ['id', 'name']
     *      有别名的字段(不会自动对有别名的字段增加反引号,需要调用时自行处理)  'id, name AS n'
     *      有聚合函数的字段(不会自动对有聚合函数的字段增加反引号,需要调用时自行处理)  'SUM(amount)'
     * @return $this
     */
    public function select($field = '*');

    /**
     * SELECT COUNT(字段)
     * @param string $field 字段,只能指定一个字段,默认*
     * @param string $alias 字段别名,COUNT()聚合函数的别名,默认别名为total
     * @return $this
     */
    public function selectCount($field = '*', $alias = 'total');

    /**
     * FROM 表名
     * 当需要from多个表的时候,多次调用from()
     * @param string $table 表名
     * @param string $alias 表别名
     * @return $this
     */
    public function from($table, $alias = '');

    /**
     * SELECT DISTINCT
     * @return $this
     */
    public function distinct();

    /**
     * 强制使用主库来执行SQL
     * @return $this
     */
    public function forceMaster();

    /**
     * JOIN 表名 ON 联表条件
     * @param string $table 表名
     * @param string $condition JOIN ON的条件,如:t1.id=t2.id
     *      不会自动对字段增加反引号,且不支持参数化,如果有需要传参的条件可以转化为where条件
     * @param string $alias 表别名
     * @return $this
     */
    public function join($table, $condition, $alias = '');

    /**
     * LEFT JOIN 表名 ON 联表条件
     * @param string $table 表名
     * @param string $condition JOIN ON的条件,如:t1.id=t2.id
     *      不会自动对字段增加反引号,且不支持参数化,如果有需要传参的条件可以转化为where条件
     * @param string $alias 表别名
     * @return $this
     */
    public function leftJoin($table, $condition, $alias = '');

    /**
     * RIGHT JOIN 表名 ON 联表条件
     * @param string $table 表名
     * @param string $condition JOIN ON的条件,如:t1.id=t2.id
     *      不会自动对字段增加反引号,且不支持参数化,如果有需要传参的条件可以转化为where条件
     * @param string $alias 表别名
     * @return $this
     */
    public function rightJoin($table, $condition, $alias = '');

    /**
     * GROUP BY 字段
     * @param string|array $field 字段
     *      某个字段    'id'
     *      某些字段    'id,name'   ['id', 'name']
     * @return $this
     */
    public function groupBy($field);

    /**
     * ORDER BY 字段(升降序)
     * @param string|array $field 字段
     *      某个字段    'id'
     *      某些字段    'id,name'   ['id', 'name DESC'] ['id', 'name' => 'DESC']
     * @param string $type 升降序,不指定或asc/ASC为升序,desc/DESC为降序
     *      若字段中已经表明了升降序就不要在这个参数里指定升降序
     * @return $this
     */
    public function orderBy($field, $type = '');

    /**
     * LIMIT中的count部分
     * @param int $count
     * @return $this
     */
    public function limit($count);

    /**
     * LIMIT中的offset部分
     * @param int $offset
     * @return $this
     */
    public function offset($offset);

    /**
     * 翻页页数
     * @param $page
     * @return $this
     */
    public function page($page);

    /**
     * 翻页中的每页个数
     * @param $count
     * @return $this
     */
    public function count($count);

    /**
     * 【慎用】SELECT语句后面增加for update，用于确保查询时数据不会被修改、删除，会触发行锁或表锁
     * @return $this
     */
    public function forUpdate();

    /**
     * INSERT INTO 表名 (字段) VALUES (...)
     * @param string $table 表名
     * @param array $info 要插入的数据,key为字段名,value为字段对应的值
     * @return $this
     */
    public function insert($table, array $info);

    /**
     * INSERT INTO 表名 (字段) VALUES (...),(...)
     *  可能会有多条insert语句
     * @param string $table 表名
     * @param array $data 要插入的数据数组,数组中的每一个数据都是一行要插入的记录,每个数据的key为字段名,value为字段对应的值
     * @param int $onceMaxCount 一次处理几条数据
     * @return $this
     */
    public function insertBatch($table, array $data, $onceMaxCount = 100);

    /**
     * INSERT IGNORE
     * @return $this
     */
    public function ignore();

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE ...
     * @param string $expr 更新的表达式，如：a=1,b=values(b),c=?
     * @param array $params 表达式中占位符对应的参数
     * @return $this
     */
    public function onDuplicateKeyUpdate($expr, array $params = []);

    /**
     * UPDATE 表名 SET ...
     * @param string $table 表名
     * @param array $info 要更新的数据,key为字段名,value为字段对应的值
     * @return $this
     */
    public function update($table, array $info);

    /**
     * UPDATE 表名 SET ...CASE ... WHEN ... END ...
     *  可能会有多条update语句
     * @param string $table 表名
     * @param array $data 要更新的数据数组,数组中的每一个数据都是一行要更新的记录,每个数据的key为字段名,value为字段对应的值
     * @param string $index 表的主键/唯一索引,该索引需是要更新的数据数组中每个数据都有的key字段名
     * @param int $onceMaxCount 一次处理几条数据
     * @return $this
     */
    public function updateBatch($table, array $data, $index, $onceMaxCount = 100);

    /**
     * REPLACE INTO 表名 (字段) VALUES (...)
     * @param string $table 表名
     * @param array $info 要替换的数据,key为字段名,value为字段对应的值
     * @return $this
     */
    public function replace($table, array $info);

    /**
     * REPLACE INTO 表名 (字段) VALUES (...),(...)
     *  可能会有多条replace语句
     * @param string $table 表名
     * @param array $data 要替换的数据数组,数组中的每一个数据都是一行要替换的记录,每个数据的key为字段名,value为字段对应的值
     * @param int $onceMaxCount 一次处理几条数据
     * @return $this
     */
    public function replaceBatch($table, array $data, $onceMaxCount = 100);

    /**
     * DELETE FROM 表名
     * @param string $table 表名
     * @return $this
     */
    public function delete($table);

    /**
     * WHERE条件,与其他条件之间属于AND关系
     * @param string $field 字段
     *      字段名         'id'
     *      字段名+运算符   'id >' 字段名与运算符用空格分隔,若已指定了$op参数则不能在字段名后拼接运算符
     * @param mixed $value 字段对应的值
     * @param string $op 运算符(=,!=,>,>=,<,<=,IN,NOT IN,LIKE,NOT LIKE,BETWEEN,NOT BETWEEN,IS NULL,IS NOT NULL)
     * @return $this
     */
    public function where($field, $value, $op = '');

    /**
     * WHERE条件,与其他条件之间属于AND关系【特别注意key重复的问题】
     * @param array $conditions where条件,多个条件之间属于AND关系
     *      例如: ['id' => 1, 'id >' => 0]
     * @return $this
     */
    public function multiWhere(array $conditions);

    /**
     * WHERE条件,与其他条件之间属于OR关系
     * @param string $field 字段
     *      字段名         'id'
     *      字段名+运算符   'id >' 字段名与运算符用空格分隔,若已指定了$op参数则不能在字段名后拼接运算符
     * @param mixed $value 字段对应的值
     * @param string $op 运算符(=,!=,>,>=,<,<=,IN,NOT IN,LIKE,NOT LIKE,BETWEEN,NOT BETWEEN,IS NULL,IS NOT NULL)
     * @return $this
     */
    public function orWhere($field, $value, $op = '');

    /**
     * WHERE条件,与其他条件之间属于OR关系【特别注意key重复的问题】
     * @param array $conditions where条件,多个条件之间属于AND关系
     *      例如: ['id' => 1, 'id >' => 0]
     * @return $this
     */
    public function multiOrWhere(array $conditions);

    /**
     * WHERE ... BETWEEN ... AND ...
     *  与其他条件之间属于AND关系
     * @param string $field 字段名
     * @param int|float $start
     * @param int|float $end
     * @return $this
     */
    public function whereBetween($field, $start, $end);

    /**
     * WHERE ... LIKE '%...%'
     *  与其他条件之间属于AND关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function whereLike($field, $value);

    /**
     * WHERE ... LIKE '%...'
     *  与其他条件之间属于AND关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function whereLikeBefore($field, $value);

    /**
     * WHERE ... LIKE '...%'
     *  与其他条件之间属于AND关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function whereLikeAfter($field, $value);

    /**
     * WHERE ... BETWEEN ... AND ...
     *  与其他条件之间属于OR关系
     * @param string $field 字段名
     * @param int|float $start
     * @param int|float $end
     * @return $this
     */
    public function orWhereBetween($field, $start, $end);

    /**
     * WHERE ... LIKE '%...%'
     *  与其他条件之间属于OR关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function orWhereLike($field, $value);

    /**
     * WHERE ... LIKE '%...'
     *  与其他条件之间属于OR关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function orWhereLikeBefore($field, $value);

    /**
     * WHERE ... LIKE '...%'
     *  与其他条件之间属于OR关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function orWhereLikeAfter($field, $value);

    /**
     * WHERE ...
     *  与其他条件之间属于AND关系
     * @param string $where where条件(参数化),例如: id=? AND `type`=1
     * @param array $params where条件中参数对应的值
     * @return $this
     */
    public function whereSql($where, array $params = []);

    /**
     * HAVING条件,与其他条件之间属于AND关系
     * @param string $field 字段
     *      字段名         'id'
     *      字段名+运算符   'id >' 字段名与运算符用空格分隔,若已指定了$op参数则不能在字段名后拼接运算符
     * @param mixed $value 字段对应的值
     * @param string $op 运算符(=,!=,>,>=,<,<=,IN,NOT IN,LIKE,NOT LIKE,BETWEEN,NOT BETWEEN,IS NULL,IS NOT NULL)
     * @return $this
     */
    public function having($field, $value, $op = '');

    /**
     * HAVING条件,与其他条件之间属于AND关系【特别注意key重复的问题】
     * @param array $conditions where条件,多个条件之间属于AND关系
     *      例如: ['id' => 1, 'id >' => 0]
     * @return $this
     */
    public function multiHaving(array $conditions);

    /**
     * HAVING条件,与其他条件之间属于OR关系
     * @param string $field 字段
     *      字段名         'id'
     *      字段名+运算符   'id >' 字段名与运算符用空格分隔,若已指定了$op参数则不能在字段名后拼接运算符
     * @param mixed $value 字段对应的值
     * @param string $op 运算符(=,!=,>,>=,<,<=,IN,NOT IN,LIKE,NOT LIKE,BETWEEN,NOT BETWEEN,IS NULL,IS NOT NULL)
     * @return $this
     */
    public function orHaving($field, $value, $op = '');

    /**
     * HAVING条件,与其他条件之间属于OR关系【特别注意key重复的问题】
     * @param array $conditions where条件,多个条件之间属于AND关系
     *      例如: ['id' => 1, 'id >' => 0]
     * @return $this
     */
    public function multiOrHaving(array $conditions);

    /**
     * HAVING ... BETWEEN ... AND ...
     *  与其他条件之间属于AND关系
     * @param string $field 字段名
     * @param int|float $start
     * @param int|float $end
     * @return $this
     */
    public function havingBetween($field, $start, $end);

    /**
     * HAVING ... LIKE '%...%'
     *  与其他条件之间属于AND关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function havingLike($field, $value);

    /**
     * HAVING ... LIKE '%...'
     *  与其他条件之间属于AND关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function havingLikeBefore($field, $value);

    /**
     * HAVING ... LIKE '...%'
     *  与其他条件之间属于AND关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function havingLikeAfter($field, $value);

    /**
     * HAVING ... BETWEEN ... AND ...
     *  与其他条件之间属于OR关系
     * @param string $field 字段名
     * @param int|float $start
     * @param int|float $end
     * @return $this
     */
    public function orHavingBetween($field, $start, $end);

    /**
     * HAVING ... LIKE '%...%'
     *  与其他条件之间属于OR关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function orHavingLike($field, $value);

    /**
     * HAVING ... LIKE '%...'
     *  与其他条件之间属于OR关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function orHavingLikeBefore($field, $value);

    /**
     * HAVING ... LIKE '...%'
     *  与其他条件之间属于OR关系
     * @param string $field 字段名
     * @param string $value
     * @return $this
     */
    public function orHavingLikeAfter($field, $value);

    /**
     * HAVING ...
     *  与其他条件之间属于AND关系
     * @param string $having having条件(参数化),例如: id=? AND `type`=1
     * @param array $params having条件中参数对应的值
     * @return $this
     */
    public function havingSql($having, array $params = []);

    /**
     * WHERE条件中的左括弧,若前面已有其他where条件,则用AND连接
     * @return $this
     */
    public function beginWhereGroup();

    /**
     * WHERE条件中的左括弧,若前面已有其他where条件,则用OR连接
     * @return $this
     */
    public function beginOrWhereGroup();

    /**
     * WHERE条件中的右括弧
     * @return $this
     */
    public function endWhereGroup();

    /**
     * HAVING条件中的左括弧,若前面已有其他having条件,则用AND连接
     * @return $this
     */
    public function beginHavingGroup();

    /**
     * HAVING条件中的左括弧,若前面已有其他having条件,则用OR连接
     * @return $this
     */
    public function beginOrHavingGroup();

    /**
     * HAVING条件中的右括弧
     * @return $this
     */
    public function endHavingGroup();

    /**
     * 获取拼接参数后的完整SQL语句【调试用,不要在代码中直接使用这个返回结果进行数据库操作】
     * @return $this
     */
    public function getSql();

    /**
     * 获取带问号占位符(参数化)的预处理SQL语句
     * @return $this
     */
    public function getPrepareSql();

    /**
     * 获取预处理SQL语句所需的参数值
     * @return $this
     */
    public function getParams();

    /**
     * 【慎用】执行SQL语句
     * @param string $preSql 带问号占位符(参数化)的预处理SQL语句
     *      当某个占位符对应的参数值是数组类型,则认为是WHERE IN (?)形式
     * @param array $params 预处理SQL语句所需的参数值
     * @param null|string $rwType 指定在主库还是从库执行,默认为null则自动进行读写分离
     * @return bool 执行成功返回true,否则false
     */
    public function _exec($preSql, array $params = [], $rwType = null);

    /**
     * 【慎用】执行SQL语句并返回$this
     * @param string $preSql 带问号占位符(参数化)的预处理SQL语句
     *       当某个占位符对应的参数值是数组类型,则认为是WHERE IN (?)形式
     * @param array $params 预处理SQL语句所需的参数值
     * @param null|string $rwType 指定在主库还是从库执行,默认为null则自动进行读写分离
     * @return $this
     */
    public function _sql($preSql, array $params = [], $rwType = null);

    /**
     * 执行前面通过各种函数构建的SQL语句
     * @return bool 执行成功返回true,否则false
     */
    public function exec();

    /**
     * 执行前面通过各种函数构建的SQL语句,并获取结果集的第一行数据(以字段名为key的关联数组)
     * @return mixed
     */
    public function fetch();

    /**
     * 执行前面通过各种函数构建的SQL语句,并获取结果集数据(二维数组,第二层数据是以字段名为key的关联数组)
     * @return mixed
     */
    public function fetchAll();

    /**
     * 执行前面通过各种函数构建的SQL语句,并返回影响行数
     * @return int|bool SQL执行失败返回false，否则返回影响行数
     */
    public function affectedRows();

    /**
     * 执行前面通过各种函数构建的SQL语句,并返回最后插入ID(自增主键ID)
     * @return string
     */
    public function getLastInsertId();

    /**
     * 开启事务
     * @return bool
     */
    public function beginTrans();

    /**
     * 提交事务
     * @return bool
     */
    public function commitTrans();

    /**
     * 回滚事务
     * @return bool
     */
    public function rollbackTrans();

    public function close();

    public function __destruct();

    public static function setBeforeExecuteCallback(callable $callback);

    public static function setAfterExecuteCallback(callable $callback);
}