<?php


namespace tasker;

class Console{
    /**
     * 输出头部信息
     **/
    public static function hearder(){
        $text= "------------------------- task ------------------------------".PHP_EOL;
        $text.= 'tasker version:' . Tasker::VERSION . "      PHP version:".PHP_VERSION.PHP_EOL;
        $text.= 'start_time:'.date('Y-m-d H:i:s').PHP_EOL;
        self::display($text,false);
    }

    /**
     * 输出指定信息
     * @param string $text 内容
     * @param bool $isClose 输出后是否退出
     */
    public static function display($text,$isClose=true){
        echo $text.PHP_EOL;
        $isClose==true && die;
    }
}