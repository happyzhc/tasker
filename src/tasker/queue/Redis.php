<?php


namespace tasker\queue;

use tasker\exception\RedisException;
use tasker\traits\Singleton;

class Redis
{
    use Singleton;
    protected $redis;
    private function __construct($cfg)
    {
        //连接数据库，选择数据库
        $this->redis = new \Redis();
        $this->redis->connect($cfg['host'], $cfg['port'], $cfg['timeout']);
        if ('' != $cfg['pwd']) {
            $this->redis->auth($cfg['pwd']);
        }
        if (0 != $cfg['db']) {
            $this->redis->select($cfg['db']);
        }
        if(!empty($cfg['pwd']))
        {
            $this->redis->auth($cfg['pwd']);
        }
        if(false===$this->redis->ping())
        {
            throw new RedisException('ping redis error');
        }
    }
    private function __clone()
    {
    }
    public function __call($method,$arguments){
        return call_user_func([$this->redis,$method],...$arguments);
    }
}