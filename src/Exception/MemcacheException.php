<?php
namespace Fw\Exception;

use Fw\Exception;

class MemcacheException extends Exception
{
    public $logType = '';
    public $method = '';
    public $params = [];
    public $config = null;
}