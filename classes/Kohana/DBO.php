<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Database Object
 *
 * @author Leon van der Veen <vdvleon@gmail.com>
 */
class Kohana_DBO
{
	/**
	 * Sort directions
	 */
	const SORT_ASC	= 'ASC';
	const SORT_DESC	= 'DESC';

	/**
	 * Relation types
	 */
	const RELATION_BELONGS_TO	= 'belongsTo';
	const RELATION_HAS_ONE		= 'hasOne';
	const RELATION_HAS_MANY		= 'hasMany';
	const RELATION_MANY_TO_MANY	= 'manyToMany';

	/**
	 * Relation action
	 */
	const ACTION_CASCADE		= 'CASCADE';
	const ACTION_RESTRICT		= 'RESTRICT';
	const ACTION_NONE			= 'NO ACTION';
	const ACTION_NULL			= 'SET NULL';
	const ACTION_DEFAULT		= 'SET DEFAULT';

	/**
	 * Trigger constants
	 */
	const TRIGGER_TYPE_BEFORE			= 'BEFORE';
	const TRIGGER_TYPE_AFTER			= 'AFTER';
	const TRIGGER_EVENT_INSERT			= 'INSERT';
	const TRIGGER_EVENT_UPDATE			= 'UPDATE';
	const TRIGGER_EVENT_UPDATE_COLUMNS	= 'UPDATE OF :columns';
	const TRIGGER_EVENT_DELETE			= 'DELETE';
	const TRIGGER_FOR_ROW				= 'EACH ROW';
	const TRIGGER_FOR_STATEMENT			= 'EACH STATEMENT';

	/**
	 * Rule constants
	 */
	const RULE_EVENT_INSERT			= 'INSERT';
	const RULE_EVENT_UPDATE			= 'UPDATE';
	const RULE_EVENT_DELETE			= 'DELETE';
	const RULE_EVENT_SELECT			= 'SELECT';
	const RULE_DO_ALSO				= 'ALSO :commands';
	const RULE_DO_INSTEAD			= 'INSTEAD :commands';
	const RULE_DO_INSTEAD_NOTHING	= 'INSTEAD NOTHING';

	/**
	 * Field types
	 */
	const TYPE_INTEGER			= 'integer';
	const TYPE_STRING			= 'string';
	const TYPE_BOOLEAN			= 'bool';

	/**
	 * Primary key
	 */
	const PRIMARY_KEY			= 'primaryKey';

	/**
	 * Extras
	 */
	const EXTRA_NULLABLE		= 'nullable';
	const EXTRA_AUTO_INCREMENT	= 'autoIncrement';

	/**
	 * Db
	 * 
	 * @var string
	 */
	static public $db = NULL;

	/**
	 * Inited
	 * 
	 * @var bool
	 */
	private $inited_ = false;

	/**
	 * Model
	 * 
	 * @var string
	 */
	private $model_ = NULL;

	/**
	 * Object data
	 * 
	 * @var array
	 */
	static private $modelData_ = [];

	/**
	 * Cast data
	 * 
	 * @var array
	 */
	private $castData_ = [];

	/**
	 * Data
	 * 
	 * @var array
	 */
	private $data_ = [];

	/**
	 * Relation cache
	 * 
	 * @var array
	 */
	private $relationCache_ = [];

	/**
	 * Changed
	 * 
	 * @var array
	 */
	private $changed_ = [];

	/**
	 * DB reset
	 * 
	 * @var bool
	 */
	private $dbReset_ = true;

	/**
	 * DB pending
	 * 
	 * @var array
	 */
	private $dbPending_ = [];

	/**
	 * Saved
	 * 
	 * @var bool
	 */
	private $saved_ = false;

	/**
	 * Loaded
	 * 
	 * @var bool
	 */
	private $loaded_ = false;

	/**
	 * DB builder
	 * 
	 * @var Database_Query
	 */
	private $dbBuilder_ = NULL;

	/**
	 * _original_values
	 * 
	 * @var array
	 */
	private $originalValues_ = [];

	/**
	 * DB applied
	 * 
	 * @var array
	 */
	private $dbApplied_ = [];

	/**
	 * Constructor
	 *
	 * @param mixed $id
	 */
	public function __construct($id = NULL)
	{
		$this->init();
		if ($id !== NULL)
		{
			if (is_array($id))
			{
				if (count($id) > 0 && count($id) == count(self::$modelData_[$this->model_]['primaryKey']) && isset($id[0]))
				{
					foreach ($id as $i => $value) $this->whereIs(self::$modelData_[$this->model_]['primaryKey'][$i], $value);
				}
				else foreach ($id as $key => $value) $this->whereIs($key, $value);
				$this->find();
			}
			else if (count(self::$modelData_[$this->model_]['primaryKey']) == 1)
			{
				$this->whereIs(self::$modelData_[$this->model_]['primaryKey'][0], $id)->find();
			}
			else throw new Kohana_Exception('Invalid primary key given: \':id\'', [':id' => $id]);
		}
		else if (!empty($this->castData_))
		{
			$this->load_($this->castData_);
			$this->castData_ = [];
		}
	}

