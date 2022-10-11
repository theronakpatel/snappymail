<?php

class NextcloudPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME = 'Nextcloud',
		VERSION = '2.1',
		RELEASE  = '2022-10-10',
		CATEGORY = 'Integrations',
		DESCRIPTION = 'Integrate with Nextcloud v18+',
		REQUIRED = '2.15.2';

	public function Init() : void
	{
		if (static::IsIntegrated()) {
			$this->addHook('main.fabrica', 'MainFabrica');
			$this->addHook('filter.app-data', 'FilterAppData');
			$this->addHook('json.attachments', 'SaveAttachments');

			$sAppPath = \rtrim(\trim(\OC::$server->getAppManager()->getAppWebPath()), '\\/').'/app/';
			if (!$sAppPath) {
				$sUrl = \MailSo\Base\Http::SingletonInstance()->GetUrl();
				if (\str_contains($sUrl, '/index.php/apps/snappymail/')) {
					$sAppPath = \preg_replace('/\/index\.php\/apps\/snappymail.+$/',
						'/apps/snappymail/app/', $sUrl);
				}
			}
			$_SERVER['SCRIPT_NAME'] = $sAppPath;
		}
	}

	public function Supported() : string
	{
		return static::IsIntegrated() ? '' : 'Nextcloud not found to use this plugin';
	}

	public static function IsIntegrated()
	{
		return !empty($_ENV['SNAPPYMAIL_NEXTCLOUD']) && \class_exists('OC') && isset(\OC::$server);
	}

	public static function IsLoggedIn()
	{
		return static::IsIntegrated() && \OC::$server->getUserSession()->isLoggedIn();
	}

	// DoAttachmentsActions
	public function SaveAttachments(\SnappyMail\AttachmentsAction $data)
	{
		if ('nextcloud' === $data->action) {
			$oFiles = \OCP\Files::getStorage('files');
			if ($oFiles && $data->filesProvider->IsActive() && \method_exists($oFiles, 'file_put_contents')) {
				$sSaveFolder = $this->Config()->Get('plugin', 'save_folder', '') ?: 'Attachments';
				$oFiles->is_dir($sSaveFolder) || $oFiles->mkdir($sSaveFolder);
				$data->result = true;
				foreach ($data->items as $aItem) {
					$sSavedFileName = isset($aItem['FileName']) ? $aItem['FileName'] : 'file.dat';
					$sSavedFileHash = !empty($aItem['FileHash']) ? $aItem['FileHash'] : '';
					if (!empty($sSavedFileHash)) {
						$fFile = $data->filesProvider->GetFile($data->account, $sSavedFileHash, 'rb');
						if (\is_resource($fFile)) {
							$sSavedFileNameFull = \MailSo\Base\Utils::SmartFileExists($sSaveFolder.'/'.$sSavedFileName, function ($sPath) use ($oFiles) {
								return $oFiles->file_exists($sPath);
							});

							if (!$oFiles->file_put_contents($sSavedFileNameFull, $fFile)) {
								$data->result = false;
							}

							if (\is_resource($fFile)) {
								\fclose($fFile);
							}
						}
					}
				}
			}

			foreach ($data->items as $aItem) {
				$sFileHash = (string) (isset($aItem['FileHash']) ? $aItem['FileHash'] : '');
				if (!empty($sFileHash)) {
					$data->filesProvider->Clear($data->account, $sFileHash);
				}
			}
		}
	}

	public function FilterAppData($bAdmin, &$aResult) : void
	{
		if (!$bAdmin && \is_array($aResult) && static::IsIntegrated()) {
			$key = \array_search(\RainLoop\Enumerations\Capa::AUTOLOGOUT, $aResult['Capa']);
			if (false !== $key) {
				unset($aResult['Capa'][$key]);
			}
			if (static::IsLoggedIn() && \class_exists('OCP\Files')) {
				$aResult['System']['attachmentsActions'][] = 'nextcloud';
			}
		}
	}

	/**
	 * @param mixed $mResult
	 */
	public function MainFabrica(string $sName, &$mResult)
	{
		if (static::isLoggedIn()) {
			if ('suggestions' === $sName && $this->Config()->Get('plugin', 'suggestions', true)) {
				if (!\is_array($mResult)) {
					$mResult = array();
				}
				include_once __DIR__ . '/NextcloudContactsSuggestions.php';
				$mResult[] = new NextcloudContactsSuggestions();
			}
		}
	}

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('save_folder')->SetLabel('Save Folder')
				->SetDefaultValue('Attachments'),
			\RainLoop\Plugins\Property::NewInstance('suggestions')->SetLabel('Suggestions')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(true)
		);
	}
}