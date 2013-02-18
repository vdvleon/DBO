<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * DBO SQL
 *
 * @copyright (c) 2013 Leon van der Veen
 * @author Leon van der Veen <vdvleon@gmail.com>
 */
abstract class Kohana_DBO_SQL
{
	/**
	 * DB
	 * 
	 * @var Database
	 */
	protected $db_ = NULL;

	/**
	 * Constructor
	 * 
	 * @param string $db
	 */
	public function __construct($db)
	{
		$this->db_ = Database::instance($db);
	}

	/**
	 * Upgrade tables
	 * 
	 * @param array $tables
	 */
	abstract public function upgradeTables(array $tables);

	/**
	 * Run event
	 * 
	 * @param array &$tables
	 * @param array $args
	 * @param string $event
	 */
	static protected function runEvent(array &$tables, array $args, $event)
	{
		foreach ($tables as $table) if (isset($table['events'][$event])) call_user_func_array($table['events'][$event], $args);
	}
}
