<?php
namespace Fw\Config;

interface ConfigInterface
{
    public static function getInstance($path);

    public function get($key);
}