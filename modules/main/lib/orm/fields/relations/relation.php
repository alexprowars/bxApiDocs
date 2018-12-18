<?php
/**
 * Bitrix Framework
 * @package    bitrix
 * @subpackage main
 * @copyright  2001-2018 Bitrix
 */

namespace Bitrix\Main\ORM\Fields\Relations;

use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\Field;
use Bitrix\Main\ORM\Objectify\EntityObject;

/**
 * Performs relation mapping: back-reference and many-to-many relations.
 *
 * @package    bitrix
 * @subpackage main
 */
abstract class Relation extends Field
{
	/** @var string Name of target entity */
	protected $refEntityName;

	/** @var Entity Target entity */
	protected $refEntity;

	/**
	 * @return Entity
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getRefEntity()
	{
		if ($this->refEntity === null)
		{
			// refEntityName could be an object or a data class
			if (class_exists($this->refEntityName) && is_subclass_of($this->refEntityName, EntityObject::class))
			{
				/** @var EntityObject $refObjectClass */
				$refObjectClass = $this->refEntityName;
				$this->refEntityName = $refObjectClass::$dataClass;
			}

			$this->refEntity = Entity::getInstance($this->refEntityName);
		}

		return $this->refEntity;
	}

	/**
	 * @return string
	 */
	public function getRefEntityName()
	{
		return $this->refEntityName;
	}

}
