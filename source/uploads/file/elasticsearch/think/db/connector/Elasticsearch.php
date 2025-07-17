<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\db\connector;

use Elasticsearch\ClientBuilder;
use think\Container;
use think\Db;
use think\db\Connection;
use think\db\exception\BindParamException;
use think\db\Query;
use think\exception\PDOException;

class Elasticsearch extends Connection
{
    public function connect(array $config = [], $linkNum = 0, $autoConnection = false)
    {


        if (isset($this->links[$linkNum])) {
            return $this->links[$linkNum];
        }

        $params                = array(
            $this->config['hostname'] . ':' . $this->config['hostport']
        );

        $this->links[$linkNum] = ClientBuilder::create()
                                              ->setHosts($params);

        if (!empty($this->config['username']) && !empty($this->config['password'])) {
            $this->links[$linkNum]->setBasicAuthentication($this->config['username'], $this->config['password']);
        }


        $this->links[$linkNum] = $this->links[$linkNum]->build();

        return $this->links[$linkNum];
    }

    public function select(Query $query ,$get_origin_res = false)
    {
        $params = $this->builder->select($query);
        $options = $query->getOptions();
        if (!empty($options['fetch_sql'])) {
            // 获取实际执行的SQL语句
            return $this->getRealSqlByQuery($query);
        }
        $this->debug(true);
        $result = $this->connect()
                       ->search($params);
        $this->debug(false, $params );
        return false !== $result ? (!empty($result['hits']['hits'])? array_column($result['hits']['hits'],'_source'): []) : false;
    }
    public function firstScroll(Query $query,$scroll_time)
    {
        $params = $this->builder->select($query);
        $options = $query->getOptions();
        if (!empty($options['fetch_sql'])) {
            // 获取实际执行的SQL语句
            return $this->getRealSqlByQuery($query);
        }
        $this->debug(true);
        $params['scroll'] = $scroll_time;
        $result = $this->connect()
                       ->search($params);
        $this->debug(false, $params );
        return false !== $result ? [$result['_scroll_id']?? '',(!empty($result['hits']['hits'])? array_column($result['hits']['hits'],'_source'): [])] : false;
    }

    public function scroll(Query $query,$scroll_id,$scroll_time = '')
    {

        $params['body']['scroll'] = $scroll_time;
        $params['body']['scroll_id'] = $scroll_id;

        $this->debug(false, $params );
        $result = $this->connect()->scroll($params);
        return false !== $result ? (!empty($result['hits']['hits'])? array_column($result['hits']['hits'],'_source'): []) : false;
    }

    public function clearScroll(Query $query,$scroll_id )
    {

        $params['body']['scroll_id'] = $scroll_id;
        $this->debug(false, $params );
        $result = $this->connect()->clearScroll($params);
        return false !== $result ? (!empty($result['hits']['hits'])? array_column($result['hits']['hits'],'_source'): []) : false;
    }



    public function find(Query $query)
    {
        $query->setOption('limit', 1);
        $result = $this->select($query);

        return $result? current($result): null;
    }



    /**
     * 执行查询 返回数据集
     * @access public
     * @param  array    $sql   $param

     */
    public function queryByParam(Query $query,$param)
    {

        $params = $this->builder->select($query);
        $params['body'] = array_merge_recursive($params['body'],$param);

        // 调试开始
        $this->debug(true);
        // 调试结束
        $this->debug(false, '', false);
        // 返回结果集
        $result = $this->connect()->search($params);
        $this->debug(false, $params );
        return $result;

    }

    protected function parseDsn($config)
    {
        return '';
    }

    public function getFields($tableName)
    {

        return [];
    }

    public function getTables($dbName)
    {

        return [];
    }

    protected function getExplain($sql)
    {
        return [];
    }


    /**
     * 得到某个字段的值
     * @access public
     * @param  Query     $query 查询对象
     * @param  string    $field   字段名
     * @param  mixed     $default   默认值
     * @param  bool      $one   是否返回一个值
     * @return mixed
     */
    public function value(Query $query, $field, $default = null, $one = true)
    {

        $options = $query->getOptions();

        if (isset($options['field'])) {
            $query->removeOption('field');
        }

        if (is_string($field)) {
            $field = array_map('trim', explode(',', $field));
        }

        $query->setOption('field', $field);




        $field = current($field); //

        $field = $this->builder->removeAlias($query,$field);
        $query->setOption('limit',1);
        if (!empty($options['fetch_sql'])) {
            // 获取实际执行的SQL语句
            return $this->getRealSqlByQuery($query);
        }

        $this->debug(true);

        $params = $this->builder->select($query);
        if (isset($options['field'])) {
            $query->setOption('field', $options['field']);
        } else {
            $query->removeOption('field');
        }

        $result = $this->connect()->search($params);
        $this->debug(false, $params );

        return false !== $result ? ($result['hits']['hits'][0]['_source'][$field]?? $default) : $default;
    }