	/**
	 * Update database
	 */
	static public function updateDatabase()
	{
		// Collect models
		foreach (Kohana::list_files('classes/Model') as $file)
		{
			try
			{
				if (preg_match('@/Model/(.+)\.php$@is', $file, $m))
				{
					$model = $m[1];
					$class = 'Model_' . $model;
					if (is_a($class, 'DBO', true)) $class::staticInit();
				}
			}
			catch (Exception $e)
			{}
		}

		// Collect all tables
		$tables = [];
		foreach (self::$modelData_ as $model => $data)
		{
			// Table
			$table = $data['table'];
			if (!isset($tables[$table])) $tables[$table] = [
				'table'			=> $table,
				'model'			=> NULL,
				'columns'		=> [],
				'foreignKeys'	=> [],
				'uniques'		=> [],
				'indexes'		=> [],
				'functions'		=> [],
				'triggers'		=> [],
				'rules'			=> [],
				'updateDefaults' => [],
				'postTable'		=> NULL,
				'pre'			=> NULL,
			];

			// Model
			$tables[$table]['model'] = $model;

			// Columns
			$tables[$table]['columns'] = Arr::merge($tables[$table]['columns'], $data['columns']);

			// Uniques
			$tables[$table]['uniques'] = Arr::merge($tables[$table]['uniques'], $data['uniques']);

			// Indexes
			$tables[$table]['indexes'] = Arr::merge($tables[$table]['indexes'], $data['indexes']);

			// Functions
			$tables[$table]['functions'] = Arr::merge($tables[$table]['functions'], $data['functions']);

			// Triggers
			$tables[$table]['triggers'] = Arr::merge($tables[$table]['triggers'], $data['triggers']);

			// Rules
			$tables[$table]['rules'] = Arr::merge($tables[$table]['rules'], $data['rules']);

			// Update defaults
			$tables[$table]['updateDefaults'] = Arr::merge($tables[$table]['updateDefaults'], $data['updateDefaults']);

			// Post table update
			$tables[$table]['postTable'] = $data['postTableUpdate'];

			// Pre update
			$tables[$table]['pre'] = $data['preUpdate'];
		}
		foreach (self::$modelData_ as $model => $data)
		{
			foreach ($data['relations'] as $rel => $relation)
			{
				if ($relation['getFunction'] === NULL && $relation['setFunction'] === NULL && $relation['type'] == self::RELATION_MANY_TO_MANY)
				{
					// Table
					$table = $relation['through'];
					if (!isset($tables[$table])) $tables[$table] = [
						'table'			=> $table,
						'model'			=> NULL,
						'columns'		=> [],
						'foreignKeys'	=> [],
						'uniques'		=> [],
						'indexes'		=> [],
						'functions'		=> [],
						'triggers'		=> [],
						'rules'			=> [],
						'updateDefaults' => [],
						'postTable'		=> NULL,
						'pre'			=> NULL,
					];

					// Columns
					$columns = [];
					foreach ($relation['foreignKey'] as $i => $fk) $columns[$fk] = $data['columns'][$data['primaryKey'][$i]];
					foreach ($relation['farKey'] as $i => $fk) $columns[$fk] = self::$modelData_[$relation['model']]['columns'][self::$modelData_[$relation['model']]['primaryKey'][$i]];
					foreach ($columns as &$column)
					{
						$column['autoIncrement'] = false;
						$column['nullable'] = false;
						$column['default'] = NULL;
						$column['indexes'] = [];
						$column['uniques'] = [];
						$column['sql'] = NULL;
					}
					$tables[$table]['columns'] = Arr::merge($tables[$table]['columns'], $columns);
				}
			}
		}

		// Collect all foreign keys
		foreach (self::$modelData_ as $model => $data)
		{
			// Table
			$table = $data['table'];
			
			// Foreign keys
			foreach ($data['relations'] as $rel => $relation)
			{
				$onUpdate = $relation['onUpdate'] ?: self::ACTION_CASCADE;
				$onDelete = $relation['onDelete'] ?: self::ACTION_CASCADE;
				if ($relation['getFunction'] !== NULL || $relation['setFunction'] !== NULL) continue;
				switch ($relation['type'])
				{
					case self::RELATION_BELONGS_TO:
						$owner = $table;
						$targets = [self::$modelData_[$relation['model']]['table']];
						$foreignKeys = [$relation['foreignKey']];
						$farKeys = [$relation['farKey']];
					break;
					case self::RELATION_HAS_ONE:
					case self::RELATION_HAS_MANY:
						$owner = self::$modelData_[$relation['model']]['table'];
						$targets = [$table];
						$foreignKeys = [$relation['foreignKey']];
						$farKeys = [$relation['farKey']];
					break;
					case self::RELATION_MANY_TO_MANY:
						$owner = $relation['through'];
						$targets = [$table, self::$modelData_[$relation['model']]['table']];
						$foreignKeys = [$relation['foreignKey'], $relation['farKey']];
						$farKeys = [$data['primaryKey'], self::$modelData_[$relation['model']]['primaryKey']];
					break;
				}
				foreach ($targets as $i => $target)
				{
					$name = $owner . '-' . implode('_', $foreignKeys[$i]) . '-' . implode('_', $farKeys[$i]) . '-' . $target . '-fk';
					$tables[$owner]['foreignKeys'][$name] = [
						'name'			=> $name,
						'relation'		=> $table . '-' . $rel,
						'columns'		=> $foreignKeys[$i],
						'table'			=> $target,
						'farColumns'	=> $farKeys[$i],
						'onUpdate'		=> $onUpdate,
						'onDelete'		=> $onDelete,
					];
				}
			}
		}

		// SQL helper
		$class = 'DBO_' . Kohana::$config->load('database')->{self::$db ?: Database::$default}['type'];
		$sql = new $class(self::$db);
		if (!($sql instanceof DBO_SQL)) throw new Kohana_Exception('Invalid DBO_SQL class: \':class\'', [':class' => $class]);
		$sql->upgradeTables($tables);
	}

	/**
	 * Factory
	 * 
	 * @param string $model
	 * @param mixed $id
	 * @return DBO
	 */
	static public function factory($model = NULL, $id = NULL)
	{
		if (get_called_class() != 'DBO') 
		{
			$class = get_called_class();
			$id = $model;
		}
		else $class = 'Model_' . $model;
		$i = new $class($id);
		if (!($i instanceof DBO)) throw new Kohana_Exception('Class :class is not if type DBO', [':class' => $class]);
		return $i;
	}

	/**
	 * Load
	 * 
	 * @param array $values
	 */
	protected function load_(array $values)
	{
		// Primary key given?
		$this->loaded_ = true;
		foreach (self::$modelData_[$this->model_]['primaryKey'] as $pk)
		{
			if (!array_key_exists($pk, $values) || $values[$pk] === NULL)
			{
				$this->loaded_ = false;
				break;
			}
		}

		// Set vars
		foreach ($values as $column => $value) $this->data_[$column] = $value;

		// Original values
		if ($this->loaded_) $this->originalValues_ = $this->data_;

		return $this;
	}

