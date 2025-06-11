<?php
namespace Fw;

/**
 * Class Controller
 *
 * @package Mt
 * @property Request $request
 * @property Response $response
 */
class Controller
{
    protected $request;
    protected $response;

    public $calledAfterFunction = false;

    public function __construct()
    {
        $this->request = Request::getInstance();
        $this->response = Response::getInstance();
        $this->response->controller = $this;
    }
}