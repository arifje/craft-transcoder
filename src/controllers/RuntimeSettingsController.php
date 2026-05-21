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
use craft\web\Controller;
use craft\web\Response;
use nystudio107\transcoder\Transcoder;
use Throwable;

/**
 * Saves runtime settings from CP utilities.
 */
class RuntimeSettingsController extends Controller
{
    /**
     * Save runtime encoding settings.
     *
     * @return Response
     */
    public function actionSave(): Response
    {
        $this->requirePermission('utility:transcoder-encoding');
        $this->requirePostRequest();

        $enabled = (bool)Craft::$app->getRequest()->getBodyParam('enabled');

        try {
            Transcoder::$plugin->runtimeSettings->setEncodingEnabled($enabled);
            $stopped = $enabled ? 0 : Transcoder::$plugin->transcode->stopActiveEncodingProcesses();
            Craft::$app->getSession()->setNotice(
                $stopped > 0
                    ? Craft::t('transcoder', 'Transcoder runtime settings saved. Stopped {count} active encode(s).', ['count' => $stopped])
                    : Craft::t('transcoder', 'Transcoder runtime settings saved.')
            );
        } catch (Throwable $e) {
            Craft::error('Transcoder: Could not save runtime settings: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError(Craft::t('transcoder', 'Could not save Transcoder runtime settings.'));
        }

        return $this->redirectToPostedUrl();
    }
}