	/**
	 * Loaded
	 * 
	 * @return bool
	 */
	public function loaded() { return $this->loaded_; }

	/**
	 * Saved
	 * 
	 * @return bool
	 */
	public function saved() { return $this->saved_; }

	/**
	 * Translate key
	 * 
	 * @param string $key
	 * @return string
	 */
	static protected function translateKey($key) { return Inflector::underscore(Inflector::decamelize($key)); }

	/**
	 * Find
	 * 
	 * @return This
	 */
	public function find()
	{
		if ($this->loaded_) throw new Kohana_Exception('Method find() cannot be called on loaded objects');
		$this->build_(Database::SELECT);
		return $this->loadResult_(false);
	}

	/**
	 * Find all
	 * 
	 * @return This
	 */
	public function findAll()
	{
		if ($this->loaded_) throw new Kohana_Exception('Method findAll() cannot be called on loaded objects');
		$this->build_(Database::SELECT);
		return $this->loadResult_(true);
	}

	protected function buildSelect_()
	{
		$columns = array();
		foreach (self::$modelData_[$this->model_]['columns'] as $column => $_) $columns[] = [$this->model_ . '.' . $column, $column];
		return $columns;
	}

	public function fields()
	{
		$columns = array();
		foreach (self::$modelData_[$this->model_]['columns'] as $column => $_) $columns[] = $column;
		return $columns;
	}

	protected function loadResult_($multiple = false)
	{
		$this->dbBuilder_->from([self::$modelData_[$this->model_]['table'], $this->model_]);
		if ($multiple === false) $this->dbBuilder_->limit(1);
		$this->dbBuilder_->select_array($this->buildSelect_());
		if ($multiple === true)
		{
			$result = $this->dbBuilder_->as_object(self::$modelData_[$this->model_]['class'])->execute(self::$db);
			$this->reset();
			return $result;
		}
		else
		{
			$result = $this->dbBuilder_->as_assoc()->execute(self::$db);
			$this->reset();
			if ($result->count() === 1) $this->load_($result->current());
			else $this->clear();
			return $this;
		}
	}

	protected function build_($type)
	{
		switch ($type)
		{
			case Database::SELECT:
				$this->dbBuilder_ = DB::select();
			break;
			case Database::UPDATE:
				$this->dbBuilder_ = DB::update([self::$modelData_[$this->model_]['table'], $this->model_]);
			break;
			case Database::DELETE:
				$this->dbBuilder_ = DB::delete(self::$modelData_[$this->model_]['table']);
		}
		foreach ($this->dbPending_ as $method)
		{
			$this->dbApplied_[$method['name']] = $method['name'];
			call_user_func_array(array($this->dbBuilder_, $method['name']), $method['args']);
		}
		return $this;
	}

	/**
	 * Get
	 * 
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function getField($key, $default = NULL)
	{
		$key = self::translateKey($key);
		if (!array_key_exists($key, $this->data_)) throw new Kohana_Exception('Field \':key\' does not exists in model :model', [':key' => $key, ':model' => $this->model_]);
		else if ($this->data_[$key] === NULL) return $default;
		return $this->data_[$key];
	}

	/**
	 * Set
	 * 
	 * @param string $key
	 * @param mixed $value
	 * @param bool $ignoreChange
	 * @return This
	 */
	public function setField($key, $value, $ignoreChange = false)
	{
		$key = self::translateKey($key);
		if (!array_key_exists($key, $this->data_)) throw new Kohana_Exception('Field \':key\' does not exists', [':key' => $key]);
		else if ($value !== $this->data_[$key])
		{
			$this->data_[$key] = $value;
			if (!$ignoreChange) $this->changed_[$key] = true;
			$this->saved_ = false;
		}
		return $this;
	}

	/**
	 * Save
	 * 
	 * @return This
	 */
	public function save()
	{
		return $this->loaded() ? $this->update() : $this->create();
	}

	/**
	 * PK
	 * 
	 * @return array
	 */
	public function pk()
	{
		$pk = [];
		foreach (self::$modelData_[$this->model_]['primaryKey'] as $k) $pk[$k] = $this->getField($k);
		return $pk;
	}

	/**
	 * As array
	 * 
	 * @param bool $includeSingleRelations
	 * @return array
	 */
	public function asArray(array $whiteList = NULL, array $blackList = NULL, $includeSingleRelations = true, $maxDepth = 2)
	{
		// Array
		$array = ['__loaded__' => false];

		// Blacklist
		if ($blackList === NULL) $blackList = self::$modelData_[$this->model_]['hidden'];

		// Max depth?
		if ($maxDepth > 0)
		{
			// Loaded
			$array['__loaded__'] = true;

			// Columns
			if ($whiteList === NULL)
			{
				foreach (self::$modelData_[$this->model_]['columns'] as $col => $_) if (!in_array($col, $blackList)) $array[$col] = $this->getField($col);
				if ($includeSingleRelations) foreach (self::$modelData_[$this->model_]['relations'] as $rel => $relation) if (!in_array($col, $blackList) && $relation['single']) $array[$rel] = $this->getRelation($rel);
			}
			else foreach ($whiteList as $col) if (!in_array($col, $blackList)) $array[$col] = $this->get($col);
			foreach ($array as $col => $val) if ($val instanceof DBO) $array[$col] = $val->asArray(NULL, NULL, $includeSingleRelations, $maxDepth - 1);
		}

		return $array;
	}

