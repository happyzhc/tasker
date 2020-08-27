<?php


namespace tasker;


use tasker\exception\Exception;

final class Job
{
    private $caller;
    public function __construct($class_name,$method_name,$param)
    {
        if(!class_exists($class_name))
        {
            throw new Exception('class '.$class_name.' not found');
        }
        //反射判断方法 参数是否正确

        $callback=[new $class_name,$method_name];
        $this->caller=compact('callback','param');

    }
    public function fire()
    {
        return call_user_func($this->caller['callback'],...$this->caller['param']);
    }

}