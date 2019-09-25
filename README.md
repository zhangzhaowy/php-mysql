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