<?php


namespace tasker\process;


use tasker\Console;
use tasker\exception\ClassNotFoundException;
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
        $this->_status['start_memory']=memory_get_usage();
        $this->_status['start_time']=Op::microtime();
        $this->_status['success_count']=0;
        $this->_status['fail_count']=0;
        $this->_status['except_count']=0;
        $this->_status['fast_speed']=null;
        $this->_status['slow_speed']=null;
        $this->_status['work_time']=0;
        $this->cfg=$cfg;
        $this->setProcessTitle($this->cfg['worker_title']);
        $this->_process_id = posix_getpid();
        Console::log("worker ".$this->_process_id." start success");
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
                $start=Op::microtime();
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
                        //在判断一次
                        if(!class_exists($payload[0]))
                        {
                            throw new ClassNotFoundException($payload[0]);
                        }
                        $class=new $payload[0];
                        if(!method_exists($class,$payload[1]))
                        {
                            throw new ClassNotFoundException($payload[0],$payload[1]);
                        }
                        if(false===call_user_func([(new $payload[0]),$payload[1]],...$payload[2]))
                        {
                            throw new RetryException(json_encode($taster));
                        }
                        //任务标记未成功
                        $db->exce('update ' . $cfg['database']['table'] . ' set endat=' . time() . ' where id=' . $taster['id']);
                    }
                    $db->commit();
                    $this->_status['success_count']++;
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
                        $this->_status['fail_count']++;
                    }
                    else{
                        //记录异常
                        $db->exce('update ' . $cfg['database']['table'] . ' set startat=0,dotimes=99, exception="' . addslashes($e->getMessage()) . '" where id=' . $taster['id']);
                        $this->_status['except_count']++;
                    }
                } finally {
                    $use=Op::microtime()-$start;
                    if(is_null($this->_status['slow_speed']) || $use>$this->_status['slow_speed'])
                    {
                        $this->_status['slow_speed']=$use;
                    }
                    if(is_null($this->_status['fast_speed']) || $use<$this->_status['fast_speed'])
                    {
                        $this->_status['fast_speed']=$use;
                    }
                    $this->_status['work_time']+=$use;
                }
            }
            else{
                //休息0.1秒 防止cpu常用
                $cd=0.1;
                Op::sleep($cd);
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
        Console::log("worker process is stop");
        exit(0);
    }
    protected function status(){
        //统计状态存放到文件
        $_now_time=Op::microtime();
        $process_id=$this->_process_id;
        $memory=Op::memory2M( memory_get_usage()-$this->_status['start_memory']);
        //运行了多少时间
        $runtime=Op::dtime($_now_time-$this->_status['start_time']);
        //最快时间
        $fast_speed=$this->_status['fast_speed']?round(1/$this->_status['fast_speed'],2):0;
        $slow_speed=$this->_status['slow_speed']?round(1/$this->_status['slow_speed'],2):0;
        $success_count=$this->_status['success_count'];
        $fail_count=$this->_status['fail_count'];
        $except_count=$this->_status['except_count'];
        $complete_count=$success_count+$fail_count+$except_count;
        $work_time=Op::dtime($this->_status['work_time']);
        $agv_speed=$complete_count>0?round($complete_count/$this->_status['work_time'],2):0;
        $sleep_time=Op::dtime($_now_time-$this->_status['start_time']-$this->_status['work_time']);
        $data=serialize(compact(
            'process_id',
            'memory',
                'runtime',
                'sleep_time',
                'fail_count',
                'success_count',
                'except_count',
                'fast_speed',
                'slow_speed',
                'agv_speed',
                'work_time'
            )).PHP_EOL;
        file_put_contents('/tmp/status.'.posix_getppid(),$data,FILE_APPEND|LOCK_EX);
    }

}