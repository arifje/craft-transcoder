<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\MatrixBlock;
use craft\elements\db\ElementQueryInterface;
use craft\events\DefineAssetThumbUrlEvent;
use craft\fs\Local;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\Json as JsonHelper;
use craft\helpers\UrlHelper;
use mikehaertl\shellcommand\Command as ShellCommand;
use nystudio107\transcoder\events\TranscoderQueueEvent;
use nystudio107\transcoder\jobs\EncodeGif;
use nystudio107\transcoder\jobs\EncodeVideo;
use nystudio107\transcoder\jobs\GenerateVideoPosters;
use nystudio107\transcoder\Transcoder;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\validators\UrlValidator;

use function count;
use function function_exists;
use function in_array;
use function is_bool;
use function is_iterable;

/**
 * @author    nystudio107
 * @package   Transcode
 * @since     1.0.0
 */
class Transcode extends Component
{
	// Constants
	// =========================================================================

	public const EVENT_BEFORE_QUEUE_VIDEO = 'beforeQueueVideo';

	public const EVENT_BEFORE_QUEUE_GIF = 'beforeQueueGif';

	protected const MIN_VALID_VIDEO_FILE_SIZE = 16384;

	// Suffixes to add to the generated filename params
	protected const SUFFIX_MAP = [
		'videoFrameRate' => 'fps',
		'videoBitRate' => 'bps',
		'audioBitRate' => 'bps',
		'audioChannels' => 'c',
		'height' => 'h',
		'width' => 'w',
		'timeInSecs' => 's',
	];

	// Params that should be excluded from being part of the generated filename
	protected const EXCLUDE_PARAMS = [
		'videoEncoder',
		'audioEncoder',
		'fileSuffix',
		'sharpen',
		'synchronous',
		'stripMetadata',
		'preVideoFilters',
		'videoBitRate',
		'videoCodecOptions',
	];

	protected const WATERMARK_POSITIONS = [
		'top-left',
		'top-center',
		'top-right',
		'center-left',
		'center',
		'center-right',
		'bottom-left',
		'bottom-center',
		'bottom-right',
	];

	// Mappings for getFileInfo() summary values
	protected const INFO_SUMMARY = [
		'format' => [
			'filename' => 'filename',
			'duration' => 'duration',
			'size' => 'size',
		],
		'audio' => [
			'codec_name' => 'audioEncoder',
			'bit_rate' => 'audioBitRate',
			'sample_rate' => 'audioSampleRate',
			'channels' => 'audioChannels',
		],
		'video' => [
			'codec_name' => 'videoEncoder',
			'bit_rate' => 'videoBitRate',
			'avg_frame_rate' => 'videoFrameRate',
			'height' => 'height',
			'width' => 'width',
		],
	];

	// Public Methods
	// =========================================================================

	/**
	 * Return whether runtime encoding is enabled from the CP Utility switch.
	 *
	 * @return bool
	 */
	public function isRuntimeEncodingEnabled(): bool
	{
		return Transcoder::$plugin->runtimeSettings->isEncodingEnabled();
	}

