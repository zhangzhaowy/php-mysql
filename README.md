MySQLi 封装类
<hr>

# 声明

此软件是为了满足个人使用习惯而在[ThingEngineer/PHP-MySQLi-Database-Class](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class)的基础上开发的. 如果你想学习或研究MYSQL，可以去[ThingEngineer/PHP-MySQLi-Database-Class](https://github.com/ThingEngineer/PHP-MySQLi-Database-Class).

# 环境要求
PHP 5.4+ and PDO extension installed

# 安装
使用之前需要先下载或安装到自己的项目

composer 安装
```
composer require zhangzhaowy/php-mysql:dev-master
```

# 加载

引入类文件
```php
require_once ('Db.php');
```
或者
命名空间引入类文件
```php
use Zhangzhaowy\Phpmysql\Db;
```

# 初始化
默认 字符集utf8，端口3306:
```php
$db = new Db('host', 'username', 'password', 'databaseName');
```

还可以用数组来初始化:
```php
$db = new Db([
    'host' => 'host',
    'username' => 'username', 
    'password' => 'password',
    'db'=> 'databaseName',
    'port' => 3306,
    'prefix' => 'my_',
    'charset' => 'utf8']);
```
表前缀、字符集、端口参数都是可选的。

也支持mysqli对象:
```php
$mysqli = new mysqli('host', 'username', 'password', 'databaseName');
$db = new Db($mysqli);
```

如果表有前缀，我们可以定义表前缀:
```php
$db->setPrefix ('my_');
```

如果MySQL链接断开，会自动重连一次。
禁用方法：
```php
$db->autoReconnect = false;
```

如果想使用已经创建过的数据库链接：
```php
// 创建过的Mysql链接
$db = new Db('host', 'username', 'password', 'databaseName');
...
...
// 要启用创建过的Mysql链接
$db = Db::getInstance();
...
    
```

# 基本操作

## 增加
```php
$data = [
    "login" => "admin",
    "firstName" => "John",
    "lastName" => 'Doe'
];
$id = $db->table('users')->insert($data);
if($id)
    echo 'user was created. Id=' . $id;
else
    echo 'insert failed: ' . $db->getLastError();
```

在Insert中使用on duplicate key update
```php
$data = [
    "login" => "admin",
    "firstName" => "John",
    "lastName" => 'Doe',
    "createdAt" => $db->now(),
    "updatedAt" => $db->now(),
];
$updateColumns = ["updatedAt"];
$lastInsertId = "id";
$db->onDuplicate($updateColumns, $lastInsertId);
$id = $db->table('users')->insert($data);
```

## 替换
<a href='https://dev.mysql.com/doc/refman/5.0/en/replace.html'>replace()</a> 同 insert() 方法一样;

## 更新
可以使用where()、limit()等联合查询，详解见[查询](#查询)
```php
$data = [
    'firstName' => 'Bobby',
    'lastName' => 'Tables',
];
$db->where('id', 1)->limit(1);
if ($db->table('users')->update($data))
    echo $db->count . ' records were updated';
else
    echo 'update failed: ' . $db->getLastError();
```

## 删除
```php
$db->where('id', 1);
if($db->table('users')->delete()) {
    echo 'successfully deleted';
}
```

## 查询

### 获取数据
getAll() 获取多条记录  
getOne() 获取一条记录  
getColumn() 获取某列数据  
```php
// 包含全部用户
$users = $db->from('users')->getAll();
// 包含一个用户
$users = $db->from('users')->getOne();
// 包含所有用户的id
$users = $db->from('users')->getColumn('id');
```

### From
定义操作表
```php
$users = $db->from('users u')->getOne();
// select * from my_users u limit 1;
$users = $db->from(['users' => 'u'])->getOne();
// select * from my_users u limit 1;
```

### Select
定义获取列字段
```php
$users = $db->from('users')->select(['id', 'name'])->getOne();
// ['id' => 1, 'name' => 'user1']
$users = $db->from('users')->select('id, name'])->getOne();
// ['id' => 1, 'name' => 'user1']
```
给列定义别名
```php
$users = $db->from('users')->select('id, name AS username'])->getOne();
$users = $db->from('users')->select(['id', 'name AS username'])->getOne();
$users = $db->from('users')->select(['id', 'name' => 'username'])->getOne();
// ['id' => 1, 'username' => 'user1']
```

### Join
在join的条件中将表名用``括起来，会自动追加表前缀。
```php
// 左联
$users = $db->from('users')->leftJoin('score', '`score`.`uid` = `users`.`id`')->getAll();
// 右联
$users = $db->from('score s')->rightJoin('users u', 'u.`id` = s.`uid`')->getAll();
// 自定义联表
$users = $db->from('users u')->join('INNER', 'score s', 'u.`id` = s.`uid`')->getAll();
// joinWhere() 第一个参数与join的表名要一致，第二个参数与where()用法一致
$users = $db->from('users u')->leftJoin('score s', 's.`uid` = u.`id`')->joinWhere('score s', ['s.active' => 1])->getAll();
```

### Where
```php
// SELECT * FROM my_users WHERE 1=1 AND 2=2
$db->from('users')->where('1=1 AND 2=2')->getAll();
// SELECT * FROM my_users WHERE id = '1' OR id = '5'
$db->from('users')->where(['id = ? OR id = ?', [1, 5]])->getAll();
// SELECT * FROM my_users WHERE name IS NULL
$db->from('users')->where(['name'])->getAll();
$db->from('users')->where(['name', 'IS', NULL])->getAll();
// SELECT * FROM my_users WHERE name IS NOT NULL
$db->from('users')->where(['name', 'IS NOT', NULL])->getAll();
// SELECT * FROM my_users WHERE id = '1' 
$db->from('users')->where(['id' => 1])->getAll();
$db->from('users')->where(['id', 1])->getAll();
// SELECT * FROM my_users WHERE id in ( '1', '2', '3' )
$db->from('users')->where(['id' => [1, 2, 3]])->getAll();
$db->from('users')->where(['id', [1, 2, 3]])->getAll();
SELECT * FROM my_users WHERE id BETWEEN '1' AND '5' 
$db->from('users')->where(['id' => ['BETWEEN' => [1, 5]]])->getAll();
$db->from('users')->where(['id', ['BETWEEN' => [1, 5]]])->getAll();
$db->from('users')->where(['id', 'BETWEEN', [1, 5]])->getAll();
// SELECT * FROM my_users WHERE name like '%zhang%'
$db->from('users')->where(['name', 'like', '%zhang%'])->getAll();
// SELECT * FROM my_users WHERE id != '1'
$db->from('users')->where(['id', '!=', 1])->getAll();
// SELECT * FROM my_users WHERE id != '1' OR id != '2'
$db->from('users')->where(['id', '!=', 1])->where(['OR', 'id', '!=', 2])->getAll();
// SELECT * FROM my_users WHERE id != '1' OR ( id > 0 AND name = 'zhang' OR ( id = '1' OR name like 'zh%' ) AND age != '10' OR name in ( 'zhang', 'wang', 'li' ) ) 
$db->from('users')->where(['id', '!=', 1])->where(['OR', [
    'id > 0',
    ['name' => 'zhang'],
    ['OR', [
        ['id' => 1],
        ['OR', 'name', 'like', 'zh%']
    ]],
    ['age', '!=', 10],
    ['OR', 'name', 'in', ['zhang', 'wang', 'li']]
]])->getAll();
```

### Group By
```php
// SELECT * FROM my_users GROUP BY id, age
$db->from('users')->groupBy('id, age')->getAll();
$db->from('users')->groupBy(['id', 'age'])->getAll();
```

### Having
Having 用法同 Where 用法一样
```php
// SELECT * FROM my_users GROUP BY age HAVING 1=1 AND 2=2
$db->from('users')->groupBy('age')->having('1=1 AND 2=2')->getAll();
// SELECT * FROM my_users GROUP BY age HAVING age = '10' 
$db->from('users')->groupBy('age')->having(['age' => '10'])->getAll();
```

### Order By
```php
// SELECT * FROM my_users ORDER BY id DESC
$db->from('users')->orderBy('id DESC')->getAll();
$db->from('users')->orderBy(['id DESC'])->getAll();
$db->from('users')->orderBy(['id' => 'DESC'])->getAll();
// SELECT * FROM my_users ORDER BY id DESC, age ASC
$db->from('users')->orderBy('id DESC,age ASC')->getAll();
$db->from('users')->orderBy(['id' => 'DESC', 'age' => 'ASC'])->getAll();
// SELECT * FROM my_users ORDER BY FIELD (id, "1","3","2") ASC
$db->from('users')->orderBy('id', [1, 3, 2])->getAll();
$db->from('users')->orderBy(['id'], [1, 3, 2])->getAll();
// SELECT * FROM my_users ORDER BY id REGEXP '^[a-z]' ASC
$db->from('users')->orderBy('id', "^[a-z]")->getAll();
$db->from('users')->orderBy(['id'], "^[a-z]")->getAll();
```

### Limit
```php
// SELECT * FROM my_users LIMIT 1
$db->from('users')->limit(1)->getAll();
// SELECT * FROM my_users LIMIT 1, 10
$db->from('users')->limit('1, 10')->getAll();
$db->from('users')->limit(['1', '10'])->getAll();
$db->from('users')->limit(['1' => '10'])->getAll();
```

### map
将某列的值作为返回结果集的索引
```php
$users = $db->from('users')->getAll();
// 输出 [['id' => 1, 'name' => 'user1'], ['id' => 2, 'name' => 'user2']]
$users = $db->map('name')->from('users')->getAll();
// 输出 ['user1' => ['id' => 1, 'name' => 'user1'], 'user2' => ['id' => 2, 'name' => 'user2']]
```

### 定义结果集类型
```php
// 结果集返回数组（默认）
$users = $db->from('users')->asArray()->getAll();
// 结果集返回对象
$users = $db->from('users')->asObject()->getAll();
// 结果集返回Json
$users = $db->from('users')->asJson()->getAll();
```

### Total Count
```php
$db->from('users')->limit('0,2')->withTotalCount()->getAll();
// 结果输出2条数据
// $db->totalCount 显示总记录数
```

### 分页
paginate() 分页。第一个参数是页数，第二个参数是每页记录数量（默认20）。
```php
// 每页显示5条，显示第一页数据
$users = $db->from('users')->paginate(1, 5);
echo $db->totalCount; // 总记录数
echo $db->currentPage; // 当前页数
echo $db->pageLimit; // 每页记录数
echo $db->totalPages; // 总页数
```

### 子查询
需要先定义子查询对象
```php
$sub = $db->subQuery($db->getPrefix());
```

再通过子查询对象拼装子查询语句
```php
// SELECT id FROM my_users WHERE age = '10'
$sub->from('users')->select('id')->where(['age' => 10])->getAll();
```

最后子查询作为SQL的查询条件
```php
// SELECT * FROM my_users WHERE id in ( (SELECT id FROM my_users WHERE age = '10' ) ) 
$db->from('users')->where(['id', 'in', $sub])->getAll();
```

### Query
```php
$users = $db->query('select * from my_users limit 1');
```

### 事务
```php
try {
    // 开启事务
    $db->startTransaction();

    // 插入一条数据
    $id = $db->table('users')->insert(['name' => 'user', 'age' => 10]);
    if ($id <= 0) {
        // 失败，报错
        throw new \Exception('ERROR:'.$db->getLastErrno().' '.$db->getLastError());
    }

    // 提交
    $db->commit();
} catch(\Exception $e) {
    // 获取错误消息
    // $e->getMessage();
    // 回滚
    $db->rollback();
}
```

### Trace
跟踪SQL、执行时间、文件位置
```php
$db->setTrace(true);
$db->from('users')->getAll();
$db->from('users')->select(['id', 'name'])->getOne();
var_dump($db->trace);
// 打印输出结果
// [
//     0 => [
//         0 => 'SELECT * FROM my_users',
//         1 => 0.020965814590454,
//         2 => 'Zhangzhao\Phpmysql\Db->getAll() >>  file "**\controller\Test.php" line #214'
//     ],
//     1 => [
//         0 => 'SELECT  id,name FROM my_users LIMIT 1',
//         1 => 0.0006251335144043,
//         2 => 'Zhangzhao\Phpmysql\Db->getOne() >>  file "**\controller\Test.php" line #215'
//     ],
// ]
```

### SQL 关键词
LOW_PRIORITY | DELAYED | HIGH_PRIORITY | IGNORE
ALL | DISTINCT | DISTINCTROW | STRAIGHT_JOIN | SQL_SMALL_RESULT | SQL_BIG_RESULT | SQL_BUFFER_RESULT | SQL_CACHE | SQL_NO_CACHE | SQL_CALC_FOUND_ROWS | QUICK | MYSQLI_NESTJOIN
FOR UPDATE | LOCK IN SHARE MODE
```php
$db->table($table)->setQueryOption('LOW_PRIORITY')->insert($param);
// INSERT LOW_PRIORITY INTO table ...
```
```php
$db->table($table)->setQueryOption('FOR UPDATE')->get('users');
// SELECT * FROM my_users FOR UPDATE;
```

多个关键词一起用
```php
$db->table($table)->setQueryOption(['LOW_PRIORITY', 'IGNORE'])->insert($param);
// INSERT LOW_PRIORITY IGNORE INTO table ...
```

### 错误
SQL执行完成之后，需要执行下面的方法判断是否成功。 
```php
if ($db->getLastErrno() === 0)
    echo 'Succesfull';
else
    echo 'Failed. Error: '. $db->getLastError();
```

### 帮助方法
关闭数据库连接
```php
    $db->disconnect();
```

数据库连接断开时重新连接
```php
if (!$db->ping())
    $db->connect()
```

获取最后一次执行的SQL
注：函数返回SQL查询仅用于调试目的，因为它的执行很可能会由于字符变量周围缺少引号而失败。
```php
    $db->get('users');
    echo "Last executed query was ". $db->getLastQuery();
```

转义字符串方法
```php
    $escaped = $db->escape("' and 1=1");
```