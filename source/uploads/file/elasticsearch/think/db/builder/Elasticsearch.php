<?php
/**
 * TP 中的 数据转化未 ES 中语法
 *
 * select | find |count |sum
 *
 *
 *
 * 已支持  and   , or      ,=     , !=     IN      , NOT IN   >=  ,=  ,<=  , > ,<  , null , not null
 * 字段 自动去除 别名  支持（alais 与 表名）
 *
 * 时间搜索 请使用
 * >= TIME <= TIME  BETWEEN TIME
 *
 *
 * TODO  GROUP
 * https://www.jianshu.com/p/62bed9cc8349
 *
 * SUM 与 COUNT 支持 GROUP 方法
 *
 *
 *
 *
 */
namespace think\db\builder;

use app\common\exception\ServiceException;
use think\db\Builder;
use think\db\Expression;
use think\db\Query;
use think\Exception;

class Elasticsearch extends Builder
{

    public function select(Query $query)
    {
        // 不支持 join
        $option = $query->getOptions();

        $where = $option['where'] ?? '';
        $table = $option['table'] ?? '';
        $order = $option['order'] ?? '';
        $scroll = $option['scroll'] ?? '';
        $limit = !empty($option['limit'])? explode(',', $option['limit']) :[];

        if(empty($table)){
            $table = $query->getConfig('prefix').($query->getName()??'');
        }
        // 暂不支持其他字段
        $params = [
            'index' => $table,
            // 不支持别名，不支持join
       //     'type'  => '_doc',

            'body' => [

            ]
        ];



        $bool = $this->parseWhere($query, $where);


        if (!empty($bool)) {
            $params['body']['query']['bool'] = $bool;

        }
        $source = [];
        if(!empty($option['field']) && $option['field']!='*'){
            foreach ($option['field'] as $_field){
                $source[]=$this->removeAlias($query,$_field);
            }
        }
        if(!empty($source)){
            $params['body']['_source'] = $source;
        }


        $sort = $this->getSort($query,$order);

        if (!empty($sort)) {
            $params['body']['sort'] = $sort;
        }

        if (!empty($limit)) {
            if(!isset($limit[1])){
                $params['body']['from'] =  0;
                $params['body']['size'] = $limit[0] ?? 0;
            }else{
                $params['body']['from'] = $limit[0] ?? 0;
                $params['body']['size'] = $limit[1] ?? 0;
            }

        }


        return $params;
    }


    /**
     * 生成如下格式的 排序格式
     * ['age' => ['order' => 'desc']]
     *
     * @param $order
     *
     * @return array
     */
    private function getSort($query,$order)
    {

        if (empty($order)) {
            return [];
        }



        foreach ($order as $_order_key => $_order_type) {

            if(is_numeric($_order_key)){

                if(strpos($_order_type,' ') ===false){
                    //只有一个
                    $_order_key = $_order_type;
                    $_order_type = 'asc';
                }else{

                    list($_order_key,$_order_type) = array_values(array_filter(explode(' ',$_order_type)));
                }



            }

            $sort[][$this->removeAlias($query,$_order_key)] = ['order' => $_order_type];
        }

        return $sort;
    }

    public function removeAlias(Query $query, $key)
    {
        $key = trim($key,'`');
        if (strpos($key, '.') === false) {
            return $key;
        }
        $prefix = $query->getConfig('prefix');
        $table  = $query->getOptions('table');
        if(empty($table)){
            $table = $query->getConfig('prefix').$query->getName();
        }
        $alias = $query->getOptions('alias');
        if (empty($alias) && empty($prefix)) {
            return $key;
        }
        if (!empty($alias)) {
            $alias = current($alias) . '.';
            if (strpos($key, $alias) === 0) {
                $key = substr($key, strlen($alias));
            }
        }

        if (strpos($table, $prefix) === 0) {
            $table = substr($table, strlen($prefix)) . '.';
            if (strpos($key, $table) === 0) {
                $key = substr($key, strlen($table));
            }
        }

        return trim($key,'`');
    }


    protected function parseWhere(Query $query, $where)
    {

        $where =  $this->buildWhere($query, $where);
        $this->processBoolSameLevel($where);

        return $where;
    }


    // 处理 bool 与  ‘0’ 同级的情况
    private function processBoolSameLevel(&$where)
    {
        if(!is_array($where)){
            return;
        }
        if((isset($where['must'])  )){
            $this->processBoolSameLevelType($where['must'],'must');
        }
        if((isset($where['should'])  )){
            $this->processBoolSameLevelType($where['should'],'should');
            $where['minimum_should_match'] = 1; // OR 条件最少匹配一个
        }
        foreach ($where as &$v){
            $this->processBoolSameLevel($v);
        }
    }

