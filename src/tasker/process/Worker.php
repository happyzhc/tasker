<?php


namespace tasker\process;


use tasker\Op;
use tasker\queue\Database;
use tasker\exception\RetryException;
use tasker\queue\Redis;

class Worker extends Process
{
    protected $cfg;
    private $_status=[];
    public function __construct($cfg)
    {
        $this->_status['memory']=memory_get_usage();
        $this->_status['time']=time();
        $this->cfg=$cfg;
        $this->setProcessTitle($this->cfg['worker_title']);
        $this->_process_id = posix_getpid();
        $this->installSignal();
        if($this->cfg['tasker_user'])
        {
            $this->setUser($this->cfg['tasker_user']);
        }
    }

    public function run(){
        // 模拟调度,实际用event实现
        while (1) {
            // 捕获信号
            pcntl_signal_dispatch();
            $cfg=$this->cfg;
            $redis=Redis::getInstance($cfg['redis']);
            $db=Database::getInstance($cfg['database']);
            $taster=$redis->lpop($cfg['redis']['queue_key']);
            if($taster && $taster=json_decode($taster,true))
            {
                $db->beginTransaction();
                try{
                    $jobs=$db->query('select id,payload,dotimes from ' . $cfg['database']['table'] .
                        ' where id='.$taster['id'].' limit 1');
                    $job=$jobs[0];
                    if($job['dotimes']>=$cfg['retry_count'] )
                    {
                        $db->exce('update ' .
                            $cfg['database']['table'] . ' set 
                            doat=0,startat=0 where id ='.$taster['id']);
                    }
                    else{
                        $payload=json_decode($taster['payload'],true);
                        if(false===call_user_func([(new $payload[0]),$payload[1]],...$payload[2]))
                        {
                            throw new RetryException(json_encode($taster));
                        }
                        //任务标记未成功
                        $db->exce('update ' . $cfg['database']['table'] . ' set endat=' . time() . ' where id=' . $taster['id']);
                    }
                    $db->commit();
                }
                catch (\Exception $e)
                {
                    $db->rollBack();
                    if($e instanceof RetryException)
                    {
                        //重新放入队列
                        $db->exce('update ' .
                            $cfg['database']['table'] . ' set
                            dotimes=dotimes+1 where id ='.$taster['id']);
                        $redis->lpush($cfg['redis']['queue_key'],$e->getMessage());
                    }
                    else{
                        //记录异常
                        $db->exce('update ' . $cfg['database']['table'] . ' set startat=0,dotimes=99, exception="' . addslashes($e->getMessage()) . '" where id=' . $taster['id']);
                    }
                }
            }
            else{
                //休息0.1秒 防止cpu常用
                Op::sleep(0.1);
                if(false===$db->ping())
                {
                    Database::free();
                }
                if(false===$redis->ping())
                {
                    Redis::free();
                }
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
        pcntl_signal(SIGHUP, SIG_IGN, false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);
        pcntl_signal(SIGQUIT, SIG_IGN, false);
        pcntl_signal(SIGCHLD, SIG_IGN, false);
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
        //统计状态存放到文件
        $process_id=$this->_process_id;
        $memory= memory_get_usage()-$this->_status['memory'];
        $memory=round($memory/1024/1024, 2).'M';
        //运行了多少时间
        $runtime=time()-$this->_status['time'];
        $data=json_encode(compact('process_id','memory','runtime')).PHP_EOL;
        file_put_contents('/tmp/status.'.posix_getppid(),$data,FILE_APPEND|LOCK_EX);
    }

}