<?php
namespace Fw;

use Fw\Exception\ExitException;

class Response
{
    use InstanceTrait;

    /** @var Controller */
    public $controller = null;

    public function redirect($url)
    {
        header('Location:' . $url);
        $this->stop();
    }

    public function json($data)
    {
        ob_clean();
        header('Content-type:application/json;charset=utf-8');
        //指定JSON_PARTIAL_OUTPUT_ON_ERROR,避免$data中有非utf-8字符导致json编码返回false
        echo json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->stop();
    }

    public function finish()
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    public function stop()
    {
        if ($this->controller && !$this->controller->calledAfterFunction && trim(get_class($this->controller), '\\') != trim(App::getInstance()->getErrorControllerName(), '\\')) {
            $this->controller->calledAfterFunction = true;
            if (method_exists($this->controller, 'after')) {
                $this->controller->after();
            }
        }
        throw new ExitException();
    }
}