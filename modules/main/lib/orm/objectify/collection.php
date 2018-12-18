<?php
/**
 * Bitrix Framework
 * @package    bitrix
 * @subpackage main
 * @copyright  2001-2018 Bitrix
 */

namespace Bitrix\Main\ORM\Objectify;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ORM\Fields\FieldTypeMask;
use Bitrix\Main\SystemException;

/**
 * Collection of entity objects. Used to hold 1:N and N:M object collections.
 *
 * @property-read \Bitrix\Main\ORM\Entity $entity
 *
 * @package    bitrix
 * @subpackage main
 */
abstract class Collection implements \ArrayAccess, \Iterator, \Countable
{
	/**
	 * Entity Table class. Read-only property.
	 * @var DataManager
	 */
	static public $dataClass;

	/** @var Entity */
	protected $_entity;

	/** @var  EntityObject[] */
	protected $_objects = [];

	/** @var bool */
	protected $_isFilled = false;

	/** @var bool */
	protected $_isSinglePrimary;

	/** @var array [SerializedPrimary => OBJECT_CHANGE_CODE] */
	protected $_objectsChanges;

	/** @var  EntityObject[] */
	protected $_objectsRemoved;

	/** @var EntityObject[] Used for Iterator interface, allows to delete elements during foreach loop */
	protected $_iterableObjects;

	/** @var int Code for $objectsChanged */
	const OBJECT_ADDED = 1;

	/** @var int Code for $objectsChanged */
	const OBJECT_REMOVED = 2;

	/**
	 * Collection constructor.
	 *
	 * @param Entity $entity
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	final public function __construct(Entity $entity = null)
	{
		if (empty($entity))
		{
			if (__CLASS__ !== get_called_class())
			{
				// custom collection class
				$dataClass = static::$dataClass;
				$this->_entity = $dataClass::getEntity();
			}
			else
			{
				throw new ArgumentException('Entity required when constructing collection');
			}
		}
		else
		{
			$this->_entity = $entity;
		}

		$this->_isSinglePrimary = count($this->_entity->getPrimaryArray()) == 1;
	}

	/**
	 * @param EntityObject $object
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	final public function add(EntityObject $object)
	{
		$srPrimary = $this->sysGetPrimaryKey($object);

		if (empty($this->_objects[$srPrimary])
			&& (!isset($this->_objectsChanges[$srPrimary]) || $this->_objectsChanges[$srPrimary] != static::OBJECT_REMOVED))
		{
			$this->_objects[$srPrimary] = $object;
			$this->_objectsChanges[$srPrimary] = static::OBJECT_ADDED;
		}
		elseif (isset($this->_objectsChanges[$srPrimary]) && $this->_objectsChanges[$srPrimary] == static::OBJECT_REMOVED)
		{
			// silent add for removed runtime
			unset($this->_objectsChanges[$srPrimary]);
			unset($this->_objectsRemoved[$srPrimary]);
		}
	}

	/**
	 * @param EntityObject $object
	 *
	 * @return bool
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	final public function has(EntityObject $object)
	{
		return array_key_exists($this->sysGetPrimaryKey($object), $this->_objects);
	}

	/**
	 * @param $primary
	 *
	 * @return bool
	 * @throws ArgumentException
	 */
	final public function hasByPrimary($primary)
	{
		$normalizedPrimary = $this->sysNormalizePrimary($primary);
		return array_key_exists($this->sysSerializePrimaryKey($normalizedPrimary), $this->_objects);
	}

	/**
	 * @param $primary
	 *
	 * @return EntityObject
	 * @throws ArgumentException
	 */
	final public function getByPrimary($primary)
	{
		$normalizedPrimary = $this->sysNormalizePrimary($primary);
		return $this->_objects[$this->sysSerializePrimaryKey($normalizedPrimary)];
	}

	/**
	 * @return EntityObject[]
	 */
	final public function getAll()
	{
		return array_values($this->_objects);
	}

	/**
	 * @param EntityObject $object
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	final public function remove(EntityObject $object)
	{
		return $this->removeByPrimary($object->primary);
	}

	/**
	 * @param $primary
	 *
	 * @throws ArgumentException
	 */
	final public function removeByPrimary($primary)
	{
		$normalizedPrimary = $this->sysNormalizePrimary($primary);
		$srPrimary = $this->sysSerializePrimaryKey($normalizedPrimary);

		$object = $this->_objects[$srPrimary];
		unset($this->_objects[$srPrimary]);

		if (!isset($this->_objectsChanges[$srPrimary]) || $this->_objectsChanges[$srPrimary] != static::OBJECT_ADDED)
		{
			// regular remove
			$this->_objectsChanges[$srPrimary] = static::OBJECT_REMOVED;
			$this->_objectsRemoved[$srPrimary] = $object;
		}
		elseif (isset($this->_objectsChanges[$srPrimary]) && $this->_objectsChanges[$srPrimary] == static::OBJECT_ADDED)
		{
			// silent remove for added runtime
			unset($this->_objectsChanges[$srPrimary]);
			unset($this->_objectsRemoved[$srPrimary]);
		}
	}

