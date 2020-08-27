<?php


namespace tasker;


use tasker\exception\Exception;
use tasker\queue\Driver;
use tasker\traits\Singleton;

class Queue
{
    use Singleton;
    public static function listen($cfg){
        //worker循环执行这个方法
        $instance=self::getInstance($cfg);
        /**@var $instance Queue*/
        $instance->driver->fire();
    }
    /**@var  $driver Driver */
    protected $driver=null;
    private function __construct($cfg)
    {
        $driver="\\task\\queue\\driver\\".ucfirst($cfg['queue_type']);
        if(!class_exists($driver))
        {
            throw new Exception('driver '.$driver.' not found');
        }
        $this->driver=new $driver($cfg);
    }
    private function __clone()
    {
    }
    public function add($payload,$doat=0){
        $instance=self::getInstance();
        $instance->driver->add($payload,$doat);
    }
}