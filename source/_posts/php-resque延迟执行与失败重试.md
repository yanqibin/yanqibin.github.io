---
title: php-resque延迟执行与失败重试
date: 2018-02-22 13:19:35
tags: php
---


一、前言

    使用第三方库进行短信发送等，为了响应速度，失败延迟重试等，决定使用消息队列。

二、 使用 

   php-resque 在网上有不少教程在此便不加以累赘 ，参考地址: [PHP-resque使用经验总结](http://blog.csdn.net/maquealone/article/details/75333349)
 
三、结构分析

   从push与pop方法中可看出，队列中使用redis的List（列表）右侧插入数据，左侧取出数据。
   
``` php

class Resque(){



    	public static function push($queue, $item)
	{
		self::redis()->sadd('queues', $queue);
		self::redis()->rpush('queue:' . $queue, json_encode($item));
	}
	
		public static function pop($queue)
	{
		$item = self::redis()->lpop('queue:' . $queue);
		if(!$item) {
			return;
		}

		return json_decode($item, true);
	}
	
}

```


四、 结构设计

   List结构不能完成延迟执行这一功能，为此新增两个redis key 分别为SortedSet（有序集合） 与Hash（哈希表），
将队列数据放在Hash表中， 将数据的Key 放入 SortedSet 中 其中 到达可执行的时间戳为Sort ，当 Sort 小于当前时间时，则将Hash 表中的数据移动至 List 队列中右侧，排队执行

五、 代码实现

Resque_Job 中


`$this->instance->queue = $this->queue;`


后插入
	
	
```php
//执行次数
$this->instance->try_time = isset($this->payload['try_time'] ) ? $this->payload['try_time'] : 1;
		
```
Resque_Job 中插入3个方法
   
``` php


class Resque_Job
{
    /**
     * 超时执行
     * @param      $queue
     * @param      $class
     * @param null $args
     * @param      $delay
     * @param bool $monitor
     *
     * @return string
     */
	public static  function delay($queue, $class, $args = null, $delay=60,$monitor = false){
        if($args !== null && !is_array($args)) {
            throw new InvalidArgumentException(
                'Supplied $args must be an array.'
            );
        }
        $id = md5(uniqid('', true));

        Resque::later($queue,
            array(
                'class'	=>$class,
                'args'	=> array($args),
                'id'	=>$id,
            ),$delay);



        if($monitor) {
            Resque_Job_Status::create($id);
        }
        return $id;

	}

    /**
     * 重试
     * @param $delay
     */
    public function delayJob($delay)
    {
        $this->payload['try_time'] =( isset($this->payload['try_time'])&& $this->payload['try_time']>1) ? $this->payload['try_time'] : 1;
        $this->payload['try_time']++;

        return Resque::later($this->queue, $this->payload, $delay);
    }

    /**
     *  加载延迟数据
     *  到达延迟时间的 Job 从Hash移动至 List队列
     * @param $queue
     *
     * @return bool
     */
	public static function loadDelay($queue){
               return  Resque::move($queue);
	}
}
```

- reidis 支持

```php
class Resque_Redis extends Redisent {
    private $keyCommands = array(
            ...
    	'zremrangebyscore',
	'sort',
	//插入这3个类型
        'hset',
        'hget',
        'hdel'
    )
}

```

```php
class Resque_RedisCluster extends RedisentCluster{
    private $keyCommands = array(
            ...
    	'zremrangebyscore',
	'sort',
	//插入这3个类型
        'hset',
        'hget',
        'hdel'
    )
}

```


- Resque_Worker 每次判断是否有到达时间的Job
```
class Resque_Worker{
	public function work($interval = 5)
	{
		$this->updateProcLine('Starting');
		$this->startup();
		while(true) {
			if($this->shutdown) {
				break;
			}
			$this->loadDelay();// 加载延迟执行
			
			... 
			}
     }
    
      /**
     * 延迟执行 移动队列位置
     */
	public function loadDelay(){
        $queues = $this->queues();
        if($queues){
        	foreach ($queues as $queue){
                $this->log('Checking Delay' . $queue, self::LOG_VERBOSE);
                 Resque_Job::loadDelay($queue);

	        }
        }

	}
}
```

class Resque 中加入3个方法

```
class Resque{
    
    public static function later($queue,$item,$delay){
        self::redis()->sadd('queues', $queue);
        self::redis()->hset('delays:'.$queue, $item['id'], json_encode($item));
        self::redis()->zadd('delay:'.$queue,time() + $delay, $item['id']);
    }

    /**
     * 从 哈希数据移动到队列
     *
     * @param $queue
     */
    public static function move($queue){
     // 获取前一千个满足条件的数据
      $zDelayKeys =   self::redis()->zrangebyscore('delay:'.$queue,0,time(),'limit',0,1000);

      if($zDelayKeys ){
          foreach ($zDelayKeys as $delayKey){
              $item = self::redis()->hget('delays:'.$queue,$delayKey);
              if ($item) {
                  $delete = self::redis()->hdel('delays:' . $queue, $delayKey);
                  if ($delete > 0) { //可能有多个WORK 以删除为准


                      self::redis()->sadd('queues', $queue);
                      self::redis()->rpush('queue:' . $queue, $item); //无需再json_encode



                      self::redis()->zrem('delay:' . $queue,$delayKey);
                  }
              }else{
                  self::redis()->zrem('delay:' . $queue,$delayKey);
              }
          }
      }
      return true;

    }
    
    
    public static function delay($queue, $class, $args = null,$delayTime = 60, $trackStatus = false)
    {
        require_once dirname(__FILE__) . '/Resque/Job.php';
        $result = Resque_Job::delay($queue, $class, $args, $delayTime,$trackStatus);
        if ($result) {
            Resque_Event::trigger('afterDelay', array(
                'class' => $class,
                'args'  => $args,
                'queue' => $queue,
            ));
        }

        return $result;
    }
    
}

```

- 调用

```
//Job中失败时

//$this->try_time  表示执行次数
if ($this->try_time < 3) {
    $this->job->delayJob(5 * 60); //5分钟后重新执行
}

//其他地方直接调用
Resque::delay($queue, $class, $args,$delayTime,$trackStatus);

```



