<?php
class Database
{
	public static function sanitize($string)
	{
		return trim(preg_replace('/\s+/', ' ', $string));
	}

	private $db = null;
	private $logger = null;

	public function __construct($file, Logger $logger)
	{
		$this->db = new PDO("sqlite:$file");
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->logger = $logger;
	}

	public function reset()
	{
		$tables = array();
		foreach ($this->select('name', 'sqlite_master', 'type=\'table\'') as $row)
			$tables[] = $row['name'];
		foreach ($tables as $table)
			$this->exec('DELETE FROM '.$table);
	}

	public function createTable($table, $columns)
	{
		$this->exec("CREATE TABLE IF NOT EXISTS $table (
				id INTEGER PRIMARY KEY, $columns)");
		return $this;
	}

	public function exists($table, $where)
	{
		$result = (array)$this->selectFirst('COUNT(*)', $table, $where);
		return $result['COUNT(*)'] > 0;
	}

	public function select($fields, $table, $where)
	{
		$where = $where ? " WHERE $where" : '';
		return $this->query("SELECT $fields FROM $table$where");
	}

	public function selectFirst($fields, $table, $where)
	{
		return $this->select($fields, $table, $where)->fetchObject();
	}

	public function insert($table, Array $values)
	{
		$refs = self::getRefs($values);
		$cmd = $this->prepare('INSERT INTO '.$table.'
				('.implode(', ', array_keys($values)).')
				VALUES ('.implode(', ', $refs).')');
		foreach ($refs as $column => $ref)
			$cmd->bindParam($ref, $values[$column]);
		$cmd->execute();
		return $this->db->lastInsertId();
	}

	public function update($table, Array $values, Array $where)
	{
		$allValues = array_merge($values, $where);
		$refsValues = self::getRefsEquals($values);
		$refsWhere = self::getRefsEquals($where);

		$cmd = $this->prepare('UPDATE '.$table.'
				SET '.implode(', ', $refsValues).'
				WHERE '.implode(' AND ', $refsWhere));
		foreach (self::getRefs($allValues) as $column => $ref)
			$cmd->bindParam($ref, $allValues[$column]);
		$cmd->execute();
	}

	public function exec($statement)
	{
		return $this->db->exec($statement);
	}

	public function query($query)
	{
		return $this->db->query($query);
	}

	public function prepare($statement)
	{
		return $this->db->prepare($statement);
	}

	private static function getRefs(Array $values)
	{
		return self::mapRefs($values,
				function($column) { return ":$column"; });
	}

	private static function getRefsEquals(Array $values)
	{
		return self::mapRefs($values,
				function($column) { return "$column = :$column"; });
	}

	private static function mapRefs(Array $values, $callback)
	{
		$refs = array();
		foreach ($values as $column => $value)
			$refs[$column] = $callback($column);
		return $refs;
	}

	private function log($message)
	{
		$this->logger->log($message, 'DATABASE');
		return $this;
	}
}