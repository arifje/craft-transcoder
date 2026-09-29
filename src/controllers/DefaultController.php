<?php
/**
 * Transcoder plugin for Craft CMS
 *
 * Transcode videos to various formats, and provide thumbnails of the video
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\transcoder\controllers;

use Craft;
use craft\errors\AssetDisallowedExtensionException;
use craft\helpers\Path as PathHelper;
use craft\web\Controller;
use nystudio107\transcoder\Transcoder;
use yii\base\ExitException;
use yii\web\BadRequestHttpException;
use yii\web\Response;

use function count;

/**
 * @author    nystudio107
 * @package   Transcode
 * @since     1.0.0
 */
class DefaultController extends Controller
{
    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|int|array<int|string> Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected array|bool|int $allowAnonymous = [
        'download-file' => self::ALLOW_ANONYMOUS_LIVE,
        'progress' => self::ALLOW_ANONYMOUS_LIVE,
        'video-status' => self::ALLOW_ANONYMOUS_LIVE,
        'gif-status' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    // Public Methods
    // =========================================================================

    /**
     * Force the download of a given $url.  We do it this way to prevent people
     * from downloading things that are outside of the server root.
     *
     * @param $url
     *
     * @throws AssetDisallowedExtensionException
     * @throws BadRequestHttpException
     * @throws ExitException
     */
    public function actionDownloadFile($url): void
    {
        // Anonymous downloads are opt-in; logged-in users may always download.
        if (!Transcoder::$plugin->getSettings()->enableDownloadFileEndpoint) {
            $this->requireLogin();
        }

        $filePath = parse_url($url, PHP_URL_PATH);
        // Remove any relative paths
        if (!PathHelper::ensurePathIsContained($filePath)) {
            throw new BadRequestHttpException('Invalid resource path: ' . $filePath);
        }
        // Only work for `allowedFileExtensions` file extensions
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $allowedExtensions = Craft::$app->getConfig()->getGeneral()->allowedFileExtensions;
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new AssetDisallowedExtensionException("File “{$filePath}” cannot be downloaded because “{$extension}” is not allowed.");
        }

        $filePath = $_SERVER['DOCUMENT_ROOT'] . $filePath;
        Craft::$app->getResponse()->sendFile(
            $filePath,
            null,
            ['inline' => false]
        );
        Craft::$app->end();
    }

    /**
     * Return a JSON-encoded array providing the progress of the transcoding:
     *
     * 'filename' => the name of the file
     * 'duration' => the duration of the video/audio stream
     * 'time' => the time of the current encoding
     * 'progress' => a percentage indicating how much of the encoding is done
     *
     * @param $filename
     *
     * @return Response
     */
    public function actionProgress($filename): Response
    {
        $result = [];
        $filename = basename((string)$filename);
        if ($filename === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $filename)) {
            throw new BadRequestHttpException('Invalid filename');
        }
        $progressFile = Transcoder::$plugin->transcode->getWorkDirectory() . DIRECTORY_SEPARATOR . $filename . '.progress';
        if (file_exists($progressFile)) {
            $content = @file_get_contents($progressFile);
            if ($content) {
                // get duration of source
                preg_match('/Duration: (.*?), start:/', $content, $matches);
                if (count($matches) > 0) {
                    $rawDuration = $matches[1];

                    // rawDuration is in 00:00:00.00 format. This converts it to seconds.
                    $ar = array_reverse(explode(':', $rawDuration));
                    $duration = (float)$ar[0];
                    if (!empty($ar[1])) {
                        $duration += (int)$ar[1] * 60;
                    }
                    if (!empty($ar[2])) {
                        $duration += (int)$ar[2] * 60 * 60;
                    }
                } else {
                    $duration = 'unknown'; // with GIF as input, duration is unknown
                }

                // Get the time in the file that is already encoded
                preg_match_all('/time=(.*?) bitrate/', $content, $matches);
                // Use the last reported time when there is more than one match
                $timeMatches = $matches[1];
                $rawTime = (string)(end($timeMatches) ?: '');

                //rawTime is in 00:00:00.00 format. This converts it to seconds.
                $ar = array_reverse(explode(':', $rawTime));
                $time = (float)$ar[0];
                if (!empty($ar[1])) {
                    $time += (int)$ar[1] * 60;
                }
                if (!empty($ar[2])) {
                    $time += (int)$ar[2] * 60 * 60;
                }

                //calculate the progress
                if ($duration !== 'unknown') {
                    $progress = round(($time / $duration) * 100);
                } else {
                    $progress = 'unknown';
                }

                // return results
                if ($progress !== 'unknown' && $progress < 100) {
                    $result = [
                        'filename' => $filename,
                        'duration' => $duration,
                        'time' => $time,
                        'progress' => $progress,
                    ];
                } elseif ($progress === 'unknown') {
                    $result = [
                        'filename' => $filename,
                        'duration' => 'unknown',
                        'time' => $time,
                        'progress' => 'unknown',
                        'message' => 'encoding GIF, can\'t determine duration',
                    ];
                }
            }
        }

        return $this->asJson($result);
    }

    /**
     * Return the queue-aware video status by stable key.
     *
     * @param string $key
     * @return Response
     */
    public function actionVideoStatus(string $key): Response
    {
        $transcode = Transcoder::$plugin->transcode;

        return $this->asJson($transcode->getPublicStatus($transcode->getVideoStatusByKey($key), $this->canViewStatusDetails()));
    }

    /**
     * Return the queue-aware GIF status by stable key.
     *
     * @param string $key
     * @return Response
     */
    public function actionGifStatus(string $key): Response
    {
        $transcode = Transcoder::$plugin->transcode;

        return $this->asJson($transcode->getPublicStatus($transcode->getGifStatusByKey($key), $this->canViewStatusDetails()));
    }

    // Protected Methods
    // =========================================================================

    /**
     * Only admins may see ffmpeg commands, logs and server paths in status output.
     *
     * @return bool
     */
    protected function canViewStatusDetails(): bool
    {
        return (bool)Craft::$app->getUser()->getIdentity()?->admin;
    }
}
