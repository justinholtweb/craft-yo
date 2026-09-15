<?php

namespace justinholtweb\yo\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The floating panel's assets.
 */
class YoCpAsset extends AssetBundle
{
    public function init(): void
    {
        // Both bundles share one published directory. DataStar is 34 KB and there is no reason
        // for a site running both channels to ship it twice.
        $this->sourcePath = '@justinholtweb/yo/web/assets/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            // DataStar 1.0 is an ES module; it has to be registered as one or the browser parses
            // its `import` statements as syntax errors.
            ['datastar.js', 'type' => 'module'],
            'yo-cp.js',
        ];

        $this->css = [
            'yo-cp.css',
        ];

        parent::init();
    }
}
