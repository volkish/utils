<?php

abstract class Criteria_Core 
{
	protected $mysql;
	protected $tables = array();

	abstract function string();

	public function __toString() {
		return $this->string();
	}

	public function execute() {
		return $this->mysql->query($this->string());
	}
}


abstract class Criteria_Where extends Criteria_Core 
{
	protected $order = array();
	protected $limit;
	protected $offset;
	protected $where = array();
	
	public function where($column, $op, $value) {
		return $this->and_where($column, $op, $value);
	}

	public function and_where($column, $op, $value) {
		$this->where[] = array('AND' => array($column, $op, $value));

		return $this;
	}

	public function or_where($column, $op, $value) {
		$this->where[] = array('OR' => array($column, $op, $value));

		return $this;
	}

	public function where_open() {
		return $this->and_where_open();
	}

	public function and_where_open() {
		$this->where[] = array('AND' => '(');

		return $this;
	}

	public function or_where_open() {
		$this->where[] = array('OR' => '(');

		return $this;
	}

	public function where_close() {
		return $this->and_where_close();
	}

	public function and_where_close() {
		$this->where[] = array('AND' => ')');

		return $this;
	}

	public function or_where_close() {
		$this->where[] = array('OR' => ')');

		return $this;
	}

	protected function compile_conditions(array $conditions) {
		$last_condition = NULL;

		$sql = '';

		foreach ($conditions as $group) {
			foreach ($group as $logic => $condition) {
				if ($condition === '(') {
					if ( ! empty($sql) AND $last_condition !== '(') {
						$sql .= ' ' . $logic . ' ';
					}

					$sql .= '(';
				}
				elseif ($condition === ')') {
					$sql .= ')';
				}
				else {
					if ( ! empty($sql) AND $last_condition !== '(') {
						$sql .= ' ' . $logic . ' ';
					}

					list($column, $op, $value) = $condition;

					if ($value === NULL) {
						if ($op === '=') {
							$op = 'IS';
						}
						elseif ($op === '!=') {
							$op = 'IS NOT';
						}
					}

					$op = strtoupper($op);

					// Between op
					if ($op === 'BETWEEN' AND is_array($value)) {
						list($min, $max) = $value;

						$value = $this->mysql->quote($min) . ' AND ' . $this->mysql->quote($max);
					}
					// Column compare op
					else if ($op === '==') {
						$op = '=';
					}
					// Default ops
					else {
						$value = $this->mysql->quote($value);
					}

					$sql .= trim($column . ' ' . $op . ' ' . $value);
				}

				$last_condition = $condition;
			}
		}

		$last_condition = NULL;

		return $sql;
	}

	public function order($column, $type = 'ASC') {
		$this->order[] = array($column, $type);

		return $this;
	}

	public function limit($limit) {
		$this->limit = ($limit >= 0 ? $limit : NULL);

		return $this;
	}

	public function offset($offset) {
		$this->offset = ($offset >= 0 ? $offset : NULL);

		return $this;
	}
}

/**
 * INSERT/UPDATE builder
 */
class Datamanager extends Criteria_Where 
{
	protected $ignore  = FALSE;
	protected $replace = FALSE;
	protected $query   = NULL;
	protected $values  = array();

	public static function factory($tables = NULL) {
		return new self($tables);
	}

	public function __construct($tables = NULL) {
		global $con;

		$this->mysql = $con;

		if (NULL !== $tables) {
			$this->tables[] = $tables;
		}
	}

	public function table($table_name) {
		if (NULL !== $table_name) {
			$this->tables[] = $table_name;
		}

		return $this;
	}
	
	public function ignore($state) {
		$this->ignore = (bool) $state;

		return $this;
	}
	
	public function replace($state) {
		$this->replace = (bool) $state;
		
		return $this;
	}

	public function string() {
		if (! $this->query) {
			return FALSE;
		}

		return $this->query;
	}

	protected function get_values_as_string() {
		$values = array();

		foreach ($this->values as $col => $val) {
			$values[] = "$col = $val";
		}

		return implode(', ', $values);
	}

	public function insert(array $columns = NULL, array $values = NULL) {
		if (NULL === $columns OR NULL === $values) {
			if (! $this->values) {
				return FALSE;
			}
			
			$this->query = 
				($this->replace ? 'REPLACE' : 'INSERT') . 
				($this->ignore  ? ' IGNORE' : '') . ' INTO ' . (is_array($this->tables[0]) ? $this->tables[0][0] : $this->tables[0]) . ' ' .
				'SET ' . $this->get_values_as_string();
		}
		else {
			$quoted_values = array();

			for ($i = 0; $i < count($values); $i++) {
				$quoted_values[$i] = array();

				foreach ($values[$i] as $v) {
					$quoted_values[$i][] = $this->mysql->quote($v);
				}

				$quoted_values[$i] = implode(', ', $quoted_values[$i]);
			}

			$this->query = 
			'INSERT INTO ' . (is_array($this->tables[0]) ? $this->tables[0][0] : $this->tables[0]) . ' (`' . (implode('`, `', $columns)). '`) ' .
			'VALUES (' . implode('), (', $quoted_values) . ')';
		}

		return $this;
	}

	public function increment($column, $by = 1) {
		$this->values[$column] = "{$column} + {$by}";

		return $this;
	}

