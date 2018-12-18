<?
namespace Bitrix\Iblock\Helpers\Filter;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class PropertyManager
{
	private $iblockId;
	private $listProperty = array();
	private $filterFields = array();

	public function __construct($iblockId)
	{
		$this->iblockId = $iblockId;
	}

	public function getFilterFields()
	{
		if (!empty($this->filterFields))
		{
			return $this->filterFields;
		}

		$this->filterFields = array();

		$listProperty = $this->getListProperty();

		foreach ($listProperty as $property)
		{
			$fieldId = "PROPERTY_".$property["ID"];
			$fieldName = $property["NAME"];
			
			if (!empty($property["PROPERTY_USER_TYPE"]["USER_TYPE"]))
			{
				$userType = $property["USER_TYPE"];
				switch ($userType)
				{
					case "Date":
						$fields = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"type" => "date",
							"filterable" => ""
						);
						break;
					case "DateTime":
						$fields = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"type" => "date",
							"time" => true,
							"filterable" => ""
						);
						break;
					case "Sequence":
						$fields = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"type" => "number",
							"filterable" => ""
						);
						break;
					case "directory":
						if (array_key_exists("GetOptionsData", $property["PROPERTY_USER_TYPE"]))
						{
							$data = call_user_func_array(
								$property["PROPERTY_USER_TYPE"]["GetOptionsData"],
								array($property, array())
							);
							$fields = array(
								"id" => $fieldId,
								"name" => $fieldName,
								"type" => "list",
								"items" => $data,
								"params" => array("multiple" => "Y"),
								"filterable" => ""
							);
						}
						break;
					case "employee":
					case "ECrm":
						$fields = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"type" => "custom_entity",
							"filterable" => "",
						);
						break;
					default:
						if (array_key_exists("GetUIFilterProperty", $property["PROPERTY_USER_TYPE"]))
						{
							$fields = array(
								"id" => $fieldId,
								"name" => $fieldName,
								"type" => "custom",
								"value" => "",
								"filterable" => ""
							);
							call_user_func_array($property["PROPERTY_USER_TYPE"]["GetUIFilterProperty"],
								array(
									$property,
									array("VALUE" => $fieldId, "FORM_NAME" => "main-ui-filter"),
									&$fields
								)
							);
						}
						elseif (array_key_exists("GetPublicFilterHTML", $property["PROPERTY_USER_TYPE"]))
						{
							$fields = array(
								"id" => $fieldId,
								"name" => $fieldName,
								"type" => "custom",
								"value" => call_user_func_array(
									$property["PROPERTY_USER_TYPE"]["GetPublicFilterHTML"],
									array(
										$property,
										array("VALUE" => $fieldId, "FORM_NAME" => "main-ui-filter")
									)
								),
								"filterable" => ""
							);
						}
				}
				if (empty($fields))
				{
					$listLikeProperty = array("HTML");
					$fields = array(
						"id" => $fieldId,
						"name" => $fieldName,
						"filterable" => in_array($userType, $listLikeProperty) ? "?" : ""
					);
				}
				$this->filterFields[] = $fields;
			}
			else
			{
				$propertyType = $property["PROPERTY_TYPE"];
				switch ($propertyType)
				{
					case "S":
						$this->filterFields[] = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"filterable" => "?"
						);
						break;
					case "N":
						$this->filterFields[] = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"type" => "number",
							"filterable" => ""
						);
						break;
					case "L":
						$items = array(
							"NOT_REF" => Loc::getMessage("IBLOCK_PM_LIST_DEFAULT_OPTION")
						);
						$propertyEnumQueryObject = \CIBlockProperty::getPropertyEnum($property["ID"]);
						while($propertyEnum = $propertyEnumQueryObject->fetch())
						{
							$items[$propertyEnum["ID"]] = $propertyEnum["VALUE"];
						}
						$this->filterFields[] = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"type" => "list",
							"items" => $items,
							"params" => ($property["MULTIPLE"] == "Y" ? array("multiple" => "Y") : array()),
							"filterable" => ""
						);
						break;
					case "E":
						$this->filterFields[] = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"type" => "custom_entity",
							"filterable" => "",
							"property" => $property,
							"customRender" => array("Bitrix\Iblock\Helpers\Filter\Property", "render"),
							"customFilter" => array("Bitrix\Iblock\Helpers\Filter\Property", "addFilter")
						);
						break;
					case "G":
						$items = array();
						$sectionQueryObject = \CIBlockSection::getList(
							array("left_margin" => "asc"),
							array("IBLOCK_ID" => $property["LINK_IBLOCK_ID"])
						);
						while($section = $sectionQueryObject->fetch())
						{
							$items[$section["ID"]] = str_repeat(". ", $section["DEPTH_LEVEL"] - 1).$section["NAME"];
						}
						$this->filterFields[] = array(
							"id" => $fieldId,
							"name" => $fieldName,
							"type" => "list",
							"items" => $items,
							"params" => ($property["MULTIPLE"] == "Y" ? array("multiple" => "Y") : array()),
							"filterable" => ""
						);
						break;
				}
			}
		}

		return $this->filterFields;
	}

	public function renderCustomFields($filterId)
	{
		foreach ($this->getFilterFields() as $filterField)
		{
			if (array_key_exists("customRender", $filterField))
			{
				echo call_user_func_array($filterField["customRender"], array(
					$filterId,
					$filterField["property"]["PROPERTY_TYPE"],
					array($filterField["property"]),
				));
			}
		}
	}

	public function AddFilter($filterId, &$filter)
	{
		foreach ($this->getListProperty() as $property)
		{
			if ($property["FILTRABLE"] == "Y")
			{
				if (array_key_exists("AddFilterFields", $property["PROPERTY_USER_TYPE"]))
				{
					$filtered = false;
					call_user_func_array($property["PROPERTY_USER_TYPE"]["AddFilterFields"], array(
						$property,
						array("VALUE" => "PROPERTY_".$property["ID"], "FILTER_ID" => $filterId),
						&$filter,
						&$filtered,
					));
				}
				else
				{
					if ($filter["PROPERTY_".$property["ID"]] === "NOT_REF")
					{
						unset($filter["PROPERTY_".$property["ID"]]);
						$filter["?PROPERTY_".$property["ID"]] = false;
					}
				}
			}
		}

		foreach($this->getFilterFields() as $filterField)
		{
			if (array_key_exists("customFilter", $filterField))
			{
				$filtered = false;
				call_user_func_array($filterField["customFilter"], array(
					$filterField["property"],
					array(
						"VALUE" => $filterField["id"],
						"FILTER_ID" => $filterId,
					),
					&$filter,
					&$filtered,
				));
			}
		}
	}

	private function getListProperty()
	{
		if (!empty($this->listProperty))
		{
			return $this->listProperty;
		}

		$propertyQueryObject = \CIBlockProperty::getList(
			array("SORT" => "ASC"),
			array("IBLOCK_ID" => $this->iblockId, "CHECK_PERMISSIONS" => "N", "ACTIVE"=>"Y")
		);
		while ($property = $propertyQueryObject->fetch())
		{
			if ($property["FILTRABLE"] == "Y")
			{
				$property["PROPERTY_USER_TYPE"] = (!empty($property["USER_TYPE"]) ?
					\CIBlockProperty::getUserType($property["USER_TYPE"]) : array());
				$property["FIELD_ID"] = "PROPERTY_".$property["ID"];
				$this->listProperty[$property["ID"]] = $property;
			}
		}

		return $this->listProperty;
	}
}