<?php
namespace Fw\Exception;

use Fw\Exception;

class DbException extends Exception
{
    public $logType = '';
    public $config = null;
    public $sqlCode = null;
    public $preSql = '';
    public $params = [];
    public $rwType = null;
}