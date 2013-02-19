<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * PostgreSQL Database Driver Result
 * 
 * @author Leon van der Veen <vdvleon@gmail.com>
 */
class Database_PostgreSQL_Result extends Kohana_Database_PostgreSQL_Result
{
	protected function fixTypes($row)
	{
		if ($row instanceof DBO) foreach ($row->columns() as $column) $row->setField($column, $this->fixType($row->getField($column), $column), true);
		else return parent::fixTypes($row);
		return $row;
	}
}
