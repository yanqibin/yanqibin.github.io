---
title: js 中参数的获取
date: 2017-08-07 22:16:35
tags: js
---

## js 中参数的获取
0. 柯里化(currying)
```js
function foo(x) {
    return function (y) {
        console.info(x+y);
    }
}
foo(5)(6);// 11 ，如果方法中没有改变量，则向上层寻找
new foo; //注意，此时返回的不是foo 对象（注释1）

```
0. arguments，获取方法传入的所有值（场景：参数不固定）
```js
function foo() {
    console.info(arguments)
}
foo(1,2);//[1,2]
```
0.  ...x 类似php 5.6 特性中的参数获取
```js
function foo (...x){
    for(var z of x){
         console.warn(z)
       }
}
```




### 注释
1 . 当 方法返回一个 对象，或一个方法时,实例化这个方法时，得到他的返回值的实例