	/**
	 * Return whether this request is allowed to start new encode work.
	 *
	 * Empty `encodingServerNames` keeps the original behavior and allows every
	 * server. Console requests are allowed so queued jobs can run without a host.
	 *
	 * @return bool
	 */
	public function canStartEncodingFromCurrentRequest(): bool
	{
		$allowedServerNames = $this->getAllowedEncodingServerNames();
		if (empty($allowedServerNames)) {
			return true;
		}

		$request = Craft::$app->getRequest();
		if ($request->getIsConsoleRequest()) {
			return true;
		}

		$serverName = strtolower((string)$request->getServerName());
		if ($serverName === '') {
			return false;
		}

		foreach ($allowedServerNames as $allowedServerName) {
			if ($this->serverNameMatches($serverName, $allowedServerName)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stop active ffmpeg processes that Transcoder is tracking.
	 *
	 * @return int
	 */
	public function stopActiveEncodingProcesses(): int
	{
		$stopped = 0;
		$statusFiles = glob($this->getVideoStatusDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [];

		foreach ($statusFiles as $statusFile) {
			$status = JsonHelper::decodeIfJson((string)@file_get_contents($statusFile), true);
			if (!is_array($status)) {
				continue;
			}

			$lockFile = $status['lockFile'] ?? null;
			$progressFile = $status['progressFile'] ?? null;
			if (!$lockFile || !is_file($lockFile)) {
				continue;
			}

			$pid = trim((string)@file_get_contents($lockFile));
			if ($pid === '' || !ctype_digit($pid) || !$this->isProcessRunningFromLockFile($lockFile)) {
				$this->removeEncodeTempFiles($lockFile, $progressFile);
				continue;
			}

			exec('kill -TERM ' . $pid . ' 2>&1', $output, $exitCode);
			if ($exitCode === 0 || !$this->isProcessRunningFromLockFile($lockFile)) {
				$stopped++;
				$this->removeEncodeTempFiles($lockFile, $progressFile);
				$status['status'] = 'disabled';
				$status['url'] = '';
				$status['progress'] = 0;
				$status['error'] = Craft::t('transcoder', 'Encoding stopped by runtime switch.');
				if (!empty($status['key'])) {
					$this->writeVideoStatusByKey((string)$status['key'], $status);
				}
			}
		}

		return $stopped;
	}

	/**
	 * Returns a JSON-encoded status response for a transcoded video.
	 *
	 * @param string|Asset $filePath path to the original video -OR- an Asset
	 * @param array $videoOptions options for the video
	 * @param bool $generate whether the video should be encoded
	 * @param array $encodingOptions extra options recorded with queued encodes
	 *
	 * @return string
	 * @throws InvalidConfigException
	 */

	public function getVideoUrl(string|Asset $filePath, array $videoOptions, bool $generate = true, array $encodingOptions = []): string
	{
		$settings = Transcoder::$plugin->getSettings();
		if (!$this->isRuntimeEncodingEnabled() || !$settings->enableVideoEncoding) {
			return JsonHelper::encode([
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			]);
		}

		$subfolder = $this->getSubfolderFromPath($filePath);

		// Environment check
		$isDev = App::env('CRAFT_ENVIRONMENT') === 'development';

		// --- Normalize input ---
		$normalized = $this->normalizeFilePath($filePath);
		$originalExists = false;
		$filePathResolved = null;

		if (isset($normalized['url'])) {
			$filePathResolved = $normalized['url'];
			$originalExists = $this->doesRemoteFileExist($filePathResolved, $filePath instanceof Asset ? 5 : 1, 2);
		} elseif (isset($normalized['path'])) {
			$filePathResolved = $normalized['path'];
			$originalExists = file_exists($filePathResolved);
		}

		if ($isDev) {
			Craft::info("Normalized filePath: " . json_encode($normalized), __METHOD__);
			Craft::info("Resolved filePath: " . $filePathResolved, __METHOD__);
			Craft::info("Original exists? " . ($originalExists ? 'yes' : 'no'), __METHOD__);
		}

		// Destination path & URL
		if (!empty($subfolder)) {
			$destVideoPath = rtrim(App::parseEnv($settings['transcoderPaths']['video']), DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR
				. trim($subfolder, DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR;
			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['video']), '/')
				. '/' . trim($subfolder, '/');
		} else {
			$destVideoPath = rtrim(App::parseEnv($settings['transcoderPaths']['default']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['default']), '/');
		}

		if ($isDev) {
			Craft::info("Destination path: $destVideoPath", __METHOD__);
			Craft::info("Base URL: $urlBase", __METHOD__);
		}

		$videoOptions = $this->coalesceOptions('defaultVideoOptions', $videoOptions);
		$videoEncoders = $settings['videoEncoders'];
		$thisEncoder = $videoEncoders[$videoOptions['videoEncoder']];
		$videoOptions['fileSuffix'] = $thisEncoder['fileSuffix'];
		$videoOptions['autoCropVideoBlackBars'] = (bool)$settings->autoCropVideoBlackBars;

		$videoFilenameInput = $filePath instanceof Asset ? $filePath : ($filePathResolved ?? '');
		$destVideoFile = $this->getFilename($videoFilenameInput, $videoOptions, $this->getVideoFilenameExcludeParams($videoOptions));
		$videoFilenameCandidates = $this->getVideoFilenameCandidates(
			$filePath,
			$filePathResolved,
			$videoOptions,
			$destVideoFile
		);
		$destVideoFile = $this->getExistingVideoFilenameCandidate(
			$destVideoPath,
			$filePath,
			$filePathResolved,
			$videoOptions,
			$destVideoFile
		);
		$encodedFile   = $destVideoPath . $destVideoFile;
		$publicUrl     = $urlBase . '/' . $destVideoFile;

		$lockFile     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.lock';
		$progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.progress';
		$statusKey = $this->getVideoStatusKey($filePath, $videoOptions, $encodingOptions);

		if ($isDev) {
			Craft::info("Lock file: $lockFile", __METHOD__);
			Craft::info("Progress file: $progressFile", __METHOD__);
		}

		// --- Case 1: encoded already exists (and finished) ---
		if (is_file($encodedFile) && filesize($encodedFile) > 0 && !is_file($lockFile)) {
			if ($isDev) {
				Craft::info("Encoded file already exists: $encodedFile", __METHOD__);
			}

			// Always cleanup stale lock/progress files
			@unlink($lockFile);
			@unlink($progressFile);

			if ($isDev) {
				Craft::info("Removed lock/progress files if present", __METHOD__);
			}

			$response = [
				'status' => 'ok',
				'url' => $publicUrl,
			];
			if (!$originalExists) {
				$response['warning'] = "Original video missing, serving encoded version";
			}
			return JsonHelper::encode($response);
		}

		// --- Case 2: check for stalled/crashed encoding ---
		if (is_file($lockFile)) {
			$pid = trim((string) @file_get_contents($lockFile));

			if ($pid !== '' && ctype_digit($pid)) {
				exec("kill -0 $pid 2>&1", $processState);

				// If process is dead → ffmpeg crashed
				if (count($processState) > 0) {
					// Double-check if encoded file exists → maybe finished
					if (is_file($encodedFile) && filesize($encodedFile) > 0) {
						@unlink($lockFile);
						@unlink($progressFile);
						if ($isDev) {
							Craft::info("Encoding finished, cleaned up lock/progress files", __METHOD__);
						}
						return JsonHelper::encode([
							'status' => 'ok',
							'url' => $publicUrl,
						]);
					}

					$this->removeEncodeTempFiles($lockFile, $progressFile);
					Craft::error("Transcoder: ffmpeg process $pid died unexpectedly for $filePathResolved", __METHOD__);
					return JsonHelper::encode([
						'status' => 'error',
						'url' => '',
						'error' => 'Encoding failed due to a server error (process crashed, ffmpeg error)',
					]);
				}

				// Optional: detect stalled progress
				if (file_exists($progressFile)) {
					$lastUpdate = filemtime($progressFile);
					if ($lastUpdate && (time() - $lastUpdate > 60)) {
						$this->removeEncodeTempFiles($lockFile, $progressFile);
						Craft::error("Transcoder: ffmpeg stalled for $filePathResolved", __METHOD__);
						return JsonHelper::encode([
							'status' => 'error',
							'url' => '',
							'error' => 'Encoding stalled (no progress updates)',
						]);
					}
				}

				if ($isDev) {
					Craft::info("Status: encoding", __METHOD__);
				}

				return JsonHelper::encode([
					'status' => 'encoding',
					'url' => '',
					'info' => 'Encoding in progress',
				]);
			} else {
				if ($isDev) {
					Craft::info("Status: encoding (PID not yet available)", __METHOD__);
				}
				return JsonHelper::encode([
					'status' => 'encoding',
					'url' => '',
					'info' => 'Encoding in progress (PID not yet available)',
				]);
			}
		}

		$activeStatus = $this->findActiveVideoStatusForFilenames($videoFilenameCandidates, $statusKey);
		if (!empty($activeStatus)) {
			if ($originalExists && $this->isOriginalVideoSourceRetryStatus($activeStatus)) {
				Craft::info('Transcoder: ignoring queued source-retry status for ' . ($filePathResolved ?? 'unknown') . ' because the original source is now reachable.', __METHOD__);
			} else {
				return JsonHelper::encode($this->sanitizeVideoStatus($activeStatus));
			}
		}

		// --- Case 3: original missing, encoded missing ---
		if (!$originalExists) {
			$msg = "Transcoder: original video not found at " . ($filePathResolved ?? 'unknown');
			Craft::error($msg, __METHOD__);
			return JsonHelper::encode([
				'status' => 'error',
				'url' => '',
				'error' => $msg,
			]);
		}

		if ($generate && !$this->canStartEncodingFromCurrentRequest()) {
			Craft::info('Transcoder: video encoding not started because this server is not allowed to start encode work.', __METHOD__);
			return JsonHelper::encode([
				'status' => 'pending',
				'url' => '',
				'progress' => 0,
				'info' => 'Encoding is not allowed on this server',
			]);
		}

		// --- Case 4: encode new file ---
		if (!is_dir($destVideoPath)) {
			try {
				FileHelper::createDirectory($destVideoPath);
				if ($isDev) {
					Craft::info("Created destination directory: $destVideoPath", __METHOD__);
				}
			} catch (\Exception $e) {
				Craft::error($e->getMessage(), __METHOD__);
			}
		}

		$detectedCropFilter = null;
		if ($generate && $settings->autoCropVideoBlackBars && $filePathResolved !== null) {
			Craft::info("Transcoder: video black-bar auto crop enabled for $filePathResolved", __METHOD__);
			$detectedCropFilter = $this->detectVideoBlackBarCrop($filePathResolved);
			if ($detectedCropFilter !== null) {
				$videoOptions['preVideoFilters'][] = $detectedCropFilter;
				Craft::info("Transcoder: applying detected video crop filter $detectedCropFilter for $filePathResolved", __METHOD__);
			} else {
				Craft::info("Transcoder: no video crop filter applied for $filePathResolved", __METHOD__);
			}
		}

		$watermarkDebug = $this->getVideoWatermarkStatusDebug($encodingOptions);
		$watermarkConfig = $this->getVideoWatermarkConfig($encodingOptions);

		$ffmpegCmd = $settings['ffmpegPath']
			. ' -i ' . escapeshellarg($filePathResolved);

		if ($watermarkConfig !== null) {
			$ffmpegCmd .= ' -loop 1 -i ' . escapeshellarg($watermarkConfig['path']);
		}

		$ffmpegCmd .= ' -vcodec ' . $thisEncoder['videoCodec']
			. ' ' . $thisEncoder['videoCodecOptions']
			. ' -bufsize 1000k'
			. ' -threads ' . $thisEncoder['threads'];

		if (!empty($videoOptions['videoFrameRate'])) {
			$ffmpegCmd .= ' -r ' . $videoOptions['videoFrameRate'];
		}

		// Disabled bitrate setting (as in original)
		// if (!empty($videoOptions['videoBitRate'])) {
		//     $ffmpegCmd .= ' -b:v ' . $videoOptions['videoBitRate']
		//         . ' -maxrate ' . $videoOptions['videoBitRate'];
		// }

		if ($watermarkConfig !== null) {
			$filterComplex = $this->buildVideoWatermarkFilterComplex($videoOptions, $watermarkConfig, $filePathResolved);
			$ffmpegCmd .= ' -filter_complex ' . escapeshellarg($filterComplex)
				. ' -map ' . escapeshellarg('[vout]')
				. ' -map ' . escapeshellarg('0:a?');
			Craft::info("Transcoder: applying video watermark {$watermarkConfig['label']} with filter graph: $filterComplex", __METHOD__);
		} else {
			$ffmpegCmd = $this->addScalingFfmpegArgs($videoOptions, $ffmpegCmd);
		}

		if (empty($videoOptions['audioBitRate']) && empty($videoOptions['audioSampleRate']) && empty($videoOptions['audioChannels'])) {
			$ffmpegCmd .= ' -c:a copy';
		} else {
			$ffmpegCmd .= ' -acodec ' . $thisEncoder['audioCodec'];
			if (!empty($videoOptions['audioBitRate'])) {
				$ffmpegCmd .= ' -b:a ' . $videoOptions['audioBitRate'];
			}
			if (!empty($videoOptions['audioSampleRate'])) {
				$ffmpegCmd .= ' -ar ' . $videoOptions['audioSampleRate'];
			}
			if (!empty($videoOptions['audioChannels'])) {
				$ffmpegCmd .= ' -ac ' . $videoOptions['audioChannels'];
			}
			$ffmpegCmd .= ' ' . $thisEncoder['audioCodecOptions'];
		}

		$ffmpegCmd .= ' -f ' . $thisEncoder['fileFormat']
			. ' -y ' . escapeshellarg($encodedFile);
		$ffmpegCommand = $ffmpegCmd;
		$ffmpegCmd .= ' 1> ' . $progressFile . ' 2>&1 & echo $!';

		if ($isDev) {
			Craft::info("Final ffmpeg command: $ffmpegCmd", __METHOD__);
		}

		if ($generate) {
			$pid = $this->executeShellCommand($ffmpegCmd);
			file_put_contents($lockFile, $pid);

			if ($isDev) {
				Craft::info("Created lock file with PID $pid", __METHOD__);
			}

			Craft::info("Started ffmpeg PID $pid: $ffmpegCmd", __METHOD__);
			$status = [
				'status' => 'encoding',
				'url' => '',
				'info' => 'Encoding started',
				'ffmpegCommand' => $ffmpegCommand,
			];
			if ($watermarkConfig === null
				&& !empty($watermarkDebug['enabled'])
				&& !empty($watermarkDebug['requested'])
				&& !empty($watermarkDebug['skippedReason'])
			) {
				$status['watermarkWarning'] = 'Video watermark was skipped: ' . $watermarkDebug['skippedReason'];
				$status['warning'] = $status['watermarkWarning'];
			}
			if ($detectedCropFilter !== null) {
				$status['detectedCrop'] = $detectedCropFilter;
				$status['detectedCropSource'] = 'cropdetect';
			}
			if ($watermarkConfig !== null) {
				$status['watermarkAssetId'] = $watermarkConfig['assetId'];
				$status['watermarkSource'] = $watermarkConfig['source'];
				$status['watermarkLabel'] = $watermarkConfig['label'];
			}

			return JsonHelper::encode($status);
		}

		if ($isDev) {
			Craft::error("Encoding not possible, no url", __METHOD__);
		}

		return JsonHelper::encode([
			'status' => 'error',
			'url' => '',
			'error' => 'Encoding disabled',
		]);
	}

	/**
	 * Queue video encodes for all video assets found on an element.
	 *
	 * @param ElementInterface $element
	 * @return int
	 */
	public function queueVideoEncodesForElement(ElementInterface $element): int
	{
		$settings = Transcoder::$plugin->getSettings();
		$fieldHandles = $settings['autoEncodeVideoFieldHandles'] ?? [];
		$videoOptions = $settings['autoEncodeVideoOptions'] ?? [];
		$encodingOptions = $settings['autoEncodeEncodingOptions'] ?? [];
		$assets = [];

		if (!empty($fieldHandles)) {
			foreach ($fieldHandles as $fieldHandle) {
				try {
					$this->collectVideoAssets($element->getFieldValue($fieldHandle), $assets);
				} catch (Throwable $e) {
					Craft::warning('Unable to inspect Transcoder field handle "' . $fieldHandle . '": ' . $e->getMessage(), __METHOD__);
				}
			}
		} else {
			$this->collectVideoAssetsFromElement($element, $assets);
		}

		$queued = 0;
		foreach ($assets as $asset) {
			$event = new TranscoderQueueEvent([
				'element' => $element,
				'assetId' => $asset->id,
				'videoOptions' => $videoOptions,
				'encodingOptions' => $encodingOptions,
			]);
			$this->trigger(self::EVENT_BEFORE_QUEUE_VIDEO, $event);

			if (!$event->isValid) {
				continue;
			}

			$status = $this->queueVideoEncode(
				$asset,
				$event->videoOptions,
				$event->encodingOptions,
				$this->getElementTitle($event->element)
			);
			if (($status['status'] ?? null) === 'queued') {
				$queued++;
			}
		}

		return $queued;
	}

	/**
	 * Return the best available entry/owner title for a video asset.
	 *
	 * @param Asset $asset
	 * @return string|null
	 */
	public function getAssetOwnerTitle(Asset $asset): ?string
	{
		if (!$asset->id) {
			return null;
		}

		$title = $this->getMatrixBlockOwnerTitle($asset);
		if ($title !== null) {
			return $title;
		}

		$title = $this->getRelatedEntryTitle($asset);
		if ($title !== null) {
			return $title;
		}

		return $this->getAssetFolderEntryTitle($asset);
	}

	/**
	 * Return whether an asset still points at Craft's temporary upload storage.
	 *
	 * @param Asset $asset
	 * @return bool
	 */
	public function isTemporaryUploadAsset(Asset $asset): bool
	{
		foreach ($this->getAssetReferenceCandidates($asset) as $reference) {
			if ($this->isTemporaryUploadReference($reference)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return path/URL/folder references that can identify temporary upload assets.
	 *
	 * @param Asset $asset
	 * @return array
	 */
	protected function getAssetReferenceCandidates(Asset $asset): array
	{
		$candidates = [];

		foreach (['folderPath', 'path', 'url'] as $attribute) {
			try {
				$value = $asset->$attribute ?? null;
				if ($value !== null && $value !== '') {
					$candidates[] = (string)$value;
				}
			} catch (Throwable) {
			}
		}

		try {
			$folderPath = $asset->getFolder()->path ?? null;
			if ($folderPath !== null && $folderPath !== '') {
				$candidates[] = (string)$folderPath;
			}
		} catch (Throwable) {
		}

		try {
			$url = $asset->getUrl();
			if ($url !== null && $url !== '') {
				$candidates[] = $url;
			}
		} catch (Throwable) {
		}

		try {
			$assetPath = $this->getAssetPath($asset);
			if ($assetPath !== '') {
				$candidates[] = $assetPath;
			}
		} catch (Throwable) {
		}

		return array_values(array_unique($candidates));
	}

	/**
	 * Return whether a path/URL points at Craft's temp upload directory.
	 *
	 * @param string|null $reference
	 * @return bool
	 */
	protected function isTemporaryUploadReference(?string $reference): bool
	{
		$reference = str_replace('\\', '/', trim((string)$reference));
		if ($reference === '') {
			return false;
		}

		return preg_match('~(?:^|/)user_\d+(?:/|$)~i', $reference) === 1
			|| preg_match('~(?:^|/)(?:storage/)?runtime/assets/tempuploads(?:/|$)~i', $reference) === 1;
	}

	/**
	 * Return a non-error status for assets that are not in their final folder yet.
	 *
	 * @param string $mediaType
	 * @return array
	 */
	protected function getTemporaryUploadStatus(string $mediaType): array
	{
		return [
			'status' => 'pending',
			'url' => '',
			'progress' => 0,
			'info' => 'Transcoder: ' . $mediaType . ' asset is still in Craft temporary upload storage',
		];
	}

	/**
	 * Return whether an asset can produce any queued media work.
	 *
	 * @param Asset $asset
	 * @return bool
	 */
	public function isQueueableMediaAsset(Asset $asset): bool
	{
		return ($this->isVideoQueueEnabled() && $this->isVideoAsset($asset))
			|| ($this->isGifQueueEnabled() && $this->isGifAsset($asset));
	}

	/**
	 * Return whether queueing should wait for Craft to resolve the asset's final source.
	 *
	 * @param Asset $asset
	 * @return bool
	 */
	public function shouldDeferAssetQueue(Asset $asset): bool
	{
		if (!$this->isQueueableMediaAsset($asset)) {
			return false;
		}

		return $this->isTemporaryUploadAsset($asset);
	}

	/**
	 * Return whether the original asset file is currently reachable.
	 *
	 * @param Asset $asset
	 * @return bool
	 */
	public function isAssetOriginalAvailable(Asset $asset): bool
	{
		$normalized = $this->normalizeFilePath($asset);
		if (!empty($normalized['path'])) {
			return is_file($normalized['path']);
		}

		if (!empty($normalized['url'])) {
			return $this->doesRemoteFileExist($normalized['url']);
		}

		return false;
	}

	/**
	 * Return whether a status represents a missing original video source.
	 *
	 * @param array $status
	 * @return bool
	 */
	public function isOriginalVideoMissingStatus(array $status): bool
	{
		return ($status['status'] ?? null) === 'error'
			&& str_contains((string)($status['error'] ?? ''), 'Transcoder: original video not found at ');
	}

	/**
	 * Return whether a status represents a missing original GIF source.
	 *
	 * @param array $status
	 * @return bool
	 */
	public function isOriginalGifMissingStatus(array $status): bool
	{
		return ($status['status'] ?? null) === 'error'
			&& str_contains((string)($status['error'] ?? ''), 'Transcoder: original GIF not found at ');
	}

	/**
	 * Return the owner entry title for an asset related through a Matrix block.
	 *
	 * @param Asset $asset
	 * @return string|null
	 */
	protected function getMatrixBlockOwnerTitle(Asset $asset): ?string
	{
		if (!class_exists(MatrixBlock::class)) {
			return null;
		}

		try {
			$query = MatrixBlock::find();
			$query->relatedTo(['targetElement' => $asset]);
			$this->prepareOwnerLookupQuery($query);

			$block = $query->one();
			if ($block instanceof ElementInterface && method_exists($block, 'getOwner')) {
				$owner = $block->getOwner();
				if ($owner instanceof ElementInterface) {
					return $this->getElementTitle($owner);
				}
			}
		} catch (Throwable $e) {
			Craft::debug('Unable to resolve Transcoder Matrix owner title for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
		}

		return null;
	}

	/**
	 * Return a directly related entry title for an asset.
	 *
	 * @param Asset $asset
	 * @return string|null
	 */
	protected function getRelatedEntryTitle(Asset $asset): ?string
	{
		try {
			$query = Entry::find();
			$query->relatedTo(['targetElement' => $asset]);
			$this->prepareOwnerLookupQuery($query);

			$entry = $query->one();
			return $entry instanceof ElementInterface ? $this->getElementTitle($entry) : null;
		} catch (Throwable $e) {
			Craft::debug('Unable to resolve Transcoder related entry title for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
		}

		return null;
	}

	/**
	 * Return an entry title from numeric asset folder segments, such as /videos/12345/.
	 *
	 * This covers upload/save events where Craft relations may not exist yet, but
	 * the project stores assets in per-entry folders.
	 *
	 * @param Asset $asset
	 * @return string|null
	 */
	protected function getAssetFolderEntryTitle(Asset $asset): ?string
	{
		$folderPath = '';

		try {
			$folderPath = trim((string)($asset->folderPath ?? ''));
		} catch (Throwable) {
		}

		if ($folderPath === '') {
			try {
				$folderPath = trim((string)($asset->getFolder()->path ?? ''));
			} catch (Throwable $e) {
				Craft::debug('Unable to inspect Transcoder asset folder for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
			}
		}

		if ($folderPath === '' || !preg_match_all('/(?:^|[\/\\\\])(\d+)(?=[\/\\\\]|$)/', $folderPath, $matches)) {
			return null;
		}

		$entryIds = array_reverse(array_values(array_unique(array_map('intval', $matches[1]))));
		foreach ($entryIds as $entryId) {
			if ($entryId <= 0) {
				continue;
			}

			try {
				$query = Entry::find()->id($entryId);
				$this->prepareOwnerLookupQuery($query);

				$entry = $query->one();
				$title = $entry instanceof ElementInterface ? $this->getElementTitle($entry) : null;
				if ($title !== null) {
					Craft::info('Transcoder: resolved owner title from asset folder "' . $folderPath . '" for asset ' . $asset->id . ': ' . $title, __METHOD__);
					return $title;
				}
			} catch (Throwable $e) {
				Craft::debug('Unable to resolve Transcoder entry title from folder segment ' . $entryId . ' for asset ' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
			}
		}

		return null;
	}

	/**
	 * Apply broad query options for owner lookups when available.
	 *
	 * @param ElementQueryInterface $query
	 * @return void
	 */
	protected function prepareOwnerLookupQuery(ElementQueryInterface $query): void
	{
		if (method_exists($query, 'site')) {
			$query->site('*');
		}
		if (method_exists($query, 'status')) {
			$query->status(null);
		}
		if (method_exists($query, 'drafts')) {
			$query->drafts(null);
		}
		if (method_exists($query, 'revisions')) {
			$query->revisions(null);
		}
		if (method_exists($query, 'limit')) {
			$query->limit(1);
		}
	}

	/**
	 * Return a trimmed title from any element that exposes one.
	 *
	 * @param ElementInterface|null $element
	 * @return string|null
	 */
	protected function getElementTitle(?ElementInterface $element): ?string
	{
		if ($element === null) {
			return null;
		}

		try {
			$title = trim((string)($element->title ?? ''));
			return $title !== '' ? $title : null;
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Queue a single video encode.
	 *
	 * @param Asset $asset
	 * @param array $videoOptions
	 * @param array $encodingOptions
	 * @param string|null $ownerTitle
	 * @param bool $deferIfOriginalMissing
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function queueVideoEncode(
		Asset $asset,
		array $videoOptions = [],
		array $encodingOptions = [],
		?string $ownerTitle = null,
		bool $deferIfOriginalMissing = false
	): array
	{
		$settings = Transcoder::$plugin->getSettings();
		if (!$this->isRuntimeEncodingEnabled()) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}
		if (!$this->isVideoAsset($asset)) {
			return [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Transcoder: asset is not a video',
			];
		}
		if ($this->isTemporaryUploadAsset($asset)) {
			return $this->getTemporaryUploadStatus('video');
		}
		if (!$settings->enableVideoEncoding && !$settings->enableVideoPosters) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}
		if (!$this->canStartEncodingFromCurrentRequest()) {
			return [
				'status' => 'pending',
				'url' => '',
				'progress' => 0,
				'info' => 'Encoding is not allowed on this server',
			];
		}

		$statusKey = $this->getVideoStatusKey($asset, $videoOptions, $encodingOptions);
		$queueLock = $this->acquireVideoQueueLock('video-asset-' . $asset->id);
		try {
			$outputInfo = $this->getVideoOutputInfo($asset, $videoOptions);
			$status = $this->getVideoStatusData($asset, $videoOptions, $encodingOptions);
			$originalMissing = !$this->isAssetOriginalAvailable($asset);
			if ($originalMissing && !$deferIfOriginalMissing) {
				return $status;
			}

			$activeAssetStatus = $this->findActiveVideoStatusForAsset($asset, $statusKey, true, true);
			if (!empty($activeAssetStatus)) {
				if (!$originalMissing && $this->isOriginalVideoSourceRetryStatus($activeAssetStatus)) {
					Craft::info('Transcoder: ignoring queued source-retry status for asset ' . $asset->id . ' because the original source is now reachable.', __METHOD__);
				} else {
					return $this->sanitizeVideoStatus($activeAssetStatus);
				}
			}

			$missingPosters = $settings->enableVideoPosters && $this->hasMissingVideoPosters($asset);
			$videoComplete = ($status['status'] ?? null) === 'ok'
				&& is_file($outputInfo['encodedFile'])
				&& filesize($outputInfo['encodedFile']) > 0;
			$videoInProgress = in_array($status['status'] ?? null, ['queued', 'encoding'], true);
			$postersInProgress = $this->isVideoPosterStatusActive($status);
			$queueVideo = $settings->enableVideoEncoding && !$videoComplete && !$videoInProgress;
			$queuePosters = $settings->enableVideoPosters && $missingPosters && !$postersInProgress;
			if ($originalMissing) {
				unset($status['error']);
			}

			if (!$queueVideo && !$queuePosters) {
				return $status;
			}

			$videoQueueTtrSeconds = max(1, (int)$settings->videoQueueTtrSeconds);
			$videoPosterQueueDelaySeconds = max(0, (int)$settings->videoPosterQueueDelaySeconds);
			$videoQueueDelaySeconds = $originalMissing ? max(5, $videoPosterQueueDelaySeconds) : 0;

			if ($queueVideo) {
				$queue = Craft::$app->getQueue()->ttr($videoQueueTtrSeconds);
				if ($videoQueueDelaySeconds > 0) {
					$queue->delay($videoQueueDelaySeconds);
				}

				$jobId = $queue->push(new EncodeVideo([
					'assetId' => $asset->id,
					'ownerTitle' => $ownerTitle ?: $this->getAssetOwnerTitle($asset),
					'videoOptions' => $videoOptions,
					'encodingOptions' => $encodingOptions,
					'queueTtrSeconds' => $videoQueueTtrSeconds,
					'attempt' => 1,
					'maxRetries' => max(0, (int)$settings->videoEncodeMaxRetries),
					'retryDelaySeconds' => max(0, (int)$settings->videoEncodeRetryDelaySeconds),
				]));

				$status = array_merge($status, [
					'status' => 'queued',
					'url' => '',
					'progress' => 0,
					'jobId' => $jobId,
					'queueTtrSeconds' => $videoQueueTtrSeconds,
					'delay' => $videoQueueDelaySeconds,
					'info' => $originalMissing ? 'Original video source is not reachable yet; queued a delayed retry' : ($status['info'] ?? ''),
				]);
			}

			if ($queuePosters) {
				$posterJobId = Craft::$app->getQueue()->ttr($videoQueueTtrSeconds)->delay($videoPosterQueueDelaySeconds)->push(new GenerateVideoPosters([
					'assetId' => $asset->id,
					'ownerTitle' => $ownerTitle ?: $this->getAssetOwnerTitle($asset),
					'videoOptions' => $videoOptions,
					'encodingOptions' => $encodingOptions,
					'queueTtrSeconds' => $videoQueueTtrSeconds,
					'attempt' => 1,
					'maxRetries' => max(0, (int)$settings->videoPosterMaxRetries),
					'retryDelaySeconds' => max(0, (int)$settings->videoPosterRetryDelaySeconds),
				]));

				$status = array_merge($status, [
					'status' => $videoInProgress || $queueVideo ? ($status['status'] ?? 'queued') : 'queued',
					'posterStatus' => 'queued',
					'posterProgress' => 0,
					'posterMessage' => Craft::t('transcoder', 'Video posters are queued'),
					'posterError' => '',
					'posterJobId' => $posterJobId,
					'posterDelay' => $videoPosterQueueDelaySeconds,
					'posterQueueTtrSeconds' => $videoQueueTtrSeconds,
				]);
			}

			$this->writeVideoStatus($asset, $videoOptions, $status, $encodingOptions);

			return $status;
		} finally {
			$this->releaseVideoQueueLock($queueLock);
		}
	}

	/**
	 * Queue poster generation for a video asset.
	 *
	 * @param Asset $asset
	 * @param array $videoOptions
	 * @param array $encodingOptions
	 * @param string|null $ownerTitle
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function queueVideoPosters(Asset $asset, array $videoOptions = [], array $encodingOptions = [], ?string $ownerTitle = null): array
	{
		$settings = Transcoder::$plugin->getSettings();

		if (!$this->isRuntimeEncodingEnabled()) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}

		if (!$this->isVideoAsset($asset)) {
			return [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Transcoder: asset is not a video',
			];
		}
		if ($this->isTemporaryUploadAsset($asset)) {
			return $this->getTemporaryUploadStatus('video');
		}

		if (!$settings->enableVideoPosters) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}
		if (!$this->canStartEncodingFromCurrentRequest()) {
			return [
				'status' => 'pending',
				'url' => '',
				'progress' => 0,
				'info' => 'Encoding is not allowed on this server',
			];
		}

		$statusKey = $this->getVideoStatusKey($asset, $videoOptions, $encodingOptions);
		$queueLock = $this->acquireVideoQueueLock('video-asset-' . $asset->id);
		try {
			$status = $this->getVideoStatusData($asset, $videoOptions, $encodingOptions);
			if (!$this->isAssetOriginalAvailable($asset)) {
				return $status;
			}

			$activeAssetStatus = $this->findActiveVideoStatusForAsset($asset, $statusKey, false, true);
			if (!empty($activeAssetStatus)) {
				if ($this->isAssetOriginalAvailable($asset) && $this->isOriginalVideoSourceRetryStatus($activeAssetStatus)) {
					Craft::info('Transcoder: ignoring queued poster source-retry status for asset ' . $asset->id . ' because the original source is now reachable.', __METHOD__);
				} else {
					return $this->sanitizeVideoStatus($activeAssetStatus);
				}
			}

			if (!$this->hasMissingVideoPosters($asset) || $this->isVideoPosterStatusActive($status)) {
				return $status;
			}

			$videoQueueTtrSeconds = max(1, (int)$settings->videoQueueTtrSeconds);
			$videoPosterQueueDelaySeconds = max(0, (int)$settings->videoPosterQueueDelaySeconds);
			$posterJobId = Craft::$app->getQueue()->ttr($videoQueueTtrSeconds)->delay($videoPosterQueueDelaySeconds)->push(new GenerateVideoPosters([
				'assetId' => $asset->id,
				'ownerTitle' => $ownerTitle ?: $this->getAssetOwnerTitle($asset),
				'videoOptions' => $videoOptions,
				'encodingOptions' => $encodingOptions,
				'queueTtrSeconds' => $videoQueueTtrSeconds,
				'attempt' => 1,
				'maxRetries' => max(0, (int)$settings->videoPosterMaxRetries),
				'retryDelaySeconds' => max(0, (int)$settings->videoPosterRetryDelaySeconds),
			]));

			$status = array_merge($status, [
				'status' => in_array($status['status'] ?? null, ['queued', 'encoding'], true) ? $status['status'] : 'queued',
				'posterStatus' => 'queued',
				'posterProgress' => 0,
				'posterMessage' => Craft::t('transcoder', 'Video posters are queued'),
				'posterError' => '',
				'posterJobId' => $posterJobId,
				'posterDelay' => $videoPosterQueueDelaySeconds,
				'posterQueueTtrSeconds' => $videoQueueTtrSeconds,
			]);
			$this->writeVideoStatus($asset, $videoOptions, $status, $encodingOptions);

			return $status;
		} finally {
			$this->releaseVideoQueueLock($queueLock);
		}
	}

	/**
	 * Queue media work for a newly uploaded asset.
	 *
	 * @param Asset $asset
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function queueMediaForAsset(Asset $asset): array
	{
		$settings = Transcoder::$plugin->getSettings();
		$statuses = [];

		if ($this->isVideoQueueEnabled() && $this->isVideoAsset($asset)) {
			$statuses['video'] = $this->queueVideoEncode(
				$asset,
				$settings['autoEncodeVideoOptions'] ?? [],
				$settings['autoEncodeEncodingOptions'] ?? [],
				null,
				true
			);
		}

		if ($this->isGifQueueEnabled() && $this->isGifAsset($asset)) {
			$statuses['gif'] = $this->queueGifEncode(
				$asset,
				$settings['autoEncodeGifOptions'] ?? [],
				max(0, (int)$settings->gifQueueDelaySeconds),
				true
			);
		}

		return $statuses;
	}

	/**
	 * Returns whether video queueing is enabled.
	 *
	 * @return bool
	 */
	public function isVideoQueueEnabled(): bool
	{
		$settings = Transcoder::$plugin->getSettings();

		return $this->isRuntimeEncodingEnabled()
			&& $this->canStartEncodingFromCurrentRequest()
			&& ($settings->queueVideosOnSave || $settings->queueVideosOnEntrySave)
			&& ($settings->enableVideoEncoding || $settings->enableVideoPosters);
	}

	/**
	 * Returns whether GIF queueing is enabled.
	 *
	 * @return bool
	 */
	public function isGifQueueEnabled(): bool
	{
		$settings = Transcoder::$plugin->getSettings();

		return $this->isRuntimeEncodingEnabled()
			&& $this->canStartEncodingFromCurrentRequest()
			&& ($settings->queueGifsOnSave || $settings->queueGifsOnEntrySave)
			&& $settings->enableGifEncoding;
	}

	/**
	 * Queue GIF encodes for all GIF assets found on an element.
	 *
	 * @param ElementInterface $element
	 * @return int
	 */
	public function queueGifEncodesForElement(ElementInterface $element): int
	{
		$settings = Transcoder::$plugin->getSettings();
		$fieldHandles = $settings['autoEncodeGifFieldHandles'] ?? [];
		$gifOptions = $settings['autoEncodeGifOptions'] ?? [];
		$assets = [];

		if (!empty($fieldHandles)) {
			foreach ($fieldHandles as $fieldHandle) {
				try {
					$this->collectGifAssets($element->getFieldValue($fieldHandle), $assets);
				} catch (Throwable $e) {
					Craft::warning('Unable to inspect Transcoder GIF field handle "' . $fieldHandle . '": ' . $e->getMessage(), __METHOD__);
				}
			}
		} else {
			$this->collectGifAssetsFromElement($element, $assets);
		}

		$queued = 0;
		$delaySeconds = max(0, (int)$settings->gifQueueDelaySeconds);
		foreach (array_values($assets) as $index => $asset) {
			$event = new TranscoderQueueEvent([
				'element' => $element,
				'assetId' => $asset->id,
				'videoOptions' => $gifOptions,
			]);
			$this->trigger(self::EVENT_BEFORE_QUEUE_GIF, $event);

			if (!$event->isValid) {
				continue;
			}

			$status = $this->queueGifEncode($asset, $event->videoOptions, $index * $delaySeconds);
			if (($status['status'] ?? null) === 'queued') {
				$queued++;
			}
		}

		return $queued;
	}

	/**
	 * Queue a single GIF encode.
	 *
	 * @param Asset $asset
	 * @param array $gifOptions
	 * @param int $delay
	 * @param bool $deferIfOriginalMissing
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function queueGifEncode(Asset $asset, array $gifOptions = [], int $delay = 0, bool $deferIfOriginalMissing = false): array
	{
		if (!$this->isRuntimeEncodingEnabled()) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}

		if (!$this->isGifAsset($asset)) {
			return [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Transcoder: asset is not a GIF',
			];
		}
		if ($this->isTemporaryUploadAsset($asset)) {
			return $this->getTemporaryUploadStatus('GIF');
		}
		if (!Transcoder::$plugin->getSettings()->enableGifEncoding) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}
		if (!$this->canStartEncodingFromCurrentRequest()) {
			return [
				'status' => 'pending',
				'url' => '',
				'progress' => 0,
				'info' => 'Encoding is not allowed on this server',
			];
		}

		$outputInfo = $this->getGifOutputInfo($asset, $gifOptions);
		$status = $this->getGifStatusData($asset, $gifOptions);
		$originalMissing = !$this->isAssetOriginalAvailable($asset);
		if ($originalMissing && !$deferIfOriginalMissing) {
			return $status;
		}

		if (($status['status'] ?? null) === 'ok' && is_file($outputInfo['encodedFile']) && filesize($outputInfo['encodedFile']) > 0) {
			return $status;
		}
		if (in_array($status['status'] ?? null, ['queued', 'encoding'], true)) {
			return $status;
		}

		$jobId = $this->pushQueueJob(new EncodeGif([
			'assetId' => $asset->id,
			'gifOptions' => $gifOptions,
		]), $delay);

		$status = [
			'status' => 'queued',
			'url' => '',
			'progress' => 0,
			'jobId' => $jobId,
			'delay' => $delay,
		];
		if ($originalMissing) {
			$status['info'] = 'Original GIF source is not reachable yet; queued a delayed retry';
		}
		$this->writeGifStatus($asset, $gifOptions, $status);

		return $status;
	}

	/**
	 * Return the queue-aware video status as a JSON string for Twig usage.
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 * @param array $encodingOptions
	 * @param bool $queueIfMissing
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getVideoStatus(Asset|string $filePath, array $videoOptions = [], array $encodingOptions = [], bool $queueIfMissing = false, bool $includeDebug = false): string
	{
		$status = $this->getVideoStatusData($filePath, $videoOptions, $encodingOptions);
		$missingPosters = $filePath instanceof Asset
			&& $this->isRuntimeEncodingEnabled()
			&& Transcoder::$plugin->getSettings()->enableVideoPosters
			&& $this->hasMissingVideoPosters($filePath);

		if ($queueIfMissing
			&& $filePath instanceof Asset
			&& $this->isRuntimeEncodingEnabled()
			&& (
				($status['status'] ?? null) === 'pending'
				|| (($status['status'] ?? null) === 'ok' && $missingPosters)
				|| (($status['status'] ?? null) === 'disabled' && $missingPosters)
			)
		) {
			$status = $this->queueVideoEncode($filePath, $videoOptions, $encodingOptions);
		}

		if ($includeDebug) {
			$status['debug'] = $this->getVideoStatusDebug($filePath, $videoOptions, $encodingOptions);
		}

		return JsonHelper::encode($status);
	}

	/**
	 * Return a URL that can be polled for queue-aware video status.
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 * @param array $encodingOptions
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getVideoStatusUrl(Asset|string $filePath, array $videoOptions = [], array $encodingOptions = []): string
	{
		return UrlHelper::actionUrl('transcoder/default/video-status', [
			'key' => $this->getVideoStatusKey($filePath, $videoOptions, $encodingOptions),
		]);
	}

	/**
	 * Return queue-aware video status data.
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 * @param array $encodingOptions
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function getVideoStatusData(Asset|string $filePath, array $videoOptions = [], array $encodingOptions = []): array
	{
		if (!$this->isRuntimeEncodingEnabled() || !Transcoder::$plugin->getSettings()->enableVideoEncoding) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}
		if ($filePath instanceof Asset && $this->isTemporaryUploadAsset($filePath)) {
			return $this->sanitizeVideoStatus($this->getTemporaryUploadStatus('video'));
		}

		$outputInfo = $this->getVideoOutputInfo($filePath, $videoOptions);
		$statusKey = $this->getVideoStatusKey($filePath, $videoOptions, $encodingOptions);
		$storedStatus = $this->readVideoStatus($statusKey);

		if (is_file($outputInfo['encodedFile'])
			&& filesize($outputInfo['encodedFile']) > 0
			&& (!is_file($outputInfo['lockFile']) || !$this->isProcessRunningFromLockFile($outputInfo['lockFile']))
		) {
			$failure = $this->getVideoEncodeFailure($outputInfo, $storedStatus);
			if ($failure) {
				$this->removeEncodeTempFiles($outputInfo['lockFile'], $outputInfo['progressFile']);
				@unlink($outputInfo['encodedFile']);
				$status = array_merge([
					'status' => 'error',
					'url' => '',
					'progress' => 0,
				], $failure);
				$this->writeVideoStatusByKey(
					$statusKey,
					$this->addAssetStatusInfo($filePath, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status))
				);
				Craft::error($status['error'], __METHOD__);
				return $this->sanitizeVideoStatus($status);
			}
			@unlink($outputInfo['lockFile']);
			@unlink($outputInfo['progressFile']);
			$status = [
				'status' => 'ok',
				'url' => $outputInfo['publicUrl'],
				'progress' => 100,
			];
			$status = array_merge($status, $this->getVideoPosterStatusFields($storedStatus));
			if (!$outputInfo['originalExists']) {
				$status['warning'] = 'Original video missing, serving encoded version';
			}
			if (!empty($storedStatus['watermarkWarning'])) {
				$status['warning'] = !empty($status['warning'])
					? $status['warning'] . ' ' . $storedStatus['watermarkWarning']
					: $storedStatus['watermarkWarning'];
				$status['watermarkWarning'] = $storedStatus['watermarkWarning'];
			}
			$this->writeVideoStatusByKey(
				$statusKey,
				$this->addAssetStatusInfo($filePath, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status))
			);
			return $this->sanitizeVideoStatus($status);
		}

		if ($this->isEncodeLockStale($outputInfo['lockFile'], $outputInfo['progressFile'])) {
			$failure = $this->getVideoProcessCrashedFailure($outputInfo, $storedStatus);
			$this->removeEncodeTempFiles($outputInfo['lockFile'], $outputInfo['progressFile']);
			$status = array_merge([
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Encoding failed due to a server error (process crashed, ffmpeg error)',
			], $failure);
			$this->writeVideoStatusByKey(
				$statusKey,
				$this->addAssetStatusInfo($filePath, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status))
			);
			Craft::error('Transcoder: ffmpeg process died unexpectedly for ' . ($outputInfo['source'] ?? 'unknown'), __METHOD__);
			return $this->sanitizeVideoStatus($status);
		}

		if (is_file($outputInfo['lockFile'])) {
			$status = array_merge(
				[
					'status' => 'encoding',
					'url' => '',
					'progress' => 0,
				],
				!empty($storedStatus['ffmpegCommand']) ? ['ffmpegCommand' => $storedStatus['ffmpegCommand']] : [],
				!empty($storedStatus['watermarkWarning']) ? [
					'watermarkWarning' => $storedStatus['watermarkWarning'],
					'warning' => $storedStatus['watermarkWarning'],
				] : [],
				$this->getVideoPosterStatusFields($storedStatus),
				$this->getProgressData($outputInfo['filename'])
			);
			$this->writeVideoStatusByKey(
				$statusKey,
				$this->addAssetStatusInfo($filePath, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status))
			);
			return $this->sanitizeVideoStatus($status);
		}

		$activeStatus = $this->findActiveVideoStatusForFilenames($outputInfo['filenameCandidates'] ?? [$outputInfo['filename']], $statusKey);
		if (!empty($activeStatus)) {
			if ($outputInfo['originalExists'] && $this->isOriginalVideoSourceRetryStatus($activeStatus)) {
				Craft::info('Transcoder: ignoring queued source-retry status for ' . ($outputInfo['source'] ?? 'unknown') . ' because the original source is now reachable.', __METHOD__);
			} else {
				return $this->sanitizeVideoStatus($activeStatus);
			}
		}

		if ($filePath instanceof Asset) {
			$activeStatus = $this->findActiveVideoStatusForAsset($filePath, $statusKey, true, true);
			if (!empty($activeStatus)) {
				if ($outputInfo['originalExists'] && $this->isOriginalVideoSourceRetryStatus($activeStatus)) {
					Craft::info('Transcoder: ignoring queued source-retry status for asset ' . $filePath->id . ' because the original source is now reachable.', __METHOD__);
				} else {
					return $this->sanitizeVideoStatus($activeStatus);
				}
			}
		}

		if (!empty($storedStatus) && ($storedStatus['status'] ?? null) === 'ok') {
			$storedStatus = [];
		}

		if (!empty($storedStatus)
			&& ($storedStatus['status'] ?? null) === 'encoding'
			&& !is_file($outputInfo['encodedFile'])
			&& !is_file($outputInfo['lockFile'])
		) {
			if (!empty($storedStatus['lockFile'])) {
				@unlink($storedStatus['lockFile']);
			}
			if (!empty($storedStatus['progressFile'])) {
				@unlink($storedStatus['progressFile']);
			}
			$storedStatus = [];
		}

		if (!empty($storedStatus)
			&& ($storedStatus['status'] ?? null) === 'queued'
			&& !$this->isRecentVideoStatus($storedStatus, 1800)
		) {
			$storedStatus = [];
		}

		if (!empty($storedStatus)
			&& $outputInfo['originalExists']
			&& in_array($storedStatus['status'] ?? null, ['queued', 'encoding'], true)
			&& $this->isOriginalVideoSourceRetryStatus($storedStatus)
		) {
			Craft::info('Transcoder: clearing stored source-retry status for ' . ($outputInfo['source'] ?? 'unknown') . ' because the original source is now reachable.', __METHOD__);
			$storedStatus = [];
		}

		if (!empty($storedStatus)) {
			return $this->sanitizeVideoStatus($storedStatus);
		}

		if (!$outputInfo['originalExists']) {
			return $this->sanitizeVideoStatus([
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Transcoder: original video not found at ' . ($outputInfo['source'] ?? 'unknown'),
			]);
		}

		return $this->sanitizeVideoStatus([
			'status' => 'pending',
			'url' => '',
			'progress' => 0,
		]);
	}

	/**
	 * Write queue-aware video status for a file.
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 * @param array $status
	 * @param array $encodingOptions
	 * @throws InvalidConfigException
	 */
	public function writeVideoStatus(Asset|string $filePath, array $videoOptions, array $status, array $encodingOptions = []): void
	{
		$outputInfo = $this->getVideoOutputInfo($filePath, $videoOptions);
		$statusKey = $this->getVideoStatusKey($filePath, $videoOptions, $encodingOptions);
		$storedStatus = $this->readVideoStatus($statusKey);
		$status = array_merge($this->getVideoPosterStatusFields($storedStatus), $status);
		$status = array_merge($this->getVideoStatusStorageInfo($outputInfo), $status);
		$status = $this->addAssetStatusInfo($filePath, $status);

		$this->writeVideoStatusByKey(
			$statusKey,
			$status
		);
	}

	/**
	 * Write poster generation status without erasing current video progress.
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 * @param array $status
	 * @param array $encodingOptions
	 * @throws InvalidConfigException
	 */
	public function writeVideoPosterStatus(Asset|string $filePath, array $videoOptions, array $status, array $encodingOptions = []): void
	{
		$outputInfo = $this->getVideoOutputInfo($filePath, $videoOptions);
		$statusKey = $this->getVideoStatusKey($filePath, $videoOptions, $encodingOptions);
		$storedStatus = $this->readVideoStatus($statusKey);

		$this->writeVideoStatusByKey(
			$statusKey,
			$this->addAssetStatusInfo(
				$filePath,
				array_merge($this->getVideoStatusStorageInfo($outputInfo), $storedStatus, $status)
			)
		);
	}

	/**
	 * Return the stable status key for a video/options pair.
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 * @param array $encodingOptions
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getVideoStatusKey(Asset|string $filePath, array $videoOptions = [], array $encodingOptions = []): string
	{
		$videoOptions = $this->coalesceOptions('defaultVideoOptions', $videoOptions);
		$settings = Transcoder::$plugin->getSettings();
		$videoEncoders = $settings['videoEncoders'];
		$thisEncoder = $videoEncoders[$videoOptions['videoEncoder']];
		$videoOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

		return 'video-' . sha1($this->getFilename($filePath, $videoOptions) . JsonHelper::encode($encodingOptions));
	}

	/**
	 * Normalize asset or path into either a usable URL or local path.
	 */
	private function normalizeFilePath(string|Asset $input): array
	{
		if ($input instanceof Asset) {
			$filePath = $this->getAssetPath($input);
			if ($filePath !== '' && !$this->isUrl($filePath) && file_exists($filePath)) {
				return ['path' => $filePath];
			}

			$url = $this->isUrl($filePath) ? $filePath : ($input->getUrl() ?? '');
			$url = $this->normalizeSiteUrl($url);
			return ['url' => $url];
		}

		$input = (string)App::parseEnv($input);
		if ($this->isUrl($input)) {
			return ['url' => $input];
		}

		if (file_exists($input)) {
			return ['path' => $input];
		}

		return ['url' => $this->normalizeSiteUrl($input)];
	}

	/**
	 * Normalize a root-relative or relative asset URL to an absolute site URL.
	 *
	 * @param string|null $url
	 * @return string
	 */
	private function normalizeSiteUrl(?string $url): string
	{
		$url = trim((string)App::parseEnv($url ?? ''));
		if ($url === '' || $this->isUrl($url)) {
			return $url;
		}

		$siteUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();

		return rtrim($siteUrl, '/') . '/' . ltrim($url, '/');
	}


	/**
	 * Returns true if a string is a valid URL
	 *
	 */
	private function isUrl(string $url): bool
	{
		return filter_var($url, FILTER_VALIDATE_URL) !== false;
	}

	/**
	 * Returns a subfolder name based on the provided file path and settings.
	 *
	 * - If `$filePath` is an `Asset` and `createSubfolders` is enabled in settings, 
	 *   the folder path from the asset will be used.
	 * - If `subfolderUrlSegment` is set in settings and `$filePath` is a string (URL), 
	 *   the corresponding segment will be returned.
	 *
	 * @param string|Asset $filePath The file path or an Asset object
	 * @param array $settings An array containing subfolder settings:
	 *                         - 'createSubfolders' (bool)
	 *                         - 'subfolderUrlSegment' (int)
	 * @return string The subfolder name, with a trailing slash if found, or an empty string
	 */
	function getSubfolderFromPath(string|Asset $filePath): string
	{
		$settings = Transcoder::$plugin->getSettings();

		// Case: filePath is an Asset and subfolders are enabled
		if (($filePath instanceof Asset) && !empty($settings['createSubfolders'])) {
			return $filePath->folderPath ?? '';
		}

		// Case: extract subfolder from URL segment
		if (!empty($settings['subfolderUrlSegment']) && is_string($filePath)) {
			$urlPath = parse_url($filePath, PHP_URL_PATH); // Extract the path part
			$segments = array_values(array_filter(explode('/', $urlPath))); // Clean up and reindex

			$index = $settings['subfolderUrlSegment'] - 1; // Convert to zero-based index
			return isset($segments[$index]) ? $segments[$index] . '/' : '';
		}

		return '';
	}

	/**
	 * Checks if a remote file exists via HTTP.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if the file exists, false otherwise.
	 */
	private function doesRemoteFileExist(string $url, int $attempts = 1, int $delaySeconds = 0): bool
	{
		// Bail early if not a valid absolute URL
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}

		// Add custom params to the url
		$url = $this->addCustomParams($url);

		$attempts = max(1, $attempts);
		for ($attempt = 1; $attempt <= $attempts; $attempt++) {
			$headers = @get_headers($url);

			if ($headers && preg_match('/\s(200|206|302|303|307|308)\s/', $headers[0])) {
				return true;
			}

			if ($attempt < $attempts && $delaySeconds > 0) {
				sleep($delaySeconds);
			}
		}

		return false;
	}

	/**
	 * Adds direct=1 param to url, so secure url check on nginx server is skipped
	 * Also add nocache=1, so cloudflare does not cache it // you have to setup this rule on cloudflare for your domain
	 *
	 * @param string $url The URL to check.
	 * @return string $url 
	 */
	private function addCustomParams(string $url): string
	{
		$parsedUrl = parse_url($url);

		// Parse existing query parameters
		parse_str($parsedUrl['query'] ?? '', $queryParams);

		// Add or update `is_skoften=true`
		$queryParams['direct'] = '1';
		$queryParams['nocache'] = '1';

		// Build the new query string
		$newQueryString = http_build_query($queryParams);

		// Reconstruct the full URL
		$newUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

		if (isset($parsedUrl['port'])) {
			$newUrl .= ':' . $parsedUrl['port'];
		}

		$newUrl .= $parsedUrl['path'];
		$newUrl .= '?' . $newQueryString;

		if (isset($parsedUrl['fragment'])) {
			$newUrl .= '#' . $parsedUrl['fragment'];
		}

		return $newUrl;
	}

	/**
	 * Returns a URL to a video thumbnail
	 *
	 * @param Asset|string $filePath path to the original video or an Asset
	 * @param array $thumbnailOptions of options for the thumbnail
	 * @param bool $generate whether the thumbnail should be
	 *                                 generated if it doesn't exists
	 * @param bool $asPath Whether we should return a path or not
	 * @param bool $synchronous Whether ffmpeg should run synchronously
	 *
	 * @return string|false|null URL or path of the video thumbnail
	 * @throws InvalidConfigException
	 */
	public function getVideoThumbnailUrl(Asset|string $filePath, array $thumbnailOptions, bool $generate = true, bool $asPath = false, bool $synchronous = false): string|false|null
	{
		if ($generate && !$this->isRuntimeEncodingEnabled()) {
			return false;
		}

		$result = null;
		$settings = Transcoder::$plugin->getSettings();
		$subfolder = $this->getSubfolderFromPath($filePath);

		// --- Normalize input (same as getVideoUrl) ---
		$normalized = $this->normalizeFilePath($filePath);
		$filePathResolved = null;

		if (isset($normalized['url'])) {
			// Remote or site-relative URL (normalized to full URL)
			$filePathResolved = $normalized['url'];
		} elseif (isset($normalized['path'])) {
			// Local filesystem path
			$filePathResolved = $normalized['path'];
		}

		if (!empty($filePathResolved)) {
			// Destination path & public URL base
			if (!empty($subfolder)) {
				$destThumbnailPath = rtrim(App::parseEnv($settings['transcoderPaths']['thumbnail']), DIRECTORY_SEPARATOR)
					. DIRECTORY_SEPARATOR
					. trim($subfolder, DIRECTORY_SEPARATOR)
					. DIRECTORY_SEPARATOR;

				$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['thumbnail']), '/')
					. '/' . trim($subfolder, '/');
			} else {
				$destThumbnailPath = rtrim(App::parseEnv($settings['transcoderPaths']['default']), DIRECTORY_SEPARATOR)
					. DIRECTORY_SEPARATOR;

				$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['default']), '/');
			}

			// Options
			$thumbnailOptions = $this->coalesceOptions('defaultThumbnailOptions', $thumbnailOptions);

			// Build the file name. Poster handles and visual generation toggles should
			// not change poster filenames; time/size/aspect options already separate them.
			$primaryThumbnailFile = $this->getFilename(
				$filePathResolved,
				$thumbnailOptions,
				$this->getThumbnailFilenameExcludeParams()
			);
			$destThumbnailFile = $primaryThumbnailFile;
			$destThumbnailFile = $this->getExistingThumbnailFilenameCandidate(
				$destThumbnailPath,
				$filePath,
				$filePathResolved,
				$thumbnailOptions,
				$destThumbnailFile
			);
			if ($destThumbnailFile !== $primaryThumbnailFile) {
				Craft::info('Transcoder: using existing legacy video poster/thumbnail filename ' . $destThumbnailFile . ' for ' . $filePathResolved, __METHOD__);
			}

			// Public URL
			$publicUrl = $urlBase . '/' . $destThumbnailFile;

			// Check if remote file exists first
			if ($this->isUrl($filePathResolved) && $this->doesRemoteFileExist($publicUrl)) {
				return $publicUrl;
			}

			// Build the ffmpeg command
			$ffmpegCmd = $settings['ffmpegPath']
				. ' -i ' . escapeshellarg($filePathResolved)
				. ' -vcodec mjpeg'
				. ' -vframes 1';

			// Adjust scaling
			$ffmpegCmd = $this->addScalingFfmpegArgs($thumbnailOptions, $ffmpegCmd);

			// Set timecode
			if (!empty($thumbnailOptions['timeInSecs'])) {
				$seekTime = $this->getSafeVideoThumbnailSeekTime($filePathResolved, (float)$thumbnailOptions['timeInSecs']);
				$ffmpegCmd .= ' -ss ' . $this->formatFfmpegTimecode($seekTime);
			}

			// Ensure directory exists
			if (!is_dir($destThumbnailPath)) {
				try {
					FileHelper::createDirectory($destThumbnailPath);
				} catch (Exception $e) {
					Craft::error($e->getMessage(), __METHOD__);
				}
			}

			// Destination file path
			$destThumbnailPath .= $destThumbnailFile;

			// Final ffmpeg command
			$ffmpegCmd .= ' -f image2 -y ' . escapeshellarg($destThumbnailPath);

			// Generate thumbnail if not exists
			if (!file_exists($destThumbnailPath)) {
				Craft::info(
					'Transcoder: video poster/thumbnail file does not exist, checked candidates before generation: '
					. JsonHelper::encode($this->getThumbnailCandidateDebug($filePath, $filePathResolved, $thumbnailOptions, dirname($destThumbnailPath) . DIRECTORY_SEPARATOR)),
					__METHOD__
				);

				if ($generate) {
					if ($synchronous) {
						$shellOutput = $this->executeShellCommand($ffmpegCmd . ' 2>&1');
						Craft::info($ffmpegCmd, __METHOD__);

						if (!file_exists($destThumbnailPath) || filesize($destThumbnailPath) === 0) {
							$message = 'Video poster generation failed for ' . $filePathResolved
								. "\n\nFFmpeg command:\n" . $ffmpegCmd
								. "\n\nFFmpeg log:\n" . trim($shellOutput);
							Craft::error($message, __METHOD__);
							throw new \RuntimeException($message);
						}
					} else {
						$shellOutput = $this->executeShellCommand($ffmpegCmd . ' >/dev/null 2>/dev/null &');
						Craft::info($ffmpegCmd, __METHOD__);
					}
				} else {
					Craft::info('Thumbnail does not exist, but not asked to generate it: ' . $filePathResolved, __METHOD__);
				}

				if (!file_exists($destThumbnailPath)) {
					return false;
				}
			}

			// Return path or URL
			if ($asPath) {
				$result = $destThumbnailPath;
			} else {
				$result = $publicUrl;
			}
		}

		return $result;
	}

	/**
	 * Clamp poster seek time to a real frame inside the source duration.
	 *
	 * @param Asset|string $filePath
	 * @param float $requestedTime
	 * @return float
	 * @throws InvalidConfigException
	 */
	protected function getSafeVideoThumbnailSeekTime(Asset|string $filePath, float $requestedTime): float
	{
		$requestedTime = max(0.0, $requestedTime);
		$fileInfo = $this->getFileInfo($filePath, true) ?? [];
		$duration = isset($fileInfo['duration']) && is_numeric($fileInfo['duration'])
			? (float)$fileInfo['duration']
			: 0.0;

		if ($duration <= 0) {
			return $requestedTime;
		}

		$padding = min(0.25, max(0.05, $duration * 0.05));
		$safeMax = max(0.0, $duration - $padding);
		$seekTime = min($requestedTime, $safeMax);

		if ($seekTime < $requestedTime) {
			Craft::info(
				'Transcoder: clamped video poster timestamp from '
				. $requestedTime
				. 's to '
				. $seekTime
				. 's for '
				. $filePath
				. ' because source duration is '
				. $duration
				. 's',
				__METHOD__
			);
		}

		return $seekTime;
	}

	/**
	 * Format seconds as an ffmpeg timecode with millisecond precision.
	 *
	 * @param float $seconds
	 * @return string
	 */
	protected function formatFfmpegTimecode(float $seconds): string
	{
		$totalMilliseconds = (int)floor(max(0.0, $seconds) * 1000);
		$hours = intdiv($totalMilliseconds, 3600000);
		$totalMilliseconds %= 3600000;
		$minutes = intdiv($totalMilliseconds, 60000);
		$totalMilliseconds %= 60000;
		$wholeSeconds = intdiv($totalMilliseconds, 1000);
		$milliseconds = $totalMilliseconds % 1000;

		return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $wholeSeconds, $milliseconds);
	}

	/**
	 * Return a configured poster URL, or an empty string if it has not been generated.
	 *
	 * @param Asset|string $filePath
	 * @param string $formatHandle
	 * @param bool $generate
	 * @param bool $synchronous
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getVideoPosterUrl(Asset|string $filePath, string $formatHandle, bool $generate = false, bool $synchronous = false): string
	{
		if (!$this->isRuntimeEncodingEnabled() || !Transcoder::$plugin->getSettings()->enableVideoPosters) {
			return '';
		}
		if ($generate && !$this->canStartEncodingFromCurrentRequest()) {
			Craft::info('Transcoder: video poster generation not started because this server is not allowed to start encode work.', __METHOD__);
			return '';
		}

		$formats = $this->getVideoPosterFormats();
		if (!array_key_exists($formatHandle, $formats)) {
			return '';
		}

		$options = $this->coalesceOptions('defaultThumbnailOptions', $formats[$formatHandle]);
		$options['posterFormat'] = $formatHandle;
		$options['preventBlackBars'] = (bool)Transcoder::$plugin->getSettings()->preventVideoPosterBlackBars;

		$url = $this->getVideoThumbnailUrl($filePath, $options, $generate, false, $synchronous);

		return is_string($url) ? $url : '';
	}

	/**
	 * Return configured poster URLs keyed by format handle.
	 *
	 * @param Asset|string $filePath
	 * @param bool $generate
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function getVideoPosterUrls(Asset|string $filePath, bool $generate = false): array
	{
		$result = [];
		foreach ($this->getVideoPosterFormats() as $formatHandle => $format) {
			$result[$formatHandle] = $this->getVideoPosterUrl($filePath, $formatHandle, $generate);
		}

		return $result;
	}

	/**
	 * Return debug data for configured video poster files.
	 *
	 * @param Asset|string $filePath
	 * @return array
	 */
	public function getVideoPosterDebug(Asset|string $filePath): array
	{
		return $this->getVideoPosterStatusDebug($filePath);
	}

	/**
	 * Generate all configured poster formats for a video.
	 *
	 * @param Asset|string $filePath
	 * @param callable|null $progressCallback
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function generateVideoPosters(Asset|string $filePath, ?callable $progressCallback = null): array
	{
		$result = [];
		$formats = $this->getVideoPosterFormats();
		$total = count($formats);
		$current = 0;

		foreach ($formats as $formatHandle => $format) {
			$current++;
			if ($progressCallback !== null) {
				$progressCallback($formatHandle, $current, $total);
			}

			$result[$formatHandle] = $this->getVideoPosterUrl($filePath, $formatHandle, true, true);
		}

		return $result;
	}

	/**
	 * Return whether any configured poster is missing.
	 *
	 * @param Asset|string $filePath
	 * @return bool
	 * @throws InvalidConfigException
	 */
	protected function hasMissingVideoPosters(Asset|string $filePath): bool
	{
		foreach ($this->getVideoPosterUrls($filePath) as $url) {
			if ($url === '') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return normalized poster format rows for the settings UI.
	 *
	 * @return array
	 */
	public function getVideoPosterFormatRows(): array
	{
		$rows = [];
		foreach ($this->getVideoPosterFormats() as $handle => $format) {
			$rows[] = [
				'handle' => $handle,
				'width' => $format['width'] ?? '',
				'height' => $format['height'] ?? '',
				'timeInSecs' => $format['timeInSecs'] ?? '',
			];
		}

		return $rows;
	}

	/**
	 * Return configured poster formats keyed by handle.
	 *
	 * @return array
	 */
	protected function getVideoPosterFormats(): array
	{
		$formats = Transcoder::$plugin->getSettings()->videoPosterFormats;
		$result = [];

		foreach ($formats as $key => $format) {
			if (!is_array($format)) {
				continue;
			}

			$handle = is_string($key) ? $key : ($format['handle'] ?? '');
			$handle = trim((string)$handle);
			if ($handle === '') {
				continue;
			}

			$options = [];
			foreach (['width', 'height', 'timeInSecs'] as $optionKey) {
				if (isset($format[$optionKey]) && $format[$optionKey] !== '') {
					$options[$optionKey] = (int)$format[$optionKey];
				}
			}

			$result[$handle] = $options;
		}

		return $result;
	}

	/**
	 * Returns a URL to the transcoded audio file or "" if it doesn't exist
	 * (at which time it will create it).
	 *
	 * @param Asset|string $filePath path to the original audio file -OR- an Asset
	 * @param array $audioOptions array of options for the audio file
	 *
	 * @return string       URL of the transcoded audio file or ""
	 * @throws InvalidConfigException
	 */
	public function getAudioUrl(Asset|string $filePath, array $audioOptions): string
	{
		$result = '';
		$settings = Transcoder::$plugin->getSettings();
		$subfolder = '';

		// Subfolder check
		$subfolder = $this->getSubfolderFromPath($filePath);

		// Asset path
		$filePath = $this->getAssetPath($filePath);

		if (!empty($filePath)) {
			$destAudioPath = $settings['transcoderPaths']['audio'] . $subfolder ?? $settings['transcoderPaths']['default'];
			$destAudioPath = App::parseEnv($destAudioPath);

			$audioOptions = $this->coalesceOptions('defaultAudioOptions', $audioOptions);

			// Get the audio encoder presets to use
			$audioEncoders = $settings['audioEncoders'];
			$thisEncoder = $audioEncoders[$audioOptions['audioEncoder']];

			$audioOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

			// Build the basic command for ffmpeg
			$ffmpegCmd = $settings['ffmpegPath']
				. ' -i ' . escapeshellarg($filePath)
				. ' -acodec ' . $thisEncoder['audioCodec']
				. ' ' . $thisEncoder['audioCodecOptions']
				. ' -bufsize 1000k'
				. ' -vn'
				. ' -threads ' . $thisEncoder['threads'];

			// Set the bitrate if desired
			if (!empty($audioOptions['audioBitRate'])) {
				$ffmpegCmd .= ' -b:a ' . $audioOptions['audioBitRate'];
			}
			// Set the sample rate if desired
			if (!empty($audioOptions['audioSampleRate'])) {
				$ffmpegCmd .= ' -ar ' . $audioOptions['audioSampleRate'];
			}
			// Set the audio channels if desired
			if (!empty($audioOptions['audioChannels'])) {
				$ffmpegCmd .= ' -ac ' . $audioOptions['audioChannels'];
			}
			$ffmpegCmd .= ' ' . $thisEncoder['audioCodecOptions'];

			if (!empty($audioOptions['seekInSecs'])) {
				$ffmpegCmd .= ' -ss ' . $audioOptions['seekInSecs'];
			}

			if (!empty($audioOptions['timeInSecs'])) {
				$ffmpegCmd .= ' -t ' . $audioOptions['timeInSecs'];
			}

			// Create the directory if it isn't there already
			if (!is_dir($destAudioPath)) {
				try {
					FileHelper::createDirectory($destAudioPath);
				} catch (Exception $e) {
					Craft::error($e->getMessage(), __METHOD__);
				}
			}

			$destAudioFile = $this->getFilename($filePath, $audioOptions);

			// File to store the audio encoding progress in
			$progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destAudioFile . '.progress';

			// Assemble the destination path and final ffmpeg command
			$destAudioPath .= $destAudioFile;
			// Handle the `stripMetadata` setting
			$stripMetadata = false;
			if (!empty($audioOptions['stripMetadata'])) {
				$stripMetadata = $audioOptions['stripMetadata'];
			}
			if ($stripMetadata) {
				$ffmpegCmd .= ' -map_metadata -1 ';
			}
			// Add the file format
			$ffmpegCmd .= ' -f '
				. $thisEncoder['fileFormat']
				. ' -y ' . escapeshellarg($destAudioPath);
			// Handle the `synchronous` setting
			$synchronous = false;
			if (!empty($audioOptions['synchronous'])) {
				$synchronous = $audioOptions['synchronous'];
			}
			if (!$synchronous) {
				$ffmpegCmd .= ' 1> ' . $progressFile . ' 2>&1 & echo $!';
				// Make sure there isn't a lockfile for this audio file already
				$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destAudioFile . '.lock';
				$oldPid = @file_get_contents($lockFile);
				if ($oldPid !== false) {
					// See if the process is running, and empty result means the process is still running
					// ref: https://stackoverflow.com/questions/3043978/how-to-check-if-a-process-id-pid-exists
					exec("kill -0 $oldPid 2>&1", $ProcessState);
					if (count($ProcessState) === 0) {
						return $result;
					}
					// It's finished transcoding, so delete the lockfile and progress file
					@unlink($lockFile);
					@unlink($progressFile);
				}
			}

			// If the audio file already exists and hasn't been modified, return it.  Otherwise, start it transcoding
			if (file_exists($destAudioPath) && (@filemtime($destAudioPath) >= @filemtime($filePath))) {
				$url = $settings['transcoderUrls']['audio'] . $subfolder ?? $settings['transcoderUrls']['default'];
				$result = App::parseEnv($url) . $destAudioFile;
			} else {
				// Kick off the transcoding
				$pid = $this->executeShellCommand($ffmpegCmd);

				if ($synchronous) {
					Craft::info($ffmpegCmd, __METHOD__);
					$url = $settings['transcoderUrls']['audio'] . $subfolder ?? $settings['transcoderUrls']['default'];
					$result = App::parseEnv($url) . $destAudioFile;
				} else {
					Craft::info($ffmpegCmd . "\nffmpeg PID: " . $pid, __METHOD__);
					// Create a lockfile in tmp
					file_put_contents($lockFile, $pid);
				}
			}
		}

		return $result;
	}

	/**
	 * Extract information from a video/audio file
	 *
	 * @param Asset|string $filePath
	 * @param bool $summary
	 *
	 * @return null|array
	 * @throws InvalidConfigException
	 */
	public function getFileInfo(Asset|string $filePath, bool $summary = false): ?array
	{
		$result = null;
		$settings = Transcoder::$plugin->getSettings();
		$filePath = $this->getAssetPath($filePath);

		if (!empty($filePath)) {
			// Build the basic command for ffprobe
			$ffprobeOptions = $settings['ffprobeOptions'];
			$ffprobeCmd = $settings['ffprobePath']
				. ' ' . $ffprobeOptions
				. ' ' . escapeshellarg($filePath);

			$shellOutput = $this->executeShellCommand($ffprobeCmd);
			Craft::info($ffprobeCmd, __METHOD__);
			$result = JsonHelper::decodeIfJson($shellOutput, true);
			Craft::info(print_r($result, true), __METHOD__);
			// Handle the case it not being JSON
			if (!is_array($result)) {
				$result = [];
			}
			// Trim down the arrays to just a summary
			if ($summary && !empty($result)) {
				$summaryResult = [];
				foreach ($result as $topLevelKey => $topLevelValue) {
					switch ($topLevelKey) {
						// Format info
						case 'format':
							foreach (self::INFO_SUMMARY['format'] as $settingKey => $settingValue) {
								if (!empty($topLevelValue[$settingKey])) {
									$summaryResult[$settingValue] = $topLevelValue[$settingKey];
								}
							}
							break;
						// Stream info
						case 'streams':
							foreach ($topLevelValue as $stream) {
								$infoSummaryType = $stream['codec_type'] ?? null;
								if ($infoSummaryType !== null && array_key_exists($infoSummaryType, self::INFO_SUMMARY)) {
									foreach (self::INFO_SUMMARY[$infoSummaryType] as $settingKey => $settingValue) {
										if (!empty($stream[$settingKey])) {
											$summaryResult[$settingValue] = $stream[$settingKey];
										}
									}
								}
							}
							break;
						// Unknown info
						default:
							break;
					}
				}
				// Handle cases where the framerate is returned as XX/YY
				if (!empty($summaryResult['videoFrameRate'])
					&& (str_contains($summaryResult['videoFrameRate'], '/'))
				) {
					$parts = explode('/', $summaryResult['videoFrameRate']);
					$summaryResult['videoFrameRate'] = (float)$parts[0] / (float)$parts[1];
				}
				$result = $summaryResult;
			}
		}

		return $result;
	}

	/**
	 * Get the name of a video file from a path and options
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 *
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getVideoFilename(Asset|string $filePath, array $videoOptions): string
	{
		$settings = Transcoder::$plugin->getSettings();
		$videoOptions = $this->coalesceOptions('defaultVideoOptions', $videoOptions);

		// Get the video encoder presets to use
		$videoEncoders = $settings['videoEncoders'];
		$thisEncoder = $videoEncoders[$videoOptions['videoEncoder']];

		$videoOptions['fileSuffix'] = $thisEncoder['fileSuffix'];
		$videoOptions['autoCropVideoBlackBars'] = (bool)$settings->autoCropVideoBlackBars;

		return $this->getFilename($filePath, $videoOptions, $this->getVideoFilenameExcludeParams($videoOptions));
	}

	/**
	 * Get the name of an audio file from a path and options
	 *
	 * @param Asset|string $filePath
	 * @param array $audioOptions
	 *
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getAudioFilename(Asset|string $filePath, array $audioOptions): string
	{
		$settings = Transcoder::$plugin->getSettings();
		$audioOptions = $this->coalesceOptions('defaultAudioOptions', $audioOptions);

		// Get the video encoder presets to use
		$audioEncoders = $settings['audioEncoders'];
		$thisEncoder = $audioEncoders[$audioOptions['audioEncoder']];

		$audioOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

		return $this->getFilename($filePath, $audioOptions);
	}

	/**
	 * Get the name of a gif video file from a path and options
	 *
	 * @param Asset|string $filePath
	 * @param array $gifOptions
	 *
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getGifFilename(Asset|string $filePath, array $gifOptions): string
	{
		$settings = Transcoder::$plugin->getSettings();
		$gifOptions = $this->coalesceOptions('defaultGifOptions', $gifOptions);

		// Get the video encoder presets to use
		$videoEncoders = $settings['videoEncoders'];
		$thisEncoder = $videoEncoders[$gifOptions['videoEncoder']];

		$gifOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

		return $this->getFilename($filePath, $gifOptions);
	}

	/**
	 * Handle generated a thumbnail for the Control Panel
	 *
	 * @param DefineAssetThumbUrlEvent $event
	 *
	 * @return null|false|string
	 * @throws InvalidConfigException
	 */
	public function handleGetAssetThumbPath(DefineAssetThumbUrlEvent $event): null|false|string
	{
		if (!$this->isRuntimeEncodingEnabled()) {
			return null;
		}

		$options = [
			'width' => $event->width,
			'height' => $event->height,
		];
		return $this->getVideoThumbnailUrl($event->asset, $options);
	}

	// Protected Methods
	// =========================================================================

	/**
	 * Return the queue-aware GIF status as a JSON string for Twig usage.
	 *
	 * @param Asset|string $filePath
	 * @param array $gifOptions
	 * @param bool $queueIfMissing
	 * @param bool $includeDebug
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getGifStatus(Asset|string $filePath, array $gifOptions = [], bool $queueIfMissing = false, bool $includeDebug = false): string
	{
		$status = $this->getGifStatusData($filePath, $gifOptions);
		if ($queueIfMissing
			&& $filePath instanceof Asset
			&& $this->isRuntimeEncodingEnabled()
			&& ($status['status'] ?? null) === 'pending'
		) {
			$settings = Transcoder::$plugin->getSettings();
			$status = $this->queueGifEncode($filePath, $gifOptions, max(0, (int)$settings->gifQueueDelaySeconds));
		}

		if ($includeDebug) {
			$status['debug'] = $this->getGifStatusDebug($filePath, $gifOptions);
		}

		return JsonHelper::encode($status);
	}

	/**
	 * Return a URL that can be polled for queue-aware GIF status.
	 *
	 * @param Asset|string $filePath
	 * @param array $gifOptions
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getGifStatusUrl(Asset|string $filePath, array $gifOptions = []): string
	{
		return UrlHelper::actionUrl('transcoder/default/gif-status', [
			'key' => $this->getGifStatusKey($filePath, $gifOptions),
		]);
	}

	/**
	 * Return queue-aware GIF status data.
	 *
	 * @param Asset|string $filePath
	 * @param array $gifOptions
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function getGifStatusData(Asset|string $filePath, array $gifOptions = []): array
	{
		if (!$this->isRuntimeEncodingEnabled() || !Transcoder::$plugin->getSettings()->enableGifEncoding) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}
		if ($filePath instanceof Asset && $this->isTemporaryUploadAsset($filePath)) {
			return $this->sanitizeVideoStatus($this->getTemporaryUploadStatus('GIF'));
		}

		$outputInfo = $this->getGifOutputInfo($filePath, $gifOptions);
		$statusKey = $this->getGifStatusKey($filePath, $gifOptions);
		$storedStatus = $this->readVideoStatus($statusKey);

		if (is_file($outputInfo['encodedFile'])
			&& filesize($outputInfo['encodedFile']) > 0
			&& (!is_file($outputInfo['lockFile']) || !$this->isProcessRunningFromLockFile($outputInfo['lockFile']))
		) {
			$failure = $this->getGifEncodeFailure($outputInfo, $storedStatus);
			if ($failure) {
				$this->removeEncodeTempFiles($outputInfo['lockFile'], $outputInfo['progressFile']);
				@unlink($outputInfo['encodedFile']);
				$status = array_merge([
					'status' => 'error',
					'url' => '',
					'progress' => 0,
				], $failure);
				$this->writeVideoStatusByKey(
					$statusKey,
					$this->addAssetStatusInfo($filePath, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status))
				);
				Craft::error($status['error'], __METHOD__);
				return $this->sanitizeVideoStatus($status);
			}
			@unlink($outputInfo['lockFile']);
			@unlink($outputInfo['progressFile']);
			$status = [
				'status' => 'ok',
				'url' => $outputInfo['publicUrl'],
				'progress' => 100,
			];
			if (!$outputInfo['originalExists']) {
				$status['warning'] = 'Original GIF missing, serving encoded version';
			}
			$this->writeVideoStatusByKey(
				$statusKey,
				$this->addAssetStatusInfo($filePath, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status))
			);
			return $this->sanitizeVideoStatus($status);
		}

		if ($this->isEncodeLockStale($outputInfo['lockFile'], $outputInfo['progressFile'])) {
			$failure = $this->getGifEncodeFailure($outputInfo, $storedStatus);
			$this->removeEncodeTempFiles($outputInfo['lockFile'], $outputInfo['progressFile']);
			$status = array_merge([
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'GIF encoding failed due to a server error (process crashed, ffmpeg error)',
			], $failure ?? []);
			$this->writeVideoStatusByKey(
				$statusKey,
				$this->addAssetStatusInfo($filePath, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status))
			);
			Craft::error('Transcoder: ffmpeg process died unexpectedly for GIF ' . ($outputInfo['source'] ?? 'unknown'), __METHOD__);
			return $this->sanitizeVideoStatus($status);
		}

		if (is_file($outputInfo['lockFile'])) {
			$status = array_merge(
				[
					'status' => 'encoding',
					'url' => '',
					'progress' => 0,
				],
				!empty($storedStatus['ffmpegCommand']) ? ['ffmpegCommand' => $storedStatus['ffmpegCommand']] : [],
				$this->getProgressData($outputInfo['filename'])
			);
			$this->writeVideoStatusByKey($statusKey, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status));
			return $this->sanitizeVideoStatus($status);
		}

		if (!empty($storedStatus) && ($storedStatus['status'] ?? null) === 'ok') {
			$storedStatus = [];
		}

		if (!empty($storedStatus)
			&& ($storedStatus['status'] ?? null) === 'encoding'
			&& !is_file($outputInfo['encodedFile'])
			&& !is_file($outputInfo['lockFile'])
		) {
			if (!empty($storedStatus['lockFile'])) {
				@unlink($storedStatus['lockFile']);
			}
			if (!empty($storedStatus['progressFile'])) {
				@unlink($storedStatus['progressFile']);
			}
			$storedStatus = [];
		}

		if (!empty($storedStatus)) {
			return $this->sanitizeVideoStatus($storedStatus);
		}

		if (!$outputInfo['originalExists']) {
			return $this->sanitizeVideoStatus([
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Transcoder: original GIF not found at ' . ($outputInfo['source'] ?? 'unknown'),
			]);
		}

		return $this->sanitizeVideoStatus([
			'status' => 'pending',
			'url' => '',
			'progress' => 0,
		]);
	}

	/**
	 * Write queue-aware GIF status for a file.
	 *
	 * @param Asset|string $filePath
	 * @param array $gifOptions
	 * @param array $status
	 * @throws InvalidConfigException
	 */
	public function writeGifStatus(Asset|string $filePath, array $gifOptions, array $status): void
	{
		$outputInfo = $this->getGifOutputInfo($filePath, $gifOptions);
		$status = $this->addAssetStatusInfo(
			$filePath,
			array_merge($this->getVideoStatusStorageInfo($outputInfo), $status)
		);

		$this->writeVideoStatusByKey($this->getGifStatusKey($filePath, $gifOptions), $status);
	}

	/**
	 * Return GIF status data by key, for controller polling.
	 *
	 * @param string $key
	 * @return array
	 */
	public function getGifStatusByKey(string $key): array
	{
		$status = $this->readVideoStatus($key);
		if (empty($status)) {
			return [
				'status' => 'unknown',
				'url' => '',
				'progress' => 0,
			];
		}

		if (!empty($status['encodedFile']) && is_file($status['encodedFile']) && filesize($status['encodedFile']) > 0) {
			$lockFile = $status['lockFile'] ?? null;
			$progressFile = $status['progressFile'] ?? null;
			if (!$lockFile || !is_file($lockFile) || $this->isEncodeLockStale($lockFile, $progressFile)) {
				$failure = $this->getGifEncodeFailure($status, $status);
				if ($failure) {
					$this->removeEncodeTempFiles($lockFile, $progressFile);
					@unlink($status['encodedFile']);
					$status = array_merge($status, [
						'status' => 'error',
						'url' => '',
						'progress' => 0,
					], $failure);
					$this->writeVideoStatusByKey($key, $status);
					return $this->sanitizeVideoStatus($status);
				}
				$this->removeEncodeTempFiles($lockFile, $progressFile);
				$status['status'] = 'ok';
				$status['url'] = $status['publicUrl'] ?? ($status['url'] ?? '');
				$status['progress'] = 100;
				$this->writeVideoStatusByKey($key, $status);
				return $this->sanitizeVideoStatus($status);
			}
		}

		if (($status['status'] ?? null) === 'encoding'
			&& !empty($status['lockFile'])
			&& $this->isEncodeLockStale($status['lockFile'], $status['progressFile'] ?? null)
		) {
			$failure = $this->getVideoProcessCrashedFailure($status, $status);
			$this->removeEncodeTempFiles($status['lockFile'], $status['progressFile'] ?? null);
			$status = array_merge($status, [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
			], $failure);
			$this->writeVideoStatusByKey($key, $status);
			return $this->sanitizeVideoStatus($status);
		}

		if (($status['filename'] ?? null) && ($status['status'] ?? null) === 'encoding') {
			$status = array_merge($status, $this->getProgressData($status['filename']));
		}

		return $this->sanitizeVideoStatus($status);
	}

	/**
	 * Return the stable status key for a GIF/options pair.
	 *
	 * @param Asset|string $filePath
	 * @param array $gifOptions
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getGifStatusKey(Asset|string $filePath, array $gifOptions = []): string
	{
		$gifOptions = $this->coalesceOptions('defaultGifOptions', $gifOptions);
		$settings = Transcoder::$plugin->getSettings();
		$videoEncoders = $settings['videoEncoders'];
		$thisEncoder = $videoEncoders[$gifOptions['videoEncoder']];
		$gifOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

		return 'gif-' . sha1($this->getFilename($filePath, $gifOptions));
	}

	/**
	 * Build destination and status metadata for a GIF encode.
	 *
	 * @param Asset|string $filePath
	 * @param array $gifOptions
	 * @return array
	 * @throws InvalidConfigException
	 */
	protected function getGifOutputInfo(Asset|string $filePath, array $gifOptions): array
	{
		$settings = Transcoder::$plugin->getSettings();
		$subfolder = $this->getSubfolderFromPath($filePath);
		$normalized = $this->normalizeFilePath($filePath);
		$originalExists = false;
		$filePathResolved = null;

		if (isset($normalized['url'])) {
			$filePathResolved = $normalized['url'];
			$originalExists = $this->doesRemoteFileExist($filePathResolved, $filePath instanceof Asset ? 5 : 1, 2);
		} elseif (isset($normalized['path'])) {
			$filePathResolved = $normalized['path'];
			$originalExists = file_exists($filePathResolved);
		}

		if (!empty($subfolder)) {
			$destGifPath = rtrim(App::parseEnv($settings['transcoderPaths']['gif']), DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR
				. trim($subfolder, DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR;
			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['gif']), '/')
				. '/' . trim($subfolder, '/');
		} else {
			$destGifPath = rtrim(App::parseEnv($settings['transcoderPaths']['default']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['default']), '/');
		}

		$gifOptions = $this->coalesceOptions('defaultGifOptions', $gifOptions);
		$videoEncoders = $settings['videoEncoders'];
		$thisEncoder = $videoEncoders[$gifOptions['videoEncoder']];
		$gifOptions['fileSuffix'] = $thisEncoder['fileSuffix'];
		$destGifFile = $this->getFilename($filePath instanceof Asset ? $filePath : ($filePathResolved ?? ''), $gifOptions);
		$destGifFile = $this->getExistingGifFilenameCandidate(
			$destGifPath,
			$filePath,
			$filePathResolved,
			$gifOptions,
			$destGifFile
		);

		return [
			'source' => $filePathResolved,
			'originalExists' => $originalExists,
			'filename' => $destGifFile,
			'encodedFile' => $destGifPath . $destGifFile,
			'publicUrl' => $urlBase . '/' . $destGifFile,
			'lockFile' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destGifFile . '.lock',
			'progressFile' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destGifFile . '.progress',
		];
	}

	/**
	 * Returns a URL to an encoded GIF file (mp4)
	 *
	 * @param Asset|string $filePath path to the original video or an Asset
	 * @param array $gifOptions of options for the GIF file
	 *
	 * @return string|false|null URL or path of the GIF file
	 * @throws InvalidConfigException
	 */

	public function getGifUrl(Asset|string $filePath, array $gifOptions, bool $generate = true): string
	{
		$settings = Transcoder::$plugin->getSettings();
		if (!$this->isRuntimeEncodingEnabled() || !$settings->enableGifEncoding) {
			return JsonHelper::encode([
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			]);
		}

		$subfolder = $this->getSubfolderFromPath($filePath);
	
		// Environment check
		$isDev = App::env('CRAFT_ENVIRONMENT') === 'development';
	
		// --- Normalize input ---
		$normalized = $this->normalizeFilePath($filePath);
		$originalExists = false;
		$filePathResolved = null;
	
		if (isset($normalized['url'])) {
			$filePathResolved = $normalized['url'];
			$originalExists = $this->doesRemoteFileExist($filePathResolved, $filePath instanceof Asset ? 5 : 1, 2);
		} elseif (isset($normalized['path'])) {
			$filePathResolved = $normalized['path'];
			$originalExists = file_exists($filePathResolved);
		}
	
		if ($isDev) {
			Craft::info("Normalized filePath: " . json_encode($normalized), __METHOD__);
			Craft::info("Resolved filePath: " . $filePathResolved, __METHOD__);
			Craft::info("Original exists? " . ($originalExists ? 'yes' : 'no'), __METHOD__);
		}
	
		// Destination path & URL
		if (!empty($subfolder)) {
			$destGifPath = rtrim(App::parseEnv($settings['transcoderPaths']['gif']), DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR . trim($subfolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['gif']), '/')
				. '/' . trim($subfolder, '/');
		} else {
			$destGifPath = rtrim(App::parseEnv($settings['transcoderPaths']['default']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['default']), '/');
		}
	
		$gifOptions = $this->coalesceOptions('defaultGifOptions', $gifOptions);
		$videoEncoders = $settings['videoEncoders'];
		$thisEncoder = $videoEncoders[$gifOptions['videoEncoder']];
		$gifOptions['fileSuffix'] = $thisEncoder['fileSuffix'];
	
		$destGifFile = $this->getFilename($filePath instanceof Asset ? $filePath : ($filePathResolved ?? ''), $gifOptions);
		$destGifFile = $this->getExistingGifFilenameCandidate(
			$destGifPath,
			$filePath,
			$filePathResolved,
			$gifOptions,
			$destGifFile
		);
		$encodedFile = $destGifPath . $destGifFile;
		$publicUrl   = $urlBase . '/' . $destGifFile;
	
		$lockFile     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destGifFile . '.lock';
		$progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destGifFile . '.progress';
	
		// --- Case 1: encoded GIF already exists (lazy cleanup) ---
		if (is_file($encodedFile) && filesize($encodedFile) > 0) {
			// Clean up lock/progress if finished
			if (is_file($lockFile) || is_file($progressFile)) {
				$contents = @file_get_contents($progressFile);
				if (!$contents || str_contains((string)$contents, 'progress=end')) {
					@unlink($lockFile);
					@unlink($progressFile);
				}
			}
	
			return JsonHelper::encode([
				'status' => 'ok',
				'url' => $publicUrl,
			]);
		}
	
		// --- Case 2: check for stalled/crashed encoding ---
		if (is_file($lockFile)) {
			$pid = trim((string) @file_get_contents($lockFile));
	
			if ($pid !== '' && ctype_digit($pid)) {
				exec("kill -0 $pid 2>&1", $processState);
	
				if (count($processState) > 0) {
					// process died
					if (is_file($encodedFile) && filesize($encodedFile) > 0) {
						@unlink($lockFile);
						@unlink($progressFile);
						return JsonHelper::encode([
							'status' => 'ok',
							'url' => $publicUrl,
						]);
					}
	
					$this->removeEncodeTempFiles($lockFile, $progressFile);
					Craft::error("Transcoder: ffmpeg process $pid died unexpectedly for $filePathResolved", __METHOD__);
					return JsonHelper::encode([
						'status' => 'error',
						'url' => '',
						'error' => 'Encoding failed due to a server error (process crashed, ffmpeg error)',
					]);
				}
	
				// detect stalled progress
				if (file_exists($progressFile)) {
					$contents = @file_get_contents($progressFile);
					if ($contents && str_contains($contents, 'progress=end')) {
						@unlink($lockFile);
						@unlink($progressFile);
						return JsonHelper::encode([
							'status' => 'ok',
							'url' => $publicUrl,
						]);
					}
	
					$lastUpdate = filemtime($progressFile);
					if ($lastUpdate && (time() - $lastUpdate > 60)) {
						$this->removeEncodeTempFiles($lockFile, $progressFile);
						Craft::error("Transcoder: ffmpeg stalled for $filePathResolved", __METHOD__);
						return JsonHelper::encode([
							'status' => 'error',
							'url' => '',
							'error' => 'Encoding stalled (no progress updates)',
						]);
					}
				}
	
				return JsonHelper::encode([
					'status' => 'encoding',
					'url' => '',
					'info' => 'Encoding in progress',
				]);
			} else {
				return JsonHelper::encode([
					'status' => 'encoding',
					'url' => '',
					'info' => 'Encoding in progress (PID not yet available)',
				]);
			}
		}
	
		// --- Case 3: original missing ---
		if (!$originalExists) {
			$msg = "Transcoder: original file not found at " . ($filePathResolved ?? 'unknown');
			Craft::error($msg, __METHOD__);
			return JsonHelper::encode([
				'status' => 'error',
				'url' => '',
				'error' => $msg,
			]);
		}

		if ($generate && !$this->canStartEncodingFromCurrentRequest()) {
			Craft::info('Transcoder: GIF encoding not started because this server is not allowed to start encode work.', __METHOD__);
			return JsonHelper::encode([
				'status' => 'pending',
				'url' => '',
				'progress' => 0,
				'info' => 'Encoding is not allowed on this server',
			]);
		}
	
		// --- Case 4: encode new GIF ---
		if (!is_dir($destGifPath)) {
			try {
				FileHelper::createDirectory($destGifPath);
			} catch (\Exception $e) {
				Craft::error($e->getMessage(), __METHOD__);
			}
		}
	
		$ffmpegCmd = $settings['ffmpegPath']
			. ' -i ' . escapeshellarg($filePathResolved)
			. ' -vf "fps=10,scale=' . ($gifOptions['width'] ?? -1) . ':' . ($gifOptions['height'] ?? -1) . ':flags=lanczos"'
			. ' -c:v ' . $thisEncoder['videoCodec'] . ' ' . $thisEncoder['videoCodecOptions']
			. ' -y ' . escapeshellarg($encodedFile);
		$ffmpegCommand = $ffmpegCmd;
		$ffmpegCmd .= ' 1> ' . $progressFile . ' 2>&1 & echo $!';
	
		if ($isDev) {
			Craft::info("Final ffmpeg command: $ffmpegCmd", __METHOD__);
		}
	
		if ($generate) {
			$pid = $this->executeShellCommand($ffmpegCmd);
			file_put_contents($lockFile, $pid);
	
			return JsonHelper::encode([
				'status' => 'encoding',
				'url' => '',
				'info' => 'Encoding started',
				'ffmpegCommand' => $ffmpegCommand,
			]);
		}
	
		return JsonHelper::encode([
			'status' => 'error',
			'url' => '',
			'error' => 'Encoding disabled',
		]);
	}



	public function getGifUrlOld(Asset|string $filePath, array $gifOptions, bool $generate = true): string|false|null
	{
		$result = '';
		$settings = Transcoder::$plugin->getSettings();
		$subfolder = '';

		// Subfolder check
		$subfolder = $this->getSubfolderFromPath($filePath);
		 
		// Asset path
		$filePath = $this->getAssetPath($filePath);

		if (!empty($filePath)) {
			// Dest path
			$destVideoPath = $settings['transcoderPaths']['gif'] . $subfolder ?? $settings['transcoderPaths']['default'];
			$destVideoPath = App::parseEnv($destVideoPath);

			// Options
			$gifOptions = $this->coalesceOptions('defaultGifOptions', $gifOptions);

			// Build the URL
			$destVideoFile = $this->getFilename($filePath, $gifOptions);
			
			// Convert to a public URL
			$url = $settings['transcoderUrls']['gif'] . $subfolder ?? $settings['transcoderUrls']['default'];
			$publicUrl = App::parseEnv($url) . $destVideoFile;
			
			// Check if the file exists via HTTP first when the url is passed as an argument instead of an asset object
			if ($this->isUrl($filePath) && $this->doesRemoteFileExist($publicUrl)) {	
				return $publicUrl;
			}
			
			// Get the video encoder presets to use
			$videoEncoders = $settings['videoEncoders'];
			$thisEncoder = $videoEncoders[$gifOptions['videoEncoder']];
			$gifOptions['fileSuffix'] = $thisEncoder['fileSuffix'];

			// Create the directory if it isn't there already
			if (!is_dir($destVideoPath)) {
				try {
					FileHelper::createDirectory($destVideoPath);
				} catch (Exception $e) {
					Craft::error($e->getMessage(), __METHOD__);
				}
			}
			
			// Build the basic command for ffmpeg
			$destVideoPath .= $destVideoFile;

			$ffmpegCmd = $settings['ffmpegPath']
				. ' -f gif'
				. ' -i ' . escapeshellarg($filePath)
				. ' -vcodec ' . $thisEncoder['videoCodec']
				. ' ' . $thisEncoder['videoCodecOptions'];

			// File to store the video encoding progress in
			$progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.progress';

			// Assemble the destination path and final ffmpeg command
			$ffmpegCmd .= ' '
				. ' -y ' . escapeshellarg($destVideoPath)
				. ' 1> ' . $progressFile . ' 2>&1 & echo $!';

			// Make sure there isn't a lockfile for this video already
			$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.lock';
			$oldPid = @file_get_contents($lockFile);
			if ($oldPid !== false) {
				// See if the process is running, and empty result means the process is still running
				// ref: https://stackoverflow.com/questions/3043978/how-to-check-if-a-process-id-pid-exists
				exec("kill -0 $oldPid 2>&1", $ProcessState);
				if (count($ProcessState) === 0) {
					return $result;
				}
				// It's finished transcoding, so delete the lockfile and progress file
				@unlink($lockFile);
				@unlink($progressFile);
			}

			// If the video file already exists and hasn't been modified, return it.  Otherwise, start it transcoding
			if (file_exists($destVideoPath) && (@filemtime($destVideoPath) >= @filemtime($filePath))) {
				$url = $settings['transcoderUrls']['gif'] . $subfolder ?? $settings['transcoderUrls']['default'];
				$result = App::parseEnv($url) . $destVideoFile;
			} elseif (!$generate) {         
				$result = '';
			} else {							
				// Kick off the transcoding
				$pid = $this->executeShellCommand($ffmpegCmd);
				Craft::info($ffmpegCmd . "\nffmpeg PID: " . $pid, __METHOD__);

				// Create a lockfile in tmp
				file_put_contents($lockFile, $pid);
			}
		}

		return $result;
	}

	/**
	 * Get the name of a file from a path and options
	 *
	 * @param Asset|string $filePath
	 * @param array $options
	 *
	 * @return string
	 * @throws InvalidConfigException
	 */
	protected function getFilename(Asset|string $filePath, array $options, ?array $excludeParams = null, bool $includeAssetId = false): string
	{
		$settings = Transcoder::$plugin->getSettings();
		$excludeParams ??= self::EXCLUDE_PARAMS;
		$assetId = $includeAssetId && $filePath instanceof Asset ? $filePath->id : null;
		$filePath = $this->getAssetPath($filePath);

		$validator = new UrlValidator();
		$error = '';
		if ($validator->validate($filePath, $error)) {
			$urlParts = parse_url($filePath);
			$pathParts = pathinfo($urlParts['path']);
		} else {
			$pathParts = pathinfo($filePath);
		}
		$fileName = $pathParts['filename'];
		if ($assetId) {
			$fileName .= '_asset' . $assetId;
		}

		// Add our options to the file name
		foreach ($options as $key => $value) {

			// crucial change, this results in a different filename and trigger a new ffmpeg instance!
			//if (isset($value)) {
			if (!empty($value)) { 
				$suffix = '';
				if (!empty(self::SUFFIX_MAP[$key])) {
					$suffix = self::SUFFIX_MAP[$key];
				}
				if (is_bool($value)) {
					$value = $value ? $key : 'no' . $key;
				}
				if (!in_array($key, $excludeParams, true)) {
					$fileName .= '_' . $value . $suffix;
				}
			}
		}
		// See if we should use a hash instead
		if ($settings['useHashedNames']) {
			$fileName = $pathParts['filename'] . md5($fileName);
		}
		
		$fileName .= $options['fileSuffix'];

		return $fileName;
	}

	/**
	 * Extract a file system path if $filePath is an Asset object
	 *
	 * @param Asset|string $filePath
	 *
	 * @return string
	 * @throws InvalidConfigException
	 */
	protected function getAssetPath(Asset|string $filePath): string
	{
		// If we're passed an Asset, extract the path from it
		if (($filePath instanceof Asset)) {
			$asset = $filePath;
			$assetVolume = null;
			try {
				$assetVolume = $asset->getVolume();
			} catch (InvalidConfigException $e) {
				Craft::error($e->getMessage(), __METHOD__);
			}

			if ($assetVolume) {
				// If it's local, get a path to the file
				$fs = $assetVolume->getFs();
				if ($fs instanceof Local) {
					$sourcePath = rtrim($fs->path, DIRECTORY_SEPARATOR);
					$sourcePath .= '' === $sourcePath ? '' : DIRECTORY_SEPARATOR;
					$folderPath = '';
					try {
						$folderPath = rtrim($asset->getFolder()->path, DIRECTORY_SEPARATOR);
					} catch (InvalidConfigException $e) {
						Craft::error($e->getMessage(), __METHOD__);
					}
					$folderPath .= '' === $folderPath ? '' : DIRECTORY_SEPARATOR;

					$filePath = $sourcePath . $folderPath . $asset->filename;
				} else {
					// Otherwise, get a URL
					$filePath = $asset->getUrl() ?? '';
				}
			}
		}

		$filePath = (string)App::parseEnv($filePath);

		// Make sure that $filePath is either an existing file, or a valid URL
		/*if (!file_exists($filePath)) {
			$validator = new UrlValidator();
			$error = '';
			if (!$validator->validate($filePath, $error)) {
				Craft::error($error, __METHOD__);
				$filePath = '';
			}
		}*/

		return $filePath;
	}

	/**
	 * Set the width & height if desired
	 *
	 * @param array $options
	 * @param string $ffmpegCmd
	 *
	 * @return string
	 */
	protected function addScalingFfmpegArgs(array $options, string $ffmpegCmd): string
	{
		$preFilters = $this->getPreVideoFilters($options);

		if (!empty($options['width']) && !empty($options['height'])) {
			$sharpen = '';
			if (!empty($options['sharpen']) && ($options['sharpen'] !== false)) {
				$sharpen = ',unsharp=5:5:1.0:5:5:0.0';
			}

			if (!empty($options['preventBlackBars'])) {
				$ffmpegCmd .= ' -vf "' . $this->joinVideoFilters(array_merge($preFilters, ['split=2[bg][fg]'])) . ';'
					. '[bg]scale=' . $options['width'] . ':' . $options['height'] . ':force_original_aspect_ratio=increase'
					. ',crop=' . $options['width'] . ':' . $options['height']
					. ',boxblur=20:1[bg];'
					. '[fg]scale=' . $options['width'] . ':' . $options['height'] . ':force_original_aspect_ratio=decrease[fg];'
					. '[bg][fg]overlay=(W-w)/2:(H-h)/2'
					. $sharpen
					. '"';

				return $ffmpegCmd;
			}

			// Handle "none", "crop", and "letterbox" aspectRatios
			$aspectRatio = '';
			if (!empty($options['aspectRatio'])) {
				switch ($options['aspectRatio']) {
					// Scale to the appropriate aspect ratio, padding
					case 'letterbox':
						$letterboxColor = '';
						if (!empty($options['letterboxColor'])) {
							$letterboxColor = ':color=' . $options['letterboxColor'];
						}
						$aspectRatio = ':force_original_aspect_ratio=decrease'
							. ',pad=' . $options['width'] . ':' . $options['height'] . ':(ow-iw)/2:(oh-ih)/2'
							. $letterboxColor;
						break;
					// Scale to the appropriate aspect ratio, cropping
					case 'crop':
						$aspectRatio = ':force_original_aspect_ratio=increase'
							. ',crop=' . $options['width'] . ':' . $options['height'];
						break;
					// No aspect ratio scaling at all
					default:
						$aspectRatio = ':force_original_aspect_ratio=disable';
						$options['aspectRatio'] = 'none';
						break;
				}
			}
			$filters = array_merge($preFilters, [
				'scale=' . $options['width'] . ':' . $options['height'] . $aspectRatio . $sharpen,
			]);
			$ffmpegCmd .= ' -vf "' . $this->joinVideoFilters($filters) . '"';
		} elseif (!empty($preFilters)) {
			$ffmpegCmd .= ' -vf "' . $this->joinVideoFilters($preFilters) . '"';
		}

		return $ffmpegCmd;
	}

	/**
	 * Return watermark configuration for video encodes, or null when disabled.
	 *
	 * @param array $encodingOptions
	 * @return array|null
	 * @throws InvalidConfigException
	 */
	protected function getVideoWatermarkConfig(array $encodingOptions = []): ?array
	{
		$settings = Transcoder::$plugin->getSettings();
		if (!$settings->enableVideoWatermark) {
			return null;
		}

		if (array_key_exists('watermark', $encodingOptions) && empty($encodingOptions['watermark'])) {
			return null;
		}

		$watermarkSource = $this->getConfiguredVideoWatermarkSource();
		if ($watermarkSource === null) {
			$watermarkSource = $this->getAssetVideoWatermarkSource($settings->videoWatermarkAsset);
		}

		if ($watermarkSource === null) {
			Craft::warning('Transcoder: video watermarking is enabled, but no watermark source is configured.', __METHOD__);
			return null;
		}

		$watermarkPath = $watermarkSource['path'];
		$extension = $this->getPathOrUrlExtension($watermarkPath);
		if (!in_array($extension, ['svg', 'jpg', 'jpeg', 'png'], true)) {
			Craft::warning("Transcoder: video watermark source {$watermarkSource['label']} has unsupported extension .$extension.", __METHOD__);
			return null;
		}

		if (!$this->isUrl($watermarkPath) && !file_exists($watermarkPath)) {
			Craft::warning("Transcoder: video watermark file not found at $watermarkPath.", __METHOD__);
			return null;
		}

		$width = $this->normalizeWatermarkDimension($settings->videoWatermarkWidth);
		$height = $this->normalizeWatermarkDimension($settings->videoWatermarkHeight);
		$rasterizedSvg = false;
		if ($extension === 'svg') {
			try {
				$watermarkPath = $this->rasterizeSvgWatermark(
					$watermarkPath,
					$watermarkSource['sourceId'],
					$width,
					$height,
					$settings->videoWatermarkAnimation
				);
				$rasterizedSvg = true;
			} catch (Throwable $e) {
				Craft::error('Transcoder: could not rasterize SVG watermark source ' . $watermarkSource['label'] . ': ' . $e->getMessage(), __METHOD__);
				throw $e;
			}
		}

		return [
			'assetId' => $watermarkSource['assetId'],
			'source' => $watermarkSource['source'],
			'sourceId' => $watermarkSource['sourceId'],
			'label' => $watermarkSource['label'],
			'path' => $watermarkPath,
			'width' => $width,
			'height' => $height,
			'rasterizedSvg' => $rasterizedSvg,
			'position' => in_array($settings->videoWatermarkPosition, self::WATERMARK_POSITIONS, true)
				? $settings->videoWatermarkPosition
				: 'bottom-right',
			'paddingTop' => max(0, (int)$settings->videoWatermarkPaddingTop),
			'paddingRight' => max(0, (int)$settings->videoWatermarkPaddingRight),
			'paddingBottom' => max(0, (int)$settings->videoWatermarkPaddingBottom),
			'paddingLeft' => max(0, (int)$settings->videoWatermarkPaddingLeft),
			'opacity' => min(100, max(0, (int)$settings->videoWatermarkOpacity)),
			'animation' => in_array($settings->videoWatermarkAnimation, ['none', 'fade-in', 'fade-out', 'fade-in-out', 'rotate', 'pulse'], true)
				? $settings->videoWatermarkAnimation
				: 'none',
			'reposition' => (bool)$settings->videoWatermarkReposition,
			'repositionInterval' => max(1, (int)$settings->videoWatermarkRepositionInterval),
			'repositionPositions' => $this->normalizeWatermarkPositions($settings->videoWatermarkRepositionPositions, $settings->videoWatermarkPosition),
		];
	}

	/**
	 * Build the ffmpeg filter_complex graph for applying a watermark.
	 *
	 * @param array $videoOptions
	 * @param array $watermarkConfig
	 * @param string|null $sourcePath
	 * @return string
	 * @throws InvalidConfigException
	 */
	protected function buildVideoWatermarkFilterComplex(array $videoOptions, array $watermarkConfig, ?string $sourcePath = null): string
	{
		$parts = [];
		$baseLabel = '[0:v]';
		$baseGraph = $this->buildBaseVideoFilterGraph($videoOptions, '[0:v]', '[vbase]');
		if ($baseGraph !== null) {
			$parts[] = $baseGraph;
			$baseLabel = '[vbase]';
		}

		$duration = null;
		if ($sourcePath !== null && in_array($watermarkConfig['animation'], ['fade-out', 'fade-in-out'], true)) {
			$fileInfo = $this->getFileInfo($sourcePath, true) ?? [];
			if (!empty($fileInfo['duration'])) {
				$duration = (float)$fileInfo['duration'];
			}
		}

		$watermarkFilters = $this->getWatermarkImageFilters($watermarkConfig, $duration);
		$parts[] = '[1:v]' . $this->joinVideoFilters($watermarkFilters) . '[wm]';

		[$x, $y] = $this->getWatermarkOverlayExpressions($watermarkConfig);
		$parts[] = $baseLabel . '[wm]overlay=x=' . $x . ':y=' . $y . ':eval=frame:shortest=1[vout]';

		return implode(';', $parts);
	}

	/**
	 * Build a labeled base video filter graph from existing scaling/crop options.
	 *
	 * @param array $options
	 * @param string $inputLabel
	 * @param string $outputLabel
	 * @return string|null
	 */
	protected function buildBaseVideoFilterGraph(array $options, string $inputLabel, string $outputLabel): ?string
	{
		$preFilters = $this->getPreVideoFilters($options);

		if (!empty($options['width']) && !empty($options['height']) && !empty($options['preventBlackBars'])) {
			$sharpen = '';
			if (!empty($options['sharpen']) && ($options['sharpen'] !== false)) {
				$sharpen = ',unsharp=5:5:1.0:5:5:0.0';
			}

			$prefix = $inputLabel;
			if (!empty($preFilters)) {
				$prefix .= $this->joinVideoFilters($preFilters) . ',';
			}

			return $prefix . 'split=2[bg][fg];'
				. '[bg]scale=' . $options['width'] . ':' . $options['height'] . ':force_original_aspect_ratio=increase'
				. ',crop=' . $options['width'] . ':' . $options['height']
				. ',boxblur=20:1[bg];'
				. '[fg]scale=' . $options['width'] . ':' . $options['height'] . ':force_original_aspect_ratio=decrease[fg];'
				. '[bg][fg]overlay=(W-w)/2:(H-h)/2'
				. $sharpen
				. $outputLabel;
		}

		$filterChain = $this->getSimpleVideoFilterChain($options);
		if ($filterChain === null) {
			return null;
		}

		return $inputLabel . $filterChain . $outputLabel;
	}

	/**
	 * Build the existing simple video filter chain without labels.
	 *
	 * @param array $options
	 * @return string|null
	 */
	protected function getSimpleVideoFilterChain(array $options): ?string
	{
		$preFilters = $this->getPreVideoFilters($options);

		if (!empty($options['width']) && !empty($options['height'])) {
			$sharpen = '';
			if (!empty($options['sharpen']) && ($options['sharpen'] !== false)) {
				$sharpen = ',unsharp=5:5:1.0:5:5:0.0';
			}

			$aspectRatio = '';
			if (!empty($options['aspectRatio'])) {
				switch ($options['aspectRatio']) {
					case 'letterbox':
						$letterboxColor = '';
						if (!empty($options['letterboxColor'])) {
							$letterboxColor = ':color=' . $options['letterboxColor'];
						}
						$aspectRatio = ':force_original_aspect_ratio=decrease'
							. ',pad=' . $options['width'] . ':' . $options['height'] . ':(ow-iw)/2:(oh-ih)/2'
							. $letterboxColor;
						break;
					case 'crop':
						$aspectRatio = ':force_original_aspect_ratio=increase'
							. ',crop=' . $options['width'] . ':' . $options['height'];
						break;
					default:
						$aspectRatio = ':force_original_aspect_ratio=disable';
						break;
				}
			}

			return $this->joinVideoFilters(array_merge($preFilters, [
				'scale=' . $options['width'] . ':' . $options['height'] . $aspectRatio . $sharpen,
			]));
		}

		if (!empty($preFilters)) {
			return $this->joinVideoFilters($preFilters);
		}

		return null;
	}

	/**
	 * Build filters for the watermark image input.
	 *
	 * @param array $watermarkConfig
	 * @param float|null $duration
	 * @return array
	 */
	protected function getWatermarkImageFilters(array $watermarkConfig, ?float $duration = null): array
	{
		$filters = ['format=rgba'];
		$scaleFilter = $this->getWatermarkScaleFilter($watermarkConfig);
		if ($scaleFilter !== null && !($watermarkConfig['rasterizedSvg'] && in_array($watermarkConfig['animation'], ['rotate', 'pulse'], true))) {
			$filters[] = $scaleFilter;
		}

		switch ($watermarkConfig['animation']) {
			case 'rotate':
				$filters[] = 'rotate=2*PI*t/10:c=none:ow=rotw(iw):oh=roth(ih)';
				if (!empty($watermarkConfig['rasterizedSvg']) && $scaleFilter !== null) {
					$filters[] = $scaleFilter;
				}
				break;
			case 'pulse':
				$filters[] = $this->getWatermarkPulseScaleFilter($watermarkConfig);
				break;
			case 'fade-in':
				$filters[] = 'fade=t=in:st=0:d=1:alpha=1';
				break;
			case 'fade-out':
				if ($duration !== null && $duration > 1) {
					$filters[] = 'fade=t=out:st=' . max(0, round($duration - 1, 3)) . ':d=1:alpha=1';
				}
				break;
			case 'fade-in-out':
				$filters[] = 'fade=t=in:st=0:d=1:alpha=1';
				if ($duration !== null && $duration > 1) {
					$filters[] = 'fade=t=out:st=' . max(0, round($duration - 1, 3)) . ':d=1:alpha=1';
				}
				break;
		}

		if ($watermarkConfig['opacity'] < 100) {
			$filters[] = 'colorchannelmixer=aa=' . rtrim(rtrim(number_format($watermarkConfig['opacity'] / 100, 3, '.', ''), '0'), '.');
		}

		return $filters;
	}

	/**
	 * Return a dynamic pulse scale filter that keeps the configured ratio.
	 *
	 * @param array $watermarkConfig
	 * @return string
	 */
	protected function getWatermarkPulseScaleFilter(array $watermarkConfig): string
	{
		$pulse = '(1+0.04*sin(2*PI*t/3))';
		$width = $watermarkConfig['width'];
		$height = $watermarkConfig['height'];

		if ($width !== null && $height !== null) {
			return 'scale=' . $width . '*' . $pulse . ':' . $height . '*' . $pulse . ':eval=frame';
		}

		if ($width !== null) {
			return 'scale=' . $width . '*' . $pulse . ':-1:eval=frame';
		}

		if ($height !== null) {
			return 'scale=-1:' . $height . '*' . $pulse . ':eval=frame';
		}

		return 'scale=iw*' . $pulse . ':ih*' . $pulse . ':eval=frame';
	}

	/**
	 * Return a watermark image scale filter from configured dimensions.
	 *
	 * @param array $watermarkConfig
	 * @return string|null
	 */
	protected function getWatermarkScaleFilter(array $watermarkConfig): ?string
	{
		$width = $watermarkConfig['width'];
		$height = $watermarkConfig['height'];

		if ($width === null && $height === null) {
			return null;
		}

		if ($width !== null && $height !== null) {
			return 'scale=' . $width . ':' . $height;
		}

		if ($width !== null) {
			return 'scale=' . $width . ':-1';
		}

		return 'scale=-1:' . $height;
	}

	/**
	 * Return overlay x/y expressions for the watermark.
	 *
	 * @param array $watermarkConfig
	 * @return array
	 */
	protected function getWatermarkOverlayExpressions(array $watermarkConfig): array
	{
		if (empty($watermarkConfig['reposition'])) {
			return $this->getWatermarkPositionExpressions($watermarkConfig['position'], $watermarkConfig);
		}

		$positions = $watermarkConfig['repositionPositions'];
		if (count($positions) <= 1) {
			return $this->getWatermarkPositionExpressions($positions[0] ?? $watermarkConfig['position'], $watermarkConfig);
		}

		$xExpressions = [];
		$yExpressions = [];
		foreach ($positions as $position) {
			[$x, $y] = $this->getWatermarkPositionExpressions($position, $watermarkConfig);
			$xExpressions[] = $x;
			$yExpressions[] = $y;
		}

		return [
			$this->buildWatermarkCycleExpression($xExpressions, $watermarkConfig['repositionInterval']),
			$this->buildWatermarkCycleExpression($yExpressions, $watermarkConfig['repositionInterval']),
		];
	}

	/**
	 * Return x/y expressions for one configured position.
	 *
	 * @param string $position
	 * @param array $watermarkConfig
	 * @return array
	 */
	protected function getWatermarkPositionExpressions(string $position, array $watermarkConfig): array
	{
		$top = (string)$watermarkConfig['paddingTop'];
		$right = (string)$watermarkConfig['paddingRight'];
		$bottom = (string)$watermarkConfig['paddingBottom'];
		$left = (string)$watermarkConfig['paddingLeft'];

		return match ($position) {
			'top-left' => [$left, $top],
			'top-center' => ['(W-w)/2', $top],
			'top-right' => ['W-w-' . $right, $top],
			'center-left' => [$left, '(H-h)/2'],
			'center' => ['(W-w)/2', '(H-h)/2'],
			'center-right' => ['W-w-' . $right, '(H-h)/2'],
			'bottom-left' => [$left, 'H-h-' . $bottom],
			'bottom-center' => ['(W-w)/2', 'H-h-' . $bottom],
			default => ['W-w-' . $right, 'H-h-' . $bottom],
		};
	}

	/**
	 * Build a time-based ffmpeg expression that cycles through values.
	 *
	 * @param array $values
	 * @param int $interval
	 * @return string
	 */
	protected function buildWatermarkCycleExpression(array $values, int $interval): string
	{
		$values = array_values($values);
		$count = count($values);
		if ($count <= 1) {
			return $values[0] ?? '0';
		}

		$indexExpression = 'mod(floor(t/' . max(1, $interval) . ')\\,' . $count . ')';
		$expression = $values[$count - 1];
		for ($i = $count - 2; $i >= 0; $i--) {
			$expression = 'if(eq(' . $indexExpression . '\\,' . $i . ')\\,' . $values[$i] . '\\,' . $expression . ')';
		}

		return $expression;
	}

	/**
	 * Normalize watermark dimensions from settings.
	 *
	 * @param int|string|null $value
	 * @return int|null
	 */
	protected function normalizeWatermarkDimension(int|string|null $value): ?int
	{
		if (is_string($value)) {
			$value = trim($value);
			if ($value === '' || in_array(strtolower($value), ['auto', 'original'], true)) {
				return null;
			}
		}

		$value = (int)$value;
		return $value > 0 ? $value : null;
	}

	/**
	 * Rasterize an SVG watermark to PNG because many ffmpeg builds cannot decode SVG streams.
	 *
	 * @param string $svgPath
	 * @param int|string $sourceId
	 * @param int|null $width
	 * @param int|null $height
	 * @param string $animation
	 * @return string
	 */
	protected function rasterizeSvgWatermark(string $svgPath, int|string $sourceId, ?int $width, ?int $height, string $animation): string
	{
		$svg = @file_get_contents($svgPath);
		if ($svg === false || trim($svg) === '') {
			throw new \RuntimeException("SVG watermark file could not be read at $svgPath");
		}

		[$rasterWidth, $rasterHeight] = $this->getSvgWatermarkRasterDimensions($svg, $width, $height);
		$sourceId = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$sourceId) ?: 'source';
		$sourceStamp = $this->isUrl($svgPath) ? sha1($svg) : (string)@filemtime($svgPath);
		$cacheKey = sha1($svgPath . '|' . $sourceStamp . '|' . $rasterWidth . 'x' . $rasterHeight . '|' . $animation);
		$pngPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'transcoder-watermark-' . $sourceId . '-' . $cacheKey . '.png';

		if (is_file($pngPath) && filesize($pngPath) > 0) {
			return $pngPath;
		}

		$failureReasons = [];
		if ($this->rasterizeSvgWithImagick($svg, $pngPath, $rasterWidth, $rasterHeight, $failureReasons)) {
			return $pngPath;
		}

		$tempSvgPath = $svgPath;
		if ($this->isUrl($svgPath)) {
			$tempSvgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'transcoder-watermark-' . $sourceId . '-' . $cacheKey . '.svg';
			file_put_contents($tempSvgPath, $svg);
		}

		if ($this->rasterizeSvgWithCommand($tempSvgPath, $pngPath, $rasterWidth, $rasterHeight, $failureReasons)) {
			return $pngPath;
		}

		throw new \RuntimeException(
			'SVG watermark could not be rasterized. Attempts: '
			. implode(' | ', $failureReasons)
			. '. Install/configure PHP Imagick with SVG support, rsvg-convert, or ImageMagick on the encoding server.'
		);
	}

	/**
	 * Rasterize SVG using PHP Imagick when available.
	 *
	 * @param string $svg
	 * @param string $pngPath
	 * @param int $width
	 * @param int $height
	 * @param array $failureReasons
	 * @return bool
	 */
	protected function rasterizeSvgWithImagick(string $svg, string $pngPath, int $width, int $height, array &$failureReasons): bool
	{
		if (!class_exists('Imagick') || !class_exists('ImagickPixel')) {
			$failureReasons[] = 'Imagick PHP classes are not available to the PHP process';
			return false;
		}

		try {
			$formats = array_unique(array_merge(
				\Imagick::queryFormats('SVG*') ?: [],
				\Imagick::queryFormats('MSVG') ?: []
			));
			if (empty($formats)) {
				$failureReasons[] = 'Imagick is installed, but ImageMagick reports no SVG/MSVG coder support';
				return false;
			}

			$image = new \Imagick();
			$image->setBackgroundColor(new \ImagickPixel('transparent'));
			$image->setResolution(384, 384);
			$image->setSize($width, $height);
			$image->readImageBlob($svg);
			$image->setImageFormat('png32');
			$image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, false);
			$image->writeImage($pngPath);
			$image->clear();
			$image->destroy();

			return is_file($pngPath) && filesize($pngPath) > 0;
		} catch (Throwable $e) {
			$failureReasons[] = 'Imagick failed: ' . $e->getMessage();
			Craft::warning('Transcoder: Imagick could not rasterize SVG watermark: ' . $e->getMessage(), __METHOD__);
			return false;
		}
	}

	/**
	 * Rasterize SVG using an installed command-line renderer.
	 *
	 * @param string $svgPath
	 * @param string $pngPath
	 * @param int $width
	 * @param int $height
	 * @param array $failureReasons
	 * @return bool
	 */
	protected function rasterizeSvgWithCommand(string $svgPath, string $pngPath, int $width, int $height, array &$failureReasons): bool
	{
		$rsvg = $this->findExecutable('rsvg-convert');
		if ($rsvg !== null) {
			$command = escapeshellcmd($rsvg)
				. ' -w ' . $width
				. ' -h ' . $height
				. ' -f png'
				. ' -o ' . escapeshellarg($pngPath)
				. ' ' . escapeshellarg($svgPath)
				. ' 2>&1';
			exec($command, $output, $exitCode);
			if ($exitCode === 0 && is_file($pngPath) && filesize($pngPath) > 0) {
				return true;
			}
			$message = 'rsvg-convert failed with exit code ' . $exitCode . ': ' . trim(implode("\n", $output));
			$failureReasons[] = $message;
			Craft::warning('Transcoder: ' . $message, __METHOD__);
		} else {
			$failureReasons[] = 'rsvg-convert was not found in PATH';
		}

		$foundImageMagick = false;
		foreach (['magick', 'convert'] as $binary) {
			$executable = $this->findExecutable($binary);
			if ($executable === null) {
				continue;
			}
			$foundImageMagick = true;

			$command = escapeshellcmd($executable)
				. ' -background none'
				. ' -density 384'
				. ' ' . escapeshellarg($svgPath)
				. ' -resize ' . escapeshellarg($width . 'x' . $height . '!')
				. ' ' . escapeshellarg($pngPath)
				. ' 2>&1';
			exec($command, $output, $exitCode);
			if ($exitCode === 0 && is_file($pngPath) && filesize($pngPath) > 0) {
				return true;
			}
			$message = $binary . ' failed with exit code ' . $exitCode . ': ' . trim(implode("\n", $output));
			$failureReasons[] = $message;
			Craft::warning('Transcoder: ' . $message, __METHOD__);
		}

		if (!$foundImageMagick) {
			$failureReasons[] = 'ImageMagick command-line tools (magick/convert) were not found in PATH';
		}

		return false;
	}

	/**
	 * Resolve an executable from PATH.
	 *
	 * @param string $binary
	 * @return string|null
	 */
	protected function findExecutable(string $binary): ?string
	{
		$output = [];
		$exitCode = 1;
		exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $exitCode);
		if ($exitCode !== 0 || empty($output[0])) {
			return null;
		}

		return trim($output[0]);
	}

	/**
	 * Return the PNG raster dimensions for an SVG watermark.
	 *
	 * @param string $svg
	 * @param int|null $width
	 * @param int|null $height
	 * @return array
	 */
	protected function getSvgWatermarkRasterDimensions(string $svg, ?int $width, ?int $height): array
	{
		[$sourceWidth, $sourceHeight] = $this->getSvgIntrinsicDimensions($svg);
		$scale = ($width !== null || $height !== null) ? 4 : 1;

		if ($width !== null && $height !== null) {
			return [max(1, $width * $scale), max(1, $height * $scale)];
		}

		if ($width !== null) {
			return [
				max(1, $width * $scale),
				max(1, (int)round(($width * $sourceHeight / max(1, $sourceWidth)) * $scale)),
			];
		}

		if ($height !== null) {
			return [
				max(1, (int)round(($height * $sourceWidth / max(1, $sourceHeight)) * $scale)),
				max(1, $height * $scale),
			];
		}

		return [max(1, (int)round($sourceWidth)), max(1, (int)round($sourceHeight))];
	}

	/**
	 * Extract intrinsic SVG dimensions from width/height or viewBox.
	 *
	 * @param string $svg
	 * @return array
	 */
	protected function getSvgIntrinsicDimensions(string $svg): array
	{
		$width = null;
		$height = null;

		if (preg_match('/<svg\b[^>]*\swidth=(["\'])(.*?)\1/i', $svg, $match)) {
			$width = $this->parseSvgLength($match[2]);
		}
		if (preg_match('/<svg\b[^>]*\sheight=(["\'])(.*?)\1/i', $svg, $match)) {
			$height = $this->parseSvgLength($match[2]);
		}

		if (preg_match('/<svg\b[^>]*\sviewBox=(["\'])(.*?)\1/i', $svg, $match)) {
			$parts = preg_split('/[\s,]+/', trim($match[2]));
			if (count($parts) === 4) {
				$viewBoxWidth = (float)$parts[2];
				$viewBoxHeight = (float)$parts[3];
				$width ??= $viewBoxWidth > 0 ? $viewBoxWidth : null;
				$height ??= $viewBoxHeight > 0 ? $viewBoxHeight : null;
			}
		}

		return [
			$width ?? 512.0,
			$height ?? 512.0,
		];
	}

	/**
	 * Parse an SVG length into pixels.
	 *
	 * @param string $value
	 * @return float|null
	 */
	protected function parseSvgLength(string $value): ?float
	{
		$value = trim($value);
		if (!preg_match('/^([0-9.]+)\s*(px|pt|pc|in|cm|mm)?$/i', $value, $match)) {
			return null;
		}

		$number = (float)$match[1];
		$unit = strtolower($match[2] ?? 'px');

		return match ($unit) {
			'pt' => $number * 96 / 72,
			'pc' => $number * 16,
			'in' => $number * 96,
			'cm' => $number * 96 / 2.54,
			'mm' => $number * 96 / 25.4,
			default => $number,
		};
	}

	/**
	 * Normalize selected watermark positions.
	 *
	 * @param mixed $positions
	 * @param string $fallback
	 * @return array
	 */
	protected function normalizeWatermarkPositions(mixed $positions, string $fallback): array
	{
		if (is_string($positions)) {
			$positions = [$positions];
		}
		if (!is_array($positions)) {
			$positions = [];
		}

		$positions = array_values(array_filter($positions, static fn($position) => in_array($position, self::WATERMARK_POSITIONS, true)));
		if (!empty($positions)) {
			return $positions;
		}

		return in_array($fallback, self::WATERMARK_POSITIONS, true) ? [$fallback] : ['bottom-right'];
	}

	/**
	 * Return debug information for watermark source resolution.
	 *
	 * @param array $encodingOptions
	 * @return array
	 */
	protected function getVideoWatermarkStatusDebug(array $encodingOptions = []): array
	{
		$settings = Transcoder::$plugin->getSettings();
		$requested = !(array_key_exists('watermark', $encodingOptions) && empty($encodingOptions['watermark']));
		$pathRaw = (string)$settings->videoWatermarkPath;
		$pathParsed = trim((string)App::parseEnv($pathRaw));
		$pathResolved = $pathParsed !== '' ? (Craft::getAlias($pathParsed, false) ?: $pathParsed) : '';
		$urlRaw = (string)$settings->videoWatermarkUrl;
		$urlParsed = trim((string)App::parseEnv($urlRaw));
		$assetId = $this->getVideoWatermarkAssetId($settings->videoWatermarkAsset);

		$result = [
			'enabled' => (bool)$settings->enableVideoWatermark,
			'requested' => $requested,
			'active' => false,
			'skippedReason' => null,
			'sourcePreference' => [
				'videoWatermarkPath',
				'videoWatermarkUrl',
				'videoWatermarkAsset',
			],
			'configuredPath' => [
				'raw' => $pathRaw,
				'parsed' => $pathParsed,
				'resolved' => $pathResolved,
				'configured' => $pathParsed !== '',
				'extension' => $pathResolved !== '' ? $this->getPathOrUrlExtension($pathResolved) : '',
				'exists' => $pathResolved !== '' && !$this->isUrl($pathResolved) ? file_exists($pathResolved) : null,
			],
			'configuredUrl' => [
				'raw' => $urlRaw,
				'parsed' => $urlParsed,
				'configured' => $urlParsed !== '',
				'valid' => $urlParsed !== '' ? $this->isUrl($urlParsed) : null,
				'extension' => $urlParsed !== '' ? $this->getPathOrUrlExtension($urlParsed) : '',
				'exists' => null,
				'existsNote' => $urlParsed !== '' ? 'Remote watermark URLs are not preflighted; ffmpeg/rasterization will report fetch errors.' : null,
			],
			'asset' => [
				'configuredValue' => $settings->videoWatermarkAsset,
				'assetId' => $assetId,
				'found' => null,
				'filename' => null,
				'path' => null,
				'extension' => '',
				'exists' => null,
			],
			'selectedSource' => null,
			'allowedExtensions' => ['svg', 'jpg', 'jpeg', 'png'],
		];

		if ($assetId !== null) {
			try {
				$asset = Asset::find()->id($assetId)->one();
				$result['asset']['found'] = $asset instanceof Asset;
				if ($asset instanceof Asset) {
					$normalized = $this->normalizeFilePath($asset);
					$assetPath = $normalized['path'] ?? ($normalized['url'] ?? '');
					$result['asset']['filename'] = $asset->filename;
					$result['asset']['path'] = $assetPath;
					$result['asset']['extension'] = $assetPath !== '' ? $this->getPathOrUrlExtension($assetPath) : '';
					$result['asset']['exists'] = $assetPath !== '' && !$this->isUrl($assetPath) ? file_exists($assetPath) : null;
				}
			} catch (Throwable $e) {
				$result['asset']['found'] = false;
				$result['asset']['error'] = $e->getMessage();
			}
		}

		if (!$result['enabled']) {
			$result['skippedReason'] = 'Video watermarking is disabled in plugin settings.';
			return $result;
		}

		if (!$requested) {
			$result['skippedReason'] = 'Watermarking was disabled for this encode via encoding options.';
			return $result;
		}

		$watermarkSource = $this->getConfiguredVideoWatermarkSource();
		if ($watermarkSource === null) {
			$watermarkSource = $this->getAssetVideoWatermarkSource($settings->videoWatermarkAsset);
		}

		if ($watermarkSource === null) {
			$result['skippedReason'] = 'No watermark path, URL, or asset could be resolved.';
			return $result;
		}

		$extension = $this->getPathOrUrlExtension($watermarkSource['path']);
		$result['selectedSource'] = array_merge($watermarkSource, [
			'extension' => $extension,
			'isUrl' => $this->isUrl($watermarkSource['path']),
			'exists' => !$this->isUrl($watermarkSource['path']) ? file_exists($watermarkSource['path']) : null,
		]);

		if (!in_array($extension, ['svg', 'jpg', 'jpeg', 'png'], true)) {
			$result['skippedReason'] = "Selected watermark source has unsupported extension .$extension.";
			return $result;
		}

		if (!$result['selectedSource']['isUrl'] && empty($result['selectedSource']['exists'])) {
			$result['skippedReason'] = 'Selected watermark source file does not exist on this server.';
			return $result;
		}

		$result['active'] = true;

		return $result;
	}

	/**
	 * Return a configured watermark path/URL source, or null when not configured.
	 *
	 * @return array|null
	 */
	protected function getConfiguredVideoWatermarkSource(): ?array
	{
		$settings = Transcoder::$plugin->getSettings();
		$path = trim((string)App::parseEnv((string)$settings->videoWatermarkPath));
		if ($path !== '') {
			$resolvedPath = Craft::getAlias($path, false) ?: $path;

			return [
				'assetId' => null,
				'source' => 'config:path',
				'sourceId' => 'path-' . substr(sha1($resolvedPath), 0, 12),
				'label' => $path,
				'path' => $resolvedPath,
			];
		}

		$url = trim((string)App::parseEnv((string)$settings->videoWatermarkUrl));
		if ($url !== '') {
			if (!$this->isUrl($url)) {
				Craft::warning("Transcoder: configured video watermark URL is not a valid absolute URL: $url", __METHOD__);
				return null;
			}

			return [
				'assetId' => null,
				'source' => 'config:url',
				'sourceId' => 'url-' . substr(sha1($url), 0, 12),
				'label' => $url,
				'path' => $url,
			];
		}

		return null;
	}

	/**
	 * Return the selected watermark asset source, or null when unavailable.
	 *
	 * @param mixed $value
	 * @return array|null
	 * @throws InvalidConfigException
	 */
	protected function getAssetVideoWatermarkSource(mixed $value): ?array
	{
		$assetId = $this->getVideoWatermarkAssetId($value);
		if ($assetId === null) {
			return null;
		}

		$asset = Asset::find()->id($assetId)->one();
		if (!$asset instanceof Asset) {
			Craft::warning("Transcoder: video watermark asset $assetId could not be found.", __METHOD__);
			return null;
		}

		$normalized = $this->normalizeFilePath($asset);
		$watermarkPath = $normalized['path'] ?? ($normalized['url'] ?? '');
		if ($watermarkPath === '') {
			Craft::warning("Transcoder: video watermark asset $assetId did not resolve to a path or URL.", __METHOD__);
			return null;
		}

		return [
			'assetId' => $assetId,
			'source' => 'asset',
			'sourceId' => 'asset-' . $assetId,
			'label' => 'asset ' . $assetId,
			'path' => $watermarkPath,
		];
	}

	/**
	 * Return the lowercase extension from a local path or URL.
	 *
	 * @param string $path
	 * @return string
	 */
	protected function getPathOrUrlExtension(string $path): string
	{
		$pathPart = $this->isUrl($path)
			? (parse_url($path, PHP_URL_PATH) ?: '')
			: $path;

		return strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
	}

	/**
	 * Extract the selected watermark asset ID from the settings value.
	 *
	 * @param mixed $value
	 * @return int|null
	 */
	protected function getVideoWatermarkAssetId(mixed $value): ?int
	{
		if (is_array($value)) {
			$value = reset($value);
		}

		$value = (int)$value;
		return $value > 0 ? $value : null;
	}

	/**
	 * Return video filters that should run before scaling.
	 *
	 * @param array $options
	 * @return array
	 */
	protected function getPreVideoFilters(array $options): array
	{
		$filters = $options['preVideoFilters'] ?? [];
		if (is_string($filters) && $filters !== '') {
			$filters = [$filters];
		}
		if (!is_array($filters)) {
			return [];
		}

		return array_values(array_filter($filters, static fn($filter) => is_string($filter) && $filter !== ''));
	}

	/**
	 * Join ffmpeg video filters into a single filter chain.
	 *
	 * @param array $filters
	 * @return string
	 */
	protected function joinVideoFilters(array $filters): string
	{
		return implode(',', array_values(array_filter($filters)));
	}

	/**
	 * Detect a safe crop filter for videos with black bars.
	 *
	 * @param string $filePath
	 * @return string|null
	 * @throws InvalidConfigException
	 */
	protected function detectVideoBlackBarCrop(string $filePath): ?string
	{
		$info = $this->getFileInfo($filePath, true) ?? [];
		$sourceWidth = (int)($info['width'] ?? 0);
		$sourceHeight = (int)($info['height'] ?? 0);
		$duration = (float)($info['duration'] ?? 0);
		Craft::info("Transcoder: cropdetect source dimensions for $filePath: {$sourceWidth}x{$sourceHeight}, duration {$duration}", __METHOD__);

		if ($sourceWidth <= 0 || $sourceHeight <= 0) {
			Craft::warning("Transcoder: could not detect source dimensions for auto crop: $filePath", __METHOD__);
			return null;
		}

		$crops = [];
		foreach ($this->getCropDetectSampleTimes($duration) as $sampleTime) {
			$crop = $this->detectVideoBlackBarCropAtTime($filePath, $sampleTime, $sourceWidth, $sourceHeight);
			if ($crop !== null && $this->isDetectedCropSafe($crop, $sourceWidth, $sourceHeight)) {
				$key = implode(':', $crop);
				$crops[$key] = ($crops[$key] ?? 0) + 1;
			}
		}

		if (empty($crops)) {
			Craft::info("Transcoder: no safe black-bar crop detected for $filePath", __METHOD__);
			return null;
		}

		arsort($crops);
		[$width, $height, $x, $y] = array_map('intval', explode(':', (string)array_key_first($crops)));
		Craft::info('Transcoder: selected video crop for ' . $filePath . ': crop=' . $this->formatCrop($width, $height, $x, $y) . ' from candidates ' . JsonHelper::encode($crops), __METHOD__);

		return 'crop=' . $this->formatCrop($width, $height, $x, $y);
	}

	/**
	 * Return representative sample times for ffmpeg cropdetect.
	 *
	 * @param float $duration
	 * @return array
	 */
	protected function getCropDetectSampleTimes(float $duration): array
	{
		if ($duration <= 0) {
			return [0.5, 2.0, 5.0];
		}

		$latest = max(0.5, $duration - 1.0);
		$sampleTimes = [
			1.0,
			min(3.0, $latest),
			$duration * 0.25,
			$duration * 0.5,
			$duration * 0.75,
		];

		$sampleTimes = array_map(static fn($time) => round(max(0.2, min($time, $latest)), 2), $sampleTimes);

		return array_values(array_unique($sampleTimes));
	}

	/**
	 * Run ffmpeg cropdetect at a single timestamp.
	 *
	 * @param string $filePath
	 * @param float $sampleTime
	 * @param int $sourceWidth
	 * @param int $sourceHeight
	 * @return array|null
	 */
	protected function detectVideoBlackBarCropAtTime(string $filePath, float $sampleTime, int $sourceWidth, int $sourceHeight): ?array
	{
		$settings = Transcoder::$plugin->getSettings();
		$command = $settings['ffmpegPath']
			. ' -hide_banner -nostdin'
			. ' -ss ' . escapeshellarg((string)$sampleTime)
			. ' -i ' . escapeshellarg($filePath)
			. ' -t 1 -vf cropdetect=24:16:0 -f null - 2>&1';

		$output = $this->executeShellCommand($command);
		Craft::info("Transcoder cropdetect command: $command", __METHOD__);

		if (!preg_match_all('/crop=(\d+):(\d+):(\d+):(\d+)/', $output, $matches, PREG_SET_ORDER)) {
			Craft::info('Transcoder: cropdetect produced no crop output at sample ' . $sampleTime . 's for ' . $filePath, __METHOD__);
			return null;
		}

		$lastMatch = end($matches);
		if (!is_array($lastMatch)) {
			return null;
		}

		$rawCrop = [
			(int)$lastMatch[1],
			(int)$lastMatch[2],
			(int)$lastMatch[3],
			(int)$lastMatch[4],
		];
		$crop = $this->adjustDetectedCropToWideContentBand($rawCrop, $sourceWidth, $sourceHeight);
		Craft::info(
			'Transcoder: cropdetect sample '
			. $sampleTime
			. 's raw crop='
			. $this->formatCrop(...$rawCrop)
			. ', adjusted crop='
			. $this->formatCrop(...$crop),
			__METHOD__
		);

		return $crop;
	}

	/**
	 * Convert a full-width TikTok-style crop to the embedded 16:9 video band.
	 *
	 * @param array $crop
	 * @param int $sourceWidth
	 * @param int $sourceHeight
	 * @return array
	 */
	protected function adjustDetectedCropToWideContentBand(array $crop, int $sourceWidth, int $sourceHeight): array
	{
		[$width, $height, $x, $y] = $crop;
		if ($width <= 0 || $height <= 0) {
			return $crop;
		}

		$aspectRatio = $width / $height;
		if ($aspectRatio >= 1.45 && $aspectRatio <= 2.15) {
			return $crop;
		}

		$targetAspectRatio = 16 / 9;
		$targetHeight = (int)round(($width / $targetAspectRatio) / 2) * 2;
		$isPortraitSource = $sourceHeight > $sourceWidth;
		$isFullWidthCrop = abs($width - $sourceWidth) <= 4 && $x <= 4;
		$hasRoomForWideBand = $targetHeight > 0 && $targetHeight < $height;

		if ($isPortraitSource && $isFullWidthCrop && $hasRoomForWideBand) {
			$newY = (int)floor((($y + $height - $targetHeight) / 2)) * 2;
			$newY = max(0, min($newY, $sourceHeight - $targetHeight));
			return [$width, $targetHeight, $x, $newY];
		}

		return $crop;
	}

	/**
	 * Format crop values for ffmpeg.
	 *
	 * @param int $width
	 * @param int $height
	 * @param int $x
	 * @param int $y
	 * @return string
	 */
	protected function formatCrop(int $width, int $height, int $x, int $y): string
	{
		return $width . ':' . $height . ':' . $x . ':' . $y;
	}

	/**
	 * Only apply cropdetect results that look intentional and safe.
	 *
	 * @param array $crop
	 * @param int $sourceWidth
	 * @param int $sourceHeight
	 * @return bool
	 */
	protected function isDetectedCropSafe(array $crop, int $sourceWidth, int $sourceHeight): bool
	{
		[$width, $height, $x, $y] = $crop;
		if ($width <= 0 || $height <= 0 || $x < 0 || $y < 0) {
			return false;
		}

		if ($width > $sourceWidth || $height > $sourceHeight || ($x + $width) > ($sourceWidth + 2) || ($y + $height) > ($sourceHeight + 2)) {
			return false;
		}

		$removedWidth = $sourceWidth - $width;
		$removedHeight = $sourceHeight - $height;
		$removesMeaningfulBars = $removedWidth >= max(16, $sourceWidth * 0.04)
			|| $removedHeight >= max(16, $sourceHeight * 0.04);
		if (!$removesMeaningfulBars) {
			return false;
		}

		$keepsEnoughImage = ($width / $sourceWidth) >= 0.25
			&& ($height / $sourceHeight) >= 0.25
			&& (($width * $height) / ($sourceWidth * $sourceHeight)) >= 0.2;
		if (!$keepsEnoughImage) {
			return false;
		}

		return $width % 2 === 0 && $height % 2 === 0;
	}

	// Protected Methods
	// =========================================================================

	/**
	 * Combine the options arrays
	 *
	 * @param string $defaultName
	 * @param array $options
	 *
	 * @return array
	 */
	protected function coalesceOptions(string $defaultName, array $options): array
	{
		// Default options
		$settings = Transcoder::$plugin->getSettings();
		$defaultOptions = $settings[$defaultName];

		// Coalesce the passed in $options with the $defaultOptions
		return array_merge($defaultOptions, $options);
	}

	/**
	 * Return video status data by key, for controller polling.
	 *
	 * @param string $key
	 * @return array
	 */
	public function getVideoStatusByKey(string $key): array
	{
		$status = $this->readVideoStatus($key);
		if (empty($status)) {
			return [
				'status' => 'unknown',
				'url' => '',
				'progress' => 0,
			];
		}

		if ($this->isVideoPosterStatusActive($status)
			&& (empty($status['lockFile']) || !is_file($status['lockFile']))
		) {
			return $this->sanitizeVideoStatus($status);
		}

		if (!empty($status['encodedFile']) && is_file($status['encodedFile']) && filesize($status['encodedFile']) > 0) {
			$lockFile = $status['lockFile'] ?? null;
			$progressFile = $status['progressFile'] ?? null;
			if (!$lockFile || !is_file($lockFile) || $this->isEncodeLockStale($lockFile, $progressFile)) {
				$failure = $this->getVideoEncodeFailure($status, $status);
				if ($failure) {
					$this->removeEncodeTempFiles($lockFile, $progressFile);
					@unlink($status['encodedFile']);
					$status = array_merge($status, [
						'status' => 'error',
						'url' => '',
						'progress' => 0,
					], $failure);
					$this->writeVideoStatusByKey($key, $status);
					return $this->sanitizeVideoStatus($status);
				}
				$this->removeEncodeTempFiles($lockFile, $progressFile);
				$status['status'] = 'ok';
				$status['url'] = $status['publicUrl'] ?? ($status['url'] ?? '');
				$status['progress'] = 100;
				$this->writeVideoStatusByKey($key, $status);
				return $this->sanitizeVideoStatus($status);
			}
		}

		if (($status['status'] ?? null) === 'encoding'
			&& !empty($status['lockFile'])
			&& $this->isEncodeLockStale($status['lockFile'], $status['progressFile'] ?? null)
		) {
			$failure = $this->getVideoEncodeFailure($status, $status);
			$this->removeEncodeTempFiles($status['lockFile'], $status['progressFile'] ?? null);
			$status = array_merge($status, [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => Craft::t('transcoder', 'Video encoding failed due to a server error (process crashed, ffmpeg error)'),
			], $failure ?? []);
			$this->writeVideoStatusByKey($key, $status);
			return $this->sanitizeVideoStatus($status);
		}

		if (($status['filename'] ?? null) && ($status['status'] ?? null) === 'encoding') {
			$status = array_merge($status, $this->getProgressData($status['filename']));
		}

		return $this->sanitizeVideoStatus($status);
	}

	/**
	 * Build destination and status metadata for a video encode.
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 * @return array
	 * @throws InvalidConfigException
	 */
	protected function getVideoOutputInfo(Asset|string $filePath, array $videoOptions): array
	{
		$settings = Transcoder::$plugin->getSettings();
		$subfolder = $this->getSubfolderFromPath($filePath);
		$normalized = $this->normalizeFilePath($filePath);
		$originalExists = false;
		$filePathResolved = null;

		if (isset($normalized['url'])) {
			$filePathResolved = $normalized['url'];
			$originalExists = $this->doesRemoteFileExist($filePathResolved, $filePath instanceof Asset ? 5 : 1, 2);
		} elseif (isset($normalized['path'])) {
			$filePathResolved = $normalized['path'];
			$originalExists = file_exists($filePathResolved);
		}

		if (!empty($subfolder)) {
			$destVideoPath = rtrim(App::parseEnv($settings['transcoderPaths']['video']), DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR
				. trim($subfolder, DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR;
			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['video']), '/')
				. '/' . trim($subfolder, '/');
		} else {
			$destVideoPath = rtrim(App::parseEnv($settings['transcoderPaths']['default']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['default']), '/');
		}

		$videoOptions = $this->coalesceOptions('defaultVideoOptions', $videoOptions);
		$videoEncoders = $settings['videoEncoders'];
		$thisEncoder = $videoEncoders[$videoOptions['videoEncoder']];
		$videoOptions['fileSuffix'] = $thisEncoder['fileSuffix'];
		$videoOptions['autoCropVideoBlackBars'] = (bool)$settings->autoCropVideoBlackBars;
		$destVideoFile = $this->getFilename(
			$filePath instanceof Asset ? $filePath : ($filePathResolved ?? ''),
			$videoOptions,
			$this->getVideoFilenameExcludeParams($videoOptions)
		);
		$videoFilenameCandidates = $this->getVideoFilenameCandidates(
			$filePath,
			$filePathResolved,
			$videoOptions,
			$destVideoFile
		);
		$destVideoFile = $this->getExistingVideoFilenameCandidate(
			$destVideoPath,
			$filePath,
			$filePathResolved,
			$videoOptions,
			$destVideoFile
		);

		return [
			'source' => $filePathResolved,
			'originalExists' => $originalExists,
			'filename' => $destVideoFile,
			'encodedFile' => $destVideoPath . $destVideoFile,
			'publicUrl' => $urlBase . '/' . $destVideoFile,
			'lockFile' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.lock',
			'progressFile' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.progress',
			'filenameCandidates' => $videoFilenameCandidates,
		];
	}

	/**
	 * Return the first existing encoded video filename from current and legacy candidates.
	 *
	 * @param string $destVideoPath
	 * @param Asset|string $filePath
	 * @param string|null $filePathResolved
	 * @param array $videoOptions
	 * @param string $primaryFilename
	 * @return string
	 * @throws InvalidConfigException
	 */
	protected function getExistingVideoFilenameCandidate(
		string $destVideoPath,
		Asset|string $filePath,
		?string $filePathResolved,
		array $videoOptions,
		string $primaryFilename
	): string {
		$candidates = $this->getVideoFilenameCandidates($filePath, $filePathResolved, $videoOptions, $primaryFilename);
		foreach ($candidates as $filename) {
			$encodedFile = $destVideoPath . $filename;
			if (is_file($encodedFile) && filesize($encodedFile) > 0) {
				return $filename;
			}
		}

		foreach ($candidates as $filename) {
			$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename . '.lock';
			if (is_file($lockFile) && $this->isProcessRunningFromLockFile($lockFile)) {
				return $filename;
			}
		}

		return $primaryFilename;
	}

	/**
	 * Return current and legacy encoded video filename candidates.
	 *
	 * @param Asset|string $filePath
	 * @param string|null $filePathResolved
	 * @param array $videoOptions
	 * @param string $primaryFilename
	 * @return array
	 * @throws InvalidConfigException
	 */
	protected function getVideoFilenameCandidates(
		Asset|string $filePath,
		?string $filePathResolved,
		array $videoOptions,
		string $primaryFilename
	): array {
		$candidates = [$primaryFilename];
		$legacyInputs = [$filePath];

		if ($filePathResolved !== null && $filePathResolved !== '') {
			$legacyInputs[] = $filePathResolved;
		}

		if ($filePath instanceof Asset) {
			$assetUrl = $filePath->getUrl();
			if ($assetUrl) {
				$legacyInputs[] = $assetUrl;
			}
			if ($filePath->filename) {
				$legacyInputs[] = $filePath->filename;
			}
		}

		$seenInputs = [];
		foreach ($legacyInputs as $legacyInput) {
			if ($legacyInput === '') {
				continue;
			}

			$inputKey = $legacyInput instanceof Asset
				? 'asset:' . ($legacyInput->id ?: spl_object_id($legacyInput))
				: 'string:' . $legacyInput;
			if (isset($seenInputs[$inputKey])) {
				continue;
			}
			$seenInputs[$inputKey] = true;

			$candidates[] = $this->getFilename($legacyInput, $videoOptions, $this->getVideoFilenameExcludeParams($videoOptions, 'source'));
			$candidates[] = $this->getFilename($legacyInput, $videoOptions, $this->getVideoFilenameExcludeParams($videoOptions, 'options'));
			$candidates[] = $this->getFilename(
				$legacyInput,
				$videoOptions,
				array_values(array_diff(self::EXCLUDE_PARAMS, ['videoBitRate']))
			);

			if ($legacyInput instanceof Asset) {
				$candidates[] = $this->getFilename($legacyInput, $videoOptions, $this->getVideoFilenameExcludeParams($videoOptions, 'source'), true);
				$candidates[] = $this->getFilename($legacyInput, $videoOptions, $this->getVideoFilenameExcludeParams($videoOptions, 'options'), true);
				$candidates[] = $this->getFilename(
					$legacyInput,
					$videoOptions,
					array_values(array_diff(self::EXCLUDE_PARAMS, ['videoBitRate'])),
					true
				);
			}
		}

		return array_values(array_unique(array_filter($candidates)));
	}

	/**
	 * Return the first existing GIF filename from current and legacy candidates.
	 *
	 * @param string $destGifPath
	 * @param Asset|string $filePath
	 * @param string|null $filePathResolved
	 * @param array $gifOptions
	 * @param string $primaryFilename
	 * @return string
	 * @throws InvalidConfigException
	 */
	protected function getExistingGifFilenameCandidate(
		string $destGifPath,
		Asset|string $filePath,
		?string $filePathResolved,
		array $gifOptions,
		string $primaryFilename
	): string {
		foreach ($this->getGifFilenameCandidates($filePath, $filePathResolved, $gifOptions, $primaryFilename) as $filename) {
			$encodedFile = $destGifPath . $filename;
			if (is_file($encodedFile) && filesize($encodedFile) > 0) {
				return $filename;
			}
		}

		return $primaryFilename;
	}

	/**
	 * Return current and legacy GIF filename candidates.
	 *
	 * @param Asset|string $filePath
	 * @param string|null $filePathResolved
	 * @param array $gifOptions
	 * @param string $primaryFilename
	 * @return array
	 * @throws InvalidConfigException
	 */
	protected function getGifFilenameCandidates(
		Asset|string $filePath,
		?string $filePathResolved,
		array $gifOptions,
		string $primaryFilename
	): array {
		$candidates = [$primaryFilename];
		$legacyInputs = [$filePath];

		if ($filePathResolved !== null && $filePathResolved !== '') {
			$legacyInputs[] = $filePathResolved;
		}

		if ($filePath instanceof Asset) {
			$assetUrl = $filePath->getUrl();
			if ($assetUrl) {
				$legacyInputs[] = $assetUrl;
			}
		}

		$seenInputs = [];
		foreach ($legacyInputs as $legacyInput) {
			if ($legacyInput === '') {
				continue;
			}

			$inputKey = $legacyInput instanceof Asset
				? 'asset:' . ($legacyInput->id ?: spl_object_id($legacyInput))
				: 'string:' . $legacyInput;
			if (isset($seenInputs[$inputKey])) {
				continue;
			}
			$seenInputs[$inputKey] = true;

			$candidates[] = $this->getFilename($legacyInput, $gifOptions);
			$candidates[] = $this->getFilename(
				$legacyInput,
				$gifOptions,
				array_values(array_diff(self::EXCLUDE_PARAMS, ['videoBitRate']))
			);

			if ($legacyInput instanceof Asset) {
				$candidates[] = $this->getFilename($legacyInput, $gifOptions, null, true);
				$candidates[] = $this->getFilename(
					$legacyInput,
					$gifOptions,
					array_values(array_diff(self::EXCLUDE_PARAMS, ['videoBitRate'])),
					true
				);
			}
		}

		return array_values(array_unique(array_filter($candidates)));
	}

	/**
	 * Return the first existing thumbnail/poster filename from current and legacy candidates.
	 *
	 * @param string $destThumbnailPath
	 * @param Asset|string $filePath
	 * @param string|null $filePathResolved
	 * @param array $thumbnailOptions
	 * @param string $primaryFilename
	 * @return string
	 * @throws InvalidConfigException
	 */
	protected function getExistingThumbnailFilenameCandidate(
		string $destThumbnailPath,
		Asset|string $filePath,
		?string $filePathResolved,
		array $thumbnailOptions,
		string $primaryFilename
	): string {
		foreach ($this->getThumbnailFilenameCandidates($filePath, $filePathResolved, $thumbnailOptions, $primaryFilename) as $filename) {
			$thumbnailFile = $destThumbnailPath . $filename;
			if (is_file($thumbnailFile) && filesize($thumbnailFile) > 0) {
				return $filename;
			}
		}

		return $primaryFilename;
	}

	/**
	 * Return current and legacy thumbnail/poster filename candidates.
	 *
	 * @param Asset|string $filePath
	 * @param string|null $filePathResolved
	 * @param array $thumbnailOptions
	 * @param string $primaryFilename
	 * @return array
	 * @throws InvalidConfigException
	 */
	protected function getThumbnailFilenameCandidates(
		Asset|string $filePath,
		?string $filePathResolved,
		array $thumbnailOptions,
		string $primaryFilename
	): array {
		$candidates = [$primaryFilename];
		$legacyInputs = [$filePath];
		$thumbnailFilenameExcludeParams = $this->getThumbnailFilenameExcludeParams();

		if ($filePathResolved !== null && $filePathResolved !== '') {
			$legacyInputs[] = $filePathResolved;
		}

		if ($filePath instanceof Asset) {
			$assetUrl = $filePath->getUrl();
			if ($assetUrl) {
				$legacyInputs[] = $assetUrl;
			}
			if ($filePath->filename) {
				$legacyInputs[] = $filePath->filename;
			}
		}

		$thumbnailOptionsWithoutPosterFormat = $thumbnailOptions;
		unset($thumbnailOptionsWithoutPosterFormat['posterFormat']);
		$thumbnailOptionsWithoutPreventBlackBars = $thumbnailOptions;
		unset($thumbnailOptionsWithoutPreventBlackBars['preventBlackBars']);
		$thumbnailOptionsWithoutPosterRuntimeOptions = $thumbnailOptions;
		unset($thumbnailOptionsWithoutPosterRuntimeOptions['posterFormat'], $thumbnailOptionsWithoutPosterRuntimeOptions['preventBlackBars']);

		$seenInputs = [];
		foreach ($legacyInputs as $legacyInput) {
			if ($legacyInput === '') {
				continue;
			}

			$inputKey = $legacyInput instanceof Asset
				? 'asset:' . ($legacyInput->id ?: spl_object_id($legacyInput))
				: 'string:' . $legacyInput;
			if (isset($seenInputs[$inputKey])) {
				continue;
			}
			$seenInputs[$inputKey] = true;

			$candidates[] = $this->getFilename($legacyInput, $thumbnailOptions, $thumbnailFilenameExcludeParams);
			$candidates[] = $this->getFilename($legacyInput, $thumbnailOptions);
			$candidates[] = $this->getFilename($legacyInput, $thumbnailOptionsWithoutPosterFormat);
			$candidates[] = $this->getFilename($legacyInput, $thumbnailOptionsWithoutPreventBlackBars);
			$candidates[] = $this->getFilename($legacyInput, $thumbnailOptionsWithoutPosterRuntimeOptions);
			$candidates[] = $this->getFilename(
				$legacyInput,
				$thumbnailOptions,
				array_values(array_unique(array_merge(self::EXCLUDE_PARAMS, ['posterFormat'])))
			);
			$candidates[] = $this->getFilename(
				$legacyInput,
				$thumbnailOptions,
				array_values(array_unique(array_merge(self::EXCLUDE_PARAMS, ['posterFormat', 'preventBlackBars'])))
			);

			if ($legacyInput instanceof Asset) {
				$candidates[] = $this->getFilename($legacyInput, $thumbnailOptions, $thumbnailFilenameExcludeParams, true);
				$candidates[] = $this->getFilename($legacyInput, $thumbnailOptions, null, true);
				$candidates[] = $this->getFilename($legacyInput, $thumbnailOptionsWithoutPosterFormat, null, true);
				$candidates[] = $this->getFilename($legacyInput, $thumbnailOptionsWithoutPreventBlackBars, null, true);
				$candidates[] = $this->getFilename($legacyInput, $thumbnailOptionsWithoutPosterRuntimeOptions, null, true);
				$candidates[] = $this->getFilename(
					$legacyInput,
					$thumbnailOptions,
					array_values(array_unique(array_merge(self::EXCLUDE_PARAMS, ['posterFormat']))),
					true
				);
				$candidates[] = $this->getFilename(
					$legacyInput,
					$thumbnailOptions,
					array_values(array_unique(array_merge(self::EXCLUDE_PARAMS, ['posterFormat', 'preventBlackBars']))),
					true
				);
			}
		}

		return array_values(array_unique(array_filter($candidates)));
	}

	/**
	 * Return excluded option keys for poster/thumbnail filenames.
	 *
	 * @return array
	 */
	protected function getThumbnailFilenameExcludeParams(): array
	{
		return array_values(array_unique(array_merge(self::EXCLUDE_PARAMS, [
			'posterFormat',
			'preventBlackBars',
		])));
	}

	/**
	 * Return excluded filename option keys for a video filename strategy.
	 *
	 * @param array $videoOptions
	 * @param string|null $strategy
	 * @return array
	 */
	protected function getVideoFilenameExcludeParams(array $videoOptions, ?string $strategy = null): array
	{
		$strategy ??= Transcoder::$plugin->getSettings()->videoFilenameStrategy ?: 'source';

		if ($strategy === 'options') {
			return self::EXCLUDE_PARAMS;
		}

		return array_values(array_unique(array_merge(self::EXCLUDE_PARAMS, array_keys($videoOptions))));
	}

	/**
	 * Return normalized server names allowed to start encode work.
	 *
	 * @return array
	 */
	protected function getAllowedEncodingServerNames(): array
	{
		$serverNames = Transcoder::$plugin->getSettings()->encodingServerNames ?? [];
		if (!is_array($serverNames)) {
			$serverNames = [$serverNames];
		}

		$result = [];

		foreach ($serverNames as $serverName) {
			foreach (explode(',', (string)App::parseEnv((string)$serverName)) as $part) {
				$part = strtolower(trim($part));
				if ($part !== '') {
					$result[] = $part;
				}
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * Return whether a current server name matches an allowed pattern.
	 *
	 * @param string $serverName
	 * @param string $allowedServerName
	 * @return bool
	 */
	protected function serverNameMatches(string $serverName, string $allowedServerName): bool
	{
		if ($serverName === $allowedServerName) {
			return true;
		}

		if (!str_contains($allowedServerName, '*')) {
			return false;
		}

		$pattern = '/^' . str_replace('\\*', '.*', preg_quote($allowedServerName, '/')) . '$/';

		return (bool)preg_match($pattern, $serverName);
	}

	/**
	 * Return the internal status storage metadata for a video output.
	 *
	 * @param array $outputInfo
	 * @return array
	 */
	protected function getVideoStatusStorageInfo(array $outputInfo): array
	{
		return [
			'filename' => $outputInfo['filename'],
			'publicUrl' => $outputInfo['publicUrl'],
			'encodedFile' => $outputInfo['encodedFile'],
			'lockFile' => $outputInfo['lockFile'],
			'progressFile' => $outputInfo['progressFile'],
		];
	}

	/**
	 * Remove internal filesystem paths from status responses.
	 *
	 * @param array $status
	 * @return array
	 */
	protected function sanitizeVideoStatus(array $status): array
	{
		unset($status['encodedFile'], $status['lockFile'], $status['progressFile'], $status['publicUrl']);

		return $status;
	}

	/**
	 * Add the asset id to stored status data when the source is a Craft asset.
	 *
	 * @param Asset|string $filePath
	 * @param array $status
	 * @return array
	 */
	protected function addAssetStatusInfo(Asset|string $filePath, array $status): array
	{
		if ($filePath instanceof Asset && $filePath->id) {
			$status['assetId'] = (int)$filePath->id;
		}

		return $status;
	}

	/**
	 * Find a recent queued/running encode for any equivalent source/options filename candidate.
	 *
	 * @param array $filenames
	 * @param string|null $excludeKey
	 * @return array
	 */
	protected function findActiveVideoStatusForFilenames(array $filenames, ?string $excludeKey = null): array
	{
		$filenames = array_flip(array_filter(array_unique($filenames)));
		if (empty($filenames)) {
			return [];
		}

		$statusFiles = glob($this->getVideoStatusDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [];
		foreach ($statusFiles as $statusFile) {
			$status = JsonHelper::decodeIfJson((string)@file_get_contents($statusFile), true);
			if (!is_array($status)) {
				continue;
			}

			if ($excludeKey !== null && ($status['key'] ?? null) === $excludeKey) {
				continue;
			}

			$state = $status['status'] ?? null;
			if (!in_array($state, ['queued', 'encoding'], true)) {
				continue;
			}

			$filename = $status['filename'] ?? null;
			if (!$filename && !empty($status['encodedFile'])) {
				$filename = basename((string)$status['encodedFile']);
			}
			if (!$filename || !isset($filenames[$filename])) {
				continue;
			}

			$lockFile = $status['lockFile'] ?? null;
			$progressFile = $status['progressFile'] ?? null;
			if ($lockFile && is_file($lockFile) && $this->isProcessRunningFromLockFile($lockFile)) {
				return array_merge($status, $this->getProgressData($filename));
			}

			if ($this->isRecentVideoStatus($status, $state === 'queued' ? 1800 : 60)) {
				return $status;
			}

			$this->removeEncodeTempFiles($lockFile, $progressFile);
		}

		return [];
	}

	/**
	 * Find a recent queued/running video or poster status for the same Craft asset.
	 *
	 * GraphQL/CP saves can touch the same asset through multiple code paths. Those paths
	 * may carry different options or owner-title context, so the status key/filename check
	 * alone is not enough to stop duplicate queue jobs.
	 *
	 * @param Asset $asset
	 * @param string|null $excludeKey
	 * @param bool $includeVideo
	 * @param bool $includePosters
	 * @return array
	 */
	protected function findActiveVideoStatusForAsset(
		Asset $asset,
		?string $excludeKey = null,
		bool $includeVideo = true,
		bool $includePosters = true
	): array
	{
		if (!$asset->id) {
			return [];
		}

		$statusFiles = glob($this->getVideoStatusDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [];
		foreach ($statusFiles as $statusFile) {
			$status = JsonHelper::decodeIfJson((string)@file_get_contents($statusFile), true);
			if (!is_array($status)) {
				continue;
			}

			if ($excludeKey !== null && ($status['key'] ?? null) === $excludeKey) {
				continue;
			}

			if ((int)($status['assetId'] ?? 0) !== (int)$asset->id) {
				continue;
			}

			$filename = $status['filename'] ?? null;
			if (!$filename && !empty($status['encodedFile'])) {
				$filename = basename((string)$status['encodedFile']);
			}

			$videoState = $status['status'] ?? null;
			$posterState = $status['posterStatus'] ?? null;
			$videoActive = $includeVideo && in_array($videoState, ['queued', 'encoding'], true);
			$posterActive = $includePosters && in_array($posterState, ['queued', 'generating'], true);
			if (!$videoActive && !$posterActive) {
				continue;
			}

			$lockFile = $status['lockFile'] ?? null;
			$progressFile = $status['progressFile'] ?? null;
			if ($videoActive && $lockFile && is_file($lockFile) && $this->isProcessRunningFromLockFile($lockFile)) {
				return $filename ? array_merge($status, $this->getProgressData($filename)) : $status;
			}

			$maxAgeSeconds = $videoActive && $videoState === 'encoding' ? 60 : 1800;
			if ($this->isRecentVideoStatus($status, $maxAgeSeconds)) {
				return $status;
			}

			if ($videoActive) {
				$this->removeEncodeTempFiles($lockFile, $progressFile);
			}
		}

		return [];
	}

	/**
	 * Return whether a stored video status was updated recently enough to treat as active.
	 *
	 * @param array $status
	 * @param int $maxAgeSeconds
	 * @return bool
	 */
	protected function isRecentVideoStatus(array $status, int $maxAgeSeconds): bool
	{
		if (empty($status['updatedAt'])) {
			return false;
		}

		return time() - (int)$status['updatedAt'] <= $maxAgeSeconds;
	}

	/**
	 * Return admin-only debug data for queue-aware video status.
	 *
	 * @param Asset|string $filePath
	 * @param array $videoOptions
	 * @param array $encodingOptions
	 * @return array
	 */
	protected function getVideoStatusDebug(Asset|string $filePath, array $videoOptions = [], array $encodingOptions = []): array
	{
		try {
			$outputInfo = $this->getVideoOutputInfo($filePath, $videoOptions);
			$statusKey = $this->getVideoStatusKey($filePath, $videoOptions, $encodingOptions);
			$storedStatus = $this->readVideoStatus($statusKey);
			$storedStatusForDebug = $storedStatus;
			unset($storedStatusForDebug['debug']);

			$settings = Transcoder::$plugin->getSettings();
			$resolvedVideoOptions = $this->coalesceOptions('defaultVideoOptions', $videoOptions);
			$videoEncoders = $settings['videoEncoders'];
			if (isset($videoEncoders[$resolvedVideoOptions['videoEncoder']])) {
				$thisEncoder = $videoEncoders[$resolvedVideoOptions['videoEncoder']];
				$resolvedVideoOptions['fileSuffix'] = $thisEncoder['fileSuffix'];
			}
			$resolvedVideoOptions['autoCropVideoBlackBars'] = (bool)$settings->autoCropVideoBlackBars;

			$destVideoPath = rtrim(dirname($outputInfo['encodedFile']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			$candidateFiles = [];
			foreach ($this->getVideoFilenameCandidates($filePath, $outputInfo['source'] ?? null, $resolvedVideoOptions, $outputInfo['filename']) as $filename) {
				$candidateFiles[] = array_merge(
					['filename' => $filename],
					$this->getFileDebugInfo($destVideoPath . $filename)
				);
			}

			return [
				'hostname' => gethostname() ?: '',
				'schemaVersion' => Transcoder::$plugin->schemaVersion,
				'runtimeEncodingEnabled' => $this->isRuntimeEncodingEnabled(),
				'canStartEncoding' => $this->canStartEncodingFromCurrentRequest(),
				'currentServerName' => Craft::$app->getRequest()->getIsConsoleRequest() ? 'console' : Craft::$app->getRequest()->getServerName(),
				'allowedEncodingServerNames' => $this->getAllowedEncodingServerNames(),
				'videoEncodingEnabled' => (bool)$settings->enableVideoEncoding,
				'videoPostersEnabled' => (bool)$settings->enableVideoPosters,
				'videoQueueEnabled' => $this->isVideoQueueEnabled(),
				'statusKey' => $statusKey,
				'source' => $outputInfo['source'] ?? null,
				'originalExists' => $outputInfo['originalExists'],
				'filename' => $outputInfo['filename'],
				'encodedFile' => $this->getFileDebugInfo($outputInfo['encodedFile']),
				'publicUrl' => [
					'url' => $outputInfo['publicUrl'],
					'exists' => $this->doesRemoteFileExist($outputInfo['publicUrl'], 1, 1),
				],
				'lockFile' => array_merge(
					$this->getFileDebugInfo($outputInfo['lockFile']),
					['processRunning' => is_file($outputInfo['lockFile']) && $this->isProcessRunningFromLockFile($outputInfo['lockFile'])]
				),
				'progressFile' => $this->getFileDebugInfo($outputInfo['progressFile']),
				'candidateFiles' => $candidateFiles,
				'videoPosters' => $this->getVideoPosterStatusDebug($filePath),
				'watermark' => $this->getVideoWatermarkStatusDebug($encodingOptions),
				'storedStatusAgeSeconds' => isset($storedStatus['updatedAt']) ? max(0, time() - (int)$storedStatus['updatedAt']) : null,
				'storedStatus' => $storedStatusForDebug,
				'sysTempDir' => sys_get_temp_dir(),
				'documentRoot' => $_SERVER['DOCUMENT_ROOT'] ?? null,
			];
		} catch (Throwable $e) {
			return [
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Return admin-only debug data for queue-aware GIF status.
	 *
	 * @param Asset|string $filePath
	 * @param array $gifOptions
	 * @return array
	 */
	protected function getGifStatusDebug(Asset|string $filePath, array $gifOptions = []): array
	{
		try {
			$outputInfo = $this->getGifOutputInfo($filePath, $gifOptions);
			$statusKey = $this->getGifStatusKey($filePath, $gifOptions);
			$storedStatus = $this->readVideoStatus($statusKey);
			$storedStatusForDebug = $storedStatus;
			unset($storedStatusForDebug['debug']);

			$settings = Transcoder::$plugin->getSettings();
			$resolvedGifOptions = $this->coalesceOptions('defaultGifOptions', $gifOptions);
			$videoEncoders = $settings['videoEncoders'];
			if (isset($videoEncoders[$resolvedGifOptions['videoEncoder']])) {
				$thisEncoder = $videoEncoders[$resolvedGifOptions['videoEncoder']];
				$resolvedGifOptions['fileSuffix'] = $thisEncoder['fileSuffix'];
			}

			$destGifPath = rtrim(dirname($outputInfo['encodedFile']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
			$candidateFiles = [];
			foreach ($this->getGifFilenameCandidates($filePath, $outputInfo['source'] ?? null, $resolvedGifOptions, $outputInfo['filename']) as $filename) {
				$candidateFiles[] = array_merge(
					['filename' => $filename],
					$this->getFileDebugInfo($destGifPath . $filename)
				);
			}

			return [
				'hostname' => gethostname() ?: '',
				'schemaVersion' => Transcoder::$plugin->schemaVersion,
				'runtimeEncodingEnabled' => $this->isRuntimeEncodingEnabled(),
				'canStartEncoding' => $this->canStartEncodingFromCurrentRequest(),
				'currentServerName' => Craft::$app->getRequest()->getIsConsoleRequest() ? 'console' : Craft::$app->getRequest()->getServerName(),
				'allowedEncodingServerNames' => $this->getAllowedEncodingServerNames(),
				'gifEncodingEnabled' => (bool)$settings->enableGifEncoding,
				'gifQueueEnabled' => $this->isGifQueueEnabled(),
				'statusKey' => $statusKey,
				'source' => $outputInfo['source'] ?? null,
				'originalExists' => $outputInfo['originalExists'],
				'filename' => $outputInfo['filename'],
				'encodedFile' => $this->getFileDebugInfo($outputInfo['encodedFile']),
				'publicUrl' => [
					'url' => $outputInfo['publicUrl'],
					'exists' => $this->doesRemoteFileExist($outputInfo['publicUrl'], 1, 1),
				],
				'lockFile' => array_merge(
					$this->getFileDebugInfo($outputInfo['lockFile']),
					['processRunning' => is_file($outputInfo['lockFile']) && $this->isProcessRunningFromLockFile($outputInfo['lockFile'])]
				),
				'progressFile' => $this->getFileDebugInfo($outputInfo['progressFile']),
				'candidateFiles' => $candidateFiles,
				'storedStatusAgeSeconds' => isset($storedStatus['updatedAt']) ? max(0, time() - (int)$storedStatus['updatedAt']) : null,
				'storedStatus' => $storedStatusForDebug,
				'sysTempDir' => sys_get_temp_dir(),
				'documentRoot' => $_SERVER['DOCUMENT_ROOT'] ?? null,
			];
		} catch (Throwable $e) {
			return [
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Return debug data for configured video poster files.
	 *
	 * @param Asset|string $filePath
	 * @return array
	 */
	protected function getVideoPosterStatusDebug(Asset|string $filePath): array
	{
		$result = [];
		$settings = Transcoder::$plugin->getSettings();
		$subfolder = $this->getSubfolderFromPath($filePath);
		$normalized = $this->normalizeFilePath($filePath);
		$filePathResolved = $normalized['url'] ?? ($normalized['path'] ?? null);

		if ($filePathResolved === null || $filePathResolved === '') {
			return [
				'error' => 'Unable to resolve poster source path or URL.',
			];
		}

		if (!empty($subfolder)) {
			$destThumbnailPath = rtrim(App::parseEnv($settings['transcoderPaths']['thumbnail']), DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR
				. trim($subfolder, DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR;

			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['thumbnail']), '/')
				. '/' . trim($subfolder, '/');
		} else {
			$destThumbnailPath = rtrim(App::parseEnv($settings['transcoderPaths']['default']), DIRECTORY_SEPARATOR)
				. DIRECTORY_SEPARATOR;

			$urlBase = rtrim(App::parseEnv($settings['transcoderUrls']['default']), '/');
		}

		foreach ($this->getVideoPosterFormats() as $formatHandle => $format) {
			$options = $this->coalesceOptions('defaultThumbnailOptions', $format);
			$options['posterFormat'] = $formatHandle;
			$options['preventBlackBars'] = (bool)$settings->preventVideoPosterBlackBars;
			$primaryFilename = $this->getFilename(
				$filePathResolved,
				$options,
				$this->getThumbnailFilenameExcludeParams()
			);

			$result[$formatHandle] = [
				'options' => $options,
				'primaryFilename' => $primaryFilename,
				'destinationPath' => $destThumbnailPath,
				'urlBase' => $urlBase,
				'candidates' => $this->getThumbnailCandidateDebug($filePath, $filePathResolved, $options, $destThumbnailPath, $primaryFilename),
			];
		}

		return $result;
	}

	/**
	 * Return candidate poster/thumbnail filenames with filesystem checks.
	 *
	 * @param Asset|string $filePath
	 * @param string|null $filePathResolved
	 * @param array $thumbnailOptions
	 * @param string $destThumbnailPath
	 * @param string|null $primaryFilename
	 * @return array
	 */
	protected function getThumbnailCandidateDebug(
		Asset|string $filePath,
		?string $filePathResolved,
		array $thumbnailOptions,
		string $destThumbnailPath,
		?string $primaryFilename = null
	): array {
		$primaryFilename ??= $filePathResolved !== null
			? $this->getFilename($filePathResolved, $thumbnailOptions, $this->getThumbnailFilenameExcludeParams())
			: '';

		$candidates = [];
		foreach ($this->getThumbnailFilenameCandidates($filePath, $filePathResolved, $thumbnailOptions, $primaryFilename) as $filename) {
			$candidates[] = array_merge(
				['filename' => $filename],
				$this->getFileDebugInfo($destThumbnailPath . $filename)
			);
		}

		return $candidates;
	}

	/**
	 * Return filesystem checks for a path.
	 *
	 * @param string|null $path
	 * @return array
	 */
	protected function getFileDebugInfo(?string $path): array
	{
		$exists = $path !== null && is_file($path);
		$directory = $path !== null ? dirname($path) : null;

		return [
			'path' => $path,
			'exists' => $exists,
			'size' => $exists ? @filesize($path) : null,
			'isReadable' => $exists && is_readable($path),
			'directoryExists' => $directory !== null && is_dir($directory),
			'directoryWritable' => $directory !== null && is_dir($directory) && is_writable($directory),
		];
	}

	/**
	 * Return whether poster generation is currently queued or running.
	 *
	 * @param array $status
	 * @return bool
	 */
	protected function isVideoPosterStatusActive(array $status): bool
	{
		return in_array($status['posterStatus'] ?? null, ['queued', 'generating'], true);
	}

	/**
	 * Return whether a queued status is only waiting for a previously missing source.
	 *
	 * @param array $status
	 * @return bool
	 */
	protected function isOriginalVideoSourceRetryStatus(array $status): bool
	{
		$message = strtolower(implode(' ', array_filter(array_map(
			static fn(mixed $value): string => is_scalar($value) ? (string)$value : '',
			[
				$status['info'] ?? '',
				$status['error'] ?? '',
				$status['retryLastError'] ?? '',
				$status['posterMessage'] ?? '',
				$status['posterError'] ?? '',
				$status['posterLastError'] ?? '',
			]
		))));

		return str_contains($message, 'original video source is not reachable yet')
			|| str_contains($message, 'original video not found at');
	}

	/**
	 * Return poster status fields that should survive video status writes.
	 *
	 * @param array $status
	 * @return array
	 */
	protected function getVideoPosterStatusFields(array $status): array
	{
		return array_intersect_key($status, array_flip([
			'posterStatus',
			'posterProgress',
			'posterMessage',
			'posterJobId',
			'posterError',
			'posterUrls',
		]));
	}

	/**
	 * Collect video assets from an element's field layout.
	 *
	 * @param ElementInterface $element
	 * @param array $assets
	 */
	protected function collectVideoAssetsFromElement(ElementInterface $element, array &$assets): void
	{
		$fieldLayout = $element->getFieldLayout();
		if ($fieldLayout === null) {
			return;
		}

		foreach ($fieldLayout->getCustomFields() as $field) {
			try {
				$this->collectVideoAssets($element->getFieldValue($field->handle), $assets);
			} catch (Throwable $e) {
				Craft::warning('Unable to inspect Transcoder field handle "' . $field->handle . '": ' . $e->getMessage(), __METHOD__);
			}
		}
	}

	/**
	 * Collect video assets recursively from field values.
	 *
	 * @param mixed $value
	 * @param array $assets
	 */
	protected function collectVideoAssets(mixed $value, array &$assets): void
	{
		if ($value instanceof Asset) {
			if ($this->isVideoAsset($value)) {
				$assets[$value->id] = $value;
			}
			return;
		}

		if ($value instanceof ElementQueryInterface) {
			foreach ($value->all() as $element) {
				$this->collectVideoAssets($element, $assets);
			}
			return;
		}

		if ($value instanceof ElementInterface) {
			$this->collectVideoAssetsFromElement($value, $assets);
			return;
		}

		if (is_iterable($value)) {
			foreach ($value as $item) {
				$this->collectVideoAssets($item, $assets);
			}
		}
	}

	/**
	 * Collect GIF assets from an element's field layout.
	 *
	 * @param ElementInterface $element
	 * @param array $assets
	 */
	protected function collectGifAssetsFromElement(ElementInterface $element, array &$assets): void
	{
		$fieldLayout = $element->getFieldLayout();
		if ($fieldLayout === null) {
			return;
		}

		foreach ($fieldLayout->getCustomFields() as $field) {
			try {
				$this->collectGifAssets($element->getFieldValue($field->handle), $assets);
			} catch (Throwable $e) {
				Craft::warning('Unable to inspect Transcoder GIF field handle "' . $field->handle . '": ' . $e->getMessage(), __METHOD__);
			}
		}
	}

	/**
	 * Collect GIF assets recursively from field values.
	 *
	 * @param mixed $value
	 * @param array $assets
	 */
	protected function collectGifAssets(mixed $value, array &$assets): void
	{
		if ($value instanceof Asset) {
			if ($this->isGifAsset($value)) {
				$assets[$value->id] = $value;
			}
			return;
		}

		if ($value instanceof ElementQueryInterface) {
			foreach ($value->all() as $element) {
				$this->collectGifAssets($element, $assets);
			}
			return;
		}

		if ($value instanceof ElementInterface) {
			$this->collectGifAssetsFromElement($value, $assets);
			return;
		}

		if (is_iterable($value)) {
			foreach ($value as $item) {
				$this->collectGifAssets($item, $assets);
			}
		}
	}

	/**
	 * Returns whether the asset is safe to queue as a video.
	 *
	 * @param Asset $asset
	 * @return bool
	 */
	protected function isVideoAsset(Asset $asset): bool
	{
		if (!$asset->id) {
			return false;
		}

		$mimeType = strtolower((string)$asset->mimeType);
		if ($mimeType !== '') {
			return str_starts_with($mimeType, 'video/');
		}

		return AssetsHelper::getFileKindByExtension($asset->filename) === Asset::KIND_VIDEO;
	}

	/**
	 * Returns whether the asset is safe to queue as a GIF.
	 *
	 * @param Asset $asset
	 * @return bool
	 */
	protected function isGifAsset(Asset $asset): bool
	{
		if (!$asset->id) {
			return false;
		}

		$mimeType = strtolower((string)$asset->mimeType);
		if ($mimeType !== '') {
			return $mimeType === 'image/gif';
		}

		return strtolower(pathinfo($asset->filename, PATHINFO_EXTENSION)) === 'gif';
	}

	/**
	 * Parse the current ffmpeg progress file.
	 *
	 * @param string $filename
	 * @return array
	 */
	protected function getProgressData(string $filename): array
	{
		$result = [
			'filename' => $filename,
			'progress' => 0,
		];
		$progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename . '.progress';
		if (!file_exists($progressFile)) {
			return $result;
		}

		$content = @file_get_contents($progressFile);
		if (!$content) {
			return $result;
		}

		preg_match('/Duration: (.*?), start:/', $content, $matches);
		if (count($matches) === 0) {
			$result['duration'] = 'unknown';
			$result['progress'] = 'unknown';
			return $result;
		}

		$duration = $this->durationToSeconds($matches[1]);
		preg_match_all('/time=(.*?) bitrate/', $content, $matches);
		$rawTime = array_pop($matches);
		if (is_array($rawTime)) {
			$rawTime = array_pop($rawTime);
		}
		$time = $this->durationToSeconds((string)$rawTime);

		$result['duration'] = $duration;
		$result['time'] = $time;
		$result['progress'] = $duration > 0 ? min(99, round(($time / $duration) * 100)) : 0;

		return $result;
	}

	/**
	 * Convert a ffmpeg duration string to seconds.
	 *
	 * @param string $duration
	 * @return float
	 */
	protected function durationToSeconds(string $duration): float
	{
		$parts = array_reverse(explode(':', $duration));
		$seconds = (float)($parts[0] ?? 0);
		if (!empty($parts[1])) {
			$seconds += (int)$parts[1] * 60;
		}
		if (!empty($parts[2])) {
			$seconds += (int)$parts[2] * 60 * 60;
		}

		return $seconds;
	}

	/**
	 * Read status metadata from runtime storage.
	 *
	 * @param string $key
	 * @return array
	 */
	protected function readVideoStatus(string $key): array
	{
		$path = $this->getVideoStatusPath($key);
		if (!is_file($path)) {
			return [];
		}

		$status = JsonHelper::decodeIfJson((string)@file_get_contents($path), true);

		return is_array($status) ? $status : [];
	}

	/**
	 * Write status metadata to runtime storage.
	 *
	 * @param string $key
	 * @param array $status
	 */
	protected function writeVideoStatusByKey(string $key, array $status): void
	{
		$status['key'] = $key;
		$status['updatedAt'] = time();

		try {
			FileHelper::createDirectory($this->getVideoStatusDirectory());
			file_put_contents($this->getVideoStatusPath($key), JsonHelper::encode($status));
		} catch (Throwable $e) {
			Craft::error($e->getMessage(), __METHOD__);
		}
	}

	/**
	 * Acquire a short-lived filesystem lock around queue decisions for a status key.
	 *
	 * @param string $key
	 * @return mixed
	 */
	protected function acquireVideoQueueLock(string $key): mixed
	{
		try {
			FileHelper::createDirectory($this->getVideoStatusDirectory());
		} catch (Throwable $e) {
			Craft::warning('Unable to create Transcoder status directory for queue lock: ' . $e->getMessage(), __METHOD__);
		}

		$lockPath = $this->getVideoStatusPath($key) . '.queue.lock';
		$handle = @fopen($lockPath, 'c');
		if ($handle === false) {
			Craft::warning('Unable to open Transcoder queue lock: ' . $lockPath, __METHOD__);
			return null;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			Craft::warning('Unable to acquire Transcoder queue lock: ' . $lockPath, __METHOD__);
			return null;
		}

		return $handle;
	}

	/**
	 * Release a queue decision lock.
	 *
	 * @param mixed $handle
	 * @return void
	 */
	protected function releaseVideoQueueLock(mixed $handle): void
	{
		if (!is_resource($handle)) {
			return;
		}

		flock($handle, LOCK_UN);
		fclose($handle);
	}

	/**
	 * Return the directory that stores queue-aware status files.
	 *
	 * @return string
	 */
	protected function getVideoStatusDirectory(): string
	{
		return Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'transcoder-status';
	}

	/**
	 * Return the path to a status file.
	 *
	 * @param string $key
	 * @return string
	 */
	protected function getVideoStatusPath(string $key): string
	{
		$key = preg_replace('/[^a-zA-Z0-9\\-]/', '', $key);

		return $this->getVideoStatusDirectory() . DIRECTORY_SEPARATOR . $key . '.json';
	}

	/**
	 * Return whether a process stored in a lock file is still running.
	 *
	 * @param string $lockFile
	 * @return bool
	 */
	protected function isProcessRunningFromLockFile(string $lockFile): bool
	{
		$pid = trim((string)@file_get_contents($lockFile));
		if ($pid === '' || !ctype_digit($pid)) {
			return false;
		}

		exec("kill -0 $pid 2>&1", $processState);

		return count($processState) === 0;
	}

	/**
	 * Return whether a lock/progress pair no longer belongs to an active encode.
	 *
	 * @param string|null $lockFile
	 * @param string|null $progressFile
	 * @return bool
	 */
	protected function isEncodeLockStale(?string $lockFile, ?string $progressFile = null): bool
	{
		if (!$lockFile || !is_file($lockFile)) {
			return false;
		}

		if (!$this->isProcessRunningFromLockFile($lockFile)) {
			return true;
		}

		$timestampFile = $progressFile && is_file($progressFile) ? $progressFile : $lockFile;
		$lastUpdated = filemtime($timestampFile);

		return $lastUpdated !== false && (time() - $lastUpdated) > 120;
	}

	/**
	 * Return an error payload when ffmpeg produced an unusable video file.
	 *
	 * @param array $outputInfo
	 * @param array $storedStatus
	 * @return array|null
	 */
	protected function getVideoEncodeFailure(array $outputInfo, array $storedStatus = []): ?array
	{
		$encodedFile = $outputInfo['encodedFile'] ?? null;
		if (!$encodedFile || !is_file($encodedFile)) {
			return null;
		}

		$fileSize = filesize($encodedFile);
		$logExcerpt = $this->getFfmpegLogExcerpt($outputInfo['progressFile'] ?? null);
		$hasFailureLog = (bool)preg_match(
			'/conversion failed|error while|invalid data|unknown encoder|encoder .* not found|could not|failed/i',
			$logExcerpt
		);

		if ($fileSize >= self::MIN_VALID_VIDEO_FILE_SIZE && !$hasFailureLog) {
			return null;
		}

		$message = Craft::t(
			'transcoder',
			'Video encoding failed: ffmpeg produced an invalid output file ({size} bytes).',
			['size' => $fileSize]
		);

		if ($hasFailureLog) {
			$message .= ' ' . Craft::t('transcoder', 'The ffmpeg log contains errors.');
		}

		return array_filter([
			'error' => $message,
			'fileSize' => $fileSize,
			'ffmpegCommand' => $storedStatus['ffmpegCommand'] ?? null,
			'ffmpegLog' => $logExcerpt ?: null,
		], static fn($value) => $value !== null && $value !== '');
	}

	/**
	 * Return an error payload when ffmpeg died or stalled before a usable video was produced.
	 *
	 * @param array $outputInfo
	 * @param array $storedStatus
	 * @return array
	 */
	protected function getVideoProcessCrashedFailure(array $outputInfo, array $storedStatus = []): array
	{
		$encodedFile = $outputInfo['encodedFile'] ?? null;
		$fileSize = $encodedFile && is_file($encodedFile) ? filesize($encodedFile) : 0;
		$logExcerpt = $this->getFfmpegLogExcerpt($outputInfo['progressFile'] ?? null);
		$message = Craft::t('transcoder', 'Encoding failed due to a server error (process crashed, ffmpeg error)');

		if ($fileSize > 0) {
			$message .= ' ' . Craft::t(
				'transcoder',
				'ffmpeg produced a suspicious output file ({size} bytes).',
				['size' => $fileSize]
			);
		} else {
			$message .= ' ' . Craft::t('transcoder', 'No encoded output file was produced.');
		}

		if ($logExcerpt !== '') {
			$message .= ' ' . Craft::t('transcoder', 'The ffmpeg log excerpt is included below.');
		} else {
			$message .= ' ' . Craft::t('transcoder', 'No ffmpeg log output was available.');
		}

		return array_filter([
			'error' => $message,
			'source' => $outputInfo['source'] ?? null,
			'fileSize' => $fileSize,
			'ffmpegCommand' => $storedStatus['ffmpegCommand'] ?? null,
			'ffmpegLog' => $logExcerpt ?: null,
		], static fn($value) => $value !== null && $value !== '');
	}

	/**
	 * Return an error payload when ffmpeg failed to produce a usable GIF mp4 file.
	 *
	 * @param array $outputInfo
	 * @param array $storedStatus
	 * @return array|null
	 */
	protected function getGifEncodeFailure(array $outputInfo, array $storedStatus = []): ?array
	{
		$encodedFile = $outputInfo['encodedFile'] ?? null;
		$fileSize = $encodedFile && is_file($encodedFile) ? filesize($encodedFile) : 0;
		$logExcerpt = $this->getFfmpegLogExcerpt($outputInfo['progressFile'] ?? null);
		$hasFailureLog = (bool)preg_match(
			'/conversion failed|error while|invalid data|unknown encoder|encoder .* not found|could not|failed|no such file|permission denied/i',
			$logExcerpt
		);

		if ($fileSize > 0 && !$hasFailureLog) {
			return null;
		}

		$message = Craft::t('transcoder', 'GIF encoding failed due to a server error (process crashed, ffmpeg error)');
		if ($fileSize > 0) {
			$message .= ' ' . Craft::t(
				'transcoder',
				'ffmpeg produced a suspicious output file ({size} bytes).',
				['size' => $fileSize]
			);
		}
		if ($hasFailureLog) {
			$message .= ' ' . Craft::t('transcoder', 'The ffmpeg log contains errors.');
		}

		return array_filter([
			'error' => $message,
			'fileSize' => $fileSize,
			'ffmpegCommand' => $storedStatus['ffmpegCommand'] ?? null,
			'ffmpegLog' => $logExcerpt ?: null,
		], static fn($value) => $value !== null && $value !== '');
	}

	/**
	 * Return a compact ffmpeg log excerpt for admin/debug output.
	 *
	 * @param string|null $progressFile
	 * @return string
	 */
	protected function getFfmpegLogExcerpt(?string $progressFile): string
	{
		if (!$progressFile || !is_file($progressFile)) {
			return '';
		}

		$contents = trim((string)@file_get_contents($progressFile));
		if ($contents === '') {
			return '';
		}

		return substr($contents, -4000);
	}

	/**
	 * Remove temporary ffmpeg lock/progress files.
	 *
	 * @param string|null $lockFile
	 * @param string|null $progressFile
	 * @return void
	 */
	protected function removeEncodeTempFiles(?string $lockFile, ?string $progressFile = null): void
	{
		if ($lockFile) {
			@unlink($lockFile);
		}
		if ($progressFile) {
			@unlink($progressFile);
		}
	}

	/**
	 * Push a queue job, applying a delay when the active queue supports it.
	 *
	 * @param object $job
	 * @param int $delay
	 * @return mixed
	 */
	protected function pushQueueJob(object $job, int $delay = 0): mixed
	{
		$queue = Craft::$app->getQueue();
		if ($delay > 0 && method_exists($queue, 'delay')) {
			return $queue->delay($delay)->push($job);
		}

		return $queue->push($job);
	}

	/**
	 * Execute a shell command
	 *
	 * @param string $command
	 *
	 * @return string
	 */
	protected function executeShellCommand(string $command): string
	{
		// Create the shell command
		$shellCommand = new ShellCommand();
		$shellCommand->setCommand($command);

		// If we don't have proc_open, maybe we've got exec
		if (!function_exists('proc_open') && function_exists('exec')) {
			$shellCommand->useExec = true;
		}

		// Return the result of the command's output or error
		if ($shellCommand->execute()) {
			$result = $shellCommand->getOutput();
		} else {
			$result = $shellCommand->getError();
		}

		return $result;
	}
}
