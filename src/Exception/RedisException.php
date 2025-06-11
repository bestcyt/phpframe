<?php
namespace Fw\Exception;

use Fw\Exception;

class RedisException extends Exception
{
    public $logType = '';
    public $method = '';
    public $params = [];
    public $config = null;
    public $rwType = null;
}