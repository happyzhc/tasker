<?php


namespace tests;


use tasker\Console;
use tasker\Tasker;

class Gc
{
    private static $_instance=null;
    public function gc_test(){
        if(is_null(self::$_instance))
        {
            self::$_instance=mt_rand(0,10);
        }
        Console::log(self::$_instance);
        Tasker::push(__CLASS__,'gc_test');
    }
}