<?php


namespace tasker;
/**
 * Class Op
 * 一些优化函数
 * @package tasker
 */

class Op
{
    /**
     * 优化sleep 调用usleep 参数为秒
     * @param $sec
     */
    public static function sleep($sec){
        usleep(intval(floatval($sec)*1000000));
    }
}