	/**
	 * Is deletable
	 * 
	 * @param array $history
	 * @return bool
	 */
	public function isDeletable(array &$history = NULL)
	{
		if ($history === NULL) $history = [];
		if (isset($history[$this->model_ . '::' . json_encode($this->pk())])) return true;
		$history[$this->model_ . '::' . json_encode($this->pk())] = true;

		// Check all relations
		foreach (self::$modelData_[$this->model_]['relations'] as $rel => $relation)
		{
			if ($relation['getFunction'] !== NULL || $relation['setFunction'] !== NULL) continue;
			switch ($relation['type'])
			{
				case self::RELATION_HAS_ONE:
					$r = $this->getRelation($rel);
					if (
						$r->loaded()
						&& (
							$relation['onDelete'] == self::ACTION_RESTRICT
							|| !$r->isDeletable($history)
						)
					) return false;
				break;
				case self::RELATION_HAS_MANY:
				case self::RELATION_MANY_TO_MANY:
					foreach ($this->getRelation($rel)->findAll() as $r)
					{
						if (
							$r->loaded()
							&& (
								$relation['onDelete'] == self::ACTION_RESTRICT
								|| !$r->isDeletable($history)
							)
						) return false;	
					}
				break;
			}
		}

		return true;
	}

	/**
	 * Delete
	 * 
	 * @param bool $check
	 * @return This
	 */
	public function delete($check = true)
	{
		if (!$this->loaded()) throw new Kohana_Exception('Cannot delete :model model because it is not loaded.', [':model' => $this->model_]);

		// Check
		if ($check && !$this->isDeletable()) throw new Kohana_Exception('Cannot delete :model model because it is not deletable.', [':model' => $this->model_]);

		// Delete
		$delete = DB::delete(self::$modelData_[$this->model_]['table']);
		foreach ($this->pk() as $field => $value) $delete->where($field, '=', $value);
		$delete->execute(self::$modelData_[$this->model_]['db']);
 		
 		return $this->clear();
	}

	/**
	 * 
	 * 
	 * @return This
	 */
	public function reload()
	{
		// Store primary key
		$primaryKey = [];
		foreach (self::$modelData_[$this->model_]['primaryKey'] as $pk) $primaryKey[$pk] = $this->data_[$pk];

		// Reload or just clear
		if ($this->loaded())
		{
			$this->clear();
			foreach ($primaryKey as $pk => $val) $this->where($pk, '=', $val);
			return $this->find();
		}
		else return $this->clear();
	}

	/**
	 * Update
	 * 
	 * @return This
	 */
	public function update()
	{
		if (!$this->loaded()) throw new Kohana_Exception('Can not update an not loaded model');

		// Nothing to update
		if (empty($this->changed_)) return $this;

		// Get data
		$data = array();
		foreach ($this->changed_ as $column => $_) $data[$column] = $this->data_[$column];
		
		// Update a single record
		$update = DB::update(self::$modelData_[$this->model_]['table'])
			->set($data);
		foreach (self::$modelData_[$this->model_]['primaryKey'] as $pk) $update->where($pk, '=', $this->originalValues_[$pk]);
		$update->execute(self::$db);

		// Reset changed
		$this->changed_ = [];
		$this->originalValues_ = $this->data_;

		// Reload
		$this->reload();

		// Saved
		$this->saved_ = $this->loaded_;

		return $this;
	}

	/**
	 * Create
	 * 
	 * @return This
	 */
	public function create()
	{
		if ($this->loaded()) throw new Kohana_Exception('Can not create an already loaded model');

		// Get data
		$data = array();
		foreach ($this->changed_ as $column => $_) $data[$column] = $this->data_[$column];
		
		// Valid data?
		if (count(self::$modelData_[$this->model_]['primaryKey']) > 1)
		{
			foreach (self::$modelData_[$this->model_]['primaryKey'] as $pk)
			{
				if (!isset($data[$pk])) throw new Kohana_Exception('Multi primary key field \':field\' not given', [':field' => $pk]);
			}
		}

		// Insert
		if (empty($data))
		{
			// Insert with defaults
			$db = is_object(self::$db) ? self::$db : Database::instance(self::$db);
			$result = $db->query(Database::INSERT, 'INSERT INTO ' . $db->quote_table(self::$modelData_[$this->model_]['table']) . ' DEFAULT VALUES');
		}
		else
		{
			$result = DB::insert(self::$modelData_[$this->model_]['table'])
				->columns(array_keys($data))
				->values(array_values($data))
				->execute(self::$db);
		}

		// Single primary key?
		if (count(self::$modelData_[$this->model_]['primaryKey']) == 1 && !array_key_exists(self::$modelData_[$this->model_]['primaryKey'][0], $data))
		{
			$this->data_[self::$modelData_[$this->model_]['primaryKey'][0]] = $result[0];
		}

		// Loaded and saved
		$this->loaded_ = $this->saved_ = true;

		// Reset changes
		$this->changed_ = [];
		$this->originalValues_ = $this->data_;

		// Reload
		$this->reload();

		// Saved
		$this->saved_ = $this->loaded_;

		return $this;
	}

	/**
	 * Get belongs to
	 * 
	 * @param array $relation
	 * @return mixed
	 */
	protected function getBelongsTo(array $relation)
	{
		$model = DBO::factory($relation['model']);
		foreach ($relation['farKey'] as $i => $fk)
		{
			$val = $this->getField($relation['foreignKey'][$i]);
			if ($val === NULL) return $model->reset();
			$model->whereIs($relation['model'] . '.' . $fk, $val);
		}
		return $model->find();
	}

	/**
	 * Get has one
	 * 
	 * @param array $relation
	 * @return mixed
	 */
	protected function getHasOne(array $relation)
	{
		return $this->getHasMany($relation)->find();
	}

	/**
	 * Get has many
	 * 
	 * @param array $relation
	 * @return mixed
	 */
	protected function getHasMany(array $relation)
	{
		$model = DBO::factory($relation['model']);
		foreach ($relation['farKey'] as $i => $fk) $model->whereIs($model->model_ . '.' . $relation['foreignKey'][$i], $this->getField($fk));
		return $model;
	}