    private function processBoolSameLevelType(&$where, $type)
    {

        if(isset($where['bool']) && isset($where['0'])){
            for($i=0;isset($where[$i]);$i++){
                $where['bool'][$type][]  = $where[$i];
                unset($where[$i]);
            }
        }


    }

    protected function logicFitter($logic,$value  = [])
    {

        $logic = strtoupper($logic);
        $is_not = false;
        if(isset($value[0]) && in_array(strtoupper($value[0]),['NOT IN','NEQ'])){
            $is_not = true;
        }

        // should_not  不支持
        $logic_map = [
            'AND' => 'must',
            'OR'  => 'should',
        ];
        if (!isset($logic_map[$logic])) {
            throw new Exception('logic ' . $logic . ' not support yet');
        }

        $result =  $logic_map[$logic].($is_not ? '_not':'');

        if($result=='should_not'){
            throw new Exception('should_not not support ! please change condition');
        }

        return $result;
    }

    /**
     * 生成查询条件SQL
     *
     * @access public
     *
     * @param Query $query 查询对象
     * @param mixed $where 查询条件
     *
     * @return string
     */
    public function buildWhere(Query $query, $where)
    {

        if (empty($where)) {
            $where = [];
        }
        $bool = [];
        foreach ($where as $logic => $val) {

            foreach ($val as $value) {
                if ($value instanceof Expression) {
                    throw new Exception('Expression not support yet');
                }

                if (is_array($value)) {
                    if (key($value) !== 0) {
                        throw new Exception('where express error:' . var_export($value, true));
                    }
                    $field = array_shift($value);
                } elseif (!($value instanceof \Closure)) {
                    throw new Exception('where express error:' . var_export($value, true));
                }

                if ($value instanceof \Closure) {

                    // 使用闭包查询
                    $newQuery = $query->newQuery()
                                      ->setConnection($this->connection);
                    $value($newQuery);
                    $whereClause = $this->buildWhere($newQuery, $newQuery->getOptions('where'));

                    if (!empty($whereClause)) {
                        $query->bind($newQuery->getBind(false));

                        $bool[$this->logicFitter($logic)][]['bool'] = $whereClause;
                    }
                } elseif (is_array($field)) {

                    array_unshift($value, $field);

                    foreach ($value as $item) {
                        $bool['must'][] =  $this->parseWhereItem($query, array_shift($item), $item, $logic);
                    }
                } elseif (strpos($field, '|')) {
                    // 不同字段使用相同查询条件（OR）
                    $array = explode('|', $field);
                    $item  = [];

                    foreach ($array as $k) {
                        $bool['should'][] =  $this->parseWhereItem($query, array_shift($item), $item, $logic);
                    }
                } elseif (strpos($field, '&')) {
                    // 不同字段使用相同查询条件（AND）
                    $array = explode('&', $field);
                    $item  = [];

                    foreach ($array as $k) {
                        $bool['must'][] =  $this->parseWhereItem($query, array_shift($item), $item, $logic);
                    }
                } else {

                    // 对字段使用表达式查询
                    $field          = is_string($field) ? $field : '';
                    $bool[$this->logicFitter($logic,[])][] = $this->parseWhereItem($query, $field, $value, $logic);

                }
            }
        }


        return $bool;
    }



    // where子单元分析
    protected function parseWhereItem(Query $query, $field, $val, $rule = '', $binds = [])
    {


        // 字段分析
        $key = $field ? $this->parseKey($query, $field, true) : '';

        // 查询规则和条件
        if (!is_array($val)) {
            $val = is_null($val) ? ['NULL', ''] : ['=', $val];
        }

        list($exp, $value) = $val;

        // 对一个字段使用多个查询条件
        if (is_array($exp)) {
            $item = array_pop($val);

            // 传入 or 或者 and
            if (is_string($item) && in_array($item, [
                    'AND',
                    'and',
                    'OR',
                    'or'
                ])) {
                $rule = $item;
            } else {
                array_push($val, $item);
            }
            $bool  = [];
            foreach ($val as $k => $item) {
                $bool[$this->logicFitter($rule,[])][] = $this->parseWhereItem($query, $field, $item, $rule, $binds);
            }
            return  $bool;

        }

        // 检测操作符
        $exp = strtoupper($exp);
        if (isset($this->exp[$exp])) {
            $exp = $this->exp[$exp];
        }



        if ($value instanceof Expression) {
            throw new Exception('Expression search not support yet');
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            // 对象数据写入
            $value = $value->__toString();
        }

        if (strpos($field, '->')) {
            $jsonType = $query->getJsonFieldType($field);
            $bindType = $this->connection->getFieldBindType($jsonType);
        } else {
            $bindType = isset($binds[$field]) && 'LIKE' != $exp ? $binds[$field] : \PDO::PARAM_STR;
        }

        if (is_scalar($value) && !in_array($exp, [
                'EXP',
                'NOT NULL',
                'NULL',
                'IN',
                'NOT IN',
                'BETWEEN',
                'NOT BETWEEN'
            ]) && strpos($exp, 'TIME') === false) {
            if (0 === strpos($value, ':') && $query->isBind(substr($value, 1))) {
            } else {
                $name  = $query->bind($value, $bindType);
                $value = ':' . $name;
            }

        }

        // 解析查询表达式
        foreach ($this->parser as $fun => $parse) {
            if (in_array($exp, $parse)) {

                $whereStr = $this->$fun($query, $key, $exp, $value, $field, $bindType, isset($val[2]) ? $val[2] : 'AND');

                break;
            }
        }

        if (!isset($whereStr)) {
            throw new Exception('where express error:' . $exp);
        }

        return $whereStr;
    }

