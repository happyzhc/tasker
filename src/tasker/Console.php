<?php


namespace tasker;

class Console{
    /**
     * 输出头部信息
     **/
    public static function hearder(){
        $text= "------------------------- task ------------------------------".PHP_EOL;
        $text.= 'tasker version:' . Tasker::VERSION . "      PHP version:".PHP_VERSION.PHP_EOL;
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
    public static function log($msg,$isClose=false){
        $text=date('[Y-m-d H:i:s]').$msg;
        return
        self::display($text,$isClose);
    }
}