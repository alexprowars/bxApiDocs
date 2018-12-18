<?

namespace Bitrix\Main\UI\Viewer\Transformation;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Transformer\Command;
use Bitrix\Transformer\FileTransformer;

final class TransformerManager
{
	const PULL_TAG = 'mainTransform';

	protected static $transformationList = [];

	public function __construct()
	{
		//optimize
		$this->buildTransformationList();
	}

	public static function getPullTag($fileId)
	{
		return self::PULL_TAG . $fileId;
	}

	private function buildTransformationList()
	{
		if (!empty(static::$transformationList))
		{
			return;
		}

		$default = [
			Document::class,
			Video::class,
		];

		$event = new Event('main', 'onTransformationBuildList');
		$event->send();

		$additionalList = [];
		foreach ($event->getResults() as $result)
		{
			if ($result->getType() != EventResult::SUCCESS)
			{
				continue;
			}
			$result = $result->getParameters();
			if (!is_array($result))
			{
				throw new SystemException('Wrong event result. Must be array.');
			}

			foreach ($result as $class)
			{
				if (!is_string($class) || !class_exists($class))
				{
					throw new SystemException('Wrong event result. There is not a class.');
				}

				if (!is_subclass_of($class, Transformation::class, true))
				{
					throw new SystemException("Wrong event result. {$class} is not a subclass of " . Transformation::class);
				}

				$additionalList[] = $class;
			}
		}

		static::$transformationList = array_merge($default, $additionalList);
	}

	public function isAvailable()
	{
		return ModuleManager::isModuleInstalled('transformer');
	}

	public function transform($fileId)
	{
		$result = new Result();
		if (!Loader::includeModule('transformer'))
		{
			$result->addError(new Error('Could not include module transformer'));

			return $result;
		}

		$fileData = \CFile::getByID($fileId)->fetch();
		if (!$fileData)
		{
			$result->addError(new Error('Could not find file'));

			return $result;
		}

		$transformation = $this->buildTransformationByFile($fileData);
		if (!$transformation)
		{
			$result->addError(new Error('There is no transformation for file'));

			return $result;
		}

		if(
			$transformation->getInputMaxSize() > 0 &&
			$fileData['FILE_SIZE'] > $transformation->getInputMaxSize()
		)
		{
			$result->addError(new Error('Too big file for transformation'));

			return $result;
		}

		$transformer = $transformation->buildTransformer();
		if (!$transformer)
		{
			$result->addError(new Error('Could not build transformer'));

			return $result;
		}

		$shouldSendPullTag = true;
		$information = $this->getTransformationInformation($fileId);
		if (!$information)
		{
			$result = $transformer->transform(
				(int)$fileId,
				[$transformation->getOutputExtension()],
				'main',
				CallbackHandler::class,
				['id' => $fileId, 'fileId' => $fileId, 'queue' => 'disk_on_load']
			);

			if (!$result->isSuccess())
			{
				$shouldSendPullTag = false;
			}
		}

		if (isset($information['status']) && $information['status'] == Command::STATUS_ERROR)
		{
			$shouldSendPullTag = false;
			$result->addError(new Error('Could not transform file', Command::STATUS_ERROR));
		}

		if ($shouldSendPullTag)
		{
			$pullTag = $this->subscribeCurrentUserForTransformation($fileId);
			$result->setData([
				'pullTag' => $pullTag,
		 	]);
		}

		return $result;
	}

	protected function getTransformationInformation($fileId)
	{
		return FileTransformer::getTransformationInfoByFile((int)$fileId);
	}

	public function subscribeCurrentUserForTransformation($fileId)
	{
		if (!Loader::includeModule('pull'))
		{
			return null;
		}

		$pullTag = self::getPullTag($fileId);
		\CPullWatch::add(CurrentUser::get()->getId(), $pullTag);

		return $pullTag;
	}

	/**
	 * @param array $fileData
	 *
	 * @return null|Transformation
	 * @throws \ReflectionException
	 */
	public function buildTransformationByFile(array $fileData)
	{
		foreach (static::$transformationList as $transformationClass)
		{
			/** @var Transformation $transformationClass */
			if (in_array($fileData['CONTENT_TYPE'], $transformationClass::getInputContentTypes(), true))
			{
				$reflectionClass = new \ReflectionClass($transformationClass);

				return $reflectionClass->newInstance();
			}
		}

		return null;
	}
}