	/**
	 * Get many to many
	 * 
	 * @param array $relation
	 * @return mixed
	 */
	protected function getManyToMany(array $relation)
	{
		$model = DBO::factory($relation['model']);
		$through = $relation['through'];
		$model->join($through);
		foreach (self::$modelData_[$model->model_]['primaryKey'] as $i => $pk)
		{
			$model->on($through . '.' . $relation['farKey'][$i], '=', $relation['model'] . '.' . $pk);
			$model->whereIs($through . '.' . $relation['foreignKey'][$i], $this->getField(self::$modelData_[$this->model_]['primaryKey'][$i]));
		}
		return $model;
	}

	/**
	 * Get relation
	 * 
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function getRelation($key, $default = NULL)
	{
		$key = self::translateKey($key);
		if (!isset(self::$modelData_[$this->model_]['relations'][$key])) throw new Kohana_Exception('Relation \':key\' does not exists', [':key' => $key]);
		else if (isset($this->relationCache_[$key])) return $this->relationCache_[$key];
		else if (self::$modelData_[$this->model_]['relations'][$key]['getFunction'] !== NULL)
		{
			$args = [];
			if (isset(self::$modelData_[$this->model_]['relations'][$key]['getArgs'])) $args = (array)self::$modelData_[$this->model_]['relations'][$key]['getArgs'];
			array_unshift($args, $key, $default);
			$result = call_user_func_array(array($this, self::$modelData_[$this->model_]['relations'][$key]['getFunction']), $args);
			if (self::$modelData_[$this->model_]['relations'][$key]['cached']) $this->relationCache_[$key] = $result;
			return $result;
		}
		else if (self::$modelData_[$this->model_]['relations'][$key]['setFunction'] !== NULL) throw new Kohana_Exception('This relation is write only');
		$model = DBO::factory(self::$modelData_[$this->model_]['relations'][$key]['model']);
		switch (self::$modelData_[$this->model_]['relations'][$key]['type'])
		{
			case self::RELATION_BELONGS_TO:		$model = $this->getBelongsTo(self::$modelData_[$this->model_]['relations'][$key]);	break;
			case self::RELATION_HAS_ONE:		$model = $this->getHasOne(self::$modelData_[$this->model_]['relations'][$key]);		break;
			case self::RELATION_HAS_MANY:		$model = $this->getHasMany(self::$modelData_[$this->model_]['relations'][$key]);	break;
			case self::RELATION_MANY_TO_MANY:	$model = $this->getManyToMany(self::$modelData_[$this->model_]['relations'][$key]);	break;
		}
		if (self::$modelData_[$this->model_]['relations'][$key]['cached']) $this->relationCache_[$key] = $model;
		return $model;
	}

	/**
	 * Set relation
	 * 
	 * @param string $key
	 * @param mixed $value
	 * @return This
	 */
	public function setRelation($key, $value)
	{
		$key = self::translateKey($key);
		if (!isset(self::$modelData_[$this->model_]['relations'][$key])) throw new Kohana_Exception('Relation \':key\' does not exists', [':key' => $key]);
		else if (self::$modelData_[$this->model_]['relations'][$key]['setFunction'] !== NULL)
		{
			$args = [];
			if (isset(self::$modelData_[$this->model_]['relations'][$key]['setArgs'])) $args = (array)self::$modelData_[$this->model_]['relations'][$key]['setArgs'];
			array_unshift($args, $key, $value);
			$result = call_user_func_array(array($this, self::$modelData_[$this->model_]['relations'][$key]['setFunction']), $args);
			if (self::$modelData_[$this->model_]['relations'][$key]['cached']) $this->relationCache_[$key] = $result;
			return $this;
		}
		else if (self::$modelData_[$this->model_]['relations'][$key]['getFunction'] !== NULL) throw new Kohana_Exception('This relation is read only');
		switch (self::$modelData_[$this->model_]['relations'][$key]['type'])
		{
			case self::RELATION_BELONGS_TO:
				$this->relationCache_[$key] = $value;
				foreach (self::$modelData_[$this->model_]['relations'][$key]['foreignKey'] as $i => $fk) 
				{
					$this->setField($fk, ($value instanceof DBO) ? $value->getField(self::$modelData_[$this->model_]['relations'][$key]['farKey'][$i]) : NULL);
				}
			break;
			case self::RELATION_HAS_ONE:
				if (!($value instanceof DBO)) throw new Kohana_Exception('Invalid data provided for setRelation');
				$this->relationCache_[$key] = $value;
				foreach (self::$modelData_[$this->model_]['relations'][$key]['farKey'] as $i => $fk)
				{
					$value->setField(self::$modelData_[$value->model_]['relations'][$key]['foreignKey'][$i], $this->getField($fk));
				}
				$value->save();
			break;
			case self::RELATION_HAS_MANY:
				if (!is_array($value)) $value = [$value];
				foreach ($value as $val) if (!($val instanceof DBO)) throw new Kohana_Exception('Invalid data provided for setRelation');
				foreach ($value as $val)
				{
					foreach (self::$modelData_[$this->model_]['relations'][$key]['farKey'] as $i => $fk)
					{
						$val->setField(self::$modelData_[$val->model_]['relations'][$key]['foreignKey'][$i], $this->data_[$fk]);
					}
					$val->save();
				}
			break;
			case self::RELATION_MANY_TO_MANY:
				$through = self::$modelData_[$this->model_]['relations'][$key]['through'];
				if (!is_array($value)) $value = [$value];
				foreach ($value as $val) if (!($val instanceof DBO)) throw new Kohana_Exception('Invalid data provided for setRelation');
				foreach ($value as $val)
				{
					$columns = [];
					$data = [];
					foreach (self::$modelData_[$this->model_]['relations'][$key]['foreignKey'] as $i => $fk)
					{
						$columns[] = $fk;
						$data[] = $this->getField(self::$modelData_[$this->model_]['primaryKey'][$i]);
					}
					foreach (self::$modelData_[$this->model_]['relations'][$key]['farKey'] as $i => $fk)
					{
						$columns[] = $fk;
						$data[] = $val->getField(self::$modelData_[$val->model_]['primaryKey'][$i]);
					}
					DB::insert($through, $columns)
						->values($data)
						->execute(self::$db);
				}
			break;
		}
		return $this;
	}

	/**
	 * Queue DB function call
	 * 
	 * @param string $name
	 * @param array $arguments
	 * @return This
	 */
	private function queueDB($name, array $arguments)
	{
		$this->dbPending_[] = ['name' => $name, 'args' => $arguments];
		return $this;
	}

	public function join() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function on() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function where() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function and_having() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function and_having_close() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function and_having_open() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function and_where() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function and_where_close() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function and_where_open() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function distinct() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function from() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function group_by() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function having() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function having_close() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function having_open() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function limit() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function offset() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function or_having() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function or_having_close() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function or_having_open() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function or_where() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function or_where_close() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function or_where_open() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function order_by() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function param() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function select() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function using() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function where_close() { return $this->queueDB(__FUNCTION__, func_get_args()); }
	public function where_open() { return $this->queueDB(__FUNCTION__, func_get_args()); }

	/**
	 * Get
	 * 
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	public function get($key, $default = NULL)
	{
		$key = self::translateKey($key);
		if (strlen($key) == 0) throw new Kohana_Exception('Invalid key \':key\' for get', [':key' => $key]);
		else if (isset(self::$modelData_[$this->model_]['relations'][$key])) return $this->getRelation($key, $default);
		else return $this->getField($key, $default);
	}

	/**
	 * __get
	 * 
	 * @param string $key
	 * @return mixed
	 */
	public function __get($key) { return $this->get($key); }

	/**
	 * Set
	 * 
	 * @param string $key
	 * @param mixed $value
	 * @return This
	 */
	public function set($key, $value)
	{
		$key = self::translateKey($key);
		if (strlen($key) == 0) throw new Kohana_Exception('Invalid key \':key\' for set', [':key' => $key]);
		else if (!$this->inited_) { $this->castData_[$key] = $value; return $this; }
		else if (isset(self::$modelData_[$this->model_]['relations'][$key])) return $this->setRelation($key, $value);
		else return $this->setField($key, $value);
	}

	/**
	 * __set
	 * 
	 * @param string $key
	 * @param mixed $value
	 */
	public function __set($key, $value) { $this->set($key, $value); }

	/**
	 * Call
	 * 
	 * @param string $key
	 * @param array $args
	 * @return mixed
	 */
	public function __call($key, array $args)
	{
		if (count($args) == 0) return $this->get($key);
		return $this->set($key, $args[0]);
	}

	/**
	 * Where is
	 * 
	 * @see where
	 * @param mixed $key
	 * @param mixed $value
	 * @return This
	 */
	public function whereIs($key, $value) { return $this->where($key, '=', $value); }

	/**
	 * Init
	 */
	private function init()
	{
		$this->model_ = self::staticInit();
		$this->clear();
		$this->inited_ = true;
	}

	/**
	 * Supported types
	 * 
	 * @return array
	 */
	static public function supportedTypes()
	{
		$types = [];
		$r = new ReflectionClass('DBO');
		foreach ($r->getConstants() as $name => $val) if (strpos($name, 'TYPE_') === 0) $types[] = $val;
		return $types;
	}

	/**
	 * Static init
	 * 
	 * @return string Object model
	 */
	static private function staticInit()
	{
		// Class
		$class = get_called_class();
		if (strpos($class, 'Model_') !== 0)
		{
			throw new Kohana_Exception('Can not make an instance of an DBO object in a not Model (Model_) context (for class name :class', [':class' => $class]);
		}

		// Model
		$model = substr(get_called_class(), 6);

		// Already inited?
		if (isset(self::$modelData_[$model])) return $model;

		// Table
		if (isset($class::$table)) $table = $class::$table;
		else $table = Inflector::plural(Inflector::underscore(Inflector::decamelize($model)));

		// Primary key
		if (isset($class::$primaryKey)) $primaryKey = $class::$primaryKey;
		else $primaryKey = [];
		if (!is_array($primaryKey)) $primaryKey = [$primaryKey];
		foreach ($primaryKey as &$pk__) $pk__ = self::translateKey($pk__);

		// Hidden
		if (isset($class::$hidden)) $hidden = $class::$hidden;
		else $hidden = [];
		if (!is_array($hidden)) $hidden = [$hidden];
		foreach ($hidden as &$hidden__) $hidden__ = self::translateKey($hidden__);

		// DB
		if (isset($class::$db)) $db = $class::$db;
		else $db = NULL;

		// Functions
		if (isset($class::$functions)) $functions = $class::$functions;
		else $functions = [];
		if (!is_array($functions)) throw new Kohana_Exception('Invalid $functions config, expected in form $functions = [\'name\' => \'SQL\', ...]');
		foreach ($functions as &$function) $function = trim($function);

		// Update defaults
		if (isset($class::$updateDefaults)) $updateDefaults_ = $class::$updateDefaults;
		else $updateDefaults_ = [];
		if (!is_array($updateDefaults_)) throw new Kohana_Exception('Invalid $updateDefaults config, expected in form $updateDefaults = [\'field\' => \'DEFAULT\', ...]');
		$updateDefaults = [];
		foreach ($updateDefaults_ as $key => $value) $updateDefaults[self::translateKey($key)] = $value;
		
		// Pre update
		if (is_callable([$class, 'preUpdate'])) $preUpdate = [$class, 'preUpdate'];
		else $preUpdate = NULL;

		// Post table update
		if (is_callable([$class, 'postTableUpdate'])) $postTableUpdate = [$class, 'postTableUpdate'];
		else $postTableUpdate = NULL;

		// Triggers
		if (isset($class::$triggers)) $triggers = $class::$triggers;
		else $triggers = [];
		if (!is_array($triggers)) throw new Kohana_Exception('Invalid $triggers config, expected in form $triggers = [[\'type\' => \'TYPE\', \'event\' => \'EVENT\', \'for\' => \'FOR\', \'function\' => \'FUNCTION\', \'args\' => [\'arg\', \'arg2\', ...], \'columns\' => [\'COLUMN1\', ...]]]');
		$isFlat = count($triggers) > 0;
		foreach ($triggers as $trigger)
		{
			if (is_array($trigger))
			{
				$isFlat = false;
				break;
			}
		}
		if ($isFlat) $triggers = [$triggers];
		foreach ($triggers as &$trigger)
		{
			if (
				!is_array($trigger)
				|| !isset($trigger['type'])
				|| !isset($trigger['event'])
				|| !isset($trigger['function'])
			)
			{
				throw new Kohana_Exception('Invalid $triggers config, expected in form $triggers = [[\'type\' => \'TYPE\', \'event\' => \'EVENT\', \'for\' => \'FOR\', \'function\' => \'FUNCTION\', \'args\' => [\'arg\', \'arg2\', ...], \'columns\' => [\'COLUMN1\', ...]]');
			}
			$trigger['for'] = Arr::get($trigger, 'for', NULL);
			if ($trigger['for'] === NULL) $trigger['for'] = self::TRIGGER_FOR_ROW;
			$trigger['when'] = Arr::get($trigger, 'when', NULL);
			$trigger['columns'] = Arr::get($trigger, 'columns', []);
			if (!is_array($trigger['columns'])) $trigger['columns'] = [$trigger['columns']];
			$trigger['args'] = Arr::get($trigger, 'args', []);
			if (!is_array($trigger['args'])) $trigger['args'] = [$trigger['args']];
			if (is_array($trigger['event'])) $trigger['event'] = implode(' OR ', $trigger['event']);
		}

		// Rules
		if (isset($class::$rules)) $rules = $class::$rules;
		else $rules = [];
		if (!is_array($rules)) throw new Kohana_Exception('Invalid $rules config, expected in form $rules = [[\'event\' => \'EVENT\', \'where\' => \'WHERE\', \'do\' => \'DO\', \'commands\' => [\'COMMAND1\', ...]]]');
		$isFlat = count($rules) > 0;
		foreach ($rules as $rule)
		{
			if (is_array($rule))
			{
				$isFlat = false;
				break;
			}
		}
		if ($isFlat) $rules = [$rules];
		foreach ($rules as &$rule)
		{
			if (
				!is_array($rule)
				|| !isset($rule['event'])
				|| !isset($rule['do'])
			)
			{
				throw new Kohana_Exception('Invalid $rules config, expected in form $rules = [[\'event\' => \'EVENT\', \'where\' => \'WHERE\', \'do\' => \'DO\', \'commands\' => [\'COMMAND1\', ...]]]');
			}
			$rule['where'] = Arr::get($rule, 'where', NULL);
			$rule['commands'] = Arr::get($rule, 'commands', []);
			if (!is_array($rule['commands'])) $rule['commands'] = [$rule['commands']];
		}

		// Columns
		if (!isset($class::$columns)) throw new Kohana_Exception('No columns provided');
		$columns = [];
		foreach ($class::$columns as $key => $col)
		{
			if (is_numeric($key))
			{
				if (!is_string($col)) throw new Kohana_Exception('Invalid column definition: :column', [':column' => json_encode($col)]);
				$key = $col;
				$col = [];
			}
			else if (!is_array($col)) $col = [$col];
			$key = self::translateKey($key);
			$column = [
				'type'			=> NULL,
				'primaryKey'	=> NULL,
				'args'			=> [],
				'nullable'		=> false,
				'autoIncrement'	=> NULL,
				'default'		=> NULL,
				'sql'			=> NULL,
			];
			foreach ($col as $k => $value)
			{
				if (is_numeric($k))
				{
					if (in_array($value, self::supportedTypes())) $column['type'] = $value;
					else if ($value == self::PRIMARY_KEY) $column['primaryKey'] = true;
					else if ($value == self::EXTRA_NULLABLE) $column['nullable'] = true;
					else if ($value == self::EXTRA_AUTO_INCREMENT) $column['autoIncrement'] = true;
					else $column['args'][] = $value;
				}
				else $column[$k] = $value;
			}
			if ($column['primaryKey'] === NULL && in_array($key, $primaryKey)) $column['primaryKey'] = true;
			$columns[$key] = $column;
		}

		// Primary keys valid?
		foreach ($columns as $col => $column) if ($column['primaryKey'] && !in_array($col, $primaryKey)) $primaryKey[] = $col;
		if (count($primaryKey) == 0 && isset($columns['id']))
		{
			$primaryKey = ['id'];
			$columns['id']['primaryKey'] = true;
		}
		foreach ($primaryKey as $pk) if (!isset($columns[$pk]) || !$columns[$pk]['primaryKey']) throw new Kohana_Exception('Invalid primaryKey :pk defined', [':pk' => $pk]);
		if (count($primaryKey) == 0) throw new Kohana_Exception('No primaryKey defined');

		// More column defaults
		foreach ($columns as $key => &$column)
		{
			if (count($primaryKey) == 1 && $column['primaryKey'] && $column['type'] == self::TYPE_INTEGER && $column['autoIncrement'] === NULL) $column['autoIncrement'] = true;
			if ($column['primaryKey'] && $column['nullable']) throw new Kohana_Exception('Primary key column :column may not be nullable', [':column' => $key]);
			if ($column['type'] === NULL)
			{
				if ($column['primaryKey']) $column['type'] = self::TYPE_INTEGER;
				else
				{
					$column['type'] = self::TYPE_STRING;
					$column['args'][] = 255;
				}
			}
		}

		// Unique
		if (isset($class::$uniques)) $uniques = $class::$uniques;
		else $uniques = [];
		if (is_string($uniques)) $uniques = [[$uniques]];
		else if (!is_array($uniques)) throw new Kohana_Exception('Invalid $uniques config provided, expected in form: $uniques = [[\'field1\', \'field2\'],[\'field3\'], ...]');
		foreach ($uniques as &$uniq_)
		{
			if (is_string($uniq_)) $uniq_ = [$uniq_];
			foreach ($uniq_ as &$uniq__) $uniq__ = self::translateKey($uniq__);
		}

		// Indexes
		if (isset($class::$indexes)) $indexes = $class::$indexes;
		else $indexes = [];
		if (is_string($indexes)) $indexes = [[$indexes]];
		else if (!is_array($indexes)) throw new Kohana_Exception('Invalid $indexes config provided, expected in form: $indexes = [[\'field1\', \'field2\'],[\'field3\'], ...]');
		foreach ($indexes as &$index_)
		{
			if (is_string($index_)) $index_ = [$index_];
			foreach ($index_ as &$index__) $index__ = self::translateKey($index__);
		}

		// Store object data
		self::$modelData_[$model] = [
			'model'			=> $model,
			'class'			=> $class,
			'table'			=> $table,
			'columns'		=> $columns,
			'hidden'		=> $hidden,
			'primaryKey'	=> $primaryKey,
			'relations'		=> [],
			'db'			=> $db,
			'uniques'		=> $uniques,
			'indexes'		=> $indexes,
			'functions'		=> $functions,
			'triggers'		=> $triggers,
			'rules'			=> $rules,
			'updateDefaults' => $updateDefaults,
			'postTableUpdate' => $postTableUpdate,
			'preUpdate'		=> $preUpdate,
		];

		// Relations
		if (isset($class::$relations)) $relations = $class::$relations;
		else $relations = [];
		if (!is_array(self::$modelData_[$model]['relations'])) throw new Kohana_Exception('Invalid $relations var in class :class', [':class' => $class]);
		foreach ($relations as $key => $relation)
		{
			$key = self::translateKey($key);
			if (!is_array($relation)) $relation = [];
			if (!isset($relation['type'])) $relation['type'] = self::RELATION_BELONGS_TO;
			if (!isset($relation['model'])) $relation['model'] = ucfirst(Inflector::camelize(Inflector::humanize(Inflector::singular($key))));
			if (!isset($relation['getFunction'])) $relation['getFunction'] = isset($relation['get']) ? $relation['get'] : NULL;
			if (!isset($relation['setFunction'])) $relation['setFunction'] = isset($relation['set']) ? $relation['set'] : NULL;
			unset($relation['get']);
			unset($relation['set']);
			if (!isset($relation['cached'])) $relation['cached'] = true;
			if (!isset($relation['through'])) $relation['through'] = NULL;
			if (!isset($relation['onUpdate'])) $relation['onUpdate'] = NULL;
			if (!isset($relation['onDelete'])) $relation['onDelete'] = NULL;
			if ($relation['type'] == self::RELATION_MANY_TO_MANY && $relation['through'] === NULL) throw new Kohana_Exception('Invalid relation \':key\': expected \'through\' option for many-to-many relation', [':key' => $key]);
			if ($relation['getFunction'] === NULL && $relation['setFunction'] === NULL)
			{
				$clss = 'Model_' . $relation['model']; $clss::staticInit();
				if (!isset($relation['foreignKey']))
				{
					$relation['foreignKey'] = [];
					switch ($relation['type'])
					{
						case self::RELATION_BELONGS_TO:
							$prefix = Inflector::singular($key) . '_';
							foreach (self::$modelData_[$relation['model']]['primaryKey'] as $pk) $relation['foreignKey'][] = $prefix . $pk;
						break;
						case self::RELATION_HAS_ONE:
						case self::RELATION_HAS_MANY:
						case self::RELATION_MANY_TO_MANY:
							$prefix = self::translateKey($model) . '_';
							foreach ($primaryKey as $pk) $relation['foreignKey'][] = $prefix . $pk;
						break;
					}
				}
				if (!isset($relation['farKey']))
				{
					$relation['farKey'] = [];
					switch ($relation['type'])
					{
						case self::RELATION_BELONGS_TO:
							foreach (self::$modelData_[$relation['model']]['primaryKey'] as $pk) $relation['farKey'][] = $pk;
						break;
						case self::RELATION_HAS_ONE:
						case self::RELATION_HAS_MANY:
							foreach ($primaryKey as $pk) $relation['farKey'][] = $pk;
						break;
						case self::RELATION_MANY_TO_MANY:
							$prefix = self::translateKey($relation['model']) . '_';
							foreach (self::$modelData_[$relation['model']]['primaryKey'] as $pk) $relation['farKey'][] = $prefix . $pk;
						break;
					}
				}
			}
			if (!isset($relation['single'])) $relation['single'] = ($relation['getFunction'] === NULL && $relation['setFunction'] === NULL) ? ($relation['type'] == self::RELATION_BELONGS_TO || $relation['type'] == self::RELATION_HAS_ONE) : NULL;
			self::$modelData_[$model]['relations'][$key] = $relation;
		}
		return $model;
	}

	public function reset($next = true)
	{
		if ($next AND $this->dbReset_)
		{
			$this->dbPending_   = array();
			$this->dbApplied_   = array();
			$this->dbBuilder_   = NULL;
		}
		$this->dbReset_ = $next;
		return $this;
	}

	public function clear()
	{
		$this->data_ = $this->changed_ = $this->relationCache_ = $this->originalValues_ = [];
		$this->load_(array_combine(array_keys(self::$modelData_[$this->model_]['columns']), array_fill(0, count(self::$modelData_[$this->model_]['columns']), NULL)));
		$this->loaded_ = $this->saved_ = false;
		return $this->reset();
	}

	/**
	 * Get params
	 * 
	 * @return array
	 */
	public function getParams()
	{
		$params = [];
		foreach ($this->pk() as $key => $value) $params[preg_replace('@Id$@', 'ID', Inflector::camelize(Inflector::decamelize($this->model_) . ' ' . $key))] = $value;
		return $params;
	}
}