    /**
     * 大小比较查询
     *
     * @access protected
     *
     * @param Query   $query 查询对象
     * @param string  $key
     * @param string  $exp
     * @param mixed   $value
     * @param string  $field
     * @param integer $bindType
     *
     * @return string
     */
    protected function parseCompare(Query $query, $key, $exp, $value, $field, $bindType)
    {

        if (is_array($value)) {
            throw new Exception('where express error:' . $exp . var_export($value, true));
        }

        // 比较运算
        if ($value instanceof \Closure) {
            $value = $this->parseClosure($query, $value);
        }
        $binds = $query->getBind(false);


        if ('=' == $exp ) {
            if( is_null($value)){
                return $this->parseNull( $query, $key, 'NULL', $value, $field, $bindType);
            }else if (is_numeric($key)){
                $_value = $binds[ltrim($value, ':')][0];
                // 支持   1 = 1  等写法
                if($key!=$_value){
                    // 返回空
                    return ['term' => ['_id' => '-1000']];
                }else{
                    // 返回全部
                    return $this->parseNull( $query, '_id', 'NOT NULL', $value, $field, $bindType);
                }
            }else{
                return ['term' => [$this->removeAlias($query,$key) => $binds[ltrim($value, ':')][0]]];
            }

        }



        if('<>' == $exp){
            if( is_null($value)){
                return $this->parseNull( $query, $key, 'NOT NULL', $value, $field, $bindType);
            }else if (is_numeric($key)){

                $_value = $binds[ltrim($value, ':')][0];
                // 支持   1 <> 1  等写法
                if($key==$_value){
                    // 返回空
                    return ['term' => ['_id' => '-1000']];
                }else{
                    // 返回全部
                    return $this->parseNull( $query, '_id', 'NOT NULL', $value, $field, $bindType);
                }
            }else{
                return  ['bool'=>['must_not'=>['term' => [$this->removeAlias($query,$key) =>  $binds[ltrim($value, ':')][0]]]]];
            }

        }

        $range_map = [
            '>='=>'gte',
            '<='=>'lte',
            '>'=>'gt',
            '<'=>'lt',
            '='=>'eq',
        ];



        return ['range' => [$this->removeAlias($query,$key) => [
            $range_map[$exp]=>$binds[ltrim($value, ':')][0]]

        ]];

    }

    /**
     * 闭包子查询
     *
     * @access protected
     *
     * @param Query    $query 查询对象
     * @param \Closure $call
     * @param bool     $show
     *
     * @return string
     */
    protected function parseClosure(Query $query, $call, $show = true)
    {

        $newQuery = $query->newQuery()
                          ->removeOption();
        $call($newQuery);

        return $newQuery->buildSql($show);
    }
    /**
     * IN查询
     * @access protected
     * @param  Query     $query        查询对象
     * @param  string    $key
     * @param  string    $exp
     * @param  mixed     $value
     * @param  string    $field
     * @param  integer   $bindType
     * @return string
     */
    protected function parseIn(Query $query, $key, $exp, $value, $field, $bindType)
    {
        // IN 查询
        if ($value instanceof \Closure) {
            $value = $this->parseClosure($query, $value, false);
        } elseif ($value instanceof Expression) {
            $value = $value->getValue();
        } else {
            $value = array_unique(is_array($value) ? $value : explode(',', $value));
            $array = [];

            foreach ($value as $k => $v) {
                $name    = $query->bind($v, $bindType);
                $array[] = ':' . $name;
            }
            $binds = $query->getBind(false);

            if (count($array) == 1) {
                if('IN' == $exp){

                    return ['term' => [$this->removeAlias($query,$key) => $binds[ltrim($array[0], ':')][0]]];
                }
                return  ['bool'=>['must_not'=>['term' => [$this->removeAlias($query,$key) => $binds[ltrim($array[0], ':')][0]]]]];

            } else {

                $_data = [];
                foreach ($array as $_zone){
                    $_data[]=$binds[ltrim($_zone, ':')][0];
                }
                if('IN' == $exp){
                    return ['terms' => [$this->removeAlias($query,$key) => array_values($_data)]];
                }
                return  ['bool'=>['must_not'=>['terms' => [$this->removeAlias($query,$key) => array_values($_data)]]]];

            }
        }

        return $key . ' ' . $exp . ' (' . $value . ')';
    }