    /**
     * 求和
     *
     * 支持 group
     * 当group 时
     *     当 有 field 且 field 的第一个 = group 时 返回 返回 p[ $group=>'xxx' ,'count'=>'xxx']]
     *      否则 返回 group 的 个数
     *
     *
     * @access public
     *
     * @param  Query     $query 查询对象
     *
     * @return mixed
     */
    public function count(Query $query)
    {

        $options = $query->getOptions();

        $_field  = '';
        if (isset($options['field'])) {
            $_field = $options['field'];
            $query->removeOption('field');
        }

        $query->removeOption('limit');
        $query->removeOption('order');

        if (!empty($options['fetch_sql'])) {
            // 获取实际执行的SQL语句
            return $this->getRealSqlByQuery($query);
        }

        $this->debug(true);

        $params = $this->builder->select($query);

        if(!empty($options['group'])){
            $params['body']['size'] = 0; //只需要SUM    search 时可以加 size ,count 不行
            $_group =  $this->builder->removeAlias($query, $options['group']);
            if(!empty($_field) && isset( $_field[0]) &&  $this->builder->removeAlias($query, $_field[0])==$_group){

                $params['body']['aggs']['count']['terms']['field'] =$_group;
                $result = $this->connect()->search($params);
                $this->debug(false, $params );

                $data_list = [];
                if(!empty($result['aggregations']['count']['buckets'])){
                    foreach ($result['aggregations']['count']['buckets'] as $data){
                        $data_list[] = [$_group => $data['key'],'count'=>$data['doc_count']];
                    }
                }

              return  $data_list;

            }

            $params['body']['aggs']['count']['cardinality']['field'] = $_group;
            $result = $this->connect()->search($params);
            $this->debug(false, $params );
            return  $result['aggregations']['count']['value'] ??0;
        }


        $result = $this->connect()->count($params);
        $this->debug(false, $params );
        return false !== $result ? $result['count'] : null;
    }


    /**
     * 汇总查询
     * 当使用 GROUP  时 返回 数组 ， 字段中需要返回 GROUP 字段时， 在 $field 中增加
     * @param $query
     * @param $field
     *
     * @return array|false|mixed|string
     * @throws \think\Exception
     */
    public function sum($query,$field)
    {
        $options = $query->getOptions();

        if (isset($options['field'])) {
            $query->removeOption('field');
        }

        $query->removeOption('limit');
        $query->removeOption('order');
        $field_list = is_array($field) ? $field : [$field];
        $params = $this->builder->select($query);
        $params['body']['size'] = 0; //只需要求和

        $group =  $options['group'] ?? '';
        $aggs = [];
        foreach ($field_list as $index => $_field) {
            $field_list[$index] = $_field =    $this->builder->removeAlias($query, $_field);
            if($group && $group == $_field){
                continue;
            }
            $aggs ['a_' . $index] = [
                'sum' => ['field' => $_field]
            ];
        }
        if(!empty($group)){
            $group = $this->builder->removeAlias($query, $group);
        }

        if (!empty($group)) {
            $params['body']['aggs'] = [
                $group => [
                    'terms' => ['field' => $group],
                    'aggs'  => $aggs
                ]
            ];
        } else {
            $params['body']['aggs'] = $aggs;
        }


        if (!empty($options['fetch_sql'])) {
            // 获取实际执行的SQL语句
            return $this->getRealSqlByQuery($query,$params);
        }
        $this->debug(true);

        $result = $this->connect()->search($params);


        $this->debug(false, $params );
        if(empty($result['aggregations'])){
            return  false;
        }

        $result_array = [];

        if($group){
            if(!empty($result['aggregations'][$group]['buckets'])){
                foreach ($result['aggregations'][$group]['buckets'] as $buckets) {
                    $_result_array = [];
                    foreach ($field_list as $index => $_field) {
                        if($group == $_field){
                            $_result_array[$group] = $buckets['key'] ?? ''; //自动带上group 字段
                            continue;
                        }
                        $_result_array[$_field] = $buckets['a_' . $index]['value'] ?? 0;
                    }
                    $result_array[] = $_result_array;
                }
            }

        }else{
            foreach ($field_list as $index=> $_field){
                $result_array[$_field]=$result['aggregations']['a_'.$index]['value'] ?? 0;
            }
        }

        if(is_array($field)){

            return  $result_array;
        }
        return  current($result_array);
    }



