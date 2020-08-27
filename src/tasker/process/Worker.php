<?php


namespace tasker\process;


use tasker\Database;
use tasker\Redis;

class Worker extends Process
{
    protected $cfg;
    public function __construct($cfg)
    {
        $this->cfg=$cfg;
        $this->setProcessTitle($this->cfg['worker_title']);
        $this->_process_id = posix_getpid();
        $this->installSignal();
    }

    public function run(){
        // 模拟调度,实际用event实现
        while (1) {
            // 捕获信号
            pcntl_signal_dispatch();
            $taster=Redis::getInstance($this->cfg['redis'])->lpop($this->cfg['redis']['queue_key']);
            if($taster && $taster=json_decode($taster,true))
            {
                Database::getInstance($this->cfg['database'])->exce('update ' . $this->cfg['database']['table'] . ' set endat=' . time() . ' where id=' . $taster['id']);
            }
        }
    }

    //安装信号
    protected function installSignal(){
        // SIGINT
        pcntl_signal(SIGINT, array($this, 'signalHandler'), false);
        // SIGTERM
        pcntl_signal(SIGTERM, array($this, 'signalHandler'), false);

        // SIGUSR1
        pcntl_signal(SIGUSR1, array($this, 'signalHandler'), false);


        // 忽略信号
        pcntl_signal(SIGUSR2, SIG_IGN, false);
        pcntl_signal(SIGQUIT, SIG_IGN, false);
        pcntl_signal(SIGHUP, SIG_IGN, false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * 信号处理器.
     *
     * @param integer $signal 信号.
     */
    protected function signalHandler($signal)
    {
        switch ($signal) {
            case SIGINT:
            case SIGTERM:
                $this->stop();
                break;
            case SIGUSR1:
                $this->status();
                break;
            default:
                break;
        }
    }
    protected function stop()
    {
        exit(0);
    }
    protected function status(){
        //统计状态存放到文件 todo
    }

}