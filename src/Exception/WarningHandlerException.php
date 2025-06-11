<?php
namespace Fw\Exception;

use Fw\Exception;

/**
 * 警告类的异常不会导致程序中止,但每一个警告类的异常都会调用一次Error控制器
 */
class WarningHandlerException extends Exception
{

}