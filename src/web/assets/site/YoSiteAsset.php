<?php

namespace justinholtweb\yo\web\assets\site;

use craft\web\AssetBundle;

/**
 * The front-end assets.
 *
 * No stylesheet, and that is the feature. What a message looks like on somebody's site is theirs
 * to decide; Yo ships the behaviour and a set of class names to hang it on.
 */
class YoSiteAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@justinholtweb/yo/web/assets/dist';

        $this->js = [
            ['datastar.js', 'type' => 'module'],
            'yo-site.js',
        ];

        parent::init();
    }
}