	public function decrement($column, $by = 1) {
		$this->values[$column] = "{$column} - {$by}";

		return $this;
	}

	public function equate($column, $other_column) {
		$this->values[$column] = $other_column;

		return $this;
	}

	public function set($column, $value = NULL) {
		if (is_array($column)) {
			foreach ($column as $c => $v) {
				$this->values[$c] = $this->mysql->quote($v);
			}

			return $this;
		}
		else if (is_string($column)) {
			$this->values[$column] = $this->mysql->quote($value);
		}

		return $this;
	}

	public function update() {
		if ( ! $this->values) {
			return FALSE;
		}

		$sql = "UPDATE " . implode(', ', array_map(function ($table) {
				return is_string($table) ? $table : "{$table[0]} AS {$table[1]}";
			}, $this->tables)) . ' SET ' . $this->get_values_as_string() . ' ';

		if ($this->where) {
			$sql .= 'WHERE ' . $this->compile_conditions($this->where) . ' ';
		}

		if ($this->order) {
			$sql .= 'ORDER BY ' . implode(', ', array_map(function ($order) {
					return trim((is_string($order[0]) ? $order[0] : "{$order[0][1]}.{$order[0][0]}") . " {$order[1]}");
				}, $this->order)) . ' ' ;
		}

		if ($this->limit !== NULL) {
			$sql .= 'LIMIT ' . $this->limit;
		}

		$this->query = $sql;

		return $this;
	}

	public function delete() {
		$sql = "DELETE FROM " . implode(', ', array_map(function ($table) {
				return is_string($table) ? $table : "{$table[0]} AS {$table[1]}";
			}, $this->tables)) . ' ';

		if ($this->where) {
			$sql .= 'WHERE ' . $this->compile_conditions($this->where) . ' ';
		}

		$this->query = $sql;

		return $this;
	}
}



/**
 * SELECT builder
 */
class Criteria extends Criteria_Where 
{
	protected $columns = array();
	protected $joins = array();
	protected $last_join;

	public static function factory($table_name = NULL, $columns = NULL) {
		return new self($table_name, $columns);
	}

	public function __construct($table_name = NULL, $columns = NULL) {
		global $con;

		$this->mysql = $con;
		$this->select($columns);
		$this->from($table_name);
	}

	public function select($column) {
		if (NULL !== $column) {
			if (is_array($column)) {
				foreach ($column as $c) {
					$this->columns[] = $c;
				}
			}
			else {
				for ($i = 0; $i < func_num_args(); $i++) {
					$this->columns[] = func_get_arg($i);
				}
			}
		}

		return $this;
	}

	public function from($table_name)  {
		if (NULL !== $table_name) {
			$this->tables[] = $table_name;
		}

		return $this;
	}

	public function join($table_name, $type = "left") {
		$index = count($this->joins);

		$this->joins[$index] = array($table_name, $type, array());
		$this->last_join = $index;

		return $this;
	}

	public function on($column, $expr, $value_or_column, $quote = FALSE) {
		if ($this->last_join !== NULL) {
			$this->joins[$this->last_join][2][] = array(
				$column, // left column
				$expr,   // expression
				($quote === TRUE ? $this->mysql->quote($value_or_column) : $value_or_column) // right column or value
			);
		}

		return $this;
	}

	protected function compile_join($join) {
		list($table_name, $type, $ons) = $join;

		if ($type) {
			$sql = strtoupper($type).' JOIN';
		}
		else {
			$sql = 'JOIN';
		}

		$sql .= ' ' . (is_string($table_name) ? $table_name : "{$table[0]} AS {$table[1]}") . ' ON ';

		$conditions = array();

		foreach ($ons as $condition) {
			$conditions[] = implode(' ', $condition);
		}

		$sql .= '('.implode(' AND ', $conditions).')';

		return $sql;
	}

	protected function joins_to_string() {
		if ( ! $this->joins) {
			return '';
		}
		
		$statements = array();

		foreach ($this->joins as $join) {
			$statements[] = $this->compile_join($join);
		}

		return implode(' ', $statements);
	}
	
	public function string() {
		$columns = $this->columns;
	
		if (! $columns) {
			$columns[] = '*';
		}

		$sql  = 'SELECT ' .implode(', ', array_map(function ($column) {
				return is_string($column) ? $column : "{$column[1]}.{$column[0]}";
			}, $columns)) . ' ' ;

		$sql .= 'FROM ' . implode(', ', array_map(function ($table) {
				return is_string($table) ? $table : "{$table[0]} AS {$table[1]}";
			}, $this->tables)) . ' ';

		if ($this->joins) {
			$sql .= $this->joins_to_string() . ' ';
		}

		if ($this->where) {
			$sql .= 'WHERE ' . $this->compile_conditions($this->where) . ' ';
		}

		if ($this->order) {
			$sql .= 'ORDER BY ' . implode(', ', array_map(function ($order) {
					return trim((is_string($order[0]) ? $order[0] : "{$order[0][1]}.{$order[0][0]}") . " {$order[1]}");
				}, $this->order)) . ' ' ;
		}

		if ($this->limit !== NULL) {
			$sql .= 'LIMIT ' . $this->limit . ' ';

			if ($this->offset !== NULL) {
				$sql .= 'OFFSET ' . $this->offset;
			}
		}

		return $sql;
	}

}