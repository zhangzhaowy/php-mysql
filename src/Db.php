<?php
namespace Zhangzhao\Phpmysql;

class Db
{
	protected static $_instance;
    protected $isSubQuery = false;
	protected $subQueryAlias = null;
	protected $dbLink = null;
	protected $prefix = '';
	protected $_query;
	protected $_queryOptions = [];
	protected $_bindParams = [];
	protected $_nestJoin = false;
	protected $_forUpdate = false;
	protected $_lockInShareMode = false;
	protected $_where = [];
	protected $_having = [];
	protected $_join = [];
	protected $_joinAnd = [];
	protected $_groupBy = [];
	protected $_orderBy = [];
	protected $_lastInsertId = null;
	protected $_updateColumns = null;
    protected $_lastQuery;
	protected $_tableName = '';
    protected $_limit = null;
    protected $_columns = '';
    protected $_mapKey = null;
    // 启用事务
    protected $_transaction_in_progress = false;

	// 用于查询执行跟踪的变量
	protected $traceStartQ;
    protected $traceEnabled;
    protected $traceStripPrefix;
    public $trace = array();

    // 数据库重连
    public $autoReconnect = true;
    protected $autoReconnectCount = 0;

    // 最后一条错误语句的错误代码
    protected $_stmtError;
    protected $_stmtErrno;

    // 受影响记录行数
    public $count = 0;
    // 总数量
    public $totalCount = 0;

    // 返回数据类型
    public $returnType = 'array';

    // 每页记录数量
    public $pageLimit = 20;
    // 当前页
    public $currentPage = 1;
    // 总页数
    public $totalPages = 0;

	private $hostname = null;
	private $username = null;
	private $password = null;
	private $database = null;
	private $port = null;
	private $charset = null;
	private $socket = null;

	public function __construct($hostname = null, $username = null, $password = null, $database = null, $port = 3306, $charset = 'utf8', $socket = null)
	{
        $isSubQuery = false;
        $subQueryAlias = null;
        $prefix = null;

        // 如果参数是数组
        if (is_array($hostname)) {
            // ['hostname' => 'localhost', 'username' => 'root',......]
            foreach ($hostname as $key => $val) {
                $$key = $val;
            }
        }

        // 如果参数是对象
        if (is_object($hostname)) {
            $this->dbLink = $hostname;
        }

        // 表前缀
        if (isset($prefix)) {
            $this->setPrefix($prefix);
        }

        // 是否为子查询
        if ($isSubQuery) {
            $this->isSubQuery = true;
            $this->subQueryAlias = $subQueryAlias;
            return;
        }

        // 如果参数是字符串
        if (is_string($hostname)) {
            $this->hostname   = $hostname;
            $this->username   = $username;
            $this->password   = $password;
            $this->database   = $database;
            $this->port       = $port;
            $this->socket     = $socket;
            $this->charset    = $charset;
        }

		self::$_instance  = $this;
	}

    /*
     * 返回静态实例以允许从另一个类中访问已实例化过的方法
     * 注：需要重新加载连接信息
     */
	public static function getInstance()
    {
        if(isset(self::$_instance)) {
            return self::$_instance;
        } else {
            return new Db();
        }
    }