	/**
	 * Fills all the values and relations of object
	 *
	 * @param int|string[] $fields Names of fields to fill
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	final public function fill($fields = FieldTypeMask::ALL)
	{
		$entityPrimary = $this->_entity->getPrimaryArray();

		$primaryValues = [];
		$fieldsToSelect = $entityPrimary;

		if (is_scalar($fields) && !is_numeric($fields))
		{
			$fields = [$fields];
		}

		// collect custom fields to select
		if (is_array($fields))
		{
			$fieldsToSelect = array_merge($fieldsToSelect, $fields);
		}

		foreach ($this->_objects as $object)
		{
			// collect primary
			$objectPrimary = $object->sysRequirePrimary();

			$primaryValues[] = count($objectPrimary) == 1
				? current($objectPrimary)
				: $objectPrimary;

			// collect fields to select if there is a fields flag instead of custom list
			if (!is_array($fields))
			{
				$diff = array_diff($object->sysGetIdleFields($fields), $fieldsToSelect);
				$fieldsToSelect = array_merge($fieldsToSelect, $diff);
			}
		}

		// build primary filter
		$primaryFilter = Query::filter();

		if (count($entityPrimary) == 1)
		{
			// IN for single-primary objects
			$primaryFilter->whereIn($entityPrimary[0], $primaryValues);
		}
		else
		{
			// OR for multi-primary objects
			$primaryFilter->logic('or');

			foreach ($primaryValues as $objectPrimary)
			{
				// add each object as a separate condition
				$oneObjectFilter = Query::filter();

				foreach ($objectPrimary as $primaryName => $primaryValue)
				{
					$oneObjectFilter->where($primaryName, $primaryValue);
				}

				$primaryFilter->where($oneObjectFilter);
			}
		}

		// build query
		$dataClass = $this->_entity->getDataClass();
		$result = $dataClass::query()->setSelect($fieldsToSelect)->where($primaryFilter)->exec();

		// set object to identityMap of result, and it will be partially completed by fetch
		$im = new IdentityMap;

		foreach ($this->_objects as $object)
		{
			$im->put($object);
		}

		$result->setIdentityMap($im);
		$result->fetchCollection();
	}

	/**
	 * Constructs set of existing objects from pre-selected data, including references and relations.
	 *
	 * @param $rows
	 *
	 * @return array|static
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	final public static function wakeUp($rows)
	{
		// define object class
		$dataClass = static::$dataClass;
		$objectClass = $dataClass::getObjectClass();

		// complete collection
		$collection = new static;

		foreach ($rows as $row)
		{
			$collection->sysAddActual($objectClass::wakeUp($row));
		}

		return $collection;
	}

	/**
	 * Magic read-only properties
	 *
	 * @param $name
	 *
	 * @return array|Entity
	 * @throws SystemException
	 */
	public function __get($name)
	{
		switch ($name)
		{
			case 'entity':
				return $this->_entity;
			case 'dataClass':
				throw new SystemException('Property `dataClass` should be received as static.');
		}

		throw new SystemException(sprintf(
			'Unknown property `%s` for collection `%s`', $name, get_called_class()
		));
	}

	/**
	 * Magic read-only properties
	 *
	 * @param $name
	 * @param $value
	 *
	 * @throws SystemException
	 */
	public function __set($name, $value)
	{
		switch ($name)
		{
			case 'entity':
			case 'dataClass':
				throw new SystemException(sprintf(
					'Property `%s` for collection `%s` is read-only', $name, get_called_class()
				));
		}

		throw new SystemException(sprintf(
			'Unknown property `%s` for collection `%s`', $name, get_called_class()
		));
	}

	/**
	 * Magic to handle getters, setters etc.
	 *
	 * @param $name
	 * @param $arguments
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function __call($name, $arguments)
	{
		$first3 = substr($name, 0, 3);
		$last4 = substr($name, -4);

		// group getter
		if ($first3 == 'get' && $last4 == 'List')
		{
			$fieldName = EntityObject::sysMethodToFieldCase(substr($name, 3, -4));

			// check if field exists
			if ($this->_entity->hasField($fieldName))
			{
				$values = [];

				// collect field values
				foreach ($this->_objects as $objectPrimary => $object)
				{
					$values[$objectPrimary] = $object->sysGetValue($fieldName);
				}

				return $values;
			}
		}

		$first4 = substr($name, 0, 4);

		// filler
		if ($first4 == 'fill')
		{
			$fieldName = EntityObject::sysMethodToFieldCase(substr($name, 4));

			// check if field exists
			if ($this->_entity->hasField($fieldName))
			{
				return $this->fill([$fieldName]);
			}
		}

		throw new SystemException(sprintf(
			'Unknown method `%s` for object `%s`', $name, get_called_class()
		));
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @param \Bitrix\Main\ORM\Objectify\EntityObject $object
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function sysAddActual(EntityObject $object)
	{
		$this->_objects[$this->sysGetPrimaryKey($object)] = $object;
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @return bool
	 */
	public function sysIsFilled()
	{
		return $this->_isFilled;
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @return bool
	 */
	public function sysIsChanged()
	{
		return !empty($this->_objectsChanges);
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @return array
	 * @throws SystemException
	 */
	public function sysGetChanges()
	{
		$changes = [];

		foreach ($this->_objectsChanges as $srPrimary => $changeCode)
		{
			if (isset($this->_objects[$srPrimary]))
			{
				$changedObject = $this->_objects[$srPrimary];
			}
			elseif (isset($this->_objectsRemoved[$srPrimary]))
			{
				$changedObject = $this->_objectsRemoved[$srPrimary];
			}
			else
			{
				$changedObject = null;
			}

			if (empty($changedObject))
			{
				throw new SystemException(sprintf(
					'Object with primary `%s` was not found in `%s` collection', $srPrimary, get_class($this)
				));
			}

			$changes[] = [$changedObject, $changeCode];
		}

		return $changes;
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @param bool $rollback
	 */
	public function sysResetChanges($rollback = false)
	{
		if ($rollback)
		{
			foreach ($this->_objectsChanges as $srPrimary => $changeCode)
			{
				if ($changeCode === static::OBJECT_ADDED)
				{
					unset($this->_objects[$srPrimary]);
				}
				elseif ($changeCode === static::OBJECT_REMOVED)
				{
					$this->_objects[$srPrimary] = $this->_objectsRemoved[$srPrimary];
				}
			}
		}

		$this->_objectsChanges = [];
		$this->_objectsRemoved = [];
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @param bool $value
	 */
	public function sysSetFilled($value = true)
	{
		$this->_isFilled = $value;
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @param $primary
	 *
	 * @return array
	 * @throws ArgumentException
	 */
	protected function sysNormalizePrimary($primary)
	{
		// normalize primary
		$primaryNames = $this->_entity->getPrimaryArray();

		if (!is_array($primary))
		{
			if (count($primaryNames) > 1)
			{
				throw new ArgumentException(sprintf(
					'Only one value of primary found, when entity %s has %s primary keys',
					$this->_entity->getDataClass(), count($primaryNames)
				));
			}

			$primary = [$primaryNames[0] => $primary];
		}

		// check in $this->objects
		$normalizedPrimary = [];

		foreach ($primaryNames as $primaryName)
		{
			$normalizedPrimary[$primaryName] = $primary[$primaryName];
		}

		return $normalizedPrimary;
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @param \Bitrix\Main\ORM\Objectify\EntityObject $object
	 *
	 * @return false|mixed|string
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	protected function sysGetPrimaryKey(EntityObject $object)
	{
		return $this->sysSerializePrimaryKey($object->primary);
	}

	/**
	 * @internal For internal system usage only.
	 *
	 * @param $primary
	 *
	 * @return false|mixed|string
	 */
	protected function sysSerializePrimaryKey($primary)
	{
		if ($this->_isSinglePrimary)
		{
			return current($primary);
		}

		return json_encode(array_values($primary));
	}

	/**
	 * ArrayAccess implementation
	 *
	 * @param mixed $offset
	 * @param mixed $value
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function offsetSet($offset, $value)
	{
		$this->add($value);
	}

	/**
	 * ArrayAccess implementation
	 *
	 * @param mixed $offset
	 *
	 * @return bool|void
	 * @throws NotImplementedException
	 */
	public function offsetExists($offset)
	{
		throw new NotImplementedException;
	}

	/**
	 * ArrayAccess implementation
	 *
	 * @param mixed $offset
	 *
	 * @throws NotImplementedException
	 */
	public function offsetUnset($offset)
	{
		throw new NotImplementedException;
	}

	/**
	 * ArrayAccess implementation
	 *
	 * @param mixed $offset
	 *
	 * @return mixed|void
	 * @throws NotImplementedException
	 */
	public function offsetGet($offset)
	{
		throw new NotImplementedException;
	}

	/**
	 * Iterator implementation
	 */
	public function rewind()
	{
		$this->_iterableObjects = $this->_objects;
		reset($this->_iterableObjects);
	}

	/**
	 * Iterator implementation
	 *
	 * @return EntityObject|mixed
	 */
	public function current()
	{
		return current($this->_iterableObjects);
	}

	/**
	 * Iterator implementation
	 *
	 * @return int|mixed|null|string
	 */
	public function key()
	{
		return key($this->_iterableObjects);
	}

	/**
	 * Iterator implementation
	 */
	public function next()
	{
		next($this->_iterableObjects);
	}

	/**
	 * Iterator implementation
	 *
	 * @return bool
	 */
	public function valid()
	{
		return key($this->_iterableObjects) !== null;
	}

	/**
	 * Countable implementation
	 *
	 * @return int
	 */
	public function count()
	{
		return count($this->_objects);
	}
}