    public function getRealSqlByQuery(Query $query,$params=[])
    {

        $params = $params?: $this->builder->select($query);
        return  'index ['.$params['index']. '] query: '.json_encode($params['body']);
    }
    /**
     * 数据库调试 记录当前SQL及分析性能
     * @access protected
     * @param  boolean $start 调试开始标记 true 开始 false 结束
     * @param  string  $sql 执行的SQL语句 留空自动获取
     * @param  bool    $master 主从标记
     * @return void
     */
    protected function debug($start, $sql = '', $master = false)
    {
        if (!empty($this->config['debug'])) {
            // 开启数据库调试模式
            $debug = Container::get('debug');

            if ($start) {
                $debug->remark('queryStartTime', 'time');
            } else {
                // 记录操作结束时间
                $debug->remark('queryEndTime', 'time');
                $runtime = $debug->getRangeTime('queryStartTime', 'queryEndTime');
                $sql     = $sql ?:'';
                $result  = [];


                // SQL监听
                $this->triggerSql($sql, $runtime, $result, $master);
            }
        }
    }

    /**
     * 触发SQL事件
     * @access protected
     * @param  string    $sql SQL语句
     * @param  float     $runtime SQL运行时间
     * @param  mixed     $explain SQL分析
     * @param  bool      $master 主从标记
     * @return void
     */
    protected function triggerSql($sql, $runtime, $explain = [], $master = false)
    {
        if (!empty(self::$event)) {
            foreach (self::$event as $callback) {
                if (is_callable($callback)) {
                    call_user_func_array($callback, [$sql, $runtime, $explain, $master]);
                }
            }
        } else {
            if ($this->config['deploy']) {
                // 分布式记录当前操作的主从
                $master = $master ? 'master|' : 'slave|';
            } else {
                $master = '';
            }

            // 未注册监听则记录到日志中
            $this->log('[ ES ] ' .  json_encode($sql) . ' [ ' . $master . 'RunTime:' . $runtime . 's ]');

            if (!empty($explain)) {
                $this->log('[ EXPLAIN : ' . var_export($explain, true) . ' ]');
            }
        }
    }


    /**
     * 得到某个字段的值
     * @access public
     * @param  Query     $query     查询对象
     * @param  string    $aggregate 聚合方法
     * @param  mixed     $field     字段名
     * @return mixed
     */
    public function aggregate(Query $query, $aggregate, $field)
    {
        if (is_string($field) && 0 === stripos($field, 'DISTINCT ')) {
            list($distinct, $field) = explode(' ', $field);
        }

        $field = $aggregate . '(' . (!empty($distinct) ? 'DISTINCT ' : '') . $this->builder->parseKey($query, $field, true) . ') AS tp_' . strtolower($aggregate);

        return $this->value($query, $field, 0, false);
    }

    /**
     * 得到某个列的数组
     * @access public
     * @param  Query     $query 查询对象
     * @param  string    $field 字段名 多个字段用逗号分隔
     * @param  string    $key   索引
     * @return array
     */
    public function column(Query $query, $field, $key = '')
    {
        $options = $query->getOptions();

        if (isset($options['field'])) {
            $query->removeOption('field');
        }

        if (is_null($field)) {
            $field = ['*'];
        } elseif (is_string($field)) {
            $field = array_map('trim', explode(',', $field));
        }

        if ($key && ['*'] != $field) {
            array_unshift($field, $key);
            $field = array_unique($field);
        }

        $query->setOption('field', $field);

        if (!empty($options['fetch_sql'])) {
            // 获取实际执行的SQL语句
            return $this->getRealSqlByQuery($query);
        }
        $params = $this->builder->select($query);


        // 还原field参数
        if (isset($options['field'])) {
            $query->setOption('field', $options['field']);
        } else {
            $query->removeOption('field');
        }

        $this->debug(true);
        // 执行查询操作
        $result = $this->connect()
                       ->search($params);

        $result = false !== $result ? (!empty($result['hits']['hits'])? array_column($result['hits']['hits'],'_source'): []) : false;
        $this->debug(false, $params );

        if (count($field) == 1 && $field[0] != '*') {

            $result = array_column($result, $field[0]);
        } else{
            if (['*'] == $field && $key) {
                $result = array_column($result, null, $key);
            } elseif ($result) {

                $fields = $field; // ES 不会按传递的 filed 返回结果

                $count  = count($fields);
                $key1   = array_shift($fields);
                $key2   = $fields ? array_shift($fields) : '';
                $key    = $key ?: $key1;

                if (strpos($key, '.')) {
                    list($alias, $key) = explode('.', $key);
                }

                if (2 == $count) {
                    $column = $key2;
                } elseif (1 == $count) {
                    $column = $key1;
                } else {
                    $column = null;
                }


                $result = array_column($result, $column, $key);
            } else {
                $result = [];
            }
        }
        return $result;
    }
}