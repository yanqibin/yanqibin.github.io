---
title: solr介绍
date: 2017-12-15 08:46:01
tags: tool
---

### solr是什么呢？
>一、Solr它是一种开放源码的、基于 Lucene Java 的搜索服务器，易于加入到 Web 应用程序中。

>二、Solr 提供了层面搜索(就是统计)、命中醒目显示并且支持多种输出格式（包括XML/XSLT 和JSON等格式）。它易于安装和配置，而且附带了一个基于 HTTP 的
管理界面。Solr已经在众多大型的网站中使用，较为成熟和稳定。

>三、Solr 包装并扩展了Lucene，所以Solr的基本上沿用了Lucene的相关术语。更重要的是，Solr 创建的索引与 Lucene 搜索引擎库完全兼容。

>四、通过对Solr 进行适当的配置，某些情况下可能需要进行编码，Solr 可以阅读和使用构建到其他 Lucene 应用程序中的索引。

>五、此外，很多 Lucene 工具（如Nutch、 Luke）也可以使用Solr 创建的索引。可以使用 Solr 的表现优异的基本搜索功能，也可以对它进行扩展从而满足企业的需要。

### solr的优点
①高级的全文搜索功能；
②专为高通量的网络流量进行的优化；
③基于开放接口（XML和HTTP）的标准；
④综合的HTML管理界面；
⑤可伸缩性－能够有效地复制到另外一个Solr搜索服务器；
⑥使用XML配置达到灵活性和适配性；
⑦可扩展的插件体系。


### 配置文件
 schema.xml /managed-schema
 
 - 主键 默认为id
```
<uniqueKey>id</uniqueKey>
```
- 字段类型
```xml
    <!--int 类型  -->
  <fieldType name="int" class="solr.TrieIntField" docValues="true"precisionStep="0" positionIncrementGap="0"/>
  <!--IKAnalyzer 分词器配置 -->
  <fieldType name="text_ik" class="solr.TextField">
    <analyzer type="index" class="org.wltea.analyzer.lucene.IKAnalyzer"/>
    <analyzer type="query" class="org.wltea.analyzer.lucene.IKAnalyzer"/>
  </fieldType>

```

- 添加字段
```xml
<!-- 主键必须存在 ，且当id 为主键时 类型必须为string-->
<field name="id" type="string" multiValued="false" indexed="true" required="true" stored="true"/>
  <field name="products_name" type="text_ik" indexed="true" stored="true"/>
    <!-- 动态字段  类似xxx_s的字段 无需添加名称， 类型默认为 string-->
  <dynamicField name="*_s" type="string" indexed="true" stored="true"/>
```
> 主键为比较项目 且类型为 string 



### php 对接 solr
- 安装 solr扩展

  - 下载地址 `http://pecl.php.net/package/solr`
  > 目前我们使用的是最新版 2.4.0
  - phpinfo 中出现 solr 则表示安装成功

- 使用 solr
  -  文档地址 `http://php.net/manual/zh/book.solr.php`
  -  使用 SolrClient->qurey() 查询
  -  使用 SolrClient->addDocument() 添加/更新文档


### 业务流程图

```
sequenceDiagram
user->>web:更新数据
web->>DB: 更新数据至数据库
web->>DB: 插入待更新solr数据
timer->>DB:获取要更新的数据
DB->>timer:返会更新数据
timer->>solr:推送更新数据至solr
user->>web:查询数据
web->>solr:获取数据
solr->>web:返回数据主键
web->>DB:通过主键获取数据
web->>user:展示数据
```
![image](/uploads/images/solr.png)



### solr 功能
 -  分词搜索
>例如: 将 搜索词 `花王纸尿裤`  拆分为 `花王` 和 `纸尿裤` 进行搜索

> 缺点:依赖分词器,分词结果可能与预想结果不同

- 模糊查询
> 通过相似度进行搜索，查询达到某个相似度的数据

- 权重
> 为不同字段设置权重  ，对不同字段设置不同权重,通过权重计算得出排序


- 查询范围
 >  例如:查询价格区间

- 高亮显示
> 将匹配到关键词高亮显示
- 分组统计(Facet)
> 产品匹配到的数据有几个在类目1，几个在类目2  几个在某个属性组中

- 相似匹配
> 匹配与当前商品相似的商品

- 拼音检索
> 通过拼音查询中文数据