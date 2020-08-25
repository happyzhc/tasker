<?php


namespace task\traits;


trait Singleton
{
    private static $instance;
    public static function getInstance(...$args)
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self(...$args);
        }
        return self::$instance;
    }
}