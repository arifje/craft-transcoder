<?php

namespace nystudio107\transcoder\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use nystudio107\transcoder\jobs\InspectMediaAsset;
use nystudio107\transcoder\models\Settings;
use nystudio107\transcoder\services\AssetEditor;
use nystudio107\transcoder\Transcoder;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class MediaRecoveryController extends Controller
{
    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission(AssetEditor::RETRY_PERMISSION);
        $request = Craft::$app->getRequest();
        $assetId = filter_var($request->getRequiredBodyParam('assetId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $siteId = filter_var($request->getRequiredBodyParam('siteId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($assetId === false || $siteId === false) {
            throw new BadRequestHttpException('Invalid asset or site ID.');
        }
        $asset = Asset::find()->id($assetId)->siteId($siteId)->one();
        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException('Asset not found.');
        }
        if (!Craft::$app->getElements()->canView($asset) || !Craft::$app->getElements()->canSave($asset)) {
            throw new ForbiddenHttpException('You cannot edit this asset.');
        }

        $transcode = Transcoder::$plugin->transcode;
        if (!$transcode->isVideoAsset($asset)) {
            throw new BadRequestHttpException('Only video assets can be retried.');
        }
        /** @var Settings $settings */
        $settings = Transcoder::$plugin->getSettings();
        if (!$transcode->canStartEncodingFromCurrentRequest()) {
            throw new ForbiddenHttpException('This server is not allowed to queue encoding.');
        }
        if (!$transcode->isRuntimeEncodingEnabled() || (!$settings->enableVideoEncoding && !$settings->enableVideoPosters)) {
            throw new BadRequestHttpException('Video encoding and poster recovery are disabled.');
        }

        try {
            $jobId = Craft::$app->getQueue()->push(new InspectMediaAsset([
                'assetId' => (int)$asset->id,
                'recoverMissingVideoOutputs' => true,
                'maxRetries' => max(0, (int)$settings->mediaInspectionMaxRetries),
                'retryDelaySeconds' => max(0, (int)$settings->mediaInspectionRetryDelaySeconds),
            ]));
            if ($jobId === null) {
                throw new \RuntimeException('Queue rejected the inspection job.');
            }
        } catch (Throwable $e) {
            Craft::error('Video recovery could not be queued for asset #' . $asset->id . ': ' . $e->getMessage(), __METHOD__);
            Craft::$app->getResponse()->setStatusCode(503);
            return $this->asJson(['message' => Craft::t('transcoder', 'Could not queue the check. Please try again later.')]);
        }

        return $this->asJson([
            'success' => true,
            'jobId' => $jobId,
            'message' => Craft::t('transcoder', 'Check queued. Missing video and posters will be retried.'),
        ]);
    }
}
