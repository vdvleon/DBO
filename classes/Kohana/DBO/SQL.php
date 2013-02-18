<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * DBO SQL
 *
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
}
