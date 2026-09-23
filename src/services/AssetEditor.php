<?php

namespace nystudio107\transcoder\services;

use Craft;
use craft\elements\Asset;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\View;
use nystudio107\transcoder\Transcoder;
use yii\base\Event;

/**
 * Adds an explicit, permission-controlled recovery action to the asset editor.
 */
final class AssetEditor
{
    public const RETRY_PERMISSION = 'transcoder:retry-video';

    public static function register(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => 'Transcoder',
                'permissions' => [
                    self::RETRY_PERMISSION => [
                        'label' => Craft::t('transcoder', 'Retry missing video encodes and posters'),
                    ],
                ],
            ];
        });
        Event::on(Asset::class, Asset::EVENT_DEFINE_SIDEBAR_HTML, [self::class, 'renderSidebar']);
    }

    public static function renderSidebar(DefineHtmlEvent $event): void
    {
        $asset = $event->sender;
        $request = Craft::$app->getRequest();
        if ($event->static || !$asset instanceof Asset || !$asset->id
            || $request->getIsConsoleRequest() || !$request->getIsCpRequest()
            || !Transcoder::$plugin->transcode->isVideoAsset($asset)) {
            return;
        }
        if (!Craft::$app->getUser()->checkPermission(self::RETRY_PERMISSION)
            || !Craft::$app->getElements()->canView($asset)
            || !Craft::$app->getElements()->canSave($asset)) {
            return;
        }

        $event->html .= Craft::$app->getView()->renderTemplate('transcoder/_asset-recovery', [
            'assetId' => $asset->id,
            'siteId' => $asset->siteId,
        ], View::TEMPLATE_MODE_CP);
    }
}
