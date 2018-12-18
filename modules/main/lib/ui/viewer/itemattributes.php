<?php

namespace Bitrix\Main\UI\Viewer;


use Bitrix\Main\ArgumentException;
use Bitrix\Main\UI\Viewer\Transformation\Transformation;
use Bitrix\Main\UI\Viewer\Transformation\TransformerManager;
use Bitrix\Main\Web\Json;

class ItemAttributes
{
	/**
	 * @var
	 */
	protected $fileData;
	/**
	 * @var array
	 */
	protected $attributes = [];
	/**
	 * @var array
	 */
	protected $actions = [];
	/**
	 * @var
	 */
	protected $sourceUri;
	/**
	 * @var array
	 */
	protected $options = [];

	/**
	 * @var array
	 */
	protected static $viewerTypeByContentType = [];

	/**
	 * ItemAttributes constructor.
	 *
	 * @param $fileData
	 * @param $sourceUri
	 * @param array $options
	 */
	private function __construct($fileData, $sourceUri, array $options = [])
	{
		$this->fileData = $fileData;
		$this->sourceUri = $sourceUri;
		$this->options = $options;

		$this->setDefaultAttributes();
	}

	protected function setDefaultAttributes()
	{
		$this->attributes[] = 'data-viewer';
		$this->attributes['data-viewer-type'] = static::getViewerTypeByFile($this->fileData);
		$this->attributes['data-src'] = $this->sourceUri;
	}

	/**
	 * @param $fileId
	 * @param $sourceUri
	 *
	 * @return static
	 * @throws ArgumentException
	 */
	public static function buildByFileId($fileId, $sourceUri)
	{
		$fileData = \CFile::getByID($fileId)->fetch();
		if (!$fileData)
		{
			throw new ArgumentException('Invalid fileId');
		}

		return new static($fileData, $sourceUri);
	}

	/**
	 * @param array $fileData
	 * @param $sourceUri
	 *
	 * @return static
	 * @throws ArgumentException
	 */
	public static function buildByFileData(array $fileData, $sourceUri)
	{
		if (empty($fileData['ID']))
		{
			throw new ArgumentException('Invalid file data');
		}

		return new static($fileData, $sourceUri);
	}

	/**
	 * @param $sourceUri
	 *
	 * @return static
	 */
	public static function buildAsUnknownType($sourceUri)
	{
		$fakeFileData = [
			'ID' => -1,
			'CONTENT_TYPE' => 'application/octet-stream',
		];

		return new static($fakeFileData, $sourceUri);
	}

	/**
	 * @param $title
	 *
	 * @return $this
	 */
	public function setTitle($title)
	{
		$this->attributes['data-title'] = htmlspecialcharsbx($title);

		return $this;
	}

	/**
	 * @param $id
	 *
	 * @return $this
	 */
	public function setGroupBy($id)
	{
		$this->attributes['data-viewer-group-by'] = htmlspecialcharsbx($id);

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getGroupBy()
	{
		return $this->getAttribute('data-viewer-group-by');
	}

	/**
	 * @param array $action
	 *
	 * @return $this
	 */
	public function addAction(array $action)
	{
		$this->actions[] = $action;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getActions()
	{
		return $this->actions;
	}

	/**
	 * @return mixed|null
	 */
	public function getViewerType()
	{
		if (!$this->issetAttribute('data-viewer-type'))
		{
			$this->attributes['data-viewer-type'] = static::getViewerTypeByFile($this->fileData);
		}

		return $this->getAttribute('data-viewer-type');
	}

	/**
	 * @param $name
	 * @param $value
	 *
	 * @return $this
	 */
	public function setAttribute($name, $value)
	{
		$this->attributes[$name] = $value;

		return $this;
	}

	/**
	 * @param $name
	 *
	 * @return bool
	 */
	public function issetAttribute($name)
	{
		return isset($this->attributes[$name]);
	}

	/**
	 * @param $name
	 *
	 * @return mixed|null
	 */
	public function getAttribute($name)
	{
		if (isset($this->attributes[$name]))
		{
			return $this->attributes[$name];
		}

		return null;
	}

	/**
	 * @return array
	 */
	public function getAttributes()
	{
		return $this->attributes;
	}

	/**
	 * @param array $fileArray
	 *
	 * @return mixed|string
	 * @throws \ReflectionException
	 */
	protected static function getViewerTypeByFile(array $fileArray)
	{
		if (isset(static::$viewerTypeByContentType[$fileArray['CONTENT_TYPE']]))
		{
			return static::$viewerTypeByContentType[$fileArray['CONTENT_TYPE']];
		}

		$contentType = $fileArray['CONTENT_TYPE'];

		$previewManager = new PreviewManager();
		$renderClass = $previewManager->getRenderClassByContentType($contentType);
		if ($renderClass === Renderer\Stub::class)
		{
			$transformerManager = new TransformerManager();
			if ($transformerManager->isAvailable())
			{
				/** @var Transformation $transformationClass */
				$transformation = $transformerManager->buildTransformationByFile($fileArray);
				if ($transformation)
				{
					$contentType = $transformation->getOutputContentType();
					$renderClass = $previewManager->getRenderClassByContentType($contentType);
				}
			}
		}

		static::$viewerTypeByContentType[$fileArray['CONTENT_TYPE']] = $renderClass::getJsType();

		return $renderClass::getJsType();
	}

	/**
	 * @return string
	 */
	public function toString()
	{
		return (string)$this;
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		$string = '';
		foreach ($this->attributes as $key => $value)
		{
			if (is_int($key))
			{
				$string .= "{$value} ";
			}
			else
			{
				$value = htmlspecialcharsbx($value);
				$string .= "{$key}=\"{$value}\" ";
			}
		}

		if ($this->actions)
		{
			$string .= "data-actions='" . htmlspecialcharsbx(Json::encode($this->actions)) . "'";
		}

		return $string;
	}

	/**
	 * Convert structure to array which we can use in js (node.dataset).
	 * @return array
	 */
	public function toDataSet()
	{
		$likeDataSet = [];
		foreach ($this->attributes as $key => $value)
		{
			if (is_int($key))
			{
				$likeDataSet[$this->convertKeyToDataSet($value)] = null;
			}
			else
			{
				$likeDataSet[$this->convertKeyToDataSet($key)] = $value;
			}
		}

		if ($this->actions)
		{
			$likeDataSet[$this->convertKeyToDataSet('data-actions')] = Json::encode($this->actions);
		}

		return $likeDataSet;
	}

	protected function convertKeyToDataSet($key)
	{
		$key = str_replace('data-', '', $key);
		$key = str_replace('-', ' ', strtolower($key));

		return lcfirst(str_replace(' ', '', ucwords($key)));
	}
}