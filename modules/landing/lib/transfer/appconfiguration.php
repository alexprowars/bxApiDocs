<?php
namespace Bitrix\Landing\Transfer;

use \Bitrix\Landing\File;
use \Bitrix\Main\Event;
use \Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class AppConfiguration
 * @see rest/dev/configuration/readme.php
 */
class AppConfiguration
{
	/**
	 * Prefix code.
	 */
	const PREFIX_CODE = 'landing_';

	/**
	 * With which entities we can work.
	 * @var array
	 */
	private static $entityList = [
		'LANDING' => 500
	];

	/**
	 * Additional magic manifest.
	 * @var array
	 */
	private static $accessManifest = [
		'total',
		'landing_page',
		'landing_store',
		'landing_knowledge'
	];

	/**
	 * Returns known entities.
	 * @return array
	 */
	public static function getEntityList(): array
	{
		return static::$entityList;
	}

	/**
	 * Builds manifests for each placement.
	 * @param Event $event
	 * @return array
	 */
	public static function getManifestList(Event $event): array
	{
		$manifestList = [];

		foreach (self::$accessManifest as $code)
		{
			if ($code == 'total')
			{
				continue;
			}
			$langCode = strtoupper(substr($code, strlen(self::PREFIX_CODE)));
			$manifestList[] = [
				'CODE' => $code,
				'VERSION' => 1,
				'ACTIVE' => 'Y',
				'PLACEMENT' => [$code],
				'USES' => [$code],
				'DISABLE_CLEAR_FULL' => 'Y',
				'COLOR' => '#ff799c',
				'ICON' => '/bitrix/images/landing/landing_transfer.svg',
				'TITLE' => Loc::getMessage('LANDING_TRANSFER_GROUP_TITLE_' . $langCode),
				//'DESCRIPTION' => Loc::getMessage('LANDING_TRANSFER_GROUP_DESC'),
				'EXPORT_TITLE_PAGE' => Loc::getMessage('LANDING_TRANSFER_EXPORT_ACTION_TITLE_BLOCK_' . $langCode),
				'EXPORT_TITLE_BLOCK' => Loc::getMessage('LANDING_TRANSFER_EXPORT_ACTION_TITLE_BLOCK_' . $langCode),
				'EXPORT_ACTION_DESCRIPTION' => Loc::getMessage('LANDING_TRANSFER_EXPORT_ACTION_DESCRIPTION_' . $langCode),
				'IMPORT_TITLE_PAGE' => Loc::getMessage('LANDING_TRANSFER_IMPORT_ACTION_TITLE_BLOCK_' . $langCode),
				'IMPORT_TITLE_BLOCK' => Loc::getMessage('LANDING_TRANSFER_IMPORT_ACTION_TITLE_BLOCK_' . $langCode),
				'IMPORT_DESCRIPTION_UPLOAD' => Loc::getMessage('LANDING_TRANSFER_IMPORT_DESCRIPTION_UPLOAD_' . $langCode),
				'IMPORT_DESCRIPTION_START' => ' '
			];
		}

		return $manifestList;
	}

	/**
	 * Preparing steps before export start.
	 * @param Event $event Event instance.
	 * @return array|null
	 */
	public static function onInitManifest(Event $event): ?array
	{
		$code = $event->getParameter('CODE');
		$type = $event->getParameter('TYPE');

		if (in_array($code, static::$accessManifest))
		{
			if ($type == 'EXPORT')
			{
				return Export\Site::getInitManifest($event);
			}
			else if ($type == 'IMPORT')
			{
				return Import\Site::getInitManifest($event);
			}
		}

		return null;
	}

	/**
	 * Export step.
	 * @param Event $event Event instance.
	 * @return array|null
	 */
	public static function onEventExportController(Event $event): ?array
	{
		$code = $event->getParameter('CODE');
		$manifest = $event->getParameter('MANIFEST');
		$access = array_intersect($manifest['USES'], static::$accessManifest);

		if ($access && isset(static::$entityList[$code]))
		{
			return Export\Site::nextStep($event);
		}

		return null;
	}

	/**
	 * Import step.
	 * @param Event $event Event instance.
	 * @return array|null
	 */
	public static function onEventImportController(Event $event): ?array
	{
		$code = $event->getParameter('CODE');

		if (isset(static::$entityList[$code]))
		{
			return Import\Site::nextStep($event);
		}

		return null;
	}

	/**
	 * Final step.
	 * @param Event $event
	 * @return void
	 */
	public static function onFinish(Event $event): void
	{
		$type = $event->getParameter('TYPE');
		$code = $event->getParameter('MANIFEST_CODE');

		if (in_array($code, static::$accessManifest))
		{
			if ($type == 'EXPORT')
			{
				Export\Site::onFinish($event);
			}
			else if ($type == 'IMPORT')
			{
				Import\Site::onFinish($event);
			}
		}
	}

	/**
	 * Saves file to DB and returns id ID.
	 * @param array $file File data from getUnpackFile.
	 * @return int|null
	 */
	public static function saveFile(array $file): ?int
	{
		$fileId = null;
		$fileData = \CFile::makeFileArray(
			$file['PATH']
		);
		if ($fileData)
		{
			$fileData['name'] = $file['NAME'];
		}

		if ($fileData)
		{
			if (\CFile::checkImageFile($fileData, 0, 0, 0, array('IMAGE')) === null)
			{
				$fileData['MODULE_ID'] = 'landing';
				$fileData['name'] = File::sanitizeFileName($fileData['name']);
				$fileId = (int)\CFile::saveFile($fileData, $fileData['MODULE_ID']);
				if (!$fileId)
				{
					$fileId = null;
				}
			}
		}

		return $fileId;
	}

	/**
	 * tmp
	 */
	public static function ttt($files)
	{
		$docRoot = $_SERVER['DOCUMENT_ROOT'];
		$toDir = $docRoot . '/upload/tmp/files/';

		\checkDirPath($toDir);

		foreach ($files as $file)
		{
			copy(
				$docRoot . \CFile::getPath($file['ID']),
				$toDir . $file['ID']
			);
		}
	}
}
