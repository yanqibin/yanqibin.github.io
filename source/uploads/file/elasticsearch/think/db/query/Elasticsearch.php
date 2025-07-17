<?php

namespace think\db\query;

use think\Collection;
use think\Container;
use think\Db;

use think\db\exception\BindParamException;
use think\db\Query;
use think\exception\DbException;
use think\exception\PDOException;

class Elasticsearch extends Query
{
    /**
     * 当前数据库连接对象
     * @var \think\db\connector\Elasticsearch
     */
    protected $connection;

    public function select($data = null)
    {

        return parent::select($data);
    }

    public function count($field = '*')
    {

        return $this->connection->count($this);
    }

    /**
     * 支持
     * ['expect_fee','confirm_fee','adjustment_fee']
     *
     * expect_fee
     * @param string|array $field
     *
     * @return float
     */
    public function sum($field = '*')
    {

        return $this->connection->sum($this,$field);
    }

    /**
     *
     * @return float
     */
    public function firstScroll($scroll_time)
    {

        return $this->connection->firstScroll($this,$scroll_time);
    }

    /**
     *
     * @return float
     */
    public function scroll($scroll_id,$scroll_time)
    {

        return $this->connection->scroll($this,$scroll_id,$scroll_time);
    }

    /**
     *
     * @return float
     */
    public function clearScroll($scroll_id)
    {

        return $this->connection->clearScroll($this,$scroll_id);
    }

    /**
     * 执行查询 返回数据集  (之前调用 ES 的查询)
     *
     *
     *      Db::connect('elasticsearch')->name('finance_bill_income')->query(json_decode('{"query":{"bool":{"must":[{"range":{"gmt_effect":{"gte":"2020-02-15T01:43:12+08:00","lte":"2020-02-15T01:43:12+08:00"}}}]}}}',true));
     *
     * @access public
     * @param  string      $sql    sql指令
     * @param  array       $bind   参数绑定
     * @param  boolean     $master 是否在主服务器读操作
     * @param  bool        $pdo    是否返回PDO对象
     * @return mixed
     * @throws BindParamException
     * @throws PDOException
     */
    public function query($sql, $bind = [], $master = false, $pdo = false)
    {
        return $this->connection->queryByParam($this,$sql);
    }

    public function column($field, $key = '')
    {
        $this->parseOptions();
        return $this->connection->column($this,$field, $key);

    }

    /**
     * 分批数据返回处理
     * @access public
     * @param  integer      $count    每次处理的数据数量
     * @param  callable     $callback 处理回调方法
     * @param  string|array $column   分批处理的字段名
     * @param  string       $order    字段排序
     * @return boolean
     * @throws DbException
     */
    public function chunk($count, $callback, $column = null, $order = 'asc')
    {
        $options = $this->getOptions();
        $column  = $column ?: $this->getPk($options);

        if (isset($options['order'])) {
            if (Container::get('app')->isDebug()) {
                throw new DbException('chunk not support call order');
            }
            unset($options['order']);
        }



        if (is_array($column)) {
            $times = 1;
            $query = $this->options($options)->page($times, $count);
        } else {
            $query = $this->options($options)->limit($count);


        }
        $scroll_time = '30m'; // 保留30分钟

        list($scroll_id,$resultSet) = $query->order($column, $order)->firstScroll($scroll_time);

        try {
            while (count($resultSet) > 0) {
                if ($resultSet instanceof Collection) {
                    $resultSet = $resultSet->all();
                }

                if (false === call_user_func($callback, $resultSet)) {
                    return false;
                }
                if (count($resultSet) < $count) {
                    break;
                }
                $resultSet = $query->scroll($scroll_id,$scroll_time);

            }
            // curl -X DELETE http://xxxxxxx:9200/_search/scroll/_all
            $query->clearScroll($scroll_id);
        }catch (\Exception$exception){
            try {
                $query->clearScroll($scroll_id);
            } catch (\Exception$exception) {

            }

            throw $exception;
        }


        return true;
    }

    protected $es_model = null;

    public function esmodel(\Elasticsearch\Elasticsearch $es_model)
    {
        $this->es_model = $es_model;

        return $this;
    }

    public function getEsModel()
    {
        return $this->es_model;
    }

}