<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * DBO PostgreSQL
 *
 * @author Leon van der Veen <vdvleon@gmail.com>
 */
class Kohana_DBO_PostgreSQL extends DBO_SQL
{
	/**
	 * Constructor
	 * 
	 * @param string $db
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		if (!($this->db_ instanceof Database_PostgreSQL)) throw new Kohana_Exception('Invalid database configuration provided');
	}

	/**
	 * Upgrade tables
	 * 
	 * @param array $tables
	 */
	public function upgradeTables(array $tables)
	{
		// Transaction
		$this->db_->begin();

		try
		{
			// Post table callbacks
			foreach ($tables as $table) if (is_callable($table['pre'])) call_user_func_array($table['pre'], array(&$table, &$tables));

			// Version hash
			$version = md5(json_encode($tables));

			$sql = '';

			// Scheme
			$sql .= "\n" . '--' . "\n" . '-- Create scheme '. $this->db_->schema() . "\n" . '--' . "\n\n";
			if (!($result = $this->db_->query(Database::SELECT, 'SELECT schema_name FROM information_schema.schemata WHERE schema_name = ' . $this->db_->quote($this->db_->schema()) . ';')) || !$result->valid())
			{
				$sql .= 'CREATE SCHEMA IF NOT EXISTS ' . $this->db_->quote_identifier($this->db_->schema()) . ';' . "\n";
			}
			$sql .= 'COMMENT ON SCHEMA ' . $this->db_->quote_identifier($this->db_->schema()) . ' IS ' . $this->db_->quote('Version ' . $version) . ';' . "\n";
			$sql .= 'SET search_path = ' . $this->db_->quote_identifier($this->db_->schema()) . ', pg_catalog;' . "\n";

			// Remove triggers
			$sql .= "\n";
			$sql .= '--' . "\n" . '-- Remove old triggers' . "\n" . '--' . "\n\n";
			foreach ($this->db_->query(Database::SELECT, 'SELECT DISTINCT trigger_name, event_object_table AS table FROM information_schema.triggers WHERE trigger_schema = ' . $this->db_->quote($this->db_->schema()) . ';') as $trigger)
			{
				$sql .= 'DROP TRIGGER IF EXISTS ' . $this->db_->quote_identifier($trigger['trigger_name']) . ' ON ' . $this->db_->quote_table($trigger['table']) . ' CASCADE;' . "\n";
			}

			// Remove rules
			$sql .= "\n";
			$sql .= '--' . "\n" . '-- Remove old rules' . "\n" . '--' . "\n\n";
			foreach ($this->db_->query(Database::SELECT, 'SELECT rulename AS rule, tablename AS table FROM pg_rules WHERE schemaname = ' . $this->db_->quote($this->db_->schema()) . ';') as $rule)
			{
				$sql .= 'DROP RULE IF EXISTS ' . $this->db_->quote_identifier($rule['rule']) . ' ON ' . $this->db_->quote_table($rule['table']) . ' CASCADE;' . "\n";
			}

			// Remove constraints / indexes
			$sql .= "\n";
			$sql .= '--' . "\n" . '-- Remove old constraints/indexes' . "\n" . '--' . "\n\n";
			foreach ($this->db_->query(Database::SELECT, 'SELECT constraint_name, table_name FROM information_schema.table_constraints WHERE table_schema = ' . $this->db_->quote($this->db_->schema()) . ';') as $constraint)
			{
				$sql .= 'ALTER TABLE ' . $this->db_->quote_table($constraint['table_name']) . ' DROP CONSTRAINT IF EXISTS ' . $this->db_->quote_identifier($constraint['constraint_name']) . ' CASCADE;' . "\n";
			}
			foreach ($this->db_->query(Database::SELECT, 'SELECT i.relname AS index_name FROM pg_index AS idx JOIN pg_class AS i ON i.oid = idx.indexrelid JOIN pg_am AS am ON i.relam = am.oid JOIN pg_namespace as ns ON ns.oid = i.relnamespace AND ns.nspname = ' . $this->db_->quote($this->db_->schema()) . ';') as $index)
			{
				$sql .= 'DROP INDEX IF EXISTS ' . $this->db_->quote_identifier($index['index_name']) . ' CASCADE;' . "\n";
			}

			// Remove old sequences
			$sql .= "\n";
			$sql .= '--' . "\n" . '-- Remove old sequences' . "\n" . '--' . "\n\n";
			$sequences = [];
			foreach ($this->db_->query(Database::SELECT, 'SELECT sequence_name AS seq FROM information_schema.sequences WHERE sequence_schema = ' . $this->db_->quote($this->db_->schema()) . ';') as $seq)
			{
				$sequences[$seq['seq']] = $this->db_->query(Database::SELECT, 'SELECT last_value AS cur FROM ' . $this->db_->quote_identifier($seq['seq']) . ';')->current()['cur'];
				$sql .= 'DROP SEQUENCE IF EXISTS ' . $this->db_->quote_identifier($seq['seq']) . ' CASCADE;' . "\n";
			}

			// Remove old functions
			$sql .= "\n";
			$sql .= '--' . "\n" . '-- Remove old functions' . "\n" . '--' . "\n\n";
			foreach ($this->db_->query(Database::SELECT, 'SELECT routine_name AS func, specific_name FROM information_schema.routines WHERE routine_type = \'FUNCTION\' AND specific_schema = ' . $this->db_->quote($this->db_->schema()) . ';') as $func)
			{
				// DBO function?
				if (
					preg_match('@_(\d+)@', $func['specific_name'], $m)
					&& (
						$comment = $this->db_->query(
							Database::SELECT,
							'SELECT pg_catalog.obj_description(' . DB::expr(':oid')->param(':oid', $m[1])->compile($this->db_) . ') AS cmt;'
						)
					)
					&& $comment->valid()
					&& $comment->current()['cmt'] == 'DBO Generated Function'
				)
				{
					$args = $this->db_->query(Database::SELECT, 'SELECT pg_catalog.pg_get_function_identity_arguments(p.oid) AS args FROM pg_catalog.pg_proc p LEFT JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace WHERE p.proname = ' . $this->db_->quote($func['func']) . ' AND n.nspname = ' . $this->db_->quote($this->db_->schema()) . ';');
					$sql .= 'DROP FUNCTION IF EXISTS ' . $func['func'] . ' (' . $args->current()['args'] . ');' . "\n";
				}
			}

			// Remove old tables
			$removeTables = [];
			foreach ($this->db_->list_tables() as $table) if (!isset($tables[$table])) $removeTables[] = $table;
			if (!empty($removeTables))
			{
				$sql .= "\n" . '--' . "\n" . '-- Remove old tables' . "\n" . '--' . "\n\n";
				foreach ($removeTables as $table) $sql .= 'DROP TABLE IF EXISTS ' . $this->db_->quote_table($table) . ' CASCADE;' . "\n";
			}

			// Functions
			foreach ($tables as $table)
			{
				if (!empty($table['functions']))
				{
					$sql .= "\n" . '--' . "\n" . '-- Functions for ' . $table['table'] . "\n" . '--' . "\n\n";
					foreach ($table['functions'] as $func => $function)
					{
						$sql .= 'CREATE FUNCTION ' . $func . ' ' . $function . ';' . "\n";
						$sql .= 'COMMENT ON FUNCTION ' . $func . ' IS ' . $this->db_->quote('DBO Generated Function') . ';' . "\n";
					}
				}
			}

			// Tables
			foreach ($tables as $table)
			{
				$sql .= "\n" . '--' . "\n" . '-- Create table '. $table['table'] . "\n" . '--' . "\n\n";
				$sql .= 'CREATE TABLE IF NOT EXISTS ' . $this->db_->quote_table($table['table']) . ' ();' . "\n";
				$oldColumns = $this->db_->list_columns($table['table']);
				foreach ($table['columns'] as $col => $column)
				{
					// Column (type)
					switch ($column['type'])
					{
						case DBO::TYPE_STRING:
							if (count($column['args']) == 0) $column['args'][0] = 255;
							if ($column['args'][0] == -1) $type = 'text';
							else $type = 'varchar(' . ((int)$column['args'][0]) . ')';
						break;
						case DBO::TYPE_INTEGER: $type = 'integer'; break;
						case DBO::TYPE_BOOLEAN: $type = 'boolean'; break;
						default: throw new Kohana_Exception('Unsupported field type :type', [':type' => $column['type']]);
					}
					if (isset($oldColumns[$col]))
					{
						// Alter columns
						$sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ALTER COLUMN '. $this->db_->quote_column($col) . ' TYPE ' . $type . ';' . "\n";

						// Nullable
						$sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ALTER COLUMN '. $this->db_->quote_column($col) . ' DROP NOT NULL;' . "\n";
						if (!$column['nullable']) $sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ALTER COLUMN '. $this->db_->quote_column($col) . ' SET NOT NULL;' . "\n";

						// Rest
						if ($column['sql'] !== NULL) $sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ADD ' . $column['sql'] . ';' . "\n";
					}
					else
					{
						// Create column right away
						$sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ADD COLUMN '. $this->db_->quote_column($col) . ' ' . $type;
						if (isset($table['updateDefaults'][$col])) $sql .= ' DEFAULT ' . $table['updateDefaults'][$col];
						$sql .= ' ' . ($column['nullable'] ? 'NULL' : 'NOT NULL');
						if ($column['sql'] !== NULL) $sql .= ' ' . $column['sql'];
						$sql .= ';' . "\n";
					}

					// Unset old column
					unset($oldColumns[$col]);
				}
				foreach ($oldColumns as $col => $column) $sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' DROP COLUMN '. $this->db_->quote_column($col) . ' CASCADE;' . "\n";
				$sql .= 'COMMENT ON TABLE ' . $this->db_->quote_table($table['table']) . ' IS ' . $this->db_->quote(($table['model'] !== NULL ? 'Model ' . $table['model'] . '. ' : '') . 'Table version ' . md5(json_encode($table))) . ';' . "\n";
			}

			// Execute
			$this->db_->query('CREATE', $sql);

			// Post table callbacks
			foreach ($tables as $table) if (is_callable($table['postTable'])) call_user_func_array($table['postTable'], array(&$table, &$tables, &$sequences));

			// Reset sql
			$sql = '';

			// Keys and indexes
			foreach ($tables as $table)
			{
				// Primary key
				$sql .= "\n" . '--' . "\n" . '-- Primary key for ' . $table['table'] . "\n" . '--' . "\n\n";
				$pks = []; foreach ($table['columns'] as $col => $column) if ($column['primaryKey']) $pks[] = $col;
				foreach ($pks as $pk)
				{
					if ($table['columns'][$pk]['autoIncrement'])
					{
						$sql .= 'CREATE SEQUENCE ' . $this->db_->quote_identifier($table['table'] . '_' . $pk . '_seq') . ' START WITH ' . (isset($sequences[$table['table'] . '_' . $pk . '_seq']) ? (int)$sequences[$table['table'] . '_' . $pk . '_seq'] : 1) . ' INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;' . "\n";
						$sql .= 'ALTER SEQUENCE ' . $this->db_->quote_identifier($table['table'] . '_' . $pk . '_seq') . ' OWNED BY ' . $this->db_->quote_table($table['table']) . '.' . $this->db_->quote_column($pk) . ';' . "\n";
						if ($table['columns'][$pk]['default'] === NULL) $tables[$table['table']]['columns'][$pk]['default'] = 'nextval(' . $this->db_->quote($table['table'] . '_' . $pk . '_seq') . '::regclass)';
					}
				}
				$sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ADD CONSTRAINT ' . $this->db_->quote_identifier($table['table'] . '_pk') . ' PRIMARY KEY (' . implode(', ' , array_map([$this->db_, 'quote_column'], $pks)) . ');' . "\n";
				
				// Unique indexes
				if (!empty($table['uniques']))
				{
					$sql .= "\n" . '--' . "\n" . '-- Unique index for ' . $table['table'] . "\n" . '--' . "\n\n";
					foreach ($table['uniques'] as $i => $uniq) $sql .= 'CREATE UNIQUE INDEX ' . $this->db_->quote_identifier($table['table'] . '-' . implode('_', $uniq) . '-unique-' . $i) . ' ON ' . $this->db_->quote_table($table['table']) . ' USING btree (' . implode(', ' , array_map([$this->db_, 'quote_column'], $uniq)) . ');' . "\n";
				}

				// Indexes
				if (!empty($table['indexes']))
				{
					$sql .= "\n" . '--' . "\n" . '-- Indexes for ' . $table['table'] . "\n" . '--' . "\n\n";
					foreach ($table['indexes'] as $i => $index) $sql .= 'CREATE INDEX ' . $this->db_->quote_identifier($table['table'] . '-' . implode('_', $index) . '-index-' . $i) . ' ON ' . $this->db_->quote_table($table['table']) . ' USING btree (' . implode(', ' , array_map([$this->db_, 'quote_column'], $index)) . ');' . "\n";
				}
			}

			// Foreign keys
			foreach ($tables as $table)
			{
				// Foreign keys
				if (!empty($table['foreignKeys']))
				{
					$sql .= "\n" . '--' . "\n" . '-- Foreign keys for ' . $table['table'] . "\n" . '--' . "\n\n";
					foreach ($table['foreignKeys'] as $fk) $sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ADD CONSTRAINT ' . $this->db_->quote_identifier($fk['name']) . ' FOREIGN KEY (' . implode(', ' , array_map([$this->db_, 'quote_column'], $fk['columns'])) . ') REFERENCES ' . $this->db_->quote_table($fk['table']) . ' (' . implode(', ' , array_map([$this->db_, 'quote_column'], $fk['farColumns'])) . ') MATCH FULL ON UPDATE ' . $fk['onUpdate'] . ' ON DELETE ' . $fk['onDelete'] . ';' . "\n";
				}
			}

			// Rules and triggers
			foreach ($tables as $table)
			{
				// Rules
				if (!empty($table['rules']))
				{
					$sql .= "\n" . '--' . "\n" . '-- Rules for ' . $table['table'] . "\n" . '--' . "\n\n";
					foreach ($table['rules'] as $i => $rule) $sql .= 'CREATE RULE ' . $this->db_->quote_identifier($table['table'] . '-' . sha1(json_encode($rule)) . '-rule-' . $i) . ' AS ON ' . $rule['event'] . ' TO ' . $this->db_->quote_table($table['table']) . ' DO ' . strtr($rule['do'], [':commands' => '(' . implode('; ', $rule['commands']) . ')']) . ';' . "\n";
				}

				// Triggers
				if (!empty($table['triggers']))
				{
					$sql .= "\n" . '--' . "\n" . '-- Triggers for ' . $table['table'] . "\n" . '--' . "\n\n";
					foreach ($table['triggers'] as $i => $trigger) $sql .= 'CREATE TRIGGER ' . $this->db_->quote_identifier($table['table'] . '_' . $i . '_trigger') . ' ' . $trigger['type'] . ' ' . strtr($trigger['event'], [':columns' => implode(', ', array_map([$this->db_, 'quote_column'], $trigger['columns']))]) . ' ON ' . $this->db_->quote_table($table['table']) . ' FOR ' . $trigger['for'] . ' EXECUTE PROCEDURE ' . $trigger['function'] . '(' . implode(', ', array_map([$this->db_, 'quote'], $trigger['columns'])) . ');' . "\n";
				}
			}

			// Defaults
			foreach ($tables as $table)
			{
				$sql .= "\n" . '--' . "\n" . '-- Set defaults for table '. $table['table'] . "\n" . '--' . "\n\n";
				foreach ($table['columns'] as $col => $column)
				{
					// Default
					$sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ALTER COLUMN '. $this->db_->quote_column($col) . ' DROP DEFAULT;' . "\n";
					if ($column['default'] !== NULL) $sql .= 'ALTER TABLE ONLY ' . $this->db_->quote_table($table['table']) . ' ALTER COLUMN '. $this->db_->quote_column($col) . ' SET DEFAULT ' . $column['default'] . ';' . "\n";
				}
			}

			// Execute
			$this->db_->query('CREATE', $sql);

			// Commit
			$this->db_->commit();
		}
		catch (Exception $e)
		{
			// Rollback
			$this->db_->rollback();

			throw $e;
		}
	}
}
