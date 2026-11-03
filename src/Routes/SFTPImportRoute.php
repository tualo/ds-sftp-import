<?php

namespace Tualo\Office\DSSFTPImport\Routes;

use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\DSSFTPImport\Scopes\DSSFTPImportScope;


class SFTPImportRoute extends \Tualo\Office\Basic\RouteWrapper
{
    public static function scope(): string
    {
        return DSSFTPImportScope::identifier();
    }
    public static function register()
    {
        BasicRoute::add('/dssftp/import/(?P<tablename>[\w\-\_]+)', function ($matches) {
            App::contenttype('application/json');
            $success = false;
            App::result('msg', '');

            App::result('success', $success);
        }, ['get'], true, [], self::scope());
    }
}