    /**
     * 模糊查询   ( like 的字段需要为text,且为分词搜素 ， 如果是 like '%,xxx,%' 的类型 请使用 list IN  )
     * @access protected
     * @param  Query     $query        查询对象
     * @param  string    $key
     * @param  string    $exp
     * @param  mixed     $value
     * @param  string    $field
     * @param  integer   $bindType
     * @param  string    $logic
     * @return string
     */
    protected function parseLike(Query $query, $key, $exp, $value, $field, $bindType, $logic)
    {
        $binds = $query->getBind(false);
        $_val =  $binds[ltrim($value, ':')][0];
        $_val = trim($_val,'%'); //ES 中无需 %

        if($_val===''){
            // 返回全部
            return $this->parseNull( $query, '_id', 'NOT NULL', $value, $field, $bindType);

        }
        //match 会自动拆词  不检测排序
        //  match_phrase  会自动拆词  不重新排序
        //  match_phrase_prefix  会自动拆词  不重新排序    会最后一个字符进行处理
        // ES 字符类型 需要时 text 才能使用  match_phrase 和match match_phrase_prefix
        return ['match_phrase_prefix' => [$this->removeAlias($query,$key) => $_val  ] ];

    }

    protected function parseTime(Query $query, $key, $exp, $value, $field, $bindType)
    {

        $time_range_map = [
            '>= TIME'=>'gte',
            '<= TIME'=>'lte',
            '> TIME'=>'gt',
            '< TIME'=>'lt',
        ];

        $exp = strtoupper($exp);
        if(!isset($time_range_map[$exp])){
            throw new Exception('time range ' . $exp . ' not support yet');
        }


        return ['range' => [$this->removeAlias($query,$key) => [

            $time_range_map[$exp]=>$this->parseDateTime($query, $value, $field, $bindType)

        ]]];
    }

    /**
     * 日期时间条件解析
     * @access protected
     * @param  Query     $query        查询对象
     * @param  string    $value
     * @param  string    $key
     * @param  integer   $bindType
     * @return string
     */
    protected function parseDateTime(Query $query, $value, $key, $bindType = null)
    {
        $es_model = $query->getEsModel();

        if(!empty($es_model) && !empty($es_model::$utc_date_field)  && in_array($key,$es_model::$utc_date_field)){
             return date('Y-m-d\TH:i:s+00:00',strtotime($value));
        }
        // 统一格式 如下：2020-02-15T01:43:12+08:00
        return date('Y-m-d\TH:i:s+08:00',strtotime($value));
    }


    /**
     * 时间范围查询
     * @access protected
     * @param  Query     $query        查询对象
     * @param  string    $key
     * @param  string    $exp
     * @param  mixed     $value
     * @param  string    $field
     * @param  integer   $bindType
     * @return string
     */
    protected function parseBetweenTime(Query $query, $key, $exp, $value, $field, $bindType)
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return ['range' => [$this->removeAlias($query,$key) => [
            'gte'=>$this->parseDateTime($query, $value[0], $field, $bindType),
            'lte'=>$this->parseDateTime($query, $value[1], $field, $bindType),

        ]]];




    }

    /**
     * Null查询
     * @access protected
     * @param  Query     $query        查询对象
     * @param  string    $key
     * @param  string    $exp
     * @param  mixed     $value
     * @param  string    $field
     * @param  integer   $bindType
     * @return string
     */
    protected function parseNull(Query $query, $key, $exp, $value, $field, $bindType)
    {

        if($exp=='NOT NULL'){
            return ['exists' => ['field'=>$this->removeAlias($query,$key) ]];
        }

        // NULL 查询
        return ['bool'=>['must_not' =>['exists' => ['field'=>$this->removeAlias($query,$key) ]]]];
    }
}