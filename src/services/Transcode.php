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
		'videoCodecOptions',
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
		if (!$settings->enableVideoEncoding) {
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
			$originalExists = $this->doesRemoteFileExist($filePathResolved);
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

		$destVideoFile = $this->getFilename($filePath instanceof Asset ? $filePath : ($filePathResolved ?? ''), $videoOptions);
		$encodedFile   = $destVideoPath . $destVideoFile;
		$publicUrl     = $urlBase . '/' . $destVideoFile;

		$lockFile     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.lock';
		$progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.progress';

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

					@unlink($lockFile);
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
						@unlink($lockFile);
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

		$ffmpegCmd = $settings['ffmpegPath']
			. ' -i ' . escapeshellarg($filePathResolved)
			. ' -vcodec ' . $thisEncoder['videoCodec']
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

		$ffmpegCmd = $this->addScalingFfmpegArgs($videoOptions, $ffmpegCmd);

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
			. ' -y ' . escapeshellarg($encodedFile)
			. ' 1> ' . $progressFile . ' 2>&1 & echo $!';

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
			return JsonHelper::encode([
				'status' => 'encoding',
				'url' => '',
				'info' => 'Encoding started',
			]);
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

			$status = $this->queueVideoEncode($asset, $event->videoOptions, $event->encodingOptions);
			if (($status['status'] ?? null) === 'queued') {
				$queued++;
			}
		}

		return $queued;
	}

	/**
	 * Queue a single video encode.
	 *
	 * @param Asset $asset
	 * @param array $videoOptions
	 * @param array $encodingOptions
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function queueVideoEncode(Asset $asset, array $videoOptions = [], array $encodingOptions = []): array
	{
		$settings = Transcoder::$plugin->getSettings();
		if (!$this->isVideoAsset($asset)) {
			return [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Transcoder: asset is not a video',
			];
		}
		if (!$settings->enableVideoEncoding && !$settings->enableVideoPosters) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}

		$outputInfo = $this->getVideoOutputInfo($asset, $videoOptions);
		$status = $this->getVideoStatusData($asset, $videoOptions, $encodingOptions);
		$missingPosters = $settings->enableVideoPosters && $this->hasMissingVideoPosters($asset);
		if (($status['status'] ?? null) === 'ok'
			&& is_file($outputInfo['encodedFile'])
			&& filesize($outputInfo['encodedFile']) > 0
			&& !$missingPosters
		) {
			return $status;
		}
		if (in_array($status['status'] ?? null, ['queued', 'encoding'], true)) {
			return $status;
		}

		$jobId = Craft::$app->getQueue()->push(new EncodeVideo([
			'assetId' => $asset->id,
			'videoOptions' => $videoOptions,
			'encodingOptions' => $encodingOptions,
		]));

		$status = [
			'status' => 'queued',
			'url' => '',
			'progress' => 0,
			'jobId' => $jobId,
		];
		$this->writeVideoStatus($asset, $videoOptions, $status, $encodingOptions);

		return $status;
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
				$settings['autoEncodeEncodingOptions'] ?? []
			);
		}

		if ($this->isGifQueueEnabled() && $this->isGifAsset($asset)) {
			$statuses['gif'] = $this->queueGifEncode(
				$asset,
				$settings['autoEncodeGifOptions'] ?? [],
				max(0, (int)$settings->gifQueueDelaySeconds)
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

		return ($settings->queueVideosOnSave || $settings->queueVideosOnEntrySave)
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

		return ($settings->queueGifsOnSave || $settings->queueGifsOnEntrySave)
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
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function queueGifEncode(Asset $asset, array $gifOptions = [], int $delay = 0): array
	{
		if (!$this->isGifAsset($asset)) {
			return [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Transcoder: asset is not a GIF',
			];
		}
		if (!Transcoder::$plugin->getSettings()->enableGifEncoding) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}

		$outputInfo = $this->getGifOutputInfo($asset, $gifOptions);
		$status = $this->getGifStatusData($asset, $gifOptions);
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
	public function getVideoStatus(Asset|string $filePath, array $videoOptions = [], array $encodingOptions = [], bool $queueIfMissing = false): string
	{
		$status = $this->getVideoStatusData($filePath, $videoOptions, $encodingOptions);
		if ($queueIfMissing
			&& $filePath instanceof Asset
			&& ($status['status'] ?? null) === 'pending'
		) {
			$status = $this->queueVideoEncode($filePath, $videoOptions, $encodingOptions);
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
		if (!Transcoder::$plugin->getSettings()->enableVideoEncoding) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}

		$outputInfo = $this->getVideoOutputInfo($filePath, $videoOptions);
		$statusKey = $this->getVideoStatusKey($filePath, $videoOptions, $encodingOptions);
		$storedStatus = $this->readVideoStatus($statusKey);

		if (is_file($outputInfo['encodedFile'])
			&& filesize($outputInfo['encodedFile']) > 0
			&& (!is_file($outputInfo['lockFile']) || !$this->isProcessRunningFromLockFile($outputInfo['lockFile']))
		) {
			@unlink($outputInfo['lockFile']);
			@unlink($outputInfo['progressFile']);
			$status = [
				'status' => 'ok',
				'url' => $outputInfo['publicUrl'],
				'progress' => 100,
			];
			if (!$outputInfo['originalExists']) {
				$status['warning'] = 'Original video missing, serving encoded version';
			}
			$this->writeVideoStatusByKey($statusKey, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status));
			return $this->sanitizeVideoStatus($status);
		}

		if (is_file($outputInfo['lockFile']) && !$this->isProcessRunningFromLockFile($outputInfo['lockFile'])) {
			@unlink($outputInfo['lockFile']);
			$status = [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'Encoding failed due to a server error (process crashed, ffmpeg error)',
			];
			$this->writeVideoStatusByKey($statusKey, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status));
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
				$this->getProgressData($outputInfo['filename'])
			);
			$this->writeVideoStatusByKey($statusKey, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status));
			return $this->sanitizeVideoStatus($status);
		}

		if (!empty($storedStatus) && ($storedStatus['status'] ?? null) === 'ok') {
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
		$status = array_merge($this->getVideoStatusStorageInfo($outputInfo), $status);

		$this->writeVideoStatusByKey(
			$this->getVideoStatusKey($filePath, $videoOptions, $encodingOptions),
			$status
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
			$url = $input->getUrl();
			if ($url && str_starts_with($url, '/')) {
				$siteUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();
				$url = rtrim($siteUrl, '/') . $url;
			}
			return ['url' => $url];
		}

		if ($this->isUrl($input)) {
			return ['url' => $input];
		}

		if (str_starts_with($input, '/')) {
			$siteUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();
			return ['url' => rtrim($siteUrl, '/') . $input];
		}

		return ['path' => $input];
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
	private function doesRemoteFileExist(string $url): bool
	{
		// Bail early if not a valid absolute URL
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}

		// Add custom params to the url
		$url = $this->addCustomParams($url);

		// Check if the remote file exists
		$headers = @get_headers($url);

		return $headers && strpos($headers[0], '200') !== false;
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
	 *
	 * @return string|false|null URL or path of the video thumbnail
	 * @throws InvalidConfigException
	 */
	public function getVideoThumbnailUrl(Asset|string $filePath, array $thumbnailOptions, bool $generate = true, bool $asPath = false): string|false|null
	{
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

			// Build the file name
			$destThumbnailFile = $this->getFilename($filePathResolved, $thumbnailOptions);

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
				$timeCode = gmdate('H:i:s', $thumbnailOptions['timeInSecs']);
				$ffmpegCmd .= ' -ss ' . $timeCode . '.00';
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
			$ffmpegCmd .= ' -f image2 -y ' . escapeshellarg($destThumbnailPath) . ' >/dev/null 2>/dev/null &';

			// Generate thumbnail if not exists
			if (!file_exists($destThumbnailPath)) {
				if ($generate) {
					$shellOutput = $this->executeShellCommand($ffmpegCmd);
					Craft::info($ffmpegCmd, __METHOD__);
				} else {
					Craft::info('Thumbnail does not exist, but not asked to generate it: ' . $filePathResolved, __METHOD__);
				}
				return false;
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
	 * Return a configured poster URL, or an empty string if it has not been generated.
	 *
	 * @param Asset|string $filePath
	 * @param string $formatHandle
	 * @param bool $generate
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getVideoPosterUrl(Asset|string $filePath, string $formatHandle, bool $generate = false): string
	{
		if (!Transcoder::$plugin->getSettings()->enableVideoPosters) {
			return '';
		}

		$formats = $this->getVideoPosterFormats();
		if (!array_key_exists($formatHandle, $formats)) {
			return '';
		}

		$options = $this->coalesceOptions('defaultThumbnailOptions', $formats[$formatHandle]);
		$options['posterFormat'] = $formatHandle;

		$url = $this->getVideoThumbnailUrl($filePath, $options, $generate);

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
	 * Generate all configured poster formats for a video.
	 *
	 * @param Asset|string $filePath
	 * @return array
	 * @throws InvalidConfigException
	 */
	public function generateVideoPosters(Asset|string $filePath): array
	{
		return $this->getVideoPosterUrls($filePath, true);
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
								$infoSummaryType = $stream['codec_type'];
								if (in_array($infoSummaryType, self::INFO_SUMMARY, false)) {
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

		return $this->getFilename($filePath, $videoOptions);
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
	 * @return string
	 * @throws InvalidConfigException
	 */
	public function getGifStatus(Asset|string $filePath, array $gifOptions = [], bool $queueIfMissing = false): string
	{
		$status = $this->getGifStatusData($filePath, $gifOptions);
		if ($queueIfMissing
			&& $filePath instanceof Asset
			&& ($status['status'] ?? null) === 'pending'
		) {
			$settings = Transcoder::$plugin->getSettings();
			$status = $this->queueGifEncode($filePath, $gifOptions, max(0, (int)$settings->gifQueueDelaySeconds));
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
		if (!Transcoder::$plugin->getSettings()->enableGifEncoding) {
			return [
				'status' => 'disabled',
				'url' => '',
				'progress' => 0,
			];
		}

		$outputInfo = $this->getGifOutputInfo($filePath, $gifOptions);
		$statusKey = $this->getGifStatusKey($filePath, $gifOptions);
		$storedStatus = $this->readVideoStatus($statusKey);

		if (is_file($outputInfo['encodedFile'])
			&& filesize($outputInfo['encodedFile']) > 0
			&& (!is_file($outputInfo['lockFile']) || !$this->isProcessRunningFromLockFile($outputInfo['lockFile']))
		) {
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
			$this->writeVideoStatusByKey($statusKey, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status));
			return $this->sanitizeVideoStatus($status);
		}

		if (is_file($outputInfo['lockFile']) && !$this->isProcessRunningFromLockFile($outputInfo['lockFile'])) {
			@unlink($outputInfo['lockFile']);
			$status = [
				'status' => 'error',
				'url' => '',
				'progress' => 0,
				'error' => 'GIF encoding failed due to a server error (process crashed, ffmpeg error)',
			];
			$this->writeVideoStatusByKey($statusKey, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status));
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
				$this->getProgressData($outputInfo['filename'])
			);
			$this->writeVideoStatusByKey($statusKey, array_merge($this->getVideoStatusStorageInfo($outputInfo), $status));
			return $this->sanitizeVideoStatus($status);
		}

		if (!empty($storedStatus) && ($storedStatus['status'] ?? null) === 'ok') {
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
		$status = array_merge($this->getVideoStatusStorageInfo($outputInfo), $status);

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
			if (!$lockFile || !is_file($lockFile) || !$this->isProcessRunningFromLockFile($lockFile)) {
				if ($lockFile) {
					@unlink($lockFile);
				}
				if (!empty($status['progressFile'])) {
					@unlink($status['progressFile']);
				}
				$status['status'] = 'ok';
				$status['url'] = $status['publicUrl'] ?? ($status['url'] ?? '');
				$status['progress'] = 100;
				$this->writeVideoStatusByKey($key, $status);
				return $this->sanitizeVideoStatus($status);
			}
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
			$originalExists = $this->doesRemoteFileExist($filePathResolved);
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
		if (!$settings->enableGifEncoding) {
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
			$originalExists = $this->doesRemoteFileExist($filePathResolved);
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
	
					@unlink($lockFile);
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
						@unlink($lockFile);
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
			. ' -y ' . escapeshellarg($encodedFile)
			. ' 1> ' . $progressFile . ' 2>&1 & echo $!';
	
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
	protected function getFilename(Asset|string $filePath, array $options): string
	{
		$settings = Transcoder::$plugin->getSettings();
		$assetId = $filePath instanceof Asset ? $filePath->id : null;
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
				if (!in_array($key, self::EXCLUDE_PARAMS, true)) {
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
		if (!empty($options['width']) && !empty($options['height'])) {
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
			$sharpen = '';
			if (!empty($options['sharpen']) && ($options['sharpen'] !== false)) {
				$sharpen = ',unsharp=5:5:1.0:5:5:0.0';
			}
			$ffmpegCmd .= ' -vf "scale='
				. $options['width'] . ':' . $options['height']
				. $aspectRatio
				. $sharpen
				. '"';
		}

		return $ffmpegCmd;
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

		if (!empty($status['encodedFile']) && is_file($status['encodedFile']) && filesize($status['encodedFile']) > 0) {
			$lockFile = $status['lockFile'] ?? null;
			if (!$lockFile || !is_file($lockFile) || !$this->isProcessRunningFromLockFile($lockFile)) {
				if ($lockFile) {
					@unlink($lockFile);
				}
				if (!empty($status['progressFile'])) {
					@unlink($status['progressFile']);
				}
				$status['status'] = 'ok';
				$status['url'] = $status['publicUrl'] ?? ($status['url'] ?? '');
				$status['progress'] = 100;
				$this->writeVideoStatusByKey($key, $status);
				return $this->sanitizeVideoStatus($status);
			}
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
			$originalExists = $this->doesRemoteFileExist($filePathResolved);
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
		$destVideoFile = $this->getFilename($filePath instanceof Asset ? $filePath : ($filePathResolved ?? ''), $videoOptions);

		return [
			'source' => $filePathResolved,
			'originalExists' => $originalExists,
			'filename' => $destVideoFile,
			'encodedFile' => $destVideoPath . $destVideoFile,
			'publicUrl' => $urlBase . '/' . $destVideoFile,
			'lockFile' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.lock',
			'progressFile' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . $destVideoFile . '.progress',
		];
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