    /*
     * 连接数据库
     */
	public function connect()
	{
		if ($this->isSubQuery) {
			return;
		}

		if (empty($this->hostname) && empty($this->socket)) {
			throw new \Exception('MySQL host or socket is not set');
		}

		// mysqli的反射类 提取出关于类、方法、属性、参数等的详细信息，包括注释
		$mysqlic = new \ReflectionClass('mysqli');
		// 从给出的参数创建一个新的mysqli类实例
		$mysqli = $mysqlic->newInstanceArgs([$this->hostname, $this->username, $this->password, $this->database, $this->port, $this->socket]);

		if ($mysqli->connect_error) {
			throw new \Exception('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
		}

		if (!empty($this->charset) && !$mysqli->set_charset($this->charset)) {
			throw new \Exception('Error loading character set ' . $this->charset . ': ' . $mysqli->error);
		}

		$this->dbLink = $mysqli;
	}

    /*
     * 关闭数据库
     */
    public function disconnect()
    {
        if (!isset($this->dbLink)) {
            return;
        }

        $this->dbLink->close();

        unset($this->dbLink);
    }

    /*
     * 获取数据库连接对象
     */
    public function mysqli()
    {
        if (!isset($this->dbLink)) {
            $this->connect();
        }

        return $this->dbLink;
    }

	public function insert($insertData)
	{
		return $this->_buildInsert($insertData, 'INSERT');
	}

	public function replace($insertData)
	{
		return $this->_buildInsert($insertData, 'REPLACE');
	}

    public function update($tableData)
    {
        if ($this->isSubQuery) {
            return;
        }

        $this->_query = "UPDATE " . $this->_tableName;

        // 拼装 Query
        $stmt = $this->_buildQuery($tableData);
        // 执行
        $status = $stmt->execute();
        // 错误信息
        $this->_stmtError = $stmt->error;
        $this->_stmtErrno = $stmt->errno;
        
        $this->reset();

        $this->count = $stmt->affected_rows;
        
        return $status;
    }

    public function delete()
    {
        if ($this->isSubQuery) {
            return;
        }

        if (count($this->_join)) {
            // delete t1 from t1 left join t2 on t1.uid=t2.uid where t2.uid is null
            $this->_query = "DELETE " . preg_replace('/.* (.*)/', '$1', $this->_tableName) . " FROM " . $this->_tableName;
        } else {
            // delete from t1 where uid is null
            $this->_query = "DELETE FROM " . $this->_tableName;
        }

        // 拼装 Query
        $stmt = $this->_buildQuery();
        // 执行
        $stmt->execute();
        // 错误信息
        $this->_stmtError = $stmt->error;
        $this->_stmtErrno = $stmt->errno;

        $this->reset();

        $this->count = $stmt->affected_rows;

        // -1 表示查询返回错误
        return ($stmt->affected_rows > -1);
    }

    public function getOne()
    {
        $this->_limit = 1;

        $res = $this->getAll();
        if ($res instanceof Db) {
            // 对象
            return $res;
        } elseif (is_array($res) && isset($res[0])) {
            // 数组
            return $res[0];
        } elseif ($res) {
            // 其他
            return $res;
        }

        return null;
    }

    public function getAll()
    {
        $columns = $this->_columns == '' ? '*' : $this->_columns;

        $this->_query = 'SELECT ' . implode(' ', $this->_queryOptions) . ' ' . $columns . " FROM " . $this->_tableName;
        
        // 拼装 Query
        $stmt = $this->_buildQuery();

        if ($this->isSubQuery) {
            return $this;
        }

        // 执行
        $stmt->execute();
        // 错误信息
        $this->_stmtError = $stmt->error;
        $this->_stmtErrno = $stmt->errno;
        // 动态结果
        $res = $this->_dynamicBindResults($stmt);

        $this->reset();

        return $res;
    }

    public function getColumn($column, $returnArray = true, $symbol = ',')
    {
        $this->returnType = 'array';

        $res = $this->getAll();
        if (!$res) {
            return null;
        }

        $returnRes = [];
        // $column = key($res[0]);
        for ($i = 0; $i < $this->count; $i++) {
            $returnRes[] = $res[$i][$column];
        }
        
        return $returnArray ? $returnRes : implode($symbol, $returnRes);
    }

	private function _buildInsert($insertData, $operation)
	{
		if ($this->isSubQuery) {
			return;
		}

		// INSERT/REPLACE [LOW_PRIORITY | DELAYED] INTO tableName
		$this->_query = $operation . ' ' . implode($this->_queryOptions) . ' INTO ' . $this->_tableName;

		// 拼装 Query
		$stmt = $this->_buildQuery($insertData);
		// 执行
		$status = $stmt->execute();
		// 错误信息
        $this->_stmtError = $stmt->error;
        $this->_stmtErrno = $stmt->errno;

        $haveOnDuplicate = !empty($this->_updateColumns);

        $this->reset();

        $this->count = $stmt->affected_rows;

        if ($stmt->affected_rows < 1) {
            // 在启用 onDuplicate() 的情况下,如果没有受影响记录 是说明已经入过库
            if ($status && $haveOnDuplicate) {
                return true;
            }

            return false;
        }

        // insert操作 返回入库id
        if ($stmt->insert_id > 0) {
            return $stmt->insert_id;
        }

        return true;
	}

	private function _buildQuery($tableData = null)
	{
		$this->_buildJoin();
        $this->_buildDataQuery($tableData);
        $this->_buildCondition('WHERE', $this->_where);
        $this->_buildGroupBy();
        $this->_buildCondition('HAVING', $this->_having);
        $this->_buildOrderBy();
        $this->_buildLimit();
        $this->_buildOnDuplicate($tableData);

        // 排他锁，不允许其它事务增加共享或排他锁读取。修改是惟一的，必须等待前一个事务 commit
        if ($this->_forUpdate) {
            $this->_query .= ' FOR UPDATE';
        }

        // 共享锁，事务都加，都能读。修改是惟一的，必须等待前一个事务 commit
        if ($this->_lockInShareMode) {
            $this->_query .= ' LOCK IN SHARE MODE';
        }

        $this->_lastQuery = $this->replacePlaceHolders($this->_query, $this->_bindParams);

        if ($this->isSubQuery) {
            return;
        }

        // 准备 Query 语句
        $stmt = $this->_prepareQuery();

        // 如果有 将参数绑定到语句中
        if (count($this->_bindParams) > 1) {
            call_user_func_array(array($stmt, 'bind_param'), $this->refValues($this->_bindParams));
        }

        return $stmt;
	}

	private function _buildJoin()
	{
		if (empty($this->_join)) {
			return;
		}

		foreach ($this->_join as $data) {
			list($joinType, $joinTable, $joinCondition) = $data;

			if (is_object($joinTable)) {
                $joinStr = $this->_buildPair("", $joinTable);
			} else {
                $joinStr = $joinTable;
            }
            
            $this->_query .= " " . $joinType. " JOIN " . $joinStr . 
                (false !== stripos($joinCondition, 'using') ? " " : " ON ")
                . $joinCondition;

            // Add join and query
            if (!empty($this->_joinAnd) && isset($this->_joinAnd[$joinStr])) {
                foreach($this->_joinAnd[$joinStr] as $join_and_cond) {
                    $this->_conditionAnalysis($join_and_cond);
                }
            }
		}
	}

	private function _buildDataQuery($tableData)
	{
		if (!is_array($tableData)) {
			return;
		}

		$isInsert = preg_match('/^[INSERT|REPLACE]/', $this->_query);
		$dataColumns = array_keys($tableData);

		if ($isInsert) {
			if (!empty($dataColumns))
				$this->_query .= ' (`' . implode($dataColumns, '`, `') . '`) ';
			$this->_query .= ' VALUES (';
		} else {
			$this->_query .= ' SET ';
		}

		$this->_buildDataPairs($tableData, $dataColumns, $isInsert);

		if ($isInsert) {
			$this->_query .= ')';
		}
	}

	private function _buildCondition($operator, &$conditions)
    {
    	if (empty($conditions)) {
            return;
        }

        // 准备查询的WHERE部分
        $this->_query .= ' ' . $operator;

        foreach($conditions as $condition) {
            $this->_conditionAnalysis($condition);
        }
    }

    private function _conditionAnalysis($cond)
    {
        // string
        if (is_string($cond)) {
            $this->_query .= " " . $cond;
            return;
        }

        // array
        $concatKey = key($cond);
        if (isset($cond['_scope'])) {
            $this->_query .= " " . $cond[$concatKey] ." (";
            foreach ($cond[0] as $co) {
                $this->_conditionAnalysis($co);
            }
            $this->_query .= ") ";
        } else {
            list ($concat, $varName, $operator, $val) = $cond;
            $this->_query .= " " . $concat ." " . $varName;
            $this->_conditionToSql($operator, $val);
        }
        
    }

    private function _buildGroupBy()
    {
        if (empty($this->_groupBy)) {
            return;
        }

        $this->_query .= " GROUP BY ";

        foreach ($this->_groupBy as $key => $value) {
            $this->_query .= $value . ", ";
        }

        $this->_query = rtrim($this->_query, ', ') . " ";
    }

    private function _buildOrderBy()
    {
        if (empty($this->_orderBy)) {
            return;
        }

        $this->_query .= " ORDER BY ";

        foreach ($this->_orderBy as $prop => $value) {
            if (strtolower(str_replace(" ", "", $prop)) == 'rand()') {
                $this->_query .= "rand(), ";
            } else {
                $this->_query .= $prop . " " . $value . ", ";
            }
        }

        $this->_query = rtrim($this->_query, ', ') . " ";
    }

    private function _buildLimit()
    {
        if (!isset($this->_limit)) {
            return;
        }

        if (is_array($this->_limit)) {
            $this->_query .= ' LIMIT ' . (int) $this->_limit[0] . ', ' . (int) $this->_limit[1];
        } else {
            $this->_query .= ' LIMIT ' . (int) $this->_limit;
        }
    }

    private function _buildOnDuplicate($tableData)
    {
        if (is_array($this->_updateColumns) && !empty($this->_updateColumns)) {
            $this->_query .= " ON DUPLICATE KEY UPDATE ";
            if ($this->_lastInsertId) {
                $this->_query .= $this->_lastInsertId . "=LAST_INSERT_ID (" . $this->_lastInsertId . "), ";
            }
            foreach ($this->_updateColumns as $key => $val) {
                // skip all params without a value
                if (is_numeric($key)) {
                    $this->_updateColumns[$val] = '';
                    unset($this->_updateColumns[$key]);
                } else {
                    $tableData[$key] = $val;
                }
            }
            $this->_buildDataPairs($tableData, array_keys($this->_updateColumns), false);
        }
    }

    /*
     * ON DUPLICATE KEY UPDATE 可以达到以下目的:
     * 向数据库中插入一条记录：
     * 若该数据的主键值/ UNIQUE KEY 已经在表中存在,则执行更新操作, 即UPDATE 后面的操作。
     * 否则插入一条新的记录。
     * 例：INSERT INTO table(id,`name`) VALUES(1, 'zhang') ON DUPLICATE KEY UPDATE `name`='zhang';
     * 如果table中存在id=1的会插入，否则会执行UPDATE `name`='zhang'
     */
    public function onDuplicate($updateColumns, $lastInsertId = null)
    {
        $this->_lastInsertId = $lastInsertId;
        $this->_updateColumns = $updateColumns;
        return $this;
    }

	// 建立数据对
	private function _buildDataPairs($tableData, $dataColumns, $isInsert)
	{
		foreach ($dataColumns as $column) {
			$value = $tableData[$column];

			// update field1 => `field1` t1.field2 => t1.`field2`
			if (!$isInsert) {
				if (strpos($column, '.') === false) {
					$this->_query .= '`' . $column . '` = ';
				} else {
					$this->_query .= str_replace('.', '.`', $column) . '` = ';
				}
			}

			// 判断是否为Db类的实例化  subquery value
			if ($value instanceof Db) {
				$this->_query .= $this->_buildPair('', $value) . ', ';
				continue;
			}

			// simple value
			if (!is_array($value)) {
				$this->_bindParam($value);
				$this->_query .= '?, ';
				continue;
			}

			// function value
			$key = key($value);
			$val = $value[$key];
			switch ($key) {
				case '[I]':
					// ['field' => ['[I]' => '+1']]  =>  field = field + 1
					$this->_query .= $column . $val . ', ';
					break;
				case '[F]':
					// ['field' => ['[F]' => [time()]]] => field = time()
					// ['field' => ['[F]' => [date(?), 'Y-m-d']]] => field = date(?)
					$this->_query .= $val[0] . ', ';
					if (!empty($val[1])) {
						$this->_bindParams($val[1]);
					}
					break;
				case '[N]':
					if ($val == null) {
						// ['field' => ['[N]' => [NULL]]] => field = !field
                        $this->_query .= '!' . $column . ', ';
                    } else {
						// ['field' => ['[N]' => [true]]] => field = false
                        $this->_query .= '!' . $val . ', ';
                    }
					break;
				default:
					throw new \Exception("Wrong operation");
					break;
			}
		}

		// 去除最右侧","
		$this->_query = rtrim($this->_query, ', ');
	}

	// 建立对
	private function _buildPair($operator, $value)
	{
		if (!is_object($value)) {
            $this->_bindParam($value);
            return ' ' . $operator . ' ? ';
        }

        $subQuery = $value->getSubQuery();

        $this->_bindParams($subQuery['params']);

        return ' ' . $operator . ' (' . $subQuery['query'] . ') ' . $subQuery['alias'];
	}

    /*
     * 创建子查询的 DB 对象
     */
    public static function subQuery($prefix = null, $subQueryAlias = null)
    {
        return new self([
            'prefix' => $prefix,
            'subQueryAlias' => $subQueryAlias,
            'isSubQuery' => true
        ]);
    }

    /*
     * 获取子查询的信息
     * @return array
     */
	public function getSubQuery()
	{
		if (!$this->isSubQuery) {
			return;
		}

		array_shift($this->_bindParams);

        $val = [
            'query' => $this->_query,
            'params' => $this->_bindParams,
            'alias' => $this->subQueryAlias
        ];

        $this->reset();

        return $val;
	}

	private function _conditionToSql($operator, $value)
	{
		switch (strtolower($operator)) {
			case 'not in':
			case 'in':
				$comparison = ' ' . $operator. ' (';
				if (is_object($value)) {
                    $comparison .= $this->_buildPair("", $value);
                } else {
                    foreach ($value as $v) {
                        $comparison .= ' ?,';
                        $this->_bindParam($v);
                    }
                }

                $this->_query .= rtrim($comparison, ',').' ) ';
				break;
			case 'not between':
			case 'between':
				$this->_query .= ' ' . $operator . ' ? AND ? ';
                $this->_bindParams($value);
				break;
			case 'not exists':
			case 'exists':
				$this->_query .= ' ' . $operator . $this->_buildPair("", $value);
				break;
			default:
				if (is_array($value)) {
                    $this->_bindParams($value);
				} elseif ($value === null) {
                    $this->_query .= $operator . " NULL";
				} else if ($value != 'DBNULL' || $value == '0') {
                    $this->_query .= $this->_buildPair($operator, $value);
				}
		}
	}

	/*
	 * 对于准备好的语句，需要使用此方法。
	 * 他们需要要与“i”等绑定的字段的数据类型。
	 * 此函数接受输入，确定输入的类型，然后更新param_type
	 */
	private function _determineType($item)
    {
        switch (gettype($item)) {
            case 'NULL':
            case 'string':
                return 's';
                break;
            case 'boolean':
            case 'integer':
                return 'i';
                break;
            case 'blob':
                return 'b';
                break;
            case 'double':
                return 'd';
                break;
        }
        return '';
    }

	private function _bindParam($value)
    {
    	if (isset($this->_bindParams[0])) {
    		$this->_bindParams[0] .= $this->_determineType($value);
    	} else {
    		$this->_bindParams[0] = $this->_determineType($value);
    	}
        
        array_push($this->_bindParams, $value);
    }

    private function _bindParams($values)
    {
        foreach ($values as $value) {
            $this->_bindParam($value);
        }
    }

    private function _prepareQuery()
    {
    	// pdo 预处理
        $stmt = $this->getDb()->prepare($this->_query);
        if ($stmt !== false) {
            if ($this->traceEnabled)
                $this->traceStartQ = microtime(true);
            return $stmt;
        }

        // 数据库查询超时 允许重新连接一次
        if ($this->getDb()->errno === 2006 && $this->autoReconnect === true && $this->autoReconnectCount === 0) {
            $this->connect();
            $this->autoReconnectCount++;
            return $this->_prepareQuery();
        }

        // mysql 错误信息
        $error = $this->getDb()->error;
        $query = $this->_query;
        $errno = $this->getDb()->errno;

        // 重置 mysql 相关参数
        $this->reset();

        throw new \Exception(sprintf('%s query: %s', $error, $query), $errno);
    }

    /**
     * 从php 5.3开始由mysqli提供  引用的数据数组是必需的
     * @return array
     */
    protected function refValues(array &$arr)
    {
        // 示例 https://github.com/facebook/hhvm/issues/5155
        if (strnatcmp(phpversion(), '5.3') >= 0) {
            $refs = [];
            foreach ($arr as $key => $value) {
                $refs[$key] = & $arr[$key];
            }
            return $refs;
        }

        return $arr;
    }

    /**
     * 使用来自绑定变量的变量 替换 ？
     * @return string
     */
    protected function replacePlaceHolders($str, $vals)
    {
        $i = 1;
        $newStr = "";
        if (empty($vals)) {
            return $str;
        }

        while ($pos = strpos($str, "?")) {
            $val = $vals[$i++];
            if (is_object($val)) {
                $val = '[object]';
            }

            if ($val === null) {
                $val = 'NULL';
            }

            $newStr .= substr($str, 0, $pos) . "'" . $val . "'";
            $str = substr($str, $pos + 1);
        }

        $newStr .= $str;
        return $newStr;
    }

    /*
     * 将结果作为关联数组返回，并将$idField字段值用作记录键
     * 例如：
     * [
     *     ['id'=>1, 'name'=>'a'],
     *     ['id'=>2, 'name'=>'b'],
     * ]
     * 用  ->map('id')  变更为
     * [
     *     1 => ['id'=>1, 'name'=>'a'],
     *     2 => ['id'=>2, 'name'=>'b'],
     * ]
     */
    public function map($idField)
    {
        $this->_mapKey = $idField;
        return $this;
    }

    private function _dynamicBindResults(\mysqli_stmt $stmt)
    {
        $parameters = array();
        $results = array();
        /**
         * @see http://php.net/manual/en/mysqli-result.fetch-fields.php
         */
        $mysqlLongType = 252;
        $shouldStoreResult = false;
        $meta = $stmt->result_metadata();
        // 如果$meta是false且sqlstate是true,
        // 会出现没有SQL错误，没结果的情况
        // 类似update/insert/delete
        if (!$meta && $stmt->sqlstate) {
            return [];
        }

        $row = array();
        while ($field = $meta->fetch_field()) {
            if ($field->type == $mysqlLongType) {
                $shouldStoreResult = true;
            }
            if ($this->_nestJoin && $field->table != $this->_tableName) {
                $field->table = substr($field->table, strlen($this->prefix));
                $row[$field->table][$field->name] = null;
                $parameters[] = & $row[$field->table][$field->name];
            } else {
                $row[$field->name] = null;
                $parameters[] = & $row[$field->name];
            }
        }
        // 避免php 5.2和5.3内存不足
        // mysqli为long*和blob*类型分配大量内存
        // 因此，为了避免内存不足问题，使用存储结果
        // https://github.com/joshcam/PHP-MySQLi-Database-Class/pull/119
        if ($shouldStoreResult) {
            $stmt->store_result();
        }

        call_user_func_array(array($stmt, 'bind_result'), $parameters);

        $this->totalCount = 0;
        $this->count = 0;
        while ($stmt->fetch()) {
            if ($this->returnType == 'object') {
                $result = new \stdClass ();
                foreach ($row as $key => $val) {
                    if (is_array($val)) {
                        $result->$key = new \stdClass ();
                        foreach ($val as $k => $v) {
                            $result->$key->$k = $v;
                        }
                    } else {
                        $result->$key = $val;
                    }
                }
            } else {
                $result = array();
                foreach ($row as $key => $val) {
                    if (is_array($val)) {
                        foreach ($val as $k => $v) {
                            $result[$key][$k] = $v;
                        }
                    } else {
                        $result[$key] = $val;
                    }
                }
            }
            $this->count++;
            // 将结果作为关联数据返回
            if ($this->_mapKey) {
                $results[$row[$this->_mapKey]] = count($row) > 2 ? $result : end($result);
            } else {
                array_push($results, $result);
            }
        }
        if ($shouldStoreResult) {
            $stmt->free_result();
        }
        $stmt->close();
        // stored procedures sometimes can return more then 1 resultset
        if ($this->mysqli()->more_results()) {
            $this->mysqli()->next_result();
        }
        // 总记录数
        if (in_array('SQL_CALC_FOUND_ROWS', $this->_queryOptions)) {
            $stmt = $this->mysqli()->query('SELECT FOUND_ROWS()');
            $totalCount = $stmt->fetch_row();
            $this->totalCount = $totalCount[0];
        }

        if ($this->returnType == 'json') {
            return json_encode($results);
        }
        return $results;
    }

    /**
     * 重置相关参数
     * @return object
     */
    protected function reset()
    {
        if ($this->traceEnabled) {
            $this->trace[] = [
            	$this->_lastQuery,
            	(microtime(true) - $this->traceStartQ),
            	$this->_traceGetCaller()
            ];
        }

        $this->_where = [];
        $this->_having = [];
        $this->_join = [];
        $this->_joinAnd = [];
        $this->_orderBy = [];
        $this->_groupBy = [];
        $this->_bindParams = [];
        $this->_query = null;
        $this->_queryOptions = [];
        $this->returnType = 'array';
        $this->_nestJoin = false;
        $this->_forUpdate = false;
        $this->_lockInShareMode = false;
        $this->_tableName = '';
        $this->_lastInsertId = null;
        $this->_updateColumns = null;
        $this->_mapKey = null;
        $this->autoReconnectCount = 0;
        $this->_columns = '';

        return $this;
    }

    /**
     * 获取数据库连接
     * @return object
     */
	public function getDB()
    {
        if (!isset($this->dbLink)) {
            $this->connect();
        }

        return $this->dbLink;
    }

    /**
     * 定义表前缀
     * @return object
     */
	public function setPrefix($prefix = '')
	{
		$this->prefix = $prefix;
		return $this;
	}

    /**
     * 获取表前缀
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

	/**
     * 设置Query选项
     * @return object
     */
	public function setQueryOption($options)
	{
		$allowedOptions = [
			'ALL',
			'DISTINCT',
			'DISTINCTROW',
			'HIGH_PRIORITY',
			'STRAIGHT_JOIN',
			'SQL_SMALL_RESULT',
			'SQL_BIG_RESULT',
			'SQL_BUFFER_RESULT',
			'SQL_CACHE',
			'SQL_NO_CACHE',
			'SQL_CALC_FOUND_ROWS',
            'LOW_PRIORITY',
            'IGNORE',
            'QUICK',
            'MYSQLI_NESTJOIN',
            'FOR UPDATE',
            'LOCK IN SHARE MODE'
        ];
        $options = is_array($options) ? $options : [$options];

        foreach ($options as $option) {
        	$option = strtoupper($option);
        	if (!in_array($option, $allowedOptions)) {
        		throw new \Exception("EWrong query option: " . $option);
        	}
        	
        	if ($option == 'MYSQLI_NESTJOIN') {
        		$this->_nestJoin = true;
        	} elseif ($option == 'FOR UPDATE') {
        		$this->_forUpdate = true;
        	} elseif ($option == 'LOCK IN SHARE MODE') {
        		$this->_lockInShareMode = true;
        	} else {
        		$this->_queryOptions[] = $option;
        	}
        }

        return $this;
	}

    /*
     * 获取SQL结果集的同时获取SQL总记录数
     * mysql的SQL_CALC_FOUND_ROWS 使用 类似count(*) 使用性能更高
     * 在很多分页的程序中都这样写:
     * SELECT COUNT(*) from `table` WHERE ......;  查出符合条件的记录总数
     * SELECT * FROM `table` WHERE ...... limit M,N; 查询当页要显示的数据
     * 可以改成:
     * SELECT SQL_CALC_FOUND_ROWS * FROM `table` WHERE ......  limit M, N;
     * SELECT FOUND_ROWS(); 返回一个数字，指示了在没有LIMIT子句的情况下，第一个SELECT返回了多少行
     * 这样只要执行一次较耗时的复杂查询可以同时得到与不带limit同样的记录条数
     */
    public function withTotalCount()
    {
        $this->setQueryOption('SQL_CALC_FOUND_ROWS');
        return $this;
    }

    /**
     * 表名设置
     * @param $tableName       table name
     */
    public function table($tableName)
    {
        return $this->from($tableName);
    }

    /**
     * 查询表名设置
     * @param $tableName       table name
     */
    public function from($tableName)
    {
        $table = '';
        $alias = '';
        if (is_string($tableName)) {
            // from('table')
            // from('table t')
            $table = $tableName;
        } elseif (is_array($tableName) && count($tableName) > 1) {
            // from(['table', 't'])
            $table = $tableName[0];
            $alias = $tableName[1];
        } elseif (is_array($tableName) && count($tableName) == 1) {
            $index = key($tableName);
            if (is_string($index)) {
                // from(['table' => 't'])
                $table = $index;
                $alias = $tableName[$index];
            } else {
                // from(['table'])
                $table = $tableName[$index];
            }
        } else {
            throw new \Exception("Error Table Name");
        }

        // 判断是否需要增加前缀
        // from('db.table')
        $this->_tableName = strpos($table, '.') === false ? $this->prefix . $table : $table;

        // 判断是否增加别名
        if ($alias != '') {
            $this->_tableName .= ' ' . $alias;
        }

        return $this;
    }

    /**
     * select 表字段
     * @param $columns       table column
     */
    public function select($columns)
    {
        if (is_string($columns)) {
            // select('column1,column2 c2,column3')
            $this->_columns = $columns;
        } elseif (is_array($columns)) {
            foreach ($columns as $index => $value) {
                if (is_string($index)) {
                    // select(['column2' => 'c2'])
                    $this->_columns .= $index . ' AS ' . $value;
                } else {
                    // select(['column1'])
                    $this->_columns .= $value;
                }

                $this->_columns .= ',';
            }
        } else {
            throw new \Exception("Error Table Select");
        }

        $this->_columns = trim($this->_columns, ',');

        return $this;
    }

    /**
     * join 联表
     * @param $joinType        LEFT|RIGHT|OUTER|INNER|LEFT OUTER|RIGHT OUTER|NATURAL
     * @param $joinTable       table name
     * @param $joinCondition   联表的 ON 条件
     * 
     */
    public function join($joinType, $joinTable, $joinCondition)
    {
        // 验证 joinType 有效性
        $allowedTypes = ['LEFT', 'RIGHT', 'OUTER', 'INNER', 'LEFT OUTER', 'RIGHT OUTER', 'NATURAL'];
        $joinType = strtoupper(trim($joinType));
        if ($joinType && !in_array($joinType, $allowedTypes)) {
            throw new \Exception('Wrong JOIN Type: ' . $joinType);
        }

        if (!is_object($joinTable)) {
            $joinTable = $this->prefix . $joinTable;
        }

        // 只有当表名在``中，才会自动加表前缀，以区分别名和表名
        $joinCondition = preg_replace('/(\`)([`a-zA-Z0-9_]*\.)/', '\1' . $this->prefix . '\2', $joinCondition);

        $this->_join[] = [$joinType, $joinTable, $joinCondition];

        return $this;
    }

    /**
     * leftJoin 左联表
     * @param $joinTable       table name
     * @param $joinCondition   联表的 ON 条件
     * 
     */
    public function leftJoin($joinTable, $joinCondition)
    {
        if (!is_object($joinTable)) {
            $joinTable = $this->prefix . $joinTable;
        }

        $this->_join[] = ['LEFT', $joinTable, $joinCondition];

        return $this;
    }

    /**
     * rightJoin 右联表
     * @param $joinTable       table name
     * @param $joinCondition   联表的 ON 条件
     * 
     */
    public function rightJoin($joinTable, $joinCondition)
    {
        if (!is_object($joinTable)) {
            $joinTable = $this->prefix . $joinTable;
        }

        $this->_join[] = ['RIGHT', $joinTable, $joinCondition];

        return $this;
    }

    /**
     * join where 条件
     * @param  $whereJoin       where 条件跟随的 join 位置
     * @param  $whereCondition  where 条件
     * @return object
     */
    public function joinWhere($whereJoin, $whereCondition)
    {
        $data = $this->_whereAnalysis($whereCondition);
        
        $this->_joinAnd[$this->prefix . $whereJoin] = $data;

        return $this;
    }

    /**
     * where 解析成 标准格式
     * @param $whereCondition    where 条件
     * @param $hasCond           是否需要连接符OR/AND
     * @return string/array
     */
    private function _whereAnalysis($whereCondition, $hasCond = true)
    {
        // where('id = 1')
        if (is_string($whereCondition)) {
            return $whereCondition;
        } elseif (is_array($whereCondition)) {
            // 数组长度
            $count = count($whereCondition);
            // 默认值
            $value = 'DBNULL';
            $operator = '=';
            $cond = 'AND';
            $scope = '';
            $scopeData = [];

            switch ($count) {
                case 1:
                    $varName = key($whereCondition);
                    if (is_numeric($varName)) {
                        // where(['name'])
                        $varName = $whereCondition[$varName];
                    } else {
                        // where(['id' => 1])
                        // where(['id' => [1, 2, 3]])
                        $value = $whereCondition[$varName];
                        is_array($value) && $operator = 'in';
                    }
                    
                    break;
                case 2:
                    $varKey = key($whereCondition);
                    $varName = trim($whereCondition[$varKey]);
                    if (in_array(strtoupper($varName), ['OR', 'AND'])) {
                        // where(['or', ['id > 0', ['name' => 'zhang'], ['or', [['id' => 1], ['name', 'like', 'zh%']]], ['age', '!=', 10]]])
                        $scope = strtoupper($varName);
                        $hasCond = false;
                        foreach ($whereCondition[1] as $wc) {
                            // 第一个不需要连接符
                            $scopeData[] = $this->_whereAnalysis($wc, $hasCond);
                            // 标记下一个需要
                            $hasCond = true;
                        }
                            
                    } else {
                        // where(['name', 'zhang'])
                        // where(['name', ['zhang', 'wang', 'li']])
                        $value = $whereCondition[1];
                        is_array($value) && $operator = 'in';
                    }
                    
                    break;
                case 3:
                    // where(['name', '!=', 'zhang'])
                    // where(['name', 'in', ['zhang', 'wang', 'li']])
                    $varName = $whereCondition[0];
                    $operator = $whereCondition[1];
                    $value = $whereCondition[2];
                    break;

                case 4:
                    // where(['OR', 'name', 'like', 'zhang'])
                    // where(['AND', 'name', 'in', ['zhang', 'wang', 'li']])
                    $cond = $whereCondition[0];
                    $varName = $whereCondition[1];
                    $operator = $whereCondition[2];
                    $value = $whereCondition[3];
                    break;

                default:
                    throw new \Exception("Error Where Params");
                    break;
            }

            if ($scope != '') {
                return ['_scope' => $hasCond ? $scope : '', $scopeData];
            }

            return [$hasCond ? $cond : '', $varName, $operator, $value];

        } else {
            throw new \Exception("Error Where Condition");
        }
    }

    /**
     * SQL where 条件
     * @return object
     */
    public function where($whereCondition)
    {
        $data = $this->_whereAnalysis($whereCondition);
        if (count($this->_where) == 0 && is_array($data)) {
            // where 条件开始 不需要连接符
            $dataKey = key($data);
            $data[$dataKey] = '';
        }

        $this->_where[] = $data;
        return $this;
    }

    /**
     * SQL groupBy 条件
     * @return object
     */
    public function groupBy($groupByField)
    {
        if (is_array($groupByField)) {
            foreach ($groupByField as $field) {
                // groupBy(['id', 'age'])
                $this->_groupBy[] = preg_replace("/[^-a-z0-9\.\(\),_\* <>=!]+/i", '', $field);
            }
        } elseif (is_string($groupByField)) {
            // groupBy('id, age')
            $this->_groupBy[] = preg_replace("/[^-a-z0-9\.\(\),_\* <>=!]+/i", '', $groupByField);
        } else {
            throw new \Exception('Wrong Group By Params');
        }

        return $this;
    }

    /**
     * SQL having 条件
     * @return object
     */
    public function having($whereCondition)
    {
        $data = $this->_whereAnalysis($whereCondition);
        if (count($this->_having) == 0 && is_array($data)) {
            // having 条件开始 不需要连接符
            $dataKey = key($data);
            $data[$dataKey] = '';
        }

        $this->_having[] = $data;
        return $this;
    }

    /**
     * orderBy 解析成 标准格式
     * @param $orderBy        Order By 条件
     * @param $data           返回数据
     */
    private function _orderByAnalysis($orderBy, &$data = [])
    {
        if (is_string($orderBy) && strpos($orderBy, ',') !== false) {
            // orderBy('id, age DESC')
            $fields = explode(',', $orderBy);
            foreach ($fields as $field) {
                $this->_orderByAnalysis($field, $data);
            }
        } elseif (is_string($orderBy)) {
            $orderBy = trim($orderBy);
            if (preg_match('|\s|is', $orderBy)) {
                // orderBy('age DESC')
                $result = preg_split('|\s+|', $orderBy);
                $data[] = ['field' => $result[0], 'sort' => $result[1]];
            } else {
                // orderBy('id')
                $data[] = ['field' => $orderBy, 'sort' => 'ASC'];
            }
        } elseif (is_array($orderBy)) {
            // orderBy(['id, age DESC', 'name', 'time' => 'DESC'])
            foreach ($orderBy as $field => $sort) {
                if (is_numeric($field)) {
                    $this->_orderByAnalysis($sort, $data);
                } else {
                    $data[] = ['field' => $field, 'sort' => $sort];
                }
            }
        } else {
            throw new \Exception('Wrong Order By Params');
        }
    }

    /**
     * SQL orderBy 条件
     * @param $customFieldsOrRegExp   自定义排序规则或表达式
     * @return object
     */
    public function orderBy($conditions, $customFieldsOrRegExp = null)
    {
        $this->_orderByAnalysis($conditions, $data);
        if (empty($data)) {
            return $this;
        }

        $allowedDirection = ['ASC', 'DESC'];
        foreach ($data as $order) {
            $orderbyDirection = strtoupper(trim($order['sort']));
            $orderByField = preg_replace("/[^ -a-z0-9\.\(\),_`\*\'\"]+/i", '', $order['field']);

            // 自动增加表前缀
            // 只有当表名在``中，才会自动加表前缀，以区分别名和表名
            $orderByField = preg_replace('/(\`)([`a-zA-Z0-9_]*\.)/', '\1' . $this->prefix . '\2', $orderByField);

            // 验证排序关键字有效性
            if (empty($orderbyDirection) || !in_array($orderbyDirection, $allowedDirection)) {
                throw new \Exception('Wrong Order Direction: ' . $orderbyDirection);
            }

            // 自定义排序
            if (is_array($customFieldsOrRegExp)) {
                // SELECT * FROM table ORDER BY FIELD(status,1,2,0);
                // 结果集是按照字段status的1、2、0进行排序的
                foreach ($customFieldsOrRegExp as $key => $value) {
                    $customFieldsOrRegExp[$key] = preg_replace("/[^\x80-\xff-a-z0-9\.\(\),_` ]+/i", '', $value);
                }
                $orderByField = 'FIELD (' . $orderByField . ', "' . implode('","', $customFieldsOrRegExp) . '")';
            } elseif (is_string($customFieldsOrRegExp)) {
                // SELECT * FROM table ORDER BY id ^(select (select version()) regexp '^5');
                $orderByField = $orderByField . " REGEXP '" . $customFieldsOrRegExp . "'";
            } elseif ($customFieldsOrRegExp !== null) {
                throw new \Exception('Wrong Custom Field OR Regular Expression: ' . $customFieldsOrRegExp);
            }

            $this->_orderBy[$orderByField] = $orderbyDirection;
        }
        
        return $this;
    }

    /**
     * SQL limit
     * @return object
     */
    public function limit($conditions)
    {
        if (is_array($conditions) && count($conditions) == 1) {
            // limit([1 => 10])
            $index = key($conditions);
            $this->_limit = [intval($index), intval($conditions[$index])];
        } elseif (is_array($conditions)) {
            // limit([0, 10])
            $this->_limit = [intval($conditions[0]), intval($conditions[1])];
        } elseif (is_string($conditions) && strpos($conditions, ',') !== false) {
            // limit('0, 10')
            $limitArr = explode(',', $conditions);
            $this->_limit = [intval($limitArr[0]), intval($limitArr[1])];
        } elseif (is_numeric($conditions) || is_string($conditions)) {
            // limit('1') || limit(1)
            $this->_limit = intval($conditions);
        } else {
            throw new \Exception('Wrong Limit Params');
        }

        return $this;
    }

    /*
     * 设置结果集返回数组
     */
    public function asArray()
    {
        $this->returnType = 'array';
        return $this;
    }

    /*
     * 设置结果集返回对象
     */
    public function asObject()
    {
        $this->returnType = 'object';
        return $this;
    }

    /*
     * 设置结果集返回Json
     */
    public function asJson()
    {
        $this->returnType = 'json';
        return $this;
    }

    /*
     * Query 语句
     */
    public function query($query)
    {
        // SQL 语句
        $this->_query = $query;
        // 拼接SQL
        $stmt = $this->_buildQuery();
        // 执行
        $stmt->execute();
        // 错误信息
        $this->_stmtError = $stmt->error;
        $this->_stmtErrno = $stmt->errno;
        // 结果
        $res = $this->_dynamicBindResults($stmt);

        $this->reset();

        return $res;
    }

	/**
     * 获取最终执行的SQL
     * @return string
     */
    public function getLastQuery()
    {
        return $this->_lastQuery;
    }

    /*
     * 获取最后一次插入ID
     */
    public function getInsertId()
    {
        return $this->mysqli()->insert_id;
    }

    /*
     * 返回被转义的字符串。如果失败，则返回 false
     * 下列字符受影响：
     * \x00 \n \r \ ' " \x1a
     * 本函数将 string 中的特殊字符转义，并考虑到连接的当前字符集，
     * 因此可以安全用于query
     */
    public function escape($str)
    {
        return $this->mysqli()->real_escape_string($str);
    }

    /*
     * 如果使用了长连接而长期没有对数据库进行任何操作，
     * 那么在timeout值后，MySQL server就会关闭此连接，
     * 而客户端在执行查询的时候就会得到一个
     * 类似于“mysql server has gone away“这样的错误
     * 一个好的解决方法是使用mysql_ping
     * 但是，mysql_ping会改变mysql_affected_rows的返回值
     * 所以最好是给该MYSQL句柄再加一个读写锁
     * 执行mysql_ping的线程在执行ping之间先尝试获取锁
     */
    public function ping()
    {
        return $this->mysqli()->ping();
    }

    /*
     * 获取数据库错误消息
     */
    public function getLastError()
    {
        if (!isset($this->dbLink)) {
            return "mysqli is null";
        }

        return trim($this->_stmtError . " " . $this->mysqli()->error);
    }

    /*
     * 获取数据库错误码
     */
    public function getLastErrno() {
        return $this->_stmtErrno;
    }

    /*
     * 开启事务
     */
    public function startTransaction()
    {
        // 关闭自动提交
        $this->mysqli()->autocommit(false);
        // 标记开启事务
        $this->_transaction_in_progress = true;

        /* 
         * register_shutdown_function
         * 定义：该函数是来注册一个会在PHP中止(执行完成,exit/die导致的中止,发生致命错误中止)时执行的函数
         * 参数说明：第一个参数支持以数组的形式来调用类中的方法
         * 这个函数主要是用在处理致命错误的后续处理上
         * PHP7更推荐使用Throwable来处理致命错误
        */
        register_shutdown_function(array($this, "transaction_status_check"));
    }

    /*
     * 提交事务
     */
    public function commit()
    {
        // 事务提交
        $result = $this->mysqli()->commit();
        // 标记事务结束
        $this->_transaction_in_progress = false;
        // 开启自动提交
        $this->mysqli()->autocommit(true);

        return $result;
    }

    /*
     * 回滚事务
     */
    public function rollback()
    {
        // 事务回滚
        $result = $this->mysqli()->rollback();
        // 标记事务结束
        $this->_transaction_in_progress = false;
        // 开启自动提交
        $this->mysqli()->autocommit(true);

        return $result;
    }

    /*
     * 程序终止时，将未提交的事务回滚
     */
    public function transaction_status_check()
    {
        if (!$this->_transaction_in_progress) {
            return;
        }
        // 事务回滚
        $this->rollback();
    }

    /*
     * SQL执行时间跟踪
     * @param $enabled    true/false 开关
     * @param $stripPrefix  过滤符号
     */
    public function setTrace($enabled, $stripPrefix = null)
    {
        $this->traceEnabled = $enabled;
        $this->traceStripPrefix = $stripPrefix;
        return $this;
    }

    /**
     * 获取跟踪信息
     * @return string
     */
    private function _traceGetCaller()
    {
        $dd = debug_backtrace();
        $caller = next($dd);
        while (isset($caller) && $caller["file"] == __FILE__) {
            $caller = next($dd);
        }

        return __CLASS__ . "->" . $caller["function"] . "() >>  file \"" .
            str_replace($this->traceStripPrefix, '', $caller["file"]) . "\" line #" . $caller["line"] . " ";
    }

    /*
     * 分页
     * @param  $page  页数
     * @param  $pageSize  每页数量
     */
    public function paginate($page, $pageSize = null)
    {
        if (isset($pageSize)) {
            $this->pageLimit = $pageSize;
        }

        // 起始偏移量
        $offset = $this->pageLimit * ($page - 1);
        // 记录集
        $res = $this->withTotalCount()->limit([$offset, $this->pageLimit])->getAll();
        // 定义
        $this->currentPage = $page;
        $this->totalPages = ceil($this->totalCount / $this->pageLimit);

        return $res;
    }
}