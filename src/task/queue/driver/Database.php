<?php


namespace task\queue\driver;

use task\exception\TaskException;
use task\exception\Exception;
use task\exception\RetryException;
use task\Job;
use task\queue\Driver;
use task\Task;

/**
 * Class Database
 *  数据库驱动队列
 *
 * 抢任务采用事物select for update
 *
 * task
 * 表字段
 * id 主键
 * payload 任务内容 jsonencode格式 {class,method,data}
 * doat 执行时间
 * dotimes 尝试次数
 * startat 任务开始时间 进程抢锁时修改该字段 执行失败还原该字段
 * endat 执行成功时间
 * exception 异常信息
 * @package task\queue
 */

class Database implements Driver
{
    protected $pdo;
    protected $cfg;
    public function __construct($cfg)
    {

        try{
            //连接数据库，选择数据库
            $pdo = new \PDO("mysql:host=".$cfg['host'].":".$cfg['port'].";dbname=".$cfg['db'].";charset=".$cfg['charset'],$cfg['user'],$cfg['pwd']);
        } catch (\PDOException $e){
            //输出异常信息
            throw new Exception('fail to connect db:'.$e->getMessage());
        }

        $this->pdo=$pdo;
        $this->cfg=$cfg;

    }
    public function add($payload,$doat){
        $sql='INSERT INTO '.$this->cfg['table']. '(payload,doat) VALUES(\''.json_encode($payload).'\',\''.$doat.'\')';
        if(false===$this->pdo->exec($sql))
        {
            throw new Exception('sql error:'.$sql);
        }
    }
    public function fire(){
        try {
            //加锁取一条数据
            $stime=msectime();
            $query = 'select count(1) counts from ' . $this->cfg['table'] .
                ' where doat<' . time() . ' and dotimes<' . $this->cfg['retry_count'] .
                ' and startat=0' .
                ' limit 1 ';//sql语句
            $res = $this->pdo->query($query);//准备执行
            if (false === $res) {
                throw new TaskException('sql error:' . $query);
            }
            $count_row = $res->fetch(\PDO::FETCH_ASSOC);
            $count=min($count_row['counts'],20);
            if (empty($count)) {
                //gc

                //没有任务时候休息一秒
                sleep(1);
                return;
            }
            $query = 'select id,payload,dotimes from ' . $this->cfg['table'] .
                ' where doat<' . time() . ' and dotimes<' . $this->cfg['retry_count'] .
                ' and startat=0' .
                ' limit '.mt_rand(0,$count-1).',1 ';//sql语句

            $res = $this->pdo->query($query);//准备执行
            if (false === $res) {
                throw new TaskException('sql error:' . $query);
            }
            $job_row = $res->fetch(\PDO::FETCH_ASSOC);


            $sql = 'update ' . $this->cfg['table'] . ' set startat=' . time() . ',dotimes=dotimes+1 where startat=0 and id=' . $job_row['id'];
            if (false === $num=$this->pdo->exec($sql)) {
                throw new TaskException('sql error:' . $sql);
            }
            if($num!==1)
            {
                return;
            }
            $payload = json_decode($job_row['payload'], true);
//            $this->pdo->commit();
            if(msectime()-$stime>500)
            {
                echo $query."\n".(msectime()-$stime)."\n";
            }
            //执行任务
            try {
                $this->pdo->beginTransaction();
                if (false === (new Job(...$payload))->fire()) {
                    //执行失败
                    throw new RetryException('job fail');
                }
                $this->pdo->commit();
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                if ($e instanceof RetryException) {
                    //重试
                    $sql = 'update ' . $this->cfg['table'] . ' set startat=0 where id=' . $job_row['id'];
                    if (false === $this->pdo->exec($sql)) {
                        throw new TaskException('sql error:' . $sql);
                    }
                } else {
                    $sql = 'update ' . $this->cfg['table'] . ' set endat=' . time() . ',dotimes=99,exception="' . addslashes($e->getMessage()) . '" where id=' . $job_row['id'];
                    if (false === $this->pdo->exec($sql)) {
                        throw new TaskException('sql error:' . $sql);
                    }
                }
                return;
            }
            //标记为成功
            $sql = 'update ' . $this->cfg['table'] . ' set endat=' . time() . ' where id=' . $job_row['id'];
            if (false === $this->pdo->exec($sql)) {
                throw new TaskException('sql error:' . $sql);
            }
        }
        catch (TaskException $e)
        {
            //task调试的异常
            echo $e->getMessage()."\n";
        }
        catch (\Exception $e)
        {
            //其他异常
            var_dump($e->getMessage());
//            var_dump($e);
        }
        return;
    }
}
function msectime()
{
    list($msec, $sec) = explode(' ', microtime());
    $msectime = (float)sprintf('%.0f', (floatval($msec) + floatval($sec)) * 1000);
    return $msectime;